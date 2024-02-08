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
        'a26'        => 'sync',
        'a52'        => 'sync',
        'a78'        => 'sync',
        'bll'        => 'sync',
        'fds'        => 'sync',
        'gb'         => 'sync',
        'gba'        => 'sync',
        'gbc'        => 'sync',
        'gg'         => 'sync',
        'jaguar_abs' => 'sync',
        'jaguar_cof' => 'sync',
        'jaguar_j64' => 'sync',
        'jaguar_jag' => 'sync',
        'jaguar_rom' => 'sync',
        'lnx'        => 'sync',
        'lyx'        => 'sync',
        'mame'       => 'rand:4g',
        'md'         => 'sync',
        'msx'        => 'sync',
        'msx2'       => 'sync',
        'nds'        => 'sync',
        'nes'        => 'sync',
        'ngc'        => 'sync',
        'ngp'        => 'sync',
        'pc98'       => 'rand:1g',
        'pce'        => 'sync',
        'psp'        => 'rand:1g',
        'sf_turbo'   => 'sync',
        'sms'        => 'sync',
        'snes'       => 'sync',
        'tosec_psp'  => 'rand:1g',
        'ws'         => 'sync',
        'wsc'        => 'sync',
        'x68'        => 'rand:1g',
    ],
);

$sync->sync(
    '/mnt/d/files/roms/src',
    '/storage/B42F-0FFA/Android/data/com.retroarch/files/ROM/src',
    [
        'n64'    => 'rand:1g',
        'pcecd'  => 'rand:1g',
        'pcfx'   => 'rand:1g',
        'psp'    => 'rand:1g',
        'psx'    => 'rand:1g',
        'segacd' => 'rand:1g',
    ],
);
