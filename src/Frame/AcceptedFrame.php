<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace AsyncSockets\Frame;

use AsyncSockets\Socket\SocketInterface;

/**
 * Class AcceptedFrame
 */
class AcceptedFrame implements FrameInterface
{
    /**
     * Client address, if available
     *
     * @var string
     */
    private $clientAddress;

    /**
     * Connected client socket
     *
     * @var SocketInterface
     */
    private $socket;

    /**
     * AcceptResponse constructor.
     *
     * @param string          $clientAddress Remote client address
     * @param SocketInterface $socket Remote socket
     */
    public function __construct($clientAddress, SocketInterface $socket)
    {
        $this->clientAddress = $clientAddress;
        $this->socket        = $socket;
    }

    /** {@inheritdoc} */
    public function data()
    {
        return $this->clientAddress;
    }

    /** {@inheritdoc} */
    public function __toString()
    {
        return $this->clientAddress;
    }

    /**
     * Return client socket
     *
     * @return SocketInterface
     */
    public function getClientSocket()
    {
        return $this->socket;
    }

    /**
     * Return ClientAddress
     *
     * @return string
     */
    public function getClientAddress()
    {
        return $this->clientAddress;
    }
}