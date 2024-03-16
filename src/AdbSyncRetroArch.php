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
            'c' => 'zip -9 %TO% %FROM%',
            'x' => 'unzip',
            'l' => 'unzip -l',
            'sizeParser' => '/(?<size>\\d+)\\s+(?<num>\\d+)\\s+files?/',
        ],
        '7z'  => [
            'x' => '7z e',
            'l' => '7z l',
            'sizeParser' => '/(?<size>\\d+)\\s+\\d+\\s+(?<num>\\d+)\\s+files?/',
        ],
        'cso' => [
            'c' => 'ciso 9 %FROM% %TO% 2>&1',
            'inputFilter' => '/\\.iso$/i',
        ],
        'chd' => [
            'c' => 'chdman createcd -i %FROM% -o %TO% 2>&1',
            'inputFilter' => '/\\.(gdi|cue|iso)$/i',
            'converter' => [
                '/\\.ccd$/' => [\Menrui\CCD2CUE::class, 'convert'],
            ],
        ],
    ];

    private string $tmpPath;

    public int $lockDays = 14;
    public ?array $statesPaths = null;
    private ?array $lockedStates = null;
    public ?array $favoritesPaths = null;
    private ?array $lockedFavorites = null;

    public string $diskFilter = '/^(.+?)\\(Dis[kc] *(\\d+|[A-Z])( of \\d+)?\\)/';

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
            throw new Exception(implode("\n", [
                'Local files must be within the tmp directory to be removed.',
                $path,
                "tmp : {$this->tmpPath}",
            ]));
        }
    }

    private function parseOptions(string $optionString): array
    {
        $options = [];
        if (preg_match('/^(full|rand)(:.*)?$/', $optionString, $m)) {
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
                } elseif (preg_match('/^lock(\\(.*\\))?$/', $i, $im)) {
                    $options['lock'] = strtolower(trim($im[1] ?? '*', '()'));
                } elseif (preg_match('/^incl(\\(.*\\))$/', $i, $im)) {
                    $options['incl'] = explode('|', trim($im[1], '()'));
                } elseif (preg_match('/^excl(\\(.*\\))$/', $i, $im)) {
                    $options['excl'] = explode('|', trim($im[1], '()'));
                } elseif (preg_match('/^merge(\\(.*\\))?$/', $i, $im)) {
                    $options['merge'] = strtolower(trim($im[1] ?? 'max', '()'));
                } elseif (preg_match('/^rename(\\(.*\\))$/', $i, $im)) {
                    $options['rename'] = trim($im[1], '()/');
                } else {
                    $options[$i] = true;
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
                $this->log("[SCAN] $dir");
                $settings = $targets[$dir];
                $options  = $this->parseOptions($settings);
                if (!($options['mode'] ?? false)) {
                    $this->log("[SKIP] invalid settings : $settings");
                    continue;
                }
                $dstDir  = $options['rename'] ?? $dir;
                $mode    = $options['mode'] === 'full' ? self::LIST_HASH : self::LIST_NONE;
                $srcList = $this->listLocal($this->srcPath . "/$dir", $mode);
                $srcList = $this->filterSrcList($srcList, $options);
                if ($options['index'] ?? false) {
                    $az = [];
                    foreach ($srcList as $k => $v) {
                        $i = preg_match('/^([a-zA-Z])/', $k, $m) ? strtoupper($m[1]) : '_';
                        $az[$i][$k] = $v;
                    }
                    foreach ($az as $i => $srcList) {
                        $this->log("[INDEX] $i");
                        $this->syncDir("$dstDir/$i", $srcList, $options, $mode);
                    }
                    $this->log("[CLEAN]");
                    $children = $this->listChildrenRemote($this->dstPath . "/$dstDir");
                    foreach ($children as $k => $v) {
                        if (!array_key_exists($k, $az)) {
                            [$vv] = $v;
                            $path = $vv[self::IDX_PATH];
                            $this->log("[DEL] $k");
                            $this->rmRemote($path);
                        }
                    }
                } else {
                    $this->syncDir($dstDir, $srcList, $options, $mode);
                }
            } else {
                $this->verbose && $this->log("[SKIP] $dir");
            }
        }
    }

    private function syncDir(string $dir, array $srcList, array $options, int $mode): void
    {
        $this->mkdirRemote($this->dstPath . "/$dir");
        $dstList = $this->listRemote($this->dstPath . "/$dir", $mode);
        $this->syncCore($dir, $srcList, $dstList, $options);
        $this->makeM3U($dir, $options);
    }

    private function sendM3U(string $topDir, array $m3u): void
    {
        if (count($m3u) === 0) {
            return;
        }
        $tmp = $this->makeTmpDir();
        foreach ($m3u as $key => $files) {
            sort($files);
            $m3uFile = "$key.m3u";
            file_put_contents("$tmp/$m3uFile", implode("\n", $files));
            $dst = $this->dstPath . "/$topDir/$m3uFile";
            $this->push("$tmp/$m3uFile", $dst);
            $this->log("[PUSH] $m3uFile");
        }
        $this->rmLocal($tmp);
    }

    private function makeM3U(string $topDir, array $options): void
    {
        if ($options['disks'] ?? false) {
            $m3u  = [];
            $list = $this->listRemote($this->dstPath . "/$topDir", self::LIST_NONE);
            foreach ($list as $key => $files) {
                if (preg_match($this->diskFilter, $key, $md)) {
                    $disksKey = $md[1];
                    foreach ($files as $file) {
                        $rFile = $file[self::IDX_FILE];
                        if (preg_match('/\\.(cso|chd|7z|zip)$/i', $rFile)) {
                            $m3u[$disksKey][] = $rFile;
                            break;
                        }
                    }
                }
            }
            $this->sendM3U($topDir, $m3u);
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
            $this->log("[PUSH] $file");
        }
    }

    private function makeTmpDir(): string
    {
        do {
            $rand = md5(mt_rand());
            $dir = $this->tmpPath . $rand;
        } while (file_exists($dir));
        mkdir($dir, 0777, true);
        register_shutdown_function($this->rmLocal(...), $dir);
        return $dir;
    }

    private function extractArchive(string $file, string $to = null): array
    {
        $tmp     = $this->makeTmpDir();
        $arcInfo = $this->getArchiveInfo($file);
        $exe     = $arcInfo['x'];
        $cmd     = sprintf('cd %s; %s %s', escapeshellarg($tmp), $exe, escapeshellarg($file));
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            throw new Exception(implode("\n", [$cmd, ...$lines]));
        }
        $files = glob("$tmp/*");
        if ($to && ($converter = $this->archivers[$to]['converter'] ?? null)) {
            foreach ($converter as $k => $v) {
                foreach ($files as $file) {
                    if (preg_match($k, $file)) {
                        $files[] = call_user_func($v, $file);
                    }
                }
            }
        }
        return [$tmp, array_map(fn ($n) => str_replace("$tmp/", '', $n), $files)];
    }

    private function getSizeInArchive(string $file): int
    {
        $arcInfo = $this->getArchiveInfo($file);
        $exe     = $arcInfo['l'];
        $cmd     = sprintf('%s %s', $exe, escapeshellarg($file));
        $last    = exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            throw new Exception(implode("\n", [$cmd, ...$lines]));
        }
        preg_match($arcInfo['sizeParser'], $last, $info);
        if (!($info['size'] ?? false)) {
            throw new Exception(implode("\n", ['cannot retrieve uncompressed file size.', $cmd, ...$lines]));
        }
        return $info['size'];
    }

    private function sync7zToZip(string $topDir, array $sData, string $dirName): void
    {
        $this->syncArcToArc($topDir, $sData, $dirName, 'zip');
    }

    private function syncArcToCso(string $topDir, array $sData, string $dirName): void
    {
        $this->syncArcToArc($topDir, $sData, $dirName, 'cso');
    }

    private function syncArcToChd(string $topDir, array $sData, string $dirName): void
    {
        $this->syncArcToArc($topDir, $sData, $dirName, 'chd');
    }

    private function syncArcToArc(string $topDir, array $sData, string $dirName, string $to): void
    {
        [$fileInfo]    = $sData;
        [$src]         = $fileInfo;
        [$dir, $files] = $this->extractArchive($src, $to);
        $hash          = $this->getFileHash($fileInfo);
        $hashFile      = "hash_$hash";
        touch("$dir/$hashFile");

        $archiver = $this->archivers[$to];
        $exe = $archiver['c'];
        if ($iFilter = $archiver['inputFilter'] ?? null) {
            $newFiles = [];
            foreach ($files as $file) {
                if (preg_match($iFilter, $file)) {
                    $newFiles[] = $file;
                    break;
                }
            }
            $files = $newFiles ?: $files;
        }
        $cmd = str_replace(
            ['%TO%', '%FROM%'],
            [escapeshellarg($dirName) . ".$to", implode(' ', array_map('escapeshellarg', $files))],
            sprintf("cd %s; %s", escapeshellarg($dir), $exe)
        );
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            throw new Exception(implode("\n", [
                'ERROR: Failed to create a zip file.',
                $cmd,
                ...$lines,
            ]));
        }

        $files = [
            "$dirName.$to",
            $hashFile,
        ];
        foreach ($files as $file) {
            $dst = $this->dstPath . "/$topDir/$dirName/$file";
            $dstDir = dirname($dst);
            if ($dstDir !== $this->dstPath . "/$topDir") {
                $this->mkdirRemote($dstDir);
            }
            $this->push("$dir/$file", $dst);
            $this->log("[PUSH] $file");
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
            $this->log("[PUSH] $file");
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
        $cso = $options['cso'] ?? false;
        $chd = $options['chd'] ?? false;
        $zip = $options['zip'] ?? false;
        $ext = $options['ext'] ?? false;
        $c = ['n' => 0, 'u' => 0, 'd' => 0, 's' => 0];
        foreach ($srcList as $sKey => $sData) {
            if ($zip && $this->isFileType($sData, '7z')) {
                $dKey = $this->trimArchiveExtension($sKey);
                $comp = $this->compareHashFile(...);
                $sync = $this->sync7zToZip(...);
            } elseif ($cso && $this->isFileType($sData, ['7z', 'zip'])) {
                $dKey = $this->trimArchiveExtension($sKey);
                $comp = $this->compareHashFile(...);
                $sync = $this->syncArcToCso(...);
            } elseif ($chd && $this->isFileType($sData, ['7z', 'zip'])) {
                $dKey = $this->trimArchiveExtension($sKey);
                $comp = $this->compareHashFile(...);
                $sync = $this->syncArcToChd(...);
            } elseif ($ext && $this->isExtractable($sData)) {
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
                    $this->verbose && $this->log("[SAME] $sKey => $dKey");
                    $c['s']++;
                } else {
                    $this->log("[UP] $sKey => $dKey");
                    $this->rmRemote($this->dstPath . "/$topDir/$dKey");
                    $sync($topDir, $sData, $dKey);
                    $c['u']++;
                }
            } else {
                $this->log("[NEW] $sKey => $dKey");
                $sync($topDir, $sData, $dKey);
                $c['n']++;
            }
            unset($dstList[$dKey]);
        }
        foreach (array_keys($dstList) as $key) {
            $this->log("[DEL] $key");
            $this->rmRemote($this->dstPath . "/$topDir/$key");
            $c['d']++;
        }
        $this->log(sprintf('NEW:%d, UP:%d, SAME:%d, DEL:%s', $c['n'], $c['u'], $c['s'], $c['d']));
    }

    private function getLocks(array $options): array
    {
        $dir = $options['lock'] ?? false;
        if (!$dir) {
            return [];
        }
        if ($this->statesPaths && $this->lockedStates === null) {
            $this->lockedStates = $this->loadLockedStates();
        }
        if ($this->favoritesPaths && $this->lockedFavorites === null) {
            $this->lockedFavorites = $this->loadLockedFavorites();
        }
        return array_merge(
            $this->lockedStates[$dir] ?? [],
            $this->lockedFavorites[$dir] ?? [],
        );
    }

    private function loadLockedFavorites(): array
    {
        $locks = [];
        $pattern = preg_quote(rtrim($this->dstPath, '/'), '%');
        foreach ($this->favoritesPaths as $fPath) {
            $data = $this->catRemote($fPath);
            if ($json = @json_decode($data, true)) {
                foreach ($json['items'] as $item) {
                    if (preg_match("%^{$pattern}/([^/]+)/(.+)$%", $item['path'], $m)) {
                        $dir  = $m[1];
                        $game = preg_replace('%[\\./].+$%', '', $m[2]);
                        $locks['*'][$game]  = true;
                        $locks[$dir][$game] = true;
                    }
                }
            }
        }
        return $locks;
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
                        $this->debug && $this->log("[DEBUG] {$file[self::IDX_FILE]} is too old, $date < $th");
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

    private function isFileType(array $data, string|array $ext): ?array
    {
        $file = $this->isSingleFile($data);
        if (!$file) {
            return false;
        }
        [$path] = $file;
        $escExt = implode('|', array_map(
            fn ($n) => preg_quote($n, '/'),
            is_string($ext) ? [$ext] : $ext
        ));
        return preg_match("/\\.({$escExt})\$/", $path) ? $file : null;
    }

    private function isSingleFile(array $data): ?array
    {
        return count($data) === 1 ? $data[0] : null;
    }

    private function filterExclude(array $list, array $options): array
    {
        if ($excl = ($options['excl'] ?? false)) {
            $newList = [];
            foreach ($list as $k => $v) {
                $excluded = false;
                foreach ($excl as $ex) {
                    if (str_contains($k, $ex)) {
                        $this->debug && $this->log("[EXCLUDE] $k");
                        $excluded = true;
                        break;
                    }
                }
                if (!$excluded) {
                    $newList[$k] = $v;
                }
            }
            return $newList;
        } else {
            return $list;
        }
    }

    private function mergeVariants(array $list, array $options): array
    {
        if ($cond = ($options['merge'] ?? false)) {
            $newList = [];
            $keyList = [];
            foreach (array_keys($list) as $k) {
                $key = preg_replace('/\\s*\\[.*?\\]\\s*/', '', $k);
                $keyList[$key][] = $k;
            }
            foreach ($keyList as $keys) {
                if (count($keys) > 1) {
                    usort($keys, function ($a, $b) {
                        $d = strlen($a) - strlen($b);
                        return $d === 0 ? strcmp($a, $b) : $d;
                    });
                }
                $k = match ($cond) {
                    'min'   => array_shift($keys),
                    default => array_pop($keys),
                };
                $newList[$k] = $list[$k];
                if (0 < count($keys)) {
                    $this->log("[MERGED] $k");
                    foreach ($keys as $k) {
                        $this->log("         $k");
                    }
                }
            }
            return $newList;
        } else {
            return $list;
        }
    }

    private function filterInclude(array $list, array $options): array
    {
        $newList = [];
        $locks   = $this->getLocks($options);
        $incl    = $options['incl'] ?? [];
        if ($locks || $incl) {
            foreach ($list as $k => $v) {
                foreach ($incl as $in) {
                    if (str_contains($k, $in)) {
                        $this->debug && $this->log("[INCLUDE] $k");
                        $newList[$k] = $v;
                        break;
                    }
                }
                if (array_key_exists($this->trimArchiveExtension($k), $locks)) {
                    $newList[$k] = $v;
                    $this->log("[LOCKED] $k");
                }
            }
        }
        return $newList;
    }

    private function filterSrcList(array $srcList, array $options): array
    {
        $srcList = $this->filterExclude($srcList, $options);
        $srcList = $this->mergeVariants($srcList, $options);

        if ($options['mode'] === 'full') {
            return $srcList;
        }

        if ($num = ($options['num'] ?? false)) {
            $newList = $this->filterInclude($srcList, $options);
            $leftList = array_diff_key($srcList, $newList);
            $randNum = min(count($leftList), $num - count($newList));
            if (0 < $randNum) {
                $keys = array_rand($leftList, $randNum);
                foreach (is_array($keys) ? $keys : [$keys] as $key) {
                    $newList[$key] = $leftList[$key];
                }
            }
            $this->log(sprintf('[RAND] %s files', number_format(count($newList))));
            return $newList;
        }

        if ($th = ($options['size'] ?? false)) {
            $extract = $options['ext'] ?? false;
            $sum     = 0;
            $newList = [];
            $disks   = ($options['disks'] ?? false) ? [] : null;

            $filter = function ($keys, $th) use ($srcList, &$newList, &$sum, $extract, &$disks) {
                foreach ($keys as $key) {
                    if (array_key_exists($key, $newList)) {
                        continue;
                    }
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
                    $force = $disksKey = false;
                    if ($disks !== null && preg_match($this->diskFilter, $key, $md)) {
                        $disksKey = $md[1];
                        $force    = array_key_exists($disksKey, $disks);
                    }
                    if ($force || ($sum + $size) <= $th) {
                        $newList[$key] = $data;
                        $sum += $size;
                        if ($disksKey) {
                            $disks[$disksKey][] = $key;
                        }
                    }
                }
            };

            $tmpKeys = array_keys($this->filterInclude($srcList, $options));
            $filter($tmpKeys, PHP_INT_MAX);

            if ($sum <= $th) {
                $tmpKeys = array_keys($srcList);
                shuffle($tmpKeys);
                $filter($tmpKeys, $th);
            }

            $this->log(sprintf('[RAND] %s bytes', number_format($sum)));
            return $newList;
        }

        throw new Exception('rand mode must have number or size option');
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
