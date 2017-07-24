<?php

namespace Otrs\Import;

use Otrs\Import\Command\OtrsImportCommand;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\TestCase;

class OtrsImportTest extends TestCase
{

    /** @var command */
    private $command;

    /** @var commandTester */
    private $commandTester;

    protected function setUp()
    {
        $loader = require __DIR__ . '/../../vendor/autoload.php';

        // commandTester

        $input  = new ArgvInput();
        $application = new Application();
        $application->addCommands([new OtrsImportCommand()]);

        $this->command = $application->find('otrs:import:ticket:history');
        $this->commandTester = new CommandTester($this->command);

        // esConnection

        $config = ['host'  => '127.0.0.1', 'port'  => 9200, 'index' => 'otrstest'];
        
        $this->esConnection = ClientBuilder::create()
            ->setHosts([sprintf('http://%s:%d', $config['host'], $config['port'])])
            ->allowBadJSONSerialization()
            ->build();

    }

    public function test10330231() 
    {
        $tn = '10330231';
        $response = $this->execCommand($tn);
 
        // wrong FollowUp (not filterd by subquery) 
        // ToDo: Fix OTRS

        #|    330804 | 2017-06-09 12:01:25 | 2017-06-09 12:01:25 | %%internal response required%%open%%     | StateUpdate           |
        #|    330804 | 2017-06-09 12:01:25 | 2017-06-09 12:01:25 | %%10330231%%                             | FollowUp              |
        #|    330804 | 2017-06-09 12:01:25 | 2017-06-09 12:01:25 | Reset of unlock time.                    | Misc                  |
        #|    330804 | 2017-06-09 12:01:25 | 2017-06-09 12:01:25 | %%FollowUp%%torsten.zinke@myracloud.com  | SendAgentNotification |

        $this->assertContains($response['hits']['hits']['0']['_source']['history_type'], 'EmailCustomer' );
        $this->assertContains($response['hits']['hits']['1']['_source']['history_type'], 'SendAnswer', 'first');
        $this->assertContains($response['hits']['hits']['2']['_source']['history_type'], 'FollowUp', 'wtf');
        $this->assertContains($response['hits']['hits']['3']['_source']['history_type'], 'SendAnswer', 'second');
    }
    
    public function test10374514() 
    {
        $tn = '10374514';
        $response = $this->execCommand($tn);

        $this->assertContains($response['hits']['hits']['0']['_source']['history_type'], 'EmailCustomer');
        $this->assertContains($response['hits']['hits']['1']['_source']['history_type'], 'SendAnswer', 'first');
        $this->assertContains($response['hits']['hits']['2']['_source']['history_type'], 'SendAnswer', 'second');
        $this->assertContains($response['hits']['hits']['3']['_source']['history_type'], 'FollowUp');
        $this->assertContains($response['hits']['hits']['4']['_source']['history_type'], 'SendAnswer');
        $this->assertContains($response['hits']['hits']['5']['_source']['history_type'], 'FollowUp');
        $this->assertContains($response['hits']['hits']['6']['_source']['history_type'], 'SendAnswer');
        $this->assertContains($response['hits']['hits']['7']['_source']['history_type'], 'FollowUp');
        $this->assertContains($response['hits']['hits']['8']['_source']['history_type'], 'SendAnswer');
    }
    
    public function test10374644() 
    {
        $tn = '10374644';
        $response = $this->execCommand($tn); 

        $this->assertContains($response['hits']['hits']['0']['_source']['history_type'], 'EmailCustomer');
        $this->assertContains($response['hits']['hits']['1']['_source']['history_type'], 'SendAnswer', 'first' );
        $this->assertContains($response['hits']['hits']['2']['_source']['history_type'], 'FollowUp');
        $this->assertContains($response['hits']['hits']['3']['_source']['history_type'], 'SendAnswer','second');
    }
    
    public function test10374679() 
    {
        $tn = '10374679';
        $response = $this->execCommand($tn); 
        
        #print_r($response);
        $this->assertContains($response['hits']['hits']['14']['_source']['history_type'], 'FollowUp');

    }

    private function execCommand($tn)
    {
        $this->commandTester->execute(array('command' => $this->command->getName(), '--tn' => $tn));
        sleep(1);

        $output = $this->commandTester->getDisplay();
        $this->assertContains($tn, $output);
        
        $params = [
            'index' => 'otrstest',
            'type' => 'app',
            'sort' => '@timestamp',
            'size' => 50,
            'body' => [
                'query' => [
                    'match' => [
                        'ticket_nr' => $tn
                    ]
                ]
            ]
        ];
        $response = $this->esConnection->search($params);

        return $response;
    }

    protected function tearDown()
    {
        $response = $this->esConnection->indices()
            ->delete(['index' => 'otrstest']);
    }
    
}
