<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async\Connection;

use Predis\Command\CommandInterface;
use Predis\Connection\ParametersInterface;
use Predis\Response\Error as ErrorResponse;
use Predis\Response\Status as StatusResponse;
use React\EventLoop\LoopInterface;

class PhpiredisStreamConnection extends AbstractConnection
{
    protected $reader;

    /**
     * {@inheritdoc}
     */
    public function __construct(LoopInterface $loop, ParametersInterface $parameters)
    {
        parent::__construct($loop, $parameters);

        $this->initializeReader();
    }

    /**
     * {@inheritdoc}
     */
    public function __destruct()
    {
        \phpiredis_reader_destroy($this->reader);

        parent::__destruct();
    }

    /**
     * Initializes the protocol reader resource.
     */
    protected function initializeReader()
    {
        $this->reader = phpiredis_reader_create();

        \phpiredis_reader_set_status_handler($this->reader, $this->getStatusHandler());
        \phpiredis_reader_set_error_handler($this->reader, $this->getErrorHandler());
    }

    /**
     * Returns the handler used by the protocol reader to handle status replies.
     *
     * @return \Closure
     */
    protected function getStatusHandler()
    {
        return function ($payload) {
            return StatusResponse::get($payload);
        };
    }

    /**
     * Returns the handler used by the protocol reader to handle Redis errors.
     *
     * @return \Closure
     */
    protected function getErrorHandler()
    {
        return function ($errorMessage) {
            return new ErrorResponse($errorMessage);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function parseResponseBuffer($buffer)
    {
        \phpiredis_reader_feed($reader = $this->reader, $buffer);

        while (\phpiredis_reader_get_state($reader) === PHPIREDIS_READER_STATE_COMPLETE) {
            $this->state->process(\phpiredis_reader_get_reply($reader));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function executeCommand(CommandInterface $command, callable $callback)
    {
        if ($this->buffer->isEmpty() && $stream = $this->getResource()) {
            $this->loop->addWriteStream($stream, $this->writableCallback);
        }

        $cmdargs = $command->getArguments();
        \array_unshift($cmdargs, $command->getId());

        $this->buffer->append(\phpiredis_format_command($cmdargs));
        $this->commands->enqueue([$command, $callback]);
    }
}
