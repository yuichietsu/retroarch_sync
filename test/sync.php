<?php

require_once('vendor/autoload.php');

ob_implicit_flush();

exit(main($argv));

function main(array $argv): int
{
    [
        'cmd'     => $cmd,
        'remote'  => $remote,
        'srcPath' => $srcPath,
        'dstPath' => $dstPath,
    ] = parseArgs($argv);
    $adbSync = new \Menrui\AdbSync($remote);
    $adbSync->srcPath = $srcPath;
    $adbSync->dstPath = $dstPath;
    $adbSync->$cmd();
    return 0;
}

function parseArgs(array $argv): array
{
    array_shift($argv);

    $commands = ['diff', 'send', 'update', 'sync'];
    $port = '5555';
    $remains = [];

    foreach ($argv as $v) {
        if ($v === '-P') {
            $port = null;
        } elseif ($port === null) {
            $port = $v;
        } else {
            $remains[] = $v;
        }
    }

    foreach (['cmd', 'src', 'dst'] as $k => $v) {
        $arg = $remains[$k] ?? null;
        if (!$arg) {
            fwrite(STDERR, "$v not found : $arg\n");
            exit(1);
        }
        $$v = $arg;
    }

    if (!in_array($cmd, $commands)) {
        fwrite(STDERR, "command not found : $cmd\n");
        exit(1);
    }

    if (preg_match('/^([^:]+):(.+)$/', $dst, $m)) {
        $host    = $m[1];
        $dstPath = $m[2];
    } else {
        fwrite(STDERR, "invalid dst format : $dst\n");
        exit(1);
    }

    return [
        'cmd'     => $cmd,
        'remote'  => "$host:$port",
        'srcPath' => $src,
        'dstPath' => $dstPath,
    ];
}
