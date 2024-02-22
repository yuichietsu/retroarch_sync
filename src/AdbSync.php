<?php

namespace Menrui;

class AdbSync
{
    protected const LIST_NONE = 0;
    protected const LIST_HASH = 1;
    protected const LIST_DATE = 2;

    public bool $verbose = false;
    public bool $debug   = false;
    public int $retryCount = 5;
    public int $retrySleep = 60;

    public ?string $srcPath = null;
    public ?string $dstPath = null;

    public ?\Closure $logger = null;

    public array $commands = [
        'adb'  => 'adb',
        'find' => 'find',
        'rm'   => 'rm',
    ];

    public function __construct(
        protected string $remote,
    ) {
        $this->connect();
    }

    protected function log(string|array $messages): void
    {
        if ($this->logger !== null) {
            foreach (is_string($messages) ? [$messages] : $messages as $message) {
                call_user_func($this->logger, $message);
            }
        }
    }

    public function connect(): void
    {
        exec(implode(' ', [$this->commands['adb'], 'connect', escapeshellarg($this->remote)]), $lines, $ret);
        if ($ret !== 0 || str_contains(implode($lines), 'failed to connect')) {
            throw new Exception(implode("\n", ['failed to connect adb server.', ...$lines]));
        }
    }

    protected function checkPathSettings(?string $srcPath = null, ?string $dstPath = null): void
    {
        $srcPath && ($this->srcPath = $srcPath);
        $dstPath && ($this->dstPath = $dstPath);
        if (!$this->srcPath) {
            throw new Exception('srcPath is not specified.');
        }
        if (!$this->dstPath) {
            throw new Exception('dstPath is not specified.');
        }
    }

    public function execRemote(array $args = [], ?string $exitCond = null): array
    {
        $cmd = sprintf('%s shell "%s"', $this->commands['adb'], implode(' ', $args));
        $this->debug && $this->log($cmd);
        return $this->retryExec($cmd, $exitCond);
    }

    private function retryExec(string $cmd, ?string $exitCond = null): array
    {
        for ($retry = 1; $retry <= $this->retryCount; $retry++) {
            $outputs = [];
            exec("$cmd 2>&1", $outputs, $ret);
            if ($ret === 0) {
                return $outputs;
            } else {
                $this->log("ERROR($retry): $cmd");
                $this->log($outputs);
                if ($exitCond !== null) {
                    foreach ($outputs as $output) {
                        if (str_contains($output, $exitCond)) {
                            throw new Exception("Exit condition met: $exitCond");
                        }
                    }
                }
            }
            sleep($this->retrySleep);
        }
        throw new Exception('Reached retry limit');
    }

    public function push(string $localFile, string $remoteDir): array
    {
        $this->checkRemotePath($remoteDir);
        $cmd = sprintf(
            '%s push %s %s',
            $this->commands['adb'],
            escapeshellarg($localFile),
            escapeshellarg($remoteDir),
        );
        $this->debug && $this->log($cmd);
        return $this->retryExec($cmd);
    }

    public function rmRemote(string $path): array
    {
        $this->checkRemotePath($path);
        return $this->execRemote(['rm', '-rf', escapeshellarg($path)]);
    }


    public function mkdirRemote(string $dir): array
    {
        $this->checkRemotePath($dir);
        return $this->execRemote([
            'mkdir',
            '-p',
            escapeshellarg($dir),
        ]);
    }

    protected function checkRemotePath(string $path): void
    {
        if (!str_starts_with($path, $this->dstPath)) {
            throw new Exception(implode("\n", [
                'Remote path must be within the base directory to be removed.',
                $path,
                "base : {$this->dstPath}",
            ]));
        }
    }

    protected function dirsLocal(string $scanDir): array
    {
        $lines = [];
        $cmd = sprintf('%s %s -mindepth 1 -type d', $this->commands['find'], escapeshellarg($scanDir));
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            throw new Exception($cmd);
        }
        return $this->listCore($scanDir, $lines, self::LIST_NONE);
    }

    protected function dirsRemote(string $scanDir): array
    {
        $cmd = [
            'find',
            escapeshellarg($scanDir),
            '-mindepth 1',
            '-type d',
        ];
        $lines = $this->execRemote($cmd, 'No such file or directory');
        return $this->listCore($scanDir, $lines, self::LIST_NONE);
    }

    protected function listLocal(string $scanDir, int $mode = self::LIST_HASH): array
    {
        $lines   = [];
        $args = match ($mode) {
            self::LIST_NONE => sprintf('%s -type f', escapeshellarg($scanDir)),
            self::LIST_HASH => sprintf('%s -type f -exec md5sum {} \;', escapeshellarg($scanDir)),
            self::LIST_DATE => sprintf('%s -type f -exec stat -c "%Y %n" {} \;', escapeshellarg($scanDir)),
        };
        $cmd = "{$this->commands['find']} $args";
        exec($cmd, $lines, $ret);
        if ($ret !== 0) {
            throw new Exception($cmd);
        }
        return $this->listCore($scanDir, $lines, $mode);
    }

    protected function listRemote(string $scanDir, int $mode = self::LIST_HASH): array
    {
        $cmd = match ($mode) {
            self::LIST_NONE => [
                'find',
                escapeshellarg($scanDir),
                '-type f',
            ],
            self::LIST_HASH => [
                'find',
                escapeshellarg($scanDir),
                '-type f',
                '-exec md5sum {} \;'
            ],
            self::LIST_DATE => [
                'find',
                escapeshellarg($scanDir),
                '-type f',
                "-exec stat -c '%Y %n' {} \;",
            ],
        };
        $lines = $this->execRemote($cmd, 'No such file or directory');
        return $this->listCore($scanDir, $lines, $mode);
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
                    $list[$file] = $hash;
                    break;
                case self::LIST_DATE:
                    $m = [];
                    preg_match('/^(\\d+)\\s+(.+)/', $line, $m);
                    $date = $m[1];
                    $path = $m[2];
                    $file = str_replace($base, '', $path);
                    $list[$file] = $date;
                    break;
                case self::LIST_NONE:
                    $path = $line;
                    $file = str_replace($base, '', $path);
                    $list[$file] = true;
                    break;
            }
        }
        return $list;
    }

    protected function md5(string $path): string
    {
        if (str_starts_with($path, $this->srcPath)) {
            return $this->md5Local($path);
        }

        if (str_starts_with($path, $this->dstPath)) {
            return $this->md5Remote($path);
        }

        throw new Exception(implode("\n", [
            'File for MD5 is not within srcPath or dstPath.',
            $path,
            "srcPath : {$this->srcPath}",
            "dstPath : {$this->dstPath}",
        ]));
    }

    protected function md5Local(string $path): string
    {
        return md5(file_get_contents($path));
    }

    protected function md5Remote(string $path): string
    {
        return implode($this->execRemote(['md5sum', '-b', escapeshellarg($path)]));
    }

    private function printDiffList(array $list, string $title = null, string $prefix = '', string $suffix = ''): void
    {
        if (count($list) > 0) {
            $title && $this->log($title);
            ksort($list);
            foreach ($list as $file) {
                $this->log("$prefix$file$suffix");
            }
        }
    }

    public function diff(): void
    {
        $this->checkPathSettings();

        $srcList = $this->listLocal($this->srcPath, self::LIST_HASH);
        $dstList = $this->listRemote($this->dstPath, self::LIST_HASH);
        $srcDirs = $this->dirsLocal($this->srcPath);
        $dstDirs = $this->dirsRemote($this->dstPath);

        $fSrcDiff = array_keys(array_diff_key($srcList, $dstList));
        $dSrcDiff = array_map(fn ($n) => "$n/", array_keys(array_diff_key($srcDirs, $dstDirs)));
        $this->printDiffList([...$dSrcDiff, ...$fSrcDiff], '[SRC ONLY]');

        $fDstDiff = array_keys(array_diff_key($dstList, $srcList));
        $dDstDiff = array_map(fn ($n) => "$n/", array_keys(array_diff_key($dstDirs, $srcDirs)));
        $this->printDiffList([...$dDstDiff, ...$fDstDiff], '[DST ONLY]');

        $diff = [];
        foreach (array_keys(array_intersect_key($srcList, $dstList)) as $key) {
            if ($srcList[$key] !== $dstList[$key]) {
                $diff[] = $key;
            }
        }
        $this->printDiffList($diff, '[HASH NOT MATCH]');
    }

    private function pushFile(string $file): array
    {
        $src = $this->srcPath . "/$file";
        $dst = $this->dstPath . "/$file";
        $dstDir = dirname($dst);
        if ($dstDir !== $this->dstPath) {
            $this->mkdirRemote($dstDir);
        }
        $this->push($src, $dstDir);
        return [$src, $dst];
    }

    public function send(): array
    {
        $this->checkPathSettings();
        $srcList = $this->listLocal($this->srcPath, self::LIST_HASH);
        $dstList = $this->listRemote($this->dstPath, self::LIST_HASH);
        $srcOnly = array_keys(array_diff_key($srcList, $dstList));
        foreach ($srcOnly as $file) {
            [$src, $dst] = $this->pushFile($file);
            $this->log("[SEND] $src => $dst");
        }

        $srcDirs = $this->dirsLocal($this->srcPath);
        $dstDirs = $this->dirsRemote($this->dstPath);
        $srcOnly = array_keys(array_diff_key($srcDirs, $dstDirs));
        foreach ($srcOnly as $dir) {
            $path = $this->dstPath . "/$dir";
            $this->mkdirRemote($path);
            $this->log("[MKDIR] $path");
        }
        return [$srcList, $dstList];
    }

    public function update(): array
    {
        [$srcList, $dstList] = $this->send();
        foreach (array_keys(array_intersect_key($srcList, $dstList)) as $key) {
            if ($srcList[$key] !== $dstList[$key]) {
                [$src, $dst] = $this->pushFile($key);
                $this->log("[UPDATE] $src => $dst");
            }
        }
        return [$srcList, $dstList];
    }

    public function sync(): array
    {
        [$srcList, $dstList] = $this->update();
        $dstOnly = array_keys(array_diff_key($dstList, $srcList));
        foreach ($dstOnly as $file) {
            $path = $this->dstPath . "/$file";
            $this->rmRemote($path);
            $this->log("[DELETE] $path");
        }

        $srcDirs = $this->dirsLocal($this->srcPath);
        $dstDirs = $this->dirsRemote($this->dstPath);
        $dstOnly = array_keys(array_diff_key($dstDirs, $srcDirs));
        foreach ($dstOnly as $dir) {
            $path = $this->dstPath . "/$dir";
            $this->rmRemote($path);
            $this->log("[RMDIR] $path");
        }
        return [$srcList, $dstList];
    }
}
