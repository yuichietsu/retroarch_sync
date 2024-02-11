<?php

namespace Menrui;

class AdbSync
{
    private const IDX_PATH = 0;
    private const IDX_FILE = 1;
    private const IDX_HASH = 2;

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

    public function sync(
        string $srcPath,
        string $dstPath,
        array $filters
    ): void {
        $dirs = scandir($srcPath);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            if (array_key_exists($dir, $filters)) {
                $this->println("[SCAN] $dir");
                $this->mkdir("$dstPath/$dir");
                $syncType = $filters[$dir];
                if (preg_match('/^full:?(.*)$/', $syncType, $m)) {
                    if ($m[1] === 'zip') {
                        $this->syncDirZip($srcPath, $dstPath, $dir);
                    } else {
                        $this->syncDir($srcPath, $dstPath, $dir);
                    }
                } elseif (preg_match('/^rand:?(\\d*)([mg]?)$/', $syncType, $m)) {
                    if ($m[2]) {
                        $mb = $m[1] * ($m[2] === 'g' ? 1024 : 1);
                        $this->syncDirRandMB($srcPath, $dstPath, $dir, $mb);
                    } else {
                        $this->syncDirRandNum($srcPath, $dstPath, $dir, $m[1]);
                    }
                } elseif (preg_match('/^xrand:?(\\d*)([mg]?)$/', $syncType, $m)) {
                    if ($m[2]) {
                        $mb = $m[1] * ($m[2] === 'g' ? 1024 : 1);
                        $this->syncDirXRandMB($srcPath, $dstPath, $dir, $mb);
                    } else {
                        $this->syncDirXRandNum($srcPath, $dstPath, $dir, $m[1]);
                    }
                }
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
        $ext = $this->checkSupportedArchive($file);

        $exe = match ($ext) {
            'zip' => 'unzip -l',
            '7z'  => '7z l',
        };
        $cmd  = sprintf('%s %s', $exe, escapeshellarg($file));
        $last = exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            fwrite(STDERR, implode("\n", $lines));
            exit(1);
        }
        match ($ext) {
            'zip' => preg_match('/(?<size>\\d+)\\s+(?<num>\\d+)\\s+files?/', $last, $info),
            '7z'  => preg_match('/(?<size>\\d+)\\s+\\d+\\s+(?<num>\\d+)\\s+files?/', $last, $info),
        };
        if (!($info['size'] ?? false)) {
            fwrite(STDERR, "ERROR: cannot retrieve uncompressed file size.\n");
            fwrite(STDERR, implode("\n", [$cmd, ...$lines]));
            exit(1);
        }
        return $info['size'];
    }

    private function sync7zToZip(array $srcData, string $dstPath, string $dir, string $zipFile): void
    {
        [$src, $file, $hash] = $srcData;
        [$eDir, $eFiles]     = $this->extractArchive($src);
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

    private function syncArchiveFiles(array $srcData, string $dstPath, string $dir): void
    {
        [$src, $file, $hash] = $srcData;

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

    private function syncCore(
        string $dstPath,
        string $dir,
        array $srcList,
        array $dstList,
    ): void {
        foreach ($srcList as $key => $sData) {
            if (array_key_exists($key, $dstList)) {
                $dData = $dstList[$key];
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
                if ($same) {
                    $this->verbose && $this->println("[SAME] $key");
                } else {
                    $this->println("[SYNC] $key");
                    $this->rm("$dstPath/$dir/$key");
                    $this->syncFiles($sData, $dstPath, $dir);
                }
            } else {
                $this->println("[NEW] $key");
                $this->syncFiles($sData, $dstPath, $dir);
            }
            unset($dstList[$key]);
        }
        foreach ($dstList as $key => $data) {
            $this->println("[DEL] $key");
            $this->rm("$dstPath/$dir/$key");
        }
    }

    private function trimArchiveExt(string $fileName): string
    {
        return preg_replace('/\\.(zip|7z)$/i', '', $fileName);
    }

    private function checkXRandMode(array $dataList): mixed
    {
        if (count($dataList) !== 1) {
            $error = "ERROR: xrand mode list must be one.\n";
            foreach ($dataList as $data) {
                $error .= "{$data[1]}\n";
            }
            fwrite(STDERR, $error);
            exit(1);
        }
        return $dataList[0];
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

    private function syncArchive(
        string $dstPath,
        string $dir,
        array $srcList,
        array $dstList,
    ): void {
        foreach ($srcList as $key => $sData) {
            $srcData = $this->checkXRandMode($sData);
            $dstKey = $this->trimArchiveExt($key);
            if (array_key_exists($dstKey, $dstList)) {
                $dstData = $this->checkXRandMode($dstList[$dstKey]);

                [$sPath, $sFile, $sHash] = $srcData;
                [$dPath, $dFile]         = $dstData;

                $same   = false;
                $rFiles = $this->listRemote($dstPath, "$dir/$dstKey", false);
                foreach ($rFiles as $rFile) {
                    $rmtFile     = $this->checkXRandMode($rFile);
                    $rmtFileName = $rmtFile[self::IDX_FILE];
                    if ($rmtFileName === "hash_$sHash") {
                        $same = true;
                        break;
                    }
                }
                if ($same) {
                    $this->verbose && $this->println("[SAME] $key => $dstKey");
                } else {
                    $this->println("[SYNC] $key => $dstKey");
                    $this->rm("$dstPath/$dir/$dstKey");
                    $this->syncArchiveFiles($srcData, $dstPath, $dir);
                }
            } else {
                $this->println("[NEW] $key => $dstKey");
                $this->syncArchiveFiles($srcData, $dstPath, $dir);
            }
            unset($dstList[$dstKey]);
        }
        foreach ($dstList as $key => $data) {
            $this->println("[DEL] $key");
            $this->rm("$dstPath/$dir/$key");
        }
    }

    private function syncZip(
        string $dstPath,
        string $dir,
        array $srcList,
        array $dstList,
    ): void {
        foreach ($srcList as $key => $sData) {
            $sFile = $this->isFileType($sData, '7z');
            if (!$sFile) {
                continue;
            }
            [$p, $f, $h] = $sFile;
            $dstKey = $this->trimArchiveExt($key) . "_$h.zip";

            if (array_key_exists($dstKey, $dstList)) {
                $this->verbose && $this->println("[SAME] $key => $dstKey");
            } else {
                $this->println("[NEW] $key => $dstKey");
                $this->sync7zToZip($sFile, $dstPath, $dir, $dstKey);
            }
            unset($dstList[$dstKey]);
        }
        foreach ($dstList as $key => $data) {
            $this->println("[DEL] $key");
            $this->rm("$dstPath/$dir/$key");
        }
    }

    private function syncDir(
        string $srcPath,
        string $dstPath,
        string $dir,
    ): void {
        $srcList = $this->listLocal($srcPath, $dir);
        $dstList = $this->listRemote($dstPath, $dir);
        $this->syncCore($dstPath, $dir, $srcList, $dstList);
    }

    private function syncDirZip(
        string $srcPath,
        string $dstPath,
        string $dir,
    ): void {
        $srcList = $this->listLocal($srcPath, $dir);
        $dstList = $this->listRemote($dstPath, $dir, false);
        $this->syncZip($dstPath, $dir, $srcList, $dstList);
    }

    private function syncDirRandNum(
        string $srcPath,
        string $dstPath,
        string $dir,
        int $num,
    ): void {
        $srcList = [];
        $tmpList = $this->listLocal($srcPath, $dir);
        $keys    = array_rand($tmpList, min(count($tmpList), $num));
        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            $srcList[$key] = $tmpList[$key];
        }
        $dstList = $this->listRemote($dstPath, $dir);
        $this->println(sprintf('[RAND] %s files', number_format(count($srcList))));
        $this->syncCore($dstPath, $dir, $srcList, $dstList);
    }

    private function syncDirRandMB(
        string $srcPath,
        string $dstPath,
        string $dir,
        int $mb,
    ): void {
        $sum     = 0;
        $th      = $mb * 1024 * 1024;
        $srcList = [];
        $tmpList = $this->listLocal($srcPath, $dir);
        $tmpKeys = array_keys($tmpList);
        shuffle($tmpKeys);
        foreach ($tmpKeys as $key) {
            $data = $tmpList[$key];
            $size = 0;
            foreach ($data as $file) {
                [$path] = $file;
                $size   += filesize($path);
            }
            if (($sum + $size) <= $th) {
                $srcList[$key] = $data;
                $sum += $size;
            }
        }
        $dstList = $this->listRemote($dstPath, $dir);
        $this->println(sprintf('[RAND] %s bytes', number_format($sum)));
        $this->syncCore($dstPath, $dir, $srcList, $dstList);
    }

    private function syncDirXRandNum(
        string $srcPath,
        string $dstPath,
        string $dir,
        int $num,
    ): void {
        $srcList = [];
        $tmpList = $this->listLocal($srcPath, $dir);
        $keys    = array_rand($tmpList, min(count($tmpList), $num));
        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            $srcList[$key] = $tmpList[$key];
        }
        $dstList = $this->listRemote($dstPath, $dir, false);
        $this->println(sprintf('[RAND] %s files', number_format(count($srcList))));
        $this->syncArchive($dstPath, $dir, $srcList, $dstList);
    }

    private function syncDirXRandMB(
        string $srcPath,
        string $dstPath,
        string $dir,
        int $mb,
    ): void {
        $sum     = 0;
        $th      = $mb * 1024 * 1024;
        $srcList = [];
        $tmpList = $this->listLocal($srcPath, $dir);
        $tmpKeys = array_keys($tmpList);
        shuffle($tmpKeys);
        foreach ($tmpKeys as $key) {
            $data   = $tmpList[$key];
            $file   = $this->checkXRandMode($data);
            [$path] = $file;
            $size   = $this->getSizeInArchive($path);
            if (($sum + $size) <= $th) {
                $srcList[$key] = $data;
                $sum += $size;
            }
        }
        $dstList = $this->listRemote($dstPath, $dir, false);
        $this->println(sprintf('[RAND] %s bytes', number_format($sum)));
        $this->syncArchive($dstPath, $dir, $srcList, $dstList);
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

    private function md5Local(string $path): string
    {
        return md5(file_get_contents($path));
    }

    private function md5Remote(string $path): string
    {
        return implode($this->exec([
            'md5sum',
            '-b',
            $path,
        ]));
    }
}
