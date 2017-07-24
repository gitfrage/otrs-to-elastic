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

        $this->addOption(
            'date',
            'd',
            InputOption::VALUE_REQUIRED,
            'Date to start import from'
        );

        $this->addOption(
            'initial',
            'i',
            InputOption::VALUE_NONE,
            'call it for initial import - this will skip date filter on ebents'
        );

        $this->addOption(
            'tn',
            't',
            InputOption::VALUE_REQUIRED,
            'Ticket Nummer for import'
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
                'index' => 'otrstest',
            ],
            'mysql'   => [
                'dbname' => 'otrs2',
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
    private function gatherTicketsByDate($date)
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
     * Returns a ticket by ticket number.
     *
     * @param string $id
     * @return array
     */
    private function gatherTicketsByTn($tn)
    {
        $query = 'SELECT id, tn, title FROM ticket WHERE tn = ' . $this->dbConnection->quote($tn);

        $ticket = $this->dbConnection->query($query)->fetchAll();
        if (count($ticket) == 0) {
            $this->log('No tickets found', self::LOG_LEVEL_WARN);
            exit(0);
        }

        return $ticket;
    }


    /**
     * Returns a prepared statement to retrieved list of ticket history events.
     *
     * @return \PDOStatement
     */
    private function getPreparedStatement()
    {
        return $this->dbConnection->prepare('
            SELECT      th.ticket_id,
                        th.create_time,
                        th.change_time,
                        th.name,
                        tht.name AS history_type
            FROM        ticket t
            INNER JOIN  ticket_history th ON t.id = th.ticket_id
            INNER JOIN  ticket_history_type tht ON th.history_type_id = tht.id
            LEFT JOIN   article a ON th.article_id = a.id
            LEFT JOIN   article_type at ON at.id = a.article_type_id
            WHERE       t.id = :id
            AND         tht.name IN ("EmailCustomer", "SendAnswer", "FollowUp")
            AND         at.name = "email-external"
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
        $epoch = strtotime($date);

        if ($epoch === false) {
            throw new \InvalidArgumentException(sprintf(
                'Could not convert given date [%s] using strtotime.',
                $date
            ));
        }

        $params = [
            'index' => $this->config['elastic']['index'],
            'type'  => 'app',
            'body'  => [
                'query' => [
                    'range' => [
                        '@timestamp' => ['gte' => date('Y-m-d\TH:i:sO', $epoch)],
                    ],
                ],
            ],
        ];

        $result['deleted'] = '0';
        $result            = $this->esConnection->deleteByQuery($params);

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = microtime(true);

        if ((empty($input->getOption('date')) && empty($input->getOption('tn'))) || 
            (!empty($input->getOption('date')) && !empty($input->getOption('tn')))) {
             throw new \InvalidArgumentException('Exsact one option (from two) required!');
        }

        if (!empty($input->getOption('tn'))) {
            $tn = $input->getOption('tn');
            $tickets = $this->gatherTicketsByTn($tn);
        }

        if (!empty($input->getOption('date'))) {
            $startDate = $input->getOption('date');
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
            $tickets = $this->gatherTicketsByDate($startDate);
        }

        $prep    = $this->getPreparedStatement();

        $params = [
            'body' => [],
        ];

        foreach ($tickets as $ticket) {

            $prep->bindValue('id', $ticket['id']);

            if (!$prep->execute()) {
                $this->log('Error running SQL statement', self::LOG_LEVEL_ERROR);
                exit(1);
            }

            $this->log(sprintf(
                    'Start import ticket [%s #%d]',
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

                $responseTime = 0;

                // skip already imported events on following imports

                if (!$input->getOption('initial') && isset($startDate) && $startDate < $row['change_time']) {
                    $this->log(sprintf(
                            'skip event in initial [%s ]',
                            $row['history_type']
                        ),
                        self::LOG_LEVEL_INFO
                    );
                    continue;
                }

                // filter internal (Responsible Update ect) event:
                // if (preg_match('/' . $ticket['tn'] . '/', $row['name'] ))  continue;    

                // set counter and responseTime only by "SendAnswer" events

                if (!empty($lastEvent['history_type']) && $row['history_type'] == 'SendAnswer') {
                    ++$iteration;
                    $responseTime = strtotime($row['create_time']) - strtotime($lastEvent['create_time']);
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

                $this->log(sprintf(
                        'Start import ticket history [%s #%d #%d]',
                        $row['history_type'],
                        $iteration,
                        $responseTime
                    ),
                    self::LOG_LEVEL_INFO
                );

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
