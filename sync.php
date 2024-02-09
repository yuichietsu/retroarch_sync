<?php

namespace Menrui;

class AdbSync
{
    private const IDX_PATH = 0;
    private const IDX_FILE = 1;
    private const IDX_HASH = 2;

    public bool $verbose = false;
    public bool $debug   = false;

    public function __construct(
        private string $adbPath,
        private string $target,
    ) {
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
                if ($syncType === 'sync') {
                    $this->syncDir($srcPath, $dstPath, $dir);
                } elseif (preg_match('/^rand:?(\\d*)([mg]?)$/', $syncType, $m)) {
                    if ($m[2]) {
                        $mb = $m[1] * ($m[2] === 'g' ? 1024 : 1);
                        $this->syncDirRandMB($srcPath, $dstPath, $dir, $mb);
                    } else {
                        $this->syncDirRandNum($srcPath, $dstPath, $dir, $m[1]);
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

    private function syncDir(
        string $srcPath,
        string $dstPath,
        string $dir,
    ): void {
        $srcList = $this->listLocal($srcPath, $dir);
        $dstList = $this->listRemote($dstPath, $dir);
        $this->syncCore($dstPath, $dir, $srcList, $dstList);
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
                $size   = filesize($path);
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

    private function listCore(string $base, array $lines): array
    {
        $list = [];
        foreach ($lines as $line) {
            $m = [];
            preg_match('/^([0-9a-f]{32})\\s+(.+)/', $line, $m);
            $hash = $m[1];
            $path = $m[2];
            $file = str_replace($base, '', $path);
            $key  = preg_replace('#/.+$#u', '', $file);
            $list[$key][] = [$path, $file, $hash];
        }
        return $list;
    }

    private function listLocal(string $srcPath, string $dir): array
    {
        $lines   = [];
        $base    = "$srcPath/$dir/";
        $cmd     = sprintf('find %s -type f -exec md5sum {} \;', escapeshellarg($base));
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            exit(1);
        }
        return $this->listCore($base, $lines);
    }

    private function listRemote(string $dstPath, string $dir): array
    {
        $base    = "$dstPath/$dir/";
        $lines = $this->exec([
            'find',
            escapeshellarg($base),
            '-type f',
            '-exec md5sum {} \;'
        ]);
        return $this->listCore($base, $lines);
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
