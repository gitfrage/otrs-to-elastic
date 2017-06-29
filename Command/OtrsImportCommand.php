<?php

namespace Otrs\Import\Command;

use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Yaml\Yaml;

/**
 * Class OtrsImportCommand
 *
 * @package Otrs\Import
 */
class OtrsImportCommand extends Command
{
    const LOG_LEVEL_WARN  = 'comment';
    const LOG_LEVEL_ERROR = 'error';
    const LOG_LEVEL_INFO  = 'info';

    /** @var array */
    private $config = [];

    /** @var \PDO */
    private $dbConnection;

    /** @var Client */
    private $esConnection;

    /** @var OutputInterface */
    private $output;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('otrs:import:ticket:history');
        $this->addOption(
            'configfile',
            'f',
            InputOption::VALUE_REQUIRED,
            'Configuration file to load',
            'config/config.yml'
        );

        $this->addArgument(
            'date',
            InputArgument::REQUIRED,
            'Date to start import from.'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'elastic' => [
                'host'  => '127.0.0.1',
                'port'  => 9200,
                'index' => 'otrs',
            ],
            'mysql'   => [
                'dbname' => 'otrs',
                'host'   => 'localhost',
                'user'   => 'root',
                'pass'   => 'root',
                'port'   => 3306,
            ],
        ]);

        $data = [];

        if (!empty($input->getOption('configfile')) && file_exists($input->getOption('configfile'))) {
            $data = Yaml::parse(file_get_contents($input->getOption('configfile')));
        }

        $this->config = $resolver->resolve($data);

        // init database
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s',
            $this->config['mysql']['host'],
            $this->config['mysql']['port'],
            $this->config['mysql']['dbname']
        );

        $this->dbConnection = new \PDO($dsn, $this->config['mysql']['user'], $this->config['mysql']['pass'], [
            \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
        ]);

        // init elasticsearch
        $this->esConnection = ClientBuilder::create()
            ->setHosts([
                sprintf('http://%s:%d', $this->config['elastic']['host'], $this->config['elastic']['port']),
            ])
            ->allowBadJSONSerialization()
            ->build();
    }

    /**
     * Logs $message to console using the given $level.
     *
     * @param string $message
     * @param string $level
     * @return void
     */
    private function log($message, $level = self::LOG_LEVEL_INFO)
    {
        $this->output->writeln(sprintf(
            '%s [<%2$s>%s</%2$s>] %s',
            date('Y-m-d H:i:s'),
            $level,
            $message
        ));
    }

    /**
     * Returns a list of all changed tickets starting with the given date.
     *
     * @param string $date
     * @return array
     */
    private function gatherTickets($date)
    {
        $query = 'SELECT id, tn, title FROM ticket WHERE change_time > ' . $this->dbConnection->quote($date);

        $tickets = $this->dbConnection->query($query)->fetchAll();
        if (count($tickets) == 0) {
            $this->log('No tickets found', self::LOG_LEVEL_WARN);
            exit(0);
        }

        return $tickets;
    }

    /**
     * Returns a prepared statement to retrieved list of ticket history events.
     *
     * @return \PDOStatement
     */
    private function getPreparedStatement()
    {
        return $this->dbConnection->prepare('
            SELECT 		th.ticket_id,
                        th.create_time,
                        tht.name AS history_type
            FROM 		ticket t
            INNER JOIN 	ticket_history th ON t.id = th.ticket_id
            INNER JOIN 	ticket_history_type tht ON th.history_type_id = tht.id
            LEFT JOIN	article a ON th.article_id = a.id
            LEFT JOIN	article_type at ON at.id = a.article_type_id
            WHERE 		t.id = :id
            AND 		tht.name IN ("EmailCustomer", "SendAnswer", "FollowUp")
            AND 		at.name = "email-external"
            AND 		th.change_time NOT IN (
                SELECT change_time 
                FROM ticket_history 
                WHERE ticket_id = :id
                AND change_by = 1 
                AND (ticket_history.name LIKE "%customer response required%" OR ticket_history.name LIKE "%internal response required%")
            )
		');
    }

    /**
     * Returns Elastic Response Array 
     *
     * @param string $date
     * @return array
     */
    private function elasticDeleteByQuery($date)
    {
        $params = [
            'index' => $this->config['elastic']['index'],
            'type'  => 'app',
            'body'   => [
                'query' => [
                    'range' => [
                        '@timestamp' => ['gte' => $date],
                    ] 
                ]
            ]
        ];

        $result['deleted'] = '0';
        $result = $this->esConnection->deleteByQuery($params);
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = microtime(true);
        $startDate = $input->getArgument('date');
        if (strtotime($startDate) === false) {
            throw new \InvalidArgumentException('Invalid date format given');
        }

        $deleted = $this->elasticDeleteByQuery($startDate);
        if ($output->isVerbose()) {
            $this->log(
                sprintf('%d deleted events', $deleted['deleted']),
                self::LOG_LEVEL_INFO
            );
        }

        $prep    = $this->getPreparedStatement();
        $tickets = $this->gatherTickets($startDate);

        $params = [
            'body' => [],
        ];

        foreach ($tickets as $ticket) {
            // get history per ticket
            // exclude some events (filter "FollowUp" events from internal actions)
            $prep->bindValue('id', $ticket['id']);

            if (!$prep->execute()) {
                $this->log('Error running SQL statement', self::LOG_LEVEL_ERROR);
                exit(1);
            }

            $this->log(
                sprintf(
                    'Start import ticket [ %s #%d]',
                    $ticket['title'],
                    $ticket['tn']
                ),
                self::LOG_LEVEL_INFO
            );

            $ticket_history_rows = $prep->fetchAll();

            if (count($ticket_history_rows) == 0) {
                if ($output->isVerbose()) {
                    $this->log(sprintf('- No ticket history found'), self::LOG_LEVEL_INFO);
                }
                continue;
            }

            $lastEvent = [];
            $iteration = 0;

            if ($output->isVerbose()) {
                $this->log('- Ticket history rows: ' . count($ticket_history_rows), self::LOG_LEVEL_INFO);
            }

            foreach ($ticket_history_rows as $row) {
                // standard case:
                // lastEvent "EmailCustomer or FollowUp" --> "SendAnswer"

                $responseTime = 0;

                if (!empty($lastEvent['history_type'])) {
                    if ($row['history_type'] == 'SendAnswer' &&
                        ($lastEvent['history_type'] == 'EmailCustomer' || $lastEvent['history_type'] == 'FollowUp')
                    ) {
                        ++$iteration;
                        $responseTime = strtotime($row['create_time']) - strtotime($lastEvent['create_time']);
                    }

                    switch (true) {
                        // edge case:
                        // "FollowUp" --> "FollowUp"
                        case ($row['history_type'] == 'FollowUp' && $lastEvent['history_type'] == 'FollowUp'):
                            $row['create_time'] = $lastEvent['create_time'];
                            break;

                        // edge case:
                        // "SendAnswer" --> "SendAnswer"
                        case ($row['history_type'] == 'SendAnswer' && $lastEvent['history_type'] == 'SendAnswer'):
                            continue 2;
                    }
                }

                // build params for elastic
                $params['body'][] = [
                    'index' => [
                        '_index' => $this->config['elastic']['index'],
                        '_type'  => 'app',
                    ],
                ];
                $params['body'][] = [
                    '@timestamp'             => gmdate("Y-m-d\TH:i:s\Z", strtotime($row['create_time'])),
                    'history_type'           => $row['history_type'],
                    'ticket_nr'              => $ticket['tn'],
                    'ticket_id'              => intval($row['ticket_id']),
                    'iteration'              => $iteration,
                    'response_time'          => $responseTime,

                    // Debugging
                    'response_time_in_hours' => gmdate("H i", $responseTime),
                ];

                $lastEvent = $row;
            }

            // bulk to elastic and clan params
            if (count($params['body']) > 400) {
                if ($output->isVerbose()) {
                    $this->log(
                        sprintf('Wrote %d entries to elastic: ', count($params['body'])),
                        self::LOG_LEVEL_INFO
                    );
                }

                $this->esConnection->bulk($params);
                $params = [
                    'body' => [],
                ];
            }
        }

        if (count($params['body']) > 0) {
            if ($output->isVerbose()) {
                $this->log(
                    sprintf('Wrote %d entries to elastic: ', count($params['body'])),
                    self::LOG_LEVEL_INFO
                );
            }

            $this->esConnection->bulk($params);
        }

        if ($output->isVerbose()) {
            $endTime = microtime(true);

            $this->log(
                sprintf('Finished after %dms', ($endTime - $startTime) * 1000),
                self::LOG_LEVEL_INFO
            );
        }
    }
}
