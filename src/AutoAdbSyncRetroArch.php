<?php

namespace Menrui;

class AutoAdbSyncRetroArch extends AdbSyncRetroArch
{
    protected array $extMap = [
        'nes' => 'NES',
        'smc' => 'SNES',
        'sfc' => 'SNES',
        'gb'  => 'GB',
        'gbc' => 'GBC',
    ];

    protected array $config = [
        'size' => 1024 * 1024 * 100, // 100MB
        'machines' => [
            'NES' => [
                'archiveType' => '7z',
            ],
            'SNES' => [
                'archiveType' => '7z',
            ],
            'GB' => [
                'archiveType' => '7z',
            ],
            'GBC' => [
                'archiveType' => '7z',
            ],
        ],
    ];

    public function __construct(
        protected string $remote,
    ) {
        $listFiles = ['7z l -slt', function (
            string $archivePath,
            string $archiveType,
            array $lines,
        ): array {
            $files     = [];
            $file      = null;
            $delimiter = '----------';
            foreach ($lines as $line) {
                $line = trim($line);
                if ($delimiter === $line) {
                    $file !== null && ($files[] = $file);
                    $file = [
                        'archivePath' => $archivePath,
                        'archiveType' => $archiveType,
                        'archiveSize' => $this->fileSize($archivePath),
                    ];
                } elseif ($file !== null) {
                    $kv = explode(' = ', $line);
                    if (count($kv) === 2) {
                        [$k, $v] = $kv;
                        switch ($k) {
                            case 'Path':
                                $key = 'path';
                                $val = $v;
                                break;
                            case 'Size':
                                $key = 'size';
                                $val = $v;
                                break;
                            case 'CRC':
                                $key = 'crc';
                                $val = hexdec($v);
                                break;
                            default:
                                $key = null;
                                $val = null;
                                break;
                        };
                        $key !== null && ($file[$key] = $val);
                    }
                }
            }
            $file !== null && ($files[] = $file);
            return $files;
        }];

        $this->archivers['7z']['listFiles']  = $listFiles;
        $this->archivers['zip']['listFiles'] = $listFiles;
        parent::__construct($remote);
    }

    public function syncGames(
        array $config,
        ?string $srcPath = null,
        ?string $dstPath = null,
    ): void {

        $this->checkConfig($config);
        $this->checkPathSettings($srcPath, $dstPath);
        $data = $this->listLocal($this->srcPath, self::LIST_NONE);

        $machineNum = count($data);
        if ($machineNum) {
            $sizePerMachine = $this->config['size'] / $machineNum;
            printf("Allocated size per machine: %s\n", number_format($sizePerMachine));
            foreach ($data as $machine => $images) {
                $this->log("[MACHINE] $machine");
                $this->syncDir($machine, $images, ['delete' => $this->deleteGames(...)], self::LIST_NONE);
            }
        }
    }

    protected function deleteGames(array $list): int
    {
        $count = 0;
        foreach ($list as $key => $files) {
            $this->log("[DEL] hash = $key");
            foreach ($files as $file) {
                $this->log("[DEL] {$file['path']}");
                $this->rmRemote($file['path']);
                $count++;
            }
        }
        return $count;
    }

    protected function checkConfig(array $config): void
    {
        $this->config = [...$this->config, ...$config];
    }

    protected function listCore(string $base, array $lines, int $mode): array
    {
        if (str_starts_with($base, $this->srcPath)) {
            return $this->listCoreLocal($base, $lines, $mode);
        }

        if (str_starts_with($base, $this->dstPath)) {
            return $this->listCoreRemote($base, $lines, $mode);
        }

        throw new Exception(implode("\n", [
            'list path is not within srcPath or dstPath.',
            $base,
            "srcPath : {$this->srcPath}",
            "dstPath : {$this->dstPath}",
        ]));
    }

    protected function listCoreRemote(string $base, array $lines): array
    {
        !str_ends_with($base, '/') && ($base .= '/');

        $i = 0;
        $n = count($lines);
        $usingCrc = [];
        $files    = [];
        foreach ($lines as $file) {
            $crc = 0;
            if (str_contains($file, '.crc32_')) {
                $crc = 'crc';
            } else {
                $crcInfo = $this->findFileCrc32($file, $lines);
                if ($crcInfo) {
                    $usingCrc[] = $crcInfo[0];
                    $crc        = (int)$crcInfo[1];
                }
            }
            $files[$crc][] = [
                'path' => $file,
                'file' => str_replace($base, '', $file),
                'crc'  => $crc,
            ];
            if (++$i % 100 === 0) {
                printf("[%d/%d]\n", $i, $n);
                flush();
            }
        }
        if ($files['crc'] ?? false) {
            $files['crc'] = array_filter($files['crc'], function ($i) use ($usingCrc) {
                return !in_array($i['path'], $usingCrc);
            });
        }
        return $files;
    }

    protected function listCoreLocal(string $base, array $lines): array
    {
        !str_ends_with($base, '/') && ($base .= '/');

        $i = 0;
        $n = count($lines);
        $files = [];
        foreach ($lines as $file) {
            $images = $this->listInArchive($file);
            if ($images === null) {
                $images = [[
                    'archivePath' => $file,
                    'archiveType' => 'raw',
                    'path'        => $file,
                    'crc'         => $this->fileCrc32($file),
                    'size'        => $this->fileSize($file),
                ]];
            }
            foreach ($images as $image) {
                $path                 = $image['path'];
                $image['archiveFile'] = str_replace($base, '', $image['archivePath']);
                $lcPath               = strtolower($path);
                foreach ($this->extMap as $ext => $machine) {
                    if (str_ends_with($lcPath, ".$ext")) {
                        $files[$machine][$image['crc']][] = $image;
                    }
                }
            }
            if (++$i % 100 === 0) {
                printf("[%d/%d]\n", $i, $n);
                foreach ($files as $machine => $images) {
                    printf("%s : %d\n", $machine, count($images));
                }
                flush();
            }
        }
        return $files;
    }

    protected function listInArchive(string $file): ?array
    {
        $list = null;
        $ext  = $this->getSupportedArchiveExtension($file);
        if ($ext && ($arc = $this->archivers[$ext])) {
            if ([$exe, $parser] = $arc['listFiles'] ?? null) {
                $cmd = sprintf('%s %s', $exe, escapeshellarg($file));
                exec($cmd, $lines, $ret);
                $files = $parser($file, $ext, $lines);
                count($files) === 1 && ($files[0]['only'] = true);
                $list  = $list === null ? $files : array_merge($list, $files);
            }
        }
        return $list;
    }

    protected function fileCrc32(string $path): int
    {
        if (str_starts_with($path, $this->srcPath)) {
            return $this->fileCrc32Local($path);
        }

        if (str_starts_with($path, $this->dstPath)) {
            return $this->fileCrc32Remote($path);
        }

        throw new Exception(implode("\n", [
            'File for CRC32 is not within srcPath or dstPath.',
            $path,
            "srcPath : {$this->srcPath}",
            "dstPath : {$this->dstPath}",
        ]));
    }

    protected function findFileCrc32(string $path, array $paths = null): ?array
    {
        $dir      = dirname($path);
        $baseName = basename($path);
        $pattern  = preg_quote($dir . '/.crc32_' . $baseName . '_', '#');
        foreach ($paths as $p) {
            if (preg_match("%^{$pattern}(\\d+)$%", $p, $m)) {
                return $m;
            }
        }
        return null;
    }

    protected function fileCrc32Remote(string $path): int
    {
        $dir      = dirname($path);
        $baseName = basename($path);
        $pattern  = escapeshellarg($dir) . '/.crc32_' . escapeshellarg($baseName) . '_*';
        $result   = implode($this->execRemote(['ls', $pattern, '||', 'true']));
        $escName  = preg_quote($baseName);
        return preg_match("/\\.crc32_{$escName}.(\\d+)$/", $result, $m) ? (int)$m[1] : 0;
    }

    protected function fileCrc32Local(string $path): int
    {
        $crc32 = 0;
        exec('which cksum 2>&1 >/dev/null', $lines, $ret);
        if ($ret === 0) {
            $line  = exec(sprintf('cksum %s', escapeshellarg($path)));
            $data  = explode(' ', $line);
            $crc32 = $data[0];
        }
        return $crc32;
    }

    protected function fileSize(string $path): int
    {
        if (str_starts_with($path, $this->srcPath)) {
            return filesize($path);
        }

        if (str_starts_with($path, $this->dstPath)) {
            $result = implode($this->execRemote(['stat', '-c', '%s',  escapeshellarg($path)]));
            return $result;
        }

        throw new Exception(implode("\n", [
            'File for filesize is not within srcPath or dstPath.',
            $path,
            "srcPath : {$this->srcPath}",
            "dstPath : {$this->dstPath}",
        ]));
    }

    protected function compareDir(array $sData, array $dData): bool
    {
        return true;
    }

    protected function syncCore(
        string $topDir,
        array $srcList,
        array $dstList,
        array $options
    ): void {
        if ($dstList[0] ?? false) {
            $this->deleteGames(['UNKOWN' => $dstList[0]]);
            unset($dstList[0]);
        }
        parent::syncCore($topDir, $srcList, $dstList, $options);
    }

    protected function syncFiles(string $topDir, array $sData): void
    {
        $config = $this->config['machines'][$topDir] ?? [];
        $to     = $config['archiveType'];
        foreach ($sData as $data) {
            $gameName = preg_replace('/\\.[^\\.]+$/', '', basename($data['path']));
            switch ($data['archiveType']) {
                case $to:
                    $dir = $this->makeTmpDir();
                    $archiveFile = basename($data['archivePath']);
                    $crc32 = $data['crc'];
                    $crc32File   = ".crc32_{$archiveFile}_$crc32";
                    touch("$dir/$crc32File");
                    $files = [
                        $data['archivePath'] => $this->dstPath . "/$topDir/$archiveFile",
                        "$dir/$crc32File"    => $this->dstPath . "/$topDir/$crc32File",
                    ];
                    foreach ($files as $src => $dst) {
                        $dstDir = dirname($dst);
                        if ($dstDir !== $this->dstPath . "/$topDir") {
                            $this->mkdirRemote($dstDir);
                        }
                        $this->push($src, $dst);
                        $this->log("[PUSH] $src => $dst");
                    }
                    $this->rmLocal($dir);
                    break;
                case 'raw':
                    $dir = $this->makeTmpDir();
                    $baseName = basename($data['path']);
                    copy($data['path'], "$dir/$baseName");
                    $files = [$baseName];
                    $this->syncArc($topDir, $dir, $files, $gameName, $data['crc'], $to);
                    $this->rmLocal($dir);
                    break;
                default:
                    [$dir, $files] = $this->extractArchive($data['archivePath']);
                    $this->syncArc($topDir, $dir, $files, $gameName, $data['crc'], $to);
                    $this->rmLocal($dir);
                    break;
            }
        }
    }

    protected function syncArc(
        string $topDir,
        string $dir,
        array $files,
        string $gameName,
        string $crc32,
        string $to
    ): void {
        $archiver = $this->archivers[$to];
        $exe = $archiver['c'];
        $cmd = str_replace(
            ['%TO%', '%FROM%'],
            [escapeshellarg($gameName) . ".$to", implode(' ', array_map('escapeshellarg', $files))],
            sprintf("cd %s; %s", escapeshellarg($dir), $exe)
        );
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            throw new Exception(implode("\n", [
                'ERROR: Failed to create a archive file.',
                $cmd,
                ...$lines,
            ]));
        }

        $archiveFile = "$gameName.$to";
        $crc32File   = ".crc32_{$archiveFile}_$crc32";

        touch("$dir/$crc32File");

        $files = [
            $archiveFile,
            $crc32File,
        ];
        foreach ($files as $file) {
            $dst = $this->dstPath . "/$topDir/$file";
            $dstDir = dirname($dst);
            if ($dstDir !== $this->dstPath . "/$topDir") {
                $this->mkdirRemote($dstDir);
            }
            $this->push("$dir/$file", $dst);
            $this->log("[PUSH] $file");
        }
    }
}
