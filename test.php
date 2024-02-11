<?php

require_once('sync.php');

ob_implicit_flush();

$sync = new \Menrui\AdbSync(
    '/usr/bin/adb',
    '192.168.11.44:5555',
);

$sync->sync(
    '/mnt/d/files/roms/rebuild',
    '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM',
    [
        'a26'        => 'full',
        'a52'        => 'full',
        'a78'        => 'full',
        'bll'        => 'full',
        'fds'        => 'full',
        'gb'         => 'full',
        'gba'        => 'full',
        'gbc'        => 'full',
        'gg'         => 'full',
        'jaguar_abs' => 'full',
        'jaguar_cof' => 'full',
        'jaguar_j64' => 'full',
        'jaguar_jag' => 'full',
        'jaguar_rom' => 'full',
        'lnx'        => 'full',
        'lyx'        => 'full',
        'mame'       => 'rand:4g',
        'md'         => 'full',
        'msx'        => 'full',
        'msx2'       => 'full',
        'n64'        => 'rand:1g',
        'nds'        => 'rand:1g',
        'nes'        => 'full',
        'ngc'        => 'full',
        'ngp'        => 'full',
        'pc98'       => 'rand:1g',
        'pce'        => 'full',
        'psp'        => 'rand:1g,ext',
        'sf_turbo'   => 'full',
        'sms'        => 'full',
        'snes'       => 'full:zip',
        'tosec_psp'  => 'rand:4g',
        'ws'         => 'full',
        'wsc'        => 'full',
        'x68'        => 'rand:1g',
    ],
);

$sync->sync(
    '/mnt/d/files/roms/src',
    '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM/src',
    [
        'pcecd'  => 'rand:4g,ext',
        'pcfx'   => 'rand:4g,ext',
        'psp'    => 'rand:4g,ext',
        'psx'    => 'rand:4g,ext',
        'segacd' => 'rand:4g,ext',
    ],
);
