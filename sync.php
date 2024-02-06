<?php

namespace Menrui;

class AdbSync
{
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

    public function exec(array $args = []): array
    {
        $cmd = sprintf('%s shell "%s"', $this->adbPath, implode(' ', $args));
        exec($cmd, $outputs, $ret);
        if ($ret !== 0) {
            fwrite(STDERR, "ERROR: $cmd\n");
            exit(1);
        }
        return $outputs;
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
                $this->syncDir($srcPath, $dstPath, $dir, $filters[$dir]);
            } else {
                echo "[SKIP] $dir\n";
            }
        }
    }

    private function syncDir(
        string $srcPath,
        string $dstPath,
        string $dir,
        string $syncType,
    ): void {
        $srcList = $this->listLocal($srcPath, $dir);
        $dstList = $this->listRemote($dstPath, $dir);
        foreach ($srcList as $key => $data) {
            [$sPath, $sHash] = $data;
            if (array_key_exists($key, $dstList)) {
                [$dPath, $dHash] = $dstList[$key];
                if ($sHash === $dHash) {
                    echo "[SAME] $key\n";
                } else {
                    echo "[DIFF] $key\n";
                }
            } else {
                echo "[DST NOT FOUND] $key\n";
            }
        }
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
