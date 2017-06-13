<?php

require 'vendor/autoload.php';

use Elasticsearch\ClientBuilder;

use Symfony\Component\Yaml\Yaml;

$conf = Yaml::parse(
	file_get_contents('./config/config.yml')
);

$hosts = [
	'http://'. $conf['elastic']['host'] 
	. ':' . $conf['elastic']['port']
];

$client = ClientBuilder::create()
	->setHosts($hosts)
	->allowBadJSONSerialization()
	->build();
	