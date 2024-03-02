<?php

require_once('vendor/autoload.php');

ob_implicit_flush();

try {
    $sync = new \Menrui\AdbSyncRetroArch(
        '192.168.11.44:5555',
    );
    $sync->logger = fn ($message) => print("$message\n");

    $sync->statesPaths = [
        '/storage/emulated/0/RetroArch/states',
    ];

    if (($argv[3] ?? false) === 'src') {
        $sync->srcPath = '/mnt/d/files/roms/src';
        $sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM/src';
    } else {
        $sync->srcPath = '/mnt/d/files/roms/rebuild';
        $sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM';
    }
    $sync->syncGames([$argv[1] => $argv[2]]);
} catch (\Menrui\Exception $e) {
    $error = $e->getMessage();
    fwrite(STDERR, "ERROR: $error\n");
}
