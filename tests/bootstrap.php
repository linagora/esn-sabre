<?php

set_include_path(__DIR__ . '/../lib/' . PATH_SEPARATOR . __DIR__ . PATH_SEPARATOR . get_include_path());

define('CONFIG_PATH', '../config.json');

$autoLoader = include __DIR__ . '/../vendor/autoload.php';

// SabreDAV tests auto loading
$autoLoader->add('ESN\\', __DIR__);

date_default_timezone_set('UTC');

$config = json_decode(file_get_contents(CONFIG_PATH), true);
if (!$config) {
    throw new Exception("Could not load config.json from " . realpath(CONFIGPATH) . ", Error " . json_last_error());
}

$testconfig = [
    'ESN_TEMPDIR'   => dirname(__FILE__) . '/temp/',
    'ESN_MONGO_ESNURI'  => $config['database']['esn']['connectionString'],
    'ESN_MONGO_ESNDB' => $config['database']['esn']['db'] . "_test",
    'ESN_MONGO_SABREDB' => $config['database']['sabre']['db'] . "_test",
    'ESN_MONGO_SABREURI'  => $config['database']['sabre']['connectionString'],
];

foreach($testconfig as $key=>$value) {
    if (!defined($key)) define($key, $value);
}

if (!file_exists(ESN_TEMPDIR)) mkdir(ESN_TEMPDIR);
