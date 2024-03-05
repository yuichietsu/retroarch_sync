<?php

require_once('vendor/autoload.php');

ob_implicit_flush();

try {
    $sync = new \Menrui\AdbSyncRetroArch(
        '192.168.11.51:5555',
    );
    $sync->logger = fn ($message) => print("$message\n");

    $sync->statesPaths = [
        '/storage/32BB-1E05/RetroArch/states',
    ];

    if (($argv[3] ?? false) === 'src') {
        $sync->srcPath = '/mnt/d/files/roms/src';
        $sync->dstPath = '/storage/32BB-1E05/RetroArch/ROM/src';
    } else {
        $sync->srcPath = '/mnt/d/files/roms/rebuild';
        $sync->dstPath = '/storage/32BB-1E05/RetroArch/ROM';
    }
    $sync->syncGames([$argv[1] => $argv[2]]);
} catch (\Menrui\Exception $e) {
    $error = $e->getMessage();
    fwrite(STDERR, "ERROR: $error\n");
}
