<?php

require_once('vendor/autoload.php');

ob_implicit_flush();

$sync = new \Menrui\AdbSyncRetroArch(
    '192.168.11.44:5555',
);

$sync->statesPaths = [
    '/storage/emulated/0/RetroArch/states',
];

$sync->srcPath = '/mnt/d/files/roms/rebuild';
$sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM';
$sync->syncGames(
    [
        'a26'           => 'full',
        'a52'           => 'full',
        'a78'           => 'full',
        'bll'           => 'full',
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
        'psp'           => 'rand:1g,cso',
        'sf_turbo'      => 'full',
        'sms'           => 'full',
        'snes'          => 'full',
        'tosec_psp'     => 'rand:2g,cso',
        'tosec_snes'    => 'full',
        'ws'            => 'full',
        'wsc'           => 'full',
        'x68'           => 'rand:1g',
    ],
);

$sync->srcPath = '/mnt/d/files/roms/src';
$sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM/src';
$sync->syncGames(
    [
        '3do'    => 'rand:2g,excl(Hatou|Shokutaku|Shin-chan|Kitty|Menkyo|Kanji)',
        'dc'     => 'rand:2g,chd',
        'pcecd'  => 'rand:4g,chd,lock',
        'pcfx'   => 'rand:2g,chd',
        'psp'    => 'rand:2g,cso',
        'psx'    => 'rand:8g,chd,lock,disks',
        'quake'  => 'full',
        'quake2' => 'full:ext',
        'saturn' => 'rand:2g,chd.disks',
        'segacd' => 'rand:2g,chd,disks',
        'tr'     => 'full',
    ],
);
