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

    $sync->srcPath = '/mnt/d/files/roms/rebuild';
    $sync->dstPath = '/storage/32BB-1E05/RetroArch/ROM';
    $sync->syncGames(
        [
            '32x'           => 'full',
            'a26'           => 'full',
            'a52'           => 'full',
            'a78'           => 'full',
            'bll'           => 'full',
            'c64'           => 'full',
            'c64_pp'        => 'full',
            'c64_tapes'     => 'full',
            'col'           => 'full',
            'doom'          => 'full',
            'fds'           => 'full',
            'gb'            => 'full',
            'gba'           => 'full',
            'gbc'           => 'full',
            'gg'            => 'full',
            'jaguar_abs'    => 'full',
            'jaguar_cof'    => 'full',
            'jaguar_j64'    => 'full',
            'jaguar_jag'    => 'full',
            'jaguar_rom'    => 'full',
            'libretro_snes' => 'full',
            'lnx'           => 'full',
            'lyx'           => 'full',
            'mame'          => 'rand:4g',
            'md'            => 'full',
            'msx'           => 'full',
            'msx2'          => 'full',
            'n64'           => 'rand:1g',
            'nds'           => 'rand:256m',
            'nes'           => 'full',
            'ngc'           => 'full',
            'ngp'           => 'full',
            'pc98'          => 'rand:1g',
            'pce'           => 'full',
            'plus4'         => 'full',
            'psp'           => 'rand:1g,cso',
            'sf_turbo'      => 'full',
            'sms'           => 'full',
            'snes'          => 'full',
            'tosec_adf'     => 'full',
            'tosec_amigacd' => 'rand:4g',
            'tosec_psp'     => 'rand:8g,cso',
            'tosec_snes'    => 'full',
            'tosec_st'      => 'full',
            'vb'            => 'full',
            'vic20'         => 'full',
            'ws'            => 'full',
            'wsc'           => 'full',
            'x68'           => 'rand:1g',
        ],
    );

    $sync->srcPath = '/mnt/d/files/roms/src';
    $sync->dstPath = '/storage/32BB-1E05/RetroArch/ROM/src';
    $sync->syncGames(
        [
            '3do'    => 'rand:2g,excl(Hatou|Shokutaku|Shin-chan|Kitty|Menkyo|Kanji|Golf|Audio)',
            'dc'     => 'rand:2g,chd',
            'dos'    => 'rand:1g',
            'pcecd'  => 'rand:4g,chd,lock',
            'pcfx'   => 'rand:2g,chd,disks',
            'psp'    => 'rand:8g,cso',
            'psx'    => 'rand:8g,chd,lock,disks',
            'quake'  => 'full',
            'quake2' => 'full:ext',
            'saturn' => 'rand:2g,chd,disks',
            'segacd' => 'rand:2g,chd,disks',
            'tr'     => 'full',
        ],
    );
} catch (\Menrui\Exception $e) {
    $error = $e->getMessage();
    fwrite(STDERR, "ERROR: $error\n");
}
