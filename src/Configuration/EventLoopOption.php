<?php

/*
 * This file is part of the Predis\Async package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Async\Configuration;

use Predis\Configuration\OptionInterface;
use Predis\Configuration\OptionsInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/**
 * Injects the event loop instance used by the client. The default value when no
 * event loop is specified is to use a new instance of the stream_select()-based
 * loop provided by react/event-loop.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class EventLoopOption implements OptionInterface
{
    /**
     * {@inheritdoc}
     */
    public function filter(OptionsInterface $options, $value)
    {
        if (! $value instanceof LoopInterface) {
            throw new \InvalidArgumentException('Invalid value for the eventloop option');
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefault(OptionsInterface $options)
    {
        return new StreamSelectLoop();
    }
}
