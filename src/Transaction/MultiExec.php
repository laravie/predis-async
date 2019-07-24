<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async\Transaction;

use RuntimeException;
use SplQueue;
use Predis\Response\ResponseInterface;
use Predis\Response\Status as StatusResponse;
use Predis\Async\Client;

/**
 * Client-side abstraction of a Redis transaction based on MULTI / EXEC.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class MultiExec
{
    protected $client;

    /**
     * @param Client $client Client instance.
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->commands = new SplQueue();

        $this->initialize();
    }

    /**
     * Initializes the transaction context.
     */
    protected function initialize()
    {
        $command = $this->client->createCommand('MULTI');

        $this->client->executeCommand($command, static function ($response) {
            if (false === $response) {
                throw new RuntimeException('Could not initialize a MULTI / EXEC transaction');
            }
        });
    }

    /**
     * Dynamically invokes a Redis command with the specified arguments.
     *
     * @param string $method    Command ID.
     * @param array  $arguments Arguments for the command.
     *
     * @return MultiExecContext
     */
    public function __call($method, $arguments)
    {
        $commands = $this->commands;
        $command = $this->client->createCommand($method, $arguments);

        $this->client->executeCommand($command, static function ($response, $_, $command) use ($commands) {
            if (!$response instanceof StatusResponse || $response != 'QUEUED') {
                throw new RuntimeException('Unexpected response in MULTI / EXEC [expected +QUEUED]');
            }

            $commands->enqueue($command);
        });

        return $this;
    }

    /**
     * Handles the actual execution of the whole transaction.
     *
     * @param callable $callback Callback invoked after execution.
     */
    public function execute(callable $callback)
    {
        $commands = $this->commands;
        $command  = $this->client->createCommand('EXEC');

        $this->client->executeCommand($command, static function ($responses, $client) use ($commands, $callback) {
            $size = \count($responses);
            $processed = [];

            for ($i = 0; $i < $size; $i++) {
                $command  = $commands->dequeue();
                $response = $responses[$i];

                unset($responses[$i]);

                if (!$response instanceof ResponseInterface) {
                    $response = $command->parseResponse($response);
                }

                $processed[$i] = $response;
            }

            \call_user_func($callback, $processed, $client);
        });
    }

    /**
     * This method is an alias for execute().
     *
     * @param callable $callback Callback invoked after execution.
     */
    public function exec(callable $callback)
    {
        $this->execute($callback);
    }
}
