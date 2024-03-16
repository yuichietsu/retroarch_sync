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
    $sync->favoritesPaths = [
        '/storage/32BB-1E05/RetroArch/content_favorites.lpl',
    ];

    $sync->srcPath = '/mnt/d/files/roms/rebuild';
    $sync->dstPath = '/storage/32BB-1E05/RetroArch/ROM';
    $sync->syncGames(
        [
            '32x'           => 'full',
            'a26'           => 'full:index',
            'a52'           => 'full',
            'a78'           => 'full',
            'bll'           => 'full:rename(lynx/bll)',
            'c64'           => 'full:rename(c64/c64)',
            'c64_pp'        => 'full:index,rename(c64/pp)',
            'c64_tapes'     => 'full:index,rename(c64/tapes)',
            'col'           => 'full',
            'doom'          => 'full',
            'fds'           => 'full',
            'gb'            => 'full:index',
            'gba'           => 'full:index',
            'gbc'           => 'full:index',
            'gg'            => 'full',
            'jaguar_abs'    => 'full:rename(jaguar/abs)',
            'jaguar_cof'    => 'full:rename(jaguar/cof)',
            'jaguar_j64'    => 'full:rename(jaguar/j64)',
            'jaguar_jag'    => 'full:rename(jaguar/jag)',
            'jaguar_rom'    => 'full:rename(jaguar/rom)',
            'libretro_snes' => 'full:rename(snes/libretro)',
            'lnx'           => 'full:rename(lynx/lnx)',
            'lyx'           => 'full:rename(lynx/lyx)',
            'mame'          => 'rand:4g',
            'md'            => 'full:index',
            'msx'           => 'full',
            'msx2'          => 'full',
            'n64'           => 'rand:1g',
            'nds'           => 'rand:256m',
            'nes'           => 'full:index',
            'ngc'           => 'full',
            'ngp'           => 'full',
            'pc98'          => 'rand:1g',
            'pce'           => 'full',
            'plus4'         => 'full',
            'psp'           => 'rand:1g,cso,rename(psp/psp)',
            'sf_turbo'      => 'full:rename(snes/sft)',
            'sms'           => 'full',
            'snes'          => 'full:index,rename(snes/snes)',
            'tosec_adf'     => 'full:index',
            'tosec_amigacd' => 'rand:4g',
            'tosec_psp'     => 'rand:8g,cso,rename(psp/tosec)',
            'tosec_snes'    => 'full:rename(snes/tosec)',
            'tosec_st'      => 'full:zip,merge,disks,index,rename(atari_st/st)',
            'tosec_stx'     => 'full:zip,merge,disks,index,rename(atari_st/stx)',
            'vb'            => 'full',
            'vic20'         => 'full',
            'ws'            => 'full',
            'wsc'           => 'full',
            'x68'           => 'rand:1g',
        ],
    );

    $sync->srcPath = '/mnt/d/files/roms/src';
    $sync->dstPath = '/storage/32BB-1E05/RetroArch/ROM';
    $sync->syncGames(
        [
            '3do'    => 'rand:2g,excl(Hatou|Shokutaku|Shin-chan|Kitty|Menkyo|Kanji|Golf|Audio)',
            'dc'     => 'rand:2g,chd',
            'dos'    => 'rand:1g',
            'pcecd'  => 'rand:4g,chd,lock',
            'pcfx'   => 'rand:2g,chd,disks',
            'psp'    => 'rand:8g,cso,rename(psp/src)',
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
