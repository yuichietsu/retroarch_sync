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
            '32x'                => 'full:1g1r',
            'a26'                => 'full:index,1g1r',
            'a52'                => 'full:1g1r',
            'a78'                => 'full:1g1r',
            'bll'                => 'full:rename(lynx/bll)',
            'c64'                => 'full:1g1r,rename(c64/c64)',
            'c64_pp'             => 'full:index,1g1r,rename(c64/pp)',
            'c64_tapes'          => 'full:index,1g1r,rename(c64/tapes)',
            'col'                => 'full:1g1r',
            'doom'               => 'full',
            'fds'                => 'full:1g1r',
            'gb'                 => 'full:index,1g1r',
            'gba'                => 'full:index,1g1r',
            'gbc'                => 'full:index,1g1r',
            'gg'                 => 'full:1g1r',
            'jaguar_abs'         => 'full:rename(jaguar/abs)',
            'jaguar_cof'         => 'full:rename(jaguar/cof)',
            'jaguar_j64'         => 'full:rename(jaguar/j64)',
            'jaguar_jag'         => 'full:rename(jaguar/jag)',
            'jaguar_rom'         => 'full:rename(jaguar/rom)',
            'libretro_snes'      => 'full:rename(snes/libretro)',
            'lnx'                => 'full:rename(lynx/lnx)',
            'lyx'                => 'full:rename(lynx/lyx)',
            'mame'               => 'rand:4g',
            'megaduck'           => 'full',
            'md'                 => 'full:index,1g1r',
            'msx'                => 'full:1g1r',
            'msx2'               => 'full:1g1r',
            'n64'                => 'rand:1g',
            'nds'                => 'rand:256m',
            'nes'                => 'full:index,1g1r',
            'ngc'                => 'full',
            'ngp'                => 'full',
            'pc98'               => 'rand:1g',
            'pce'                => 'full',
            'plus4'              => 'full',
            'psp'                => 'rand:1g,cso,rename(psp/psp)',
            'sf_turbo'           => 'full:rename(snes/sft)',
            'sms'                => 'full',
            'snes'               => 'full:index,1g1r,rename(snes/snes)',
            'tosec_adf'          => 'full:rename(amiga/adf)',
            'tosec_amigacd'      => 'rand:4g,rename(amigacd)',
            'tosec_cpc_dsk'      => 'full:1g1r,index,rename(cpc/dsk)',
            'tosec_psp'          => 'rand:8g,cso,rename(psp/tosec)',
            'tosec_snes'         => 'full:1g1r,rename(snes/tosec)',
            'tosec_spectrum_dsk' => 'full:1g1r,index,rename(spectrum/dsk)',
            'tosec_spectrum_scl' => 'full:1g1r,index,rename(spectrum/scl)',
            'tosec_spectrum_tap' => 'full:1g1r,index,rename(spectrum/tap)',
            'tosec_spectrum_trd' => 'full:1g1r,index,rename(spectrum/trd)',
            'tosec_spectrum_tzx' => 'full:1g1r,index,rename(spectrum/tzx)',
            'tosec_spectrum_z80' => 'full:1g1r,index,rename(spectrum/z80)',
            'tosec_st'           => 'full:zip,1g1r,disks,index,rename(atarist/st)',
            'tosec_stx'          => 'full:zip,1g1r,disks,index,rename(atarist/stx)',
            'vb'                 => 'full:1g1r',
            'vic20'              => 'full',
            'ws'                 => 'full:1g1r',
            'wsc'                => 'full:1g1r',
            'x68'                => 'rand:1g',
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
