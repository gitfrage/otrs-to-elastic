<?php

require 'vendor/autoload.php';

use Elasticsearch\ClientBuilder;
use Symfony\Component\Yaml\Yaml;

$opt = getopt("f::");

if ($opt) {
	$filename = $opt['f'];
} else {
	$filename ='./config/config.yml';
}

try {
	$conf = Yaml::parse(file_get_contents($filename));
} catch (Exception $e) {
	printf("Unable to get or parse the YAML: %s", $e->getMessage());
}

$dsn = 'mysql:host=' 
	. $conf['mysql']['host'] 
	. ';port='.$conf['mysql']['port'] 
	. ';dbname='.$conf['mysql']['dbname'];

$options = array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8');

$dbh = new \PDO($dsn, $conf['mysql']['user'], $conf['mysql']['pass'], $options);

$hosts = ['http://'. $conf['elastic']['host'] 
	. ':' . $conf['elastic']['port']];

$client = ClientBuilder::create()->setHosts($hosts)->allowBadJSONSerialization()->build();

// get changed tickets (since last script run)

$lastday = date("Y-m-d H:i:s", mktime(0, 0, 0, date("m"), date("d")-1, date("Y")));
$query = 'SELECT id, tn FROM ticket WHERE change_time > '. $dbh->quote($lastday); 

$tickets = $dbh->query($query)->fetchAll();
if (count($tickets) == 0) {
	die('no tickets found');
}

foreach ($tickets as $ticket)	{

	// get history per ticket
	// exclude some events (filter "FollowUp" events from internal actions)

	$query = '
		SELECT 		th.ticket_id,
					th.create_time,
					tht.name AS history_type
		FROM 		ticket t
		INNER JOIN 	ticket_history th ON t.id = th.ticket_id
		INNER JOIN 	ticket_history_type tht ON th.history_type_id = tht.id
		LEFT JOIN	article a ON th.article_id = a.id
		LEFT JOIN	article_type at ON at.id = a.article_type_id
		WHERE 		t.id = ' . $ticket['id'] . '
		AND 		tht.name IN ("EmailCustomer", "SendAnswer", "FollowUp")
		AND 		at.name = "email-external"
		AND 		th.change_time NOT IN (
			SELECT change_time 
			FROM ticket_history 
			WHERE ticket_id = '. $ticket['id'] .'
			AND change_by = 1 
			AND (ticket_history.name LIKE "%customer response required%" OR ticket_history.name LIKE "%internal response required%")
		)
	';
		
	$ticket_history_rows = $dbh->query($query)->fetchAll();
	if (count($ticket_history_rows) == 0)  {
		echo 'no ticket history found';
		continue;
	}

	$lastEvent = [];
	$iteration = 0;

	foreach($ticket_history_rows as $row) {    

		// standard case: 
		// lastEvent "EmailCustomer or FollowUp" --> "SendAnswer" 

		$responseTime = 0;	    
		if ($row['history_type'] == 'SendAnswer' && 
			!empty($lastEvent['history_type']) &&
			($lastEvent['history_type'] == 'EmailCustomer' || $lastEvent['history_type'] == 'FollowUp')) {
			$iteration++;
			$responseTime = strtotime($row['create_time']) - strtotime($lastEvent['create_time']);
		}

		// edge case: 
		// "FollowUp" --> "FollowUp" 

		if ($row['history_type'] == 'FollowUp' && 
			!empty($lastEvent['history_type']) && 
			$lastEvent['history_type'] == 'FollowUp') {
			$row['create_time'] = $lastEvent['create_time'];
		}

		// edge case: 
		// "SendAnswer" --> "SendAnswer" 

		if ($row['history_type'] == 'SendAnswer' &&  
			!empty($lastEvent['history_type']) && 
			$lastEvent['history_type'] == 'SendAnswer') {
			continue;
		}

		// build params for elastic

		$params['body'][] = [
		'index' => [
			'_index' => 'otrs',
			'_type' => 'app',
		]
		];
		$params['body'][] = [	
			'history_type' 	=> $row['history_type'],
			'ticket_nr' 	=> $ticket['tn'],
			'ticket_id' 	=> intval($row['ticket_id']),
			'iteration'	=> $iteration,
			'response_time' => $responseTime,
			'response_time_in_hours' => gmdate("H i", $responseTime) ,
			'@timestamp' =>  gmdate("Y-m-d\TH:i:s\Z", strtotime($row['create_time']))
		];

		$lastEvent = $row;
	}

	// bulk to elastic and clan params

	if (count($params['body']) > 0) {
		$results = $client->bulk($params);
		$params = '';
	}
	
}