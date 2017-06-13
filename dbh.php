<?php

require 'vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$conf = Yaml::parse(file_get_contents(__DIR__.'/config/config.yml'));

$dsn = 'mysql:host='
    .$conf['mysql']['host']
    .';port='.$conf['mysql']['port']
    .';dbname='.$conf['mysql']['dbname'];

$options = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');

$dbh = new \PDO(
    $dsn, 
    $conf['mysql']['user'], 
    $conf['mysql']['pass'], 
    $options
);