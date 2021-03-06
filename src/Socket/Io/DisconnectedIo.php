<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015-2017, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace AsyncSockets\Socket\Io;

use AsyncSockets\Exception\NetworkSocketException;
use AsyncSockets\Frame\FramePickerInterface;

/**
 * Class DisconnectedIo
 */
class DisconnectedIo extends AbstractIo
{
    /** {@inheritdoc} */
    public function read(FramePickerInterface $picker, Context $context, $isOutOfBand)
    {
        throw new NetworkSocketException($this->socket, 'Can not start io operation on uninitialized socket.');
    }

    /** {@inheritdoc} */
    public function write($data, Context $context, $isOutOfBand)
    {
        throw new NetworkSocketException($this->socket, 'Can not start io operation on uninitialized socket.');
    }

    /**
     * @inheritDoc
     */
    public function isConnected()
    {
        return false;
    }
}
