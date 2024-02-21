<?php

require_once('vendor/autoload.php');

$ccd2cue = new \Menrui\CCD2CUE();
$ccd2cue->convert($argv[1]);
