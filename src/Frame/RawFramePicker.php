<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015-2017, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace AsyncSockets\Frame;

/**
 * Class RawFramePicker. This FramePicker allows to get chunks from network as it was read by fread
 */
class RawFramePicker extends AbstractFramePicker
{
    /** {@inheritdoc} */
    protected function doHandleData($chunk, $remoteAddress, &$buffer)
    {
        $buffer = $chunk;
        $this->setFinished(true);
        return '';
    }

    /** {@inheritdoc} */
    protected function doCreateFrame($buffer, $remoteAddress)
    {
        return new Frame($buffer, $remoteAddress);
    }
}
