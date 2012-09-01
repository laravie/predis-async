<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async;

use Predis\ClientException;
use Predis\NotSupportedException;
use Predis\ResponseObjectInterface;
use Predis\Command\CommandInterface;
use Predis\Connection\ConnectionParameters;
use Predis\Connection\ConnectionParametersInterface;
use Predis\Option\ClientOptionsInterface;
use Predis\Profile\ServerProfile;
use Predis\Profile\ServerProfileInterface;
use Predis\Async\Connection\AsynchronousConnection;
use Predis\Async\Connection\AsynchronousConnectionInterface;
use Predis\Async\Option\ClientOptions;
use Predis\Async\Transaction\MultiExecContext;
use React\EventLoop\LoopInterface;

/**
 * Main class that exposes the most high-level interface to interact asynchronously
 * with Redis instances.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class Client
{
    const VERSION = '0.0.2-dev';

    protected $profile;
    protected $connection;

    /**
     * Initializes a new client with optional connection parameters and client options.
     *
     * @param mixed $parameters Connection parameters for one or multiple servers.
     * @param mixed $options Options that specify certain behaviours for the client.
     */
    public function __construct($parameters = null, $options = null)
    {
        $parameters = $this->filterParameters($parameters);
        $options = $this->filterOptions($options);

        $this->options = $options;
        $this->profile = $options->profile;

        $this->connection = $this->initializeConnection($parameters, $options);
    }

    /**
     * Creates connection parameters.
     *
     * @param mixed $options Connection parameters.
     * @return ConnectionParametersInterface
     */
    protected function filterParameters($parameters)
    {
        if (!$parameters instanceof ConnectionParametersInterface) {
            $parameters = new ConnectionParameters($parameters);
        }

        return $parameters;
    }

    /**
     * Creates an instance of Predis\Option\ClientOptions from various types of
     * arguments (string, array, Predis\Profile\ServerProfile) or returns the
     * passed object if it is an instance of Predis\Option\ClientOptions.
     *
     * @param mixed $options Client options.
     * @return ClientOptions
     */
    protected function filterOptions($options)
    {
        if ($options === null) {
            return new ClientOptions();
        }

        if (is_array($options)) {
            return new ClientOptions($options);
        }

        if ($options instanceof ClientOptionsInterface) {
            return $options;
        }

        if ($options instanceof LoopInterface) {
            return new ClientOptions(array('eventloop' => $options));
        }

        throw new \InvalidArgumentException('Invalid type for client options');
    }

    /**
     * Initializes one or multiple connection (cluster) objects from various
     * types of arguments (string, array) or returns the passed object if it
     * implements Predis\Connection\ConnectionInterface.
     *
     * @param mixed $parameters Connection parameters or instance.
     * @param ClientOptionsInterface $options Client options.
     * @return AsynchronousConnectionInterface
     */
    protected function initializeConnection($parameters, ClientOptionsInterface $options)
    {
        if ($parameters instanceof AsynchronousConnectionInterface) {
            if ($connection->getEventLoop() !== $this->getEventLoop()) {
                throw new ClientException('Client and connection must share the same event loop instance');
            }

            return $parameters;
        }

        $connection = new AsynchronousConnection($parameters, $this->getEventLoop());

        if (isset($options->on_error)) {
            $this->setErrorCallback($connection, $options->on_error);
        }

        return $connection;
    }

    /**
     * Sets the callback used to notify the client after a connection error.
     *
     * @param AsynchronousConnectionInterface $connection Connection instance.
     * @param mixed $callback Callback for error event.
     */
    protected function setErrorCallback(AsynchronousConnectionInterface $connection, $callback)
    {
        $client = $this;

        $connection->setErrorCallback(function ($connection, $exception) use ($callback, $client) {
            call_user_func($callback, $client, $exception, $connection);
        });
    }

    /**
     * Returns the server profile used by the client.
     *
     * @return ServerProfileInterface
     */
    public function getProfile()
    {
        return $this->profile;
    }

    /**
     * Returns the underlying event loop.
     *
     * @return LoopInterface
     */
    public function getEventLoop()
    {
        return $this->options->eventloop;
    }

    /**
     * Returns the client options specified upon initialization.
     *
     * @return ClientOptionsInterface
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Opens the connection to the server.
     *
     * @param mixed $callback Callback for connection event.
     */
    public function connect($callback)
    {
        $client = $this;

        $callback = function ($connection) use ($callback, $client) {
            call_user_func($callback, $client, $connection);
        };

        $this->connection->connect($callback);
    }

    /**
     * Disconnects from the server.
     */
    public function disconnect()
    {
        $this->connection->disconnect();
    }

    /**
     * Checks if the underlying connection is connected to Redis.
     *
     * @return boolean
     */
    public function isConnected()
    {
        return $this->connection->isConnected();
    }

    /**
     * Returns the underlying connection instance.
     *
     * @return AsynchronousConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * {@inheritdoc}
     */
    public function __call($method, $arguments)
    {
        if (false === is_callable($callback = array_pop($arguments))) {
            $arguments[] = $callback;
            $callback = null;
        }

        $command = $this->profile->createCommand($method, $arguments);
        $this->executeCommand($command, $callback);
    }

    /**
     * Creates a new instance of the specified Redis command.
     *
     * @param string $method The name of a Redis command.
     * @param array $arguments The arguments for the command.
     * @return CommandInterface
     */
    public function createCommand($method, $arguments = array())
    {
        return $this->profile->createCommand($method, $arguments);
    }

    /**
     * Executes the specified Redis command.
     *
     * @param CommandInterface $command A Redis command.
     * @param mixed $callback Optional command callback.
     */
    public function executeCommand(CommandInterface $command, $callback = null)
    {
        $this->connection->executeCommand($command, $this->wrapCallback($callback));
    }

    /**
     * Wraps a command callback to parse the raw response returned by a
     * command and pass more arguments back to user code.
     *
     * @param mixed $callback Command callback.
     */
    protected function wrapCallback($callback)
    {
        $client = $this;

        return function ($response, $command, $connection) use ($client, $callback) {
            if (false === isset($callback)) {
                return;
            }

            if (true === isset($command) && false === $response instanceof ResponseObjectInterface) {
                $response = $command->parseResponse($response);
            }

            call_user_func($callback, $response, $command, $client);
        };
    }

    /**
     * Creates a new transaction context.
     *
     * @return MultiExecContext
     */
    public function multiExec(/* arguments */)
    {
        return new MultiExecContext($this);
    }

    /**
     * {@inheritdoc}
     */
    public function pipeline(/* arguments */)
    {
        throw new NotSupportedException('Not yet implemented');
    }

    /**
     * {@inheritdoc}
     */
    public function pubSub(/* arguments */)
    {
        throw new NotSupportedException('Not yet implemented');
    }
}
