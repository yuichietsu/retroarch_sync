<?php

require_once('sync.php');

ob_implicit_flush();

$sync = new \Menrui\AdbSync(
    '/usr/bin/adb',
    '192.168.11.44:5555',
);
$sync->statesPaths = [
    '/storage/emulated/0/RetroArch/states',
];

$sync->srcPath = '/mnt/d/files/roms/rebuild';
$sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM';
$sync->sync(
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
        'psp'           => 'rand:1g,ext',
        'sf_turbo'      => 'full',
        'sms'           => 'full',
        'snes'          => 'full',
        'tosec_psp'     => 'rand:2g,ext',
        'ws'            => 'full',
        'wsc'           => 'full',
        'x68'           => 'rand:1g',
    ],
);

$sync->srcPath = '/mnt/d/files/roms/src';
$sync->dstPath = '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM/src';
$sync->sync(
    [
        'dc'     => 'rand:2g,ext',
        'gc'     => 'rand:2g,ext',
        'pcecd'  => 'rand:4g,ext',
        'pcfx'   => 'rand:2g,ext',
        'psp'    => 'rand:2g,ext',
        'psx'    => 'rand:8g,ext,lock',
        'saturn' => 'rand:2g,ext',
        'segacd' => 'rand:2g,ext',
        'tr'     => 'full',
        'quake'  => 'full',
    ],
);
