<?php

namespace Menrui;

class AdbSync
{
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
                } elseif (preg_match('/^rand:?(\\d*)(m?)$/', $syncType, $m)) {
                    if ($m[2]) {
                        $this->syncDirRandMB($srcPath, $dstPath, $dir, $m[1]);
                    } else {
                        $this->syncDirRandNum($srcPath, $dstPath, $dir, $m[1]);
                    }
                }
            } else {
                $this->verbose && $this->println("[SKIP] $dir");
            }
        }
    }

    private function syncCore(
        string $srcPath,
        string $dstPath,
        string $dir,
        array $srcList,
        array $dstList,
    ): void {
        foreach ($srcList as $key => $data) {
            [$sPath, $sHash] = $data;
            if (array_key_exists($key, $dstList)) {
                [$dPath, $dHash] = $dstList[$key];
                if ($sHash === $dHash) {
                    $this->verbose && $this->println("[SAME] $key");
                } else {
                    $this->push($sPath, "$dstPath/$dir");
                    $this->println("[SYNC] $key");
                }
            } else {
                $this->push($sPath, "$dstPath/$dir");
                $this->println("[PUSH] $key");
            }
            unset($dstList[$key]);
        }
        foreach ($dstList as $key => $data) {
            [$dPath, $dHash] = $data;
            $this->rm($dPath);
            $this->println("[DEL] $key");
        }
    }

    private function syncDir(
        string $srcPath,
        string $dstPath,
        string $dir,
    ): void {
        $srcList = $this->listLocal($srcPath, $dir);
        $dstList = $this->listRemote($dstPath, $dir);
        $this->syncCore($srcPath, $dstPath, $dir, $srcList, $dstList);
    }

    private function syncDirRandNum(
        string $srcPath,
        string $dstPath,
        string $dir,
        int $num,
    ): void {
        $srcList = [];
        $tmpList = $this->listLocal($srcPath, $dir);
        foreach (array_rand($tmpList, $num) as $key) {
            $srcList[$key] = $tmpList[$key];
        }
        $dstList = $this->listRemote($dstPath, $dir);
        $this->println(sprintf('[RAND] %s files', number_format(count($srcList))));
        $this->syncCore($srcPath, $dstPath, $dir, $srcList, $dstList);
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
            $data          = $tmpList[$key];
            [$path]        = $data;
            $size          = filesize($path);
            if (($sum + $size) <= $th) {
                $srcList[$key] = $data;
                $sum += $size;
            }
        }
        $dstList = $this->listRemote($dstPath, $dir);
        $this->println(sprintf('[RAND] %s bytes', number_format($sum)));
        $this->syncCore($srcPath, $dstPath, $dir, $srcList, $dstList);
    }


    private function listLocal(string $srcPath, string $dir): array
    {
        $srcList = [];
        $lines   = [];
        $base    = "$srcPath/$dir/";
        $cmd     = sprintf('find %s -type f -exec md5sum {} \;', escapeshellarg($base));
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            exit(1);
        }
        foreach ($lines as $line) {
            $m = [];
            preg_match('/^([0-9a-f]{32})\\s+(.+)/', $line, $m);
            $hash = $m[1];
            $path = $m[2];
            $file = str_replace($base, '', $path);
            $srcList[$file] = [$path, $hash];
        }
        return $srcList;
    }

    private function listRemote(string $dstPath, string $dir): array
    {
        $dstList = [];
        $base    = "$dstPath/$dir/";
        $lines = $this->exec([
            'find',
            escapeshellarg($base),
            '-type f',
            '-exec md5sum {} \;'
        ]);
        foreach ($lines as $line) {
            $m = [];
            preg_match('/^([0-9a-f]{32})\\s+(.+)/', $line, $m);
            $hash = $m[1];
            $path = $m[2];
            $file = str_replace($base, '', $path);
            $dstList[$file] = [$path, $hash];
        }
        return $dstList;
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
