<?php

namespace Menrui;

class AdbSync
{
    private const IDX_FILE = 1;
    private const IDX_HASH = 2;

    private array $supportedArchives = [
        'zip' => [
            'x' => '',
            'l' => 'unzip -l',
            'sizeParser' => '/(?<size>\\d+)\\s+(?<num>\\d+)\\s+files?/',
        ],
        '7z'  => [
            'x' => '',
            'l' => '7z l',
            'sizeParser' => '/(?<size>\\d+)\\s+\\d+\\s+(?<num>\\d+)\\s+files?/',
        ],
    ];

    public bool $verbose = false;
    public bool $debug   = false;
    public string $tmp;

    public function __construct(
        private string $adbPath,
        private string $target,
    ) {
        $this->tmp = sys_get_temp_dir();

        system(implode(' ', [
            $adbPath,
            'connect',
            escapeshellarg($target),
        ]));
    }

    public function println(string $message): void
    {
        echo "$message\n";
    }

    public function exec(array $args = []): array
    {
        $cmd = sprintf('%s shell "%s"', $this->adbPath, implode(' ', $args));
        $this->debug && $this->println($cmd);
        exec($cmd, $outputs, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            exit(1);
        }
        return $outputs;
    }

    public function push(string $srcPath, string $dstDir): array
    {
        $cmd = sprintf(
            '%s push %s %s',
            $this->adbPath,
            escapeshellarg($srcPath),
            escapeshellarg($dstDir),
        );
        $this->debug && $this->println($cmd);
        exec($cmd, $outputs, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            exit(1);
        }
        return $outputs;
    }

    public function rm(string $dstPath): array
    {
        return $this->exec([
            'rm',
            '-rf',
            escapeshellarg($dstPath),
        ]);
    }

    public function mkdir(string $dir): array
    {
        return $this->exec([
            'mkdir',
            '-p',
            escapeshellarg($dir),
        ]);
    }

    private function parseOptions(string $optionString): array
    {
        $options = [];
        if (preg_match('/^(full|rand):?(.*)$/', $optionString, $m)) {
            $options['mode'] = $m[1];
            foreach (explode(',', $m[2]) as $input) {
                $i = trim($input);
                if (preg_match('/^\\d+$/', $i)) {
                    $options['num'] = (int)$i;
                } elseif (preg_match('/^(\\d+)([gmk])$/', $i, $im)) {
                    $size = (int)$im[1];
                    $unit = $im[2];
                    $options['size'] = match ($unit) {
                        'g' => $size * 1024 * 1024 * 1024,
                        'm' => $size * 1024 * 1024,
                        'k' => $size * 1024,
                    };
                } elseif ($i === 'ext') {
                    $options['ext'] = true;
                } elseif ($i === 'zip') {
                    $options['zip'] = true;
                }
            }
        }
        return $options;
    }

    public function sync(
        string $srcPath,
        string $dstPath,
        array $targets
    ): void {
        $dirs = scandir($srcPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            if (array_key_exists($dir, $targets)) {
                $this->println("[SCAN] $dir");
                $settings = $targets[$dir];
                $options  = $this->parseOptions($settings);
                if (!($options['mode'] ?? false)) {
                    $this->println("[SKIP] invalid settings : $settings");
                    continue;
                }
                $this->mkdir("$dstPath/$dir");
                $srcList = $this->listLocal($srcPath, $dir);
                $srcList = $this->filterSrcList($srcList, $options);
                $dstList = $this->listRemote($dstPath, $dir);
                $this->syncCore($dstPath, $dir, $srcList, $dstList, $options);
            } else {
                $this->verbose && $this->println("[SKIP] $dir");
            }
        }
    }

    private function syncFiles(array $sData, string $dstPath, $dir): void
    {
        foreach ($sData as $data) {
            [$src, $file] = $data;
            $dst = "$dstPath/$dir/$file";
            $dstDir = dirname($dst);
            if ($dstDir !== "$dstPath/$dir") {
                $this->mkdir($dstDir);
            }
            $this->push($src, $dst);
            $this->println("[PUSH] $file");
        }
    }

    private function checkSupportedArchive(string $file): string
    {
        $m = [];
        if (preg_match('/\\.(zip|7z)$/i', $file, $m)) {
            $ext = strtolower($m[1]);
        } else {
            fwrite(STDERR, "ERROR: not supported extension. $file\n");
            exit(1);
        }
        return $ext;
    }

    private function extractArchive(string $file): array
    {
        $ext = $this->checkSupportedArchive($file);

        do {
            $rand = md5(mt_rand());
            $dir = $this->tmp . '/___adb_sync/' . $rand;
        } while (file_exists($dir));
        mkdir($dir, 0777, true);
        register_shutdown_function(function ($dir) {
            exec(sprintf('rm -rf %s', escapeshellarg($dir)));
        }, $dir);

        $exe = match ($ext) {
            'zip' => 'unzip',
            '7z'  => '7z e',
        };

        $cmd = sprintf('cd %s; %s %s', escapeshellarg($dir), $exe, escapeshellarg($file));
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            fwrite(STDERR, implode("\n", $lines));
            exit(1);
        }
        $files = glob("$dir/*");
        return [$dir, array_map(fn ($n) => str_replace("$dir/", '', $n), $files)];
    }

    private function getSizeInArchive(string $file): int
    {
        $arcInfo  = $this->getArchiveInfo($file);
        $exe  = $arcInfo['l'];
        $cmd  = sprintf('%s %s', $exe, escapeshellarg($file));
        $last = exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            fwrite(STDERR, implode("\n", $lines));
            exit(1);
        }
        preg_match($arcInfo['sizeParser'], $last, $info);
        if (!($info['size'] ?? false)) {
            fwrite(STDERR, "ERROR: cannot retrieve uncompressed file size.\n");
            fwrite(STDERR, implode("\n", [$cmd, ...$lines]));
            exit(1);
        }
        return $info['size'];
    }

    private function sync7zToZip(array $sData, string $dstPath, string $dir, string $zipFile): void
    {
        [$fileInfo]      = $sData;
        [$src]           = $fileInfo;
        [$eDir, $eFiles] = $this->extractArchive($src);
        if (count($eFiles) !== 1) {
            fwrite(STDERR, "ERROR: if 7z to zip, 7z must have one file.\n");
            fwrite(STDERR, implode("\n", $eFiles));
            exit(1);
        }
        $eFile = $eFiles[0];
        $cmd = sprintf(
            'cd %s; zip -9 %s %s',
            escapeshellarg($eDir),
            escapeshellarg($zipFile),
            escapeshellarg($eFile)
        );
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            fwrite(STDERR, implode("\n", $lines));
            exit(1);
        }
        $dst    = "$dstPath/$dir/$zipFile";
        $dstDir = dirname($dst);
        if ($dstDir !== "$dstPath/$dir") {
            $this->mkdir($dstDir);
        }
        $this->push("$eDir/$zipFile", $dst);
        $this->println("[PUSH] $zipFile");
    }

    private function syncArchiveFiles(array $sData, string $dstPath, string $dir): void
    {
        [$sFileInfo]         = $sData;
        [$src, $file, $hash] = $sFileInfo;

        $dBase = $this->trimArchiveExt($file);
        [$eDir, $eFiles] = $this->extractArchive($src);
        $hashFile = "hash_$hash";
        touch("$eDir/$hashFile");
        $eFiles[] = $hashFile;
        foreach ($eFiles as $eFile) {
            $dst = "$dstPath/$dir/$dBase/$eFile";
            $dstDir = dirname($dst);
            if ($dstDir !== "$dstPath/$dir") {
                $this->mkdir($dstDir);
            }
            $this->push("$eDir/$eFile", $dst);
            $this->println("[PUSH] $eFile");
        }
    }

    private function convFileHash(array $data): array
    {
        $map = [];
        foreach ($data as $item) {
            $map[$item[self::IDX_FILE]] = $item[self::IDX_HASH];
        }
        return $map;
    }

    private function compareDir(array $sData, array $dData): bool
    {
        $same  = count($sData) === count($dData);
        if ($same) {
            $sHash = $this->convFileHash($sData);
            $dHash = $this->convFileHash($dData);
            foreach ($sHash as $k => $v) {
                if ($v !== ($dHash[$k] ?? null)) {
                    $same = false;
                    break;
                }
            }
        }
        return $same;
    }

    private function compareArc(array $sData, array $dData): bool
    {
        [$sFileInfo] = $sData;
        $sHash       = $sFileInfo[self::IDX_HASH];

        foreach ($dData as $dFileInfo) {
            $dHashFile = basename($dFileInfo[self::IDX_FILE]);
            if ($dHashFile === "hash_$sHash") {
                return true;
            }
        }
        return false;
    }

    private function syncCore(
        string $dstPath,
        string $dir,
        array $srcList,
        array $dstList,
        array $options
    ): void {
        foreach ($srcList as $sKey => $sData) {
            if (($options['zip'] ?? false) && ($sFile = $this->isFileType($sData, '7z'))) {
                $hash = $sFile[self::IDX_HASH];
                $dKey = $this->trimArchiveExt($sKey) . "_$hash.zip";
                $comp = fn() => true;
                $sync = $this->sync7zToZip(...);
            } elseif (($options['ext'] ?? false) && $this->isExtractable($sData)) {
                $dKey = $this->trimArchiveExt($sKey);
                $comp = $this->compareArc(...);
                $sync = $this->syncArchiveFiles(...);
            } else {
                $dKey = $sKey;
                $comp = $this->compareDir(...);
                $sync = $this->syncFiles(...);
            }

            if (array_key_exists($dKey, $dstList)) {
                $dData = $dstList[$dKey];
                if ($comp($sData, $dData)) {
                    $this->println("[SAME] $sKey => $dKey");
                } else {
                    $this->println("[UP] $sKey => $dKey");
                    $this->rm("$dstPath/$dir/$dKey");
                    $sync($sData, $dstPath, $dir, $dKey);
                }
            } else {
                $this->println("[NEW] $sKey => $dKey");
                $sync($sData, $dstPath, $dir, $dKey);
            }
            unset($dstList[$dKey]);
        }
        foreach (array_keys($dstList) as $key) {
            $this->println("[DEL] $key");
            $this->rm("$dstPath/$dir/$key");
        }
    }

    private function trimArchiveExt(string $fileName): string
    {
        return preg_replace('/\\.(zip|7z)$/i', '', $fileName);
    }

    private function isFileType(array $data, string $ext): ?array
    {
        $file = $this->isSingleFile($data);
        if (!$file) {
            return false;
        }
        [$path] = $file;
        $escExt = preg_quote($ext, '/');
        return preg_match("/\\.{$escExt}\$/", $path) ? $file : null;
    }

    private function isSingleFile(array $data): ?array
    {
        return count($data) === 1 ? $data[0] : null;
    }

    private function filterSrcList(array $srcList, array $options): array
    {
        if ($options['mode'] === 'full') {
            return $srcList;
        }

        if ($num = ($options['num'] ?? false)) {
            $newList = [];
            $keys    = array_rand($srcList, min(count($srcList), $num));
            foreach (is_array($keys) ? $keys : [$keys] as $key) {
                $newList[$key] = $srcList[$key];
            }
            $this->println(sprintf('[RAND] %s files', number_format(count($newList))));
            return $newList;
        }

        if ($th = ($options['size'] ?? false)) {
            $extract = $options['ext'] ?? false;
            $sum     = 0;
            $newList = [];
            $tmpKeys = array_keys($srcList);
            shuffle($tmpKeys);
            foreach ($tmpKeys as $key) {
                $data = $srcList[$key];
                if ($extract && $this->isExtractable($data)) {
                    [$fileInfo] = $data;
                    [$file]     = $fileInfo;
                    $size   = $this->getSizeInArchive($file);
                } else {
                    $size = 0;
                    foreach ($data as $file) {
                        [$path] = $file;
                        $size   += filesize($path);
                    }
                }
                if (($sum + $size) <= $th) {
                    $newList[$key] = $data;
                    $sum += $size;
                }
            }
            $this->println(sprintf('[RAND] %s bytes', number_format($sum)));
            return $newList;
        }
    }

    private function isExtractable(array $filesInGame): bool
    {
        if (count($filesInGame) !== 1) {
            return false;
        }
        [$fileInfo] = $filesInGame;
        [$file]     = $fileInfo;
        return $this->isSupportedArchive($file);
    }

    private function isSupportedArchive(string $file): bool
    {
        return null !== $this->getSupportedArchiveExtension($file);
    }

    private function getSupportedArchiveExtension(string $file): ?string
    {
        $extensions = implode('|', array_map(
            fn ($n) => preg_quote($n, '/'),
            array_keys($this->supportedArchives)
        ));
        if (preg_match("/\\.($extensions)$/i", $file, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function getArchiveInfo(string $file): array
    {
        $extension = $this->getSupportedArchiveExtension($file);
        return $this->supportedArchives[$extension];
    }

    private function listCore(string $base, array $lines, bool $withHash = true): array
    {
        $list = [];
        foreach ($lines as $line) {
            $m = [];
            if ($withHash) {
                preg_match('/^([0-9a-f]{32})\\s+(.+)/', $line, $m);
                $hash = $m[1];
                $path = $m[2];
                $file = str_replace($base, '', $path);
                $key  = preg_replace('#/.+$#u', '', $file);
                $list[$key][] = [$path, $file, $hash];
            } else {
                $path = $line;
                $file = str_replace($base, '', $path);
                $key  = preg_replace('#/.+$#u', '', $file);
                $list[$key][] = [$path, $file];
            }
        }
        return $list;
    }

    private function listLocal(string $srcPath, string $dir, bool $withHash = true): array
    {
        $lines   = [];
        $base    = "$srcPath/$dir/";
        if ($withHash) {
            $cmd = sprintf('find %s -type f -exec md5sum {} \;', escapeshellarg($base));
        } else {
            $cmd = sprintf('find %s -type f', escapeshellarg($base));
        }
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            exit(1);
        }
        return $this->listCore($base, $lines, $withHash);
    }

    private function listRemote(string $dstPath, string $dir, bool $withHash = true): array
    {
        $base = "$dstPath/$dir/";
        if ($withHash) {
            $lines = $this->exec([
                'find',
                escapeshellarg($base),
                '-type f',
                '-exec md5sum {} \;'
            ]);
        } else {
            $lines = $this->exec([
                'find',
                escapeshellarg($base),
                '-mindepth 1',
                '-maxdepth 1',
            ]);
        }
        return $this->listCore($base, $lines, $withHash);
    }
}
