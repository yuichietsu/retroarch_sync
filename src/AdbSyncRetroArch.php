<?php

namespace Menrui;

class AdbSyncRetroArch extends AdbSync
{
    private const IDX_PATH = 0;
    private const IDX_FILE = 1;
    private const IDX_HASH = 2;
    private const IDX_DATE = 2;

    public array $archivers = [
        'zip' => [
            'c' => 'zip -9',
            'x' => 'unzip',
            'l' => 'unzip -l',
            'sizeParser' => '/(?<size>\\d+)\\s+(?<num>\\d+)\\s+files?/',
        ],
        '7z'  => [
            'x' => '7z e',
            'l' => '7z l',
            'sizeParser' => '/(?<size>\\d+)\\s+\\d+\\s+(?<num>\\d+)\\s+files?/',
        ],
    ];

    private string $tmpPath;

    public int $lockDays = 14;
    public ?array $statesPaths = null;
    private ?array $lockedStates = null;

    public function __construct(
        protected string $remote,
    ) {
        $this->tmpPath = sys_get_temp_dir() . '/___adb_sync/';
        parent::__construct($remote);
    }

    private function rmLocal(string $path): void
    {
        if (str_starts_with($path, $this->tmpPath)) {
            file_exists($path) && exec(sprintf('%s -rf %s', $this->commands['rm'], escapeshellarg($path)));
        } else {
            $this->errorln('ERROR: Local files must be within the tmp directory to be removed.');
            $this->errorln($path);
            $this->errorln("tmp : {$this->tmpPath}");
            exit(1);
        }
    }

    private function parseOptions(string $optionString): array
    {
        $options = [];
        if (preg_match('/^(full|rand|filter)(:.*)?$/', $optionString, $m)) {
            $options['mode'] = $m[1];
            foreach (explode(',', trim($m[2] ?? '', ':')) as $input) {
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
                } elseif (preg_match('/^lock(\\(.*\\))?$/', $i, $im)) {
                    $options['lock'] = strtolower(trim($im[1] ?? '*', '()'));
                } else {
                    $options['etc'][] = trim($i, '"');
                }
            }
        }
        return $options;
    }

    public function syncGames(
        array $targets,
        ?string $srcPath = null,
        ?string $dstPath = null,
    ): void {
        $this->checkPathSettings($srcPath, $dstPath);
        $dirs = scandir($this->srcPath);
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
                $mode = $options['mode'] === 'full' ? self::LIST_HASH : self::LIST_NONE;
                $this->mkdirRemote($this->dstPath . "/$dir");
                $srcList = $this->listLocal($this->srcPath . "/$dir", $mode);
                $srcList = $this->filterSrcList($srcList, $options);
                $dstList = $this->listRemote($this->dstPath . "/$dir", $mode);
                $this->syncCore($dir, $srcList, $dstList, $options);
            } else {
                $this->verbose && $this->println("[SKIP] $dir");
            }
        }
    }

    private function syncFiles(string $topDir, array $sData): void
    {
        foreach ($sData as $data) {
            [$src, $file] = $data;
            $dst = $this->dstPath . "/$topDir/$file";
            $dstDir = dirname($dst);
            if ($dstDir !== $this->dstPath . "/$topDir") {
                $this->mkdirRemote($dstDir);
            }
            $this->push($src, $dst);
            $this->println("[PUSH] $file");
        }
    }

    private function extractArchive(string $file): array
    {
        do {
            $rand = md5(mt_rand());
            $dir = $this->tmpPath . $rand;
        } while (file_exists($dir));
        mkdir($dir, 0777, true);
        register_shutdown_function($this->rmLocal(...), $dir);

        $arcInfo = $this->getArchiveInfo($file);
        $exe     = $arcInfo['x'];
        $cmd     = sprintf('cd %s; %s %s', escapeshellarg($dir), $exe, escapeshellarg($file));
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
        $arcInfo = $this->getArchiveInfo($file);
        $exe     = $arcInfo['l'];
        $cmd     = sprintf('%s %s', $exe, escapeshellarg($file));
        $last    = exec($cmd, $lines, $ret);
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

    private function sync7zToZip(string $topDir, array $sData, string $dirName): void
    {
        [$fileInfo]    = $sData;
        [$src]         = $fileInfo;
        [$dir, $files] = $this->extractArchive($src);
        $hash          = $this->getFileHash($fileInfo);
        $hashFile      = "hash_$hash";
        touch("$dir/$hashFile");

        $exe = $this->archivers['zip']['c'];
        $cmd = sprintf(
            "cd %s; $exe %s.zip %s",
            escapeshellarg($dir),
            escapeshellarg($dirName),
            implode(' ', array_map('escapeshellarg', $files))
        );
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            $this->errorln('ERROR: Failed to create a zip file.');
            $this->errorln($cmd);
            $this->errorln(implode("\n", $lines));
            exit(1);
        }

        $files = [
            "$dirName.zip",
            $hashFile,
        ];
        foreach ($files as $file) {
            $dst = $this->dstPath . "/$topDir/$dirName/$file";
            $dstDir = dirname($dst);
            if ($dstDir !== $this->dstPath . "/$topDir") {
                $this->mkdirRemote($dstDir);
            }
            $this->push("$dir/$file", $dst);
            $this->println("[PUSH] $file");
        }
        $this->rmLocal($dir);
    }

    private function syncArchiveFiles(string $topDir, array $sData, string $dirName): void
    {
        [$sFileInfo]         = $sData;
        $sPath = $sFileInfo[self::IDX_PATH];
        $sHash = $this->getFileHash($sFileInfo);

        [$dir, $files] = $this->extractArchive($sPath);
        $hashFile = "hash_$sHash";
        touch("$dir/$hashFile");
        $files[] = $hashFile;
        foreach ($files as $file) {
            $dst = $this->dstPath . "/$topDir/$dirName/$file";
            $dstDir = dirname($dst);
            if ($dstDir !== $this->dstPath . "/$topDir") {
                $this->mkdirRemote($dstDir);
            }
            $this->push("$dir/$file", $dst);
            $this->println("[PUSH] $file");
        }
        $this->rmLocal($dir);
    }

    private function getFileHash(array $fileInfo): string
    {
        return $fileInfo[self::IDX_HASH] ?? $this->md5($fileInfo[self::IDX_PATH]);
    }

    private function makeHashMap(array $data): array
    {
        $map = [];
        foreach ($data as $item) {
            $hash = $this->getFileHash($item);
            $map[$item[self::IDX_FILE]] = $hash;
        }
        return $map;
    }

    private function compareDir(array $sData, array $dData): bool
    {
        $same  = count($sData) === count($dData);
        if ($same) {
            $sHash = $this->makeHashMap($sData);
            $dHash = $this->makeHashMap($dData);
            foreach ($sHash as $k => $v) {
                if ($v !== ($dHash[$k] ?? null)) {
                    $same = false;
                    break;
                }
            }
        }
        return $same;
    }

    private function compareHashFile(array $sData, array $dData): bool
    {
        [$sFileInfo] = $sData;
        $sHash       = $this->getFileHash($sFileInfo);

        foreach ($dData as $dFileInfo) {
            $dHashFile = basename($dFileInfo[self::IDX_FILE]);
            if ($dHashFile === "hash_$sHash") {
                return true;
            }
        }
        return false;
    }

    private function syncCore(
        string $topDir,
        array $srcList,
        array $dstList,
        array $options
    ): void {
        $c = ['n' => 0, 'u' => 0, 'd' => 0, 's' => 0];
        foreach ($srcList as $sKey => $sData) {
            if (($options['zip'] ?? false) && $this->isFileType($sData, '7z')) {
                $dKey = $this->trimArchiveExtension($sKey);
                $comp = $this->compareHashFile(...);
                $sync = $this->sync7zToZip(...);
            } elseif (($options['ext'] ?? false) && $this->isExtractable($sData)) {
                $dKey = $this->trimArchiveExtension($sKey);
                $comp = $this->compareHashFile(...);
                $sync = $this->syncArchiveFiles(...);
            } else {
                $dKey = $sKey;
                $comp = $this->compareDir(...);
                $sync = $this->syncFiles(...);
            }

            if (array_key_exists($dKey, $dstList)) {
                $dData = $dstList[$dKey];
                if ($comp($sData, $dData)) {
                    $this->verbose && $this->println("[SAME] $sKey => $dKey");
                    $c['s']++;
                } else {
                    $this->println("[UP] $sKey => $dKey");
                    $this->rmRemote($this->dstPath . "/$topDir/$dKey");
                    $sync($topDir, $sData, $dKey);
                    $c['u']++;
                }
            } else {
                $this->println("[NEW] $sKey => $dKey");
                $sync($topDir, $sData, $dKey);
                $c['n']++;
            }
            unset($dstList[$dKey]);
        }
        foreach (array_keys($dstList) as $key) {
            $locks = $this->getLocks($options);
            if (array_key_exists($this->trimArchiveExtension($key), $locks)) {
                $this->println("[LOCKED] $key");
            } else {
                $this->println("[DEL] $key");
                $this->rmRemote($this->dstPath . "/$topDir/$key");
                $c['d']++;
            }
        }
        $this->println(sprintf('NEW:%d, UP:%d, SAME:%d, DEL:%s', $c['n'], $c['u'], $c['s'], $c['d']));
    }

    private function getLocks(array $options): array
    {
        $statesDir = $options['lock'] ?? false;
        if (!$this->statesPaths || !$statesDir) {
            return [];
        }
        if ($this->lockedStates === null) {
            $this->lockedStates = $this->loadLockedStates();
        }
        return $this->lockedStates[$statesDir] ?? [];
    }

    private function loadLockedStates(): array
    {
        $th    = time() - $this->lockDays * 3600 * 24;
        $locks = [];
        foreach ($this->statesPaths as $sPath) {
            $list = $this->listRemote($sPath, self::LIST_DATE);
            foreach ($list as $files) {
                foreach ($files as $file) {
                    $date = $file[self::IDX_DATE];
                    if ($date < $th) {
                        $this->debug && $this->println("[DEBUG] {$file[self::IDX_FILE]} is too old, $date < $th");
                        continue;
                    }
                    $dirs = explode('/', $file[self::IDX_FILE]);
                    $state = array_pop($dirs);
                    if (preg_match('/^(.+)\\.state(\\d*|\\.auto)$/', $state, $m)) {
                        $game = $m[1];
                        $dir  = strtolower(implode('/', $dirs));

                        $locks['*'][$game]  = true;
                        $locks[$dir][$game] = true;
                    }
                }
            }
        }
        return $locks;
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

        if ($options['mode'] === 'filter') {
            $newList = [];
            $filters = array_map(fn ($n) => strtolower($n), $options['etc'] ?? []);
            foreach ($srcList as $key => $data) {
                $lcKey = strtolower($key);
                foreach ($filters as $filter) {
                    if (str_contains($lcKey, $filter)) {
                        $newList[$key] = $data;
                        break;
                    }
                }
            }
            $this->println(sprintf('[FILTER] %s files', number_format(count($newList))));
            return $newList;
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

        $this->errorln('ERROR: rand mode must have number or size option');
        exit(1);
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
        $extensions = $this->getSupportedArchiveRE();
        if (preg_match("/\\.($extensions)$/i", $file, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function trimArchiveExtension(string $fileName): string
    {
        $extensions = $this->getSupportedArchiveRE();
        return preg_replace("/\\.($extensions)$/i", '', $fileName);
    }

    private function getSupportedArchiveRE(): string
    {
        return implode('|', array_map(
            fn ($n) => preg_quote($n, '/'),
            array_keys($this->archivers)
        ));
    }

    private function getArchiveInfo(string $file): array
    {
        $extension = $this->getSupportedArchiveExtension($file);
        return $this->archivers[$extension];
    }

    protected function listCore(string $base, array $lines, int $mode): array
    {
        $list = [];
        !str_ends_with($base, '/') && ($base .= '/');
        foreach ($lines as $line) {
            switch ($mode) {
                case self::LIST_HASH:
                    $m = [];
                    preg_match('/^([0-9a-f]{32})\\s+(.+)/', $line, $m);
                    $hash = $m[1];
                    $path = $m[2];
                    $file = str_replace($base, '', $path);
                    $key  = preg_replace('#/.+$#u', '', $file);
                    $list[$key][] = [$path, $file, $hash];
                    break;
                case self::LIST_DATE:
                    $m = [];
                    preg_match('/^(\\d+)\\s+(.+)/', $line, $m);
                    $date = $m[1];
                    $path = $m[2];
                    $file = str_replace($base, '', $path);
                    $key  = preg_replace('#/.+$#u', '', $file);
                    $list[$key][] = [$path, $file, $date];
                    break;
                case self::LIST_NONE:
                    $path = $line;
                    $file = str_replace($base, '', $path);
                    $key  = preg_replace('#/.+$#u', '', $file);
                    $list[$key][] = [$path, $file];
                    break;
            }
        }
        return $list;
    }
}
