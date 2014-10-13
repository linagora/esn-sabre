<?php

set_include_path(__DIR__ . '/../lib/' . PATH_SEPARATOR . __DIR__ . PATH_SEPARATOR . get_include_path());

$autoLoader = include __DIR__ . '/../vendor/autoload.php';

// SabreDAV tests auto loading
$autoLoader->add('ESN\\', __DIR__);

date_default_timezone_set('UTC');

$config = [
    'ESN_TEMPDIR'   => dirname(__FILE__) . '/temp/',
    'ESN_MONGO_URI'  => 'mongodb://localhost:23456',
    'ESN_MONGO_ESNDB' => 'sabre_test_esn',
    'ESN_MONGO_SABREDB' => 'sabre_test_sabre'
];

foreach($config as $key=>$value) {
    if (!defined($key)) define($key, $value);
}

if (!file_exists(ESN_TEMPDIR)) mkdir(ESN_TEMPDIR);
