<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015-2017, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Tests\AsyncSockets\Exception;

use AsyncSockets\Exception\FrameException;
use AsyncSockets\Frame\FramePickerInterface;

/**
 * Class FrameExceptionTest
 */
class FrameExceptionTest extends NetworkSocketExceptionTest
{
    /**
     * FramePickerInterface
     *
     * @var FramePickerInterface
     */
    protected $framePicker;

    /**
     * testReturnFramePicker
     *
     * @return void
     */
    public function testReturnFramePicker()
    {
        $exception = $this->createException();
        self::assertSame($this->framePicker, $exception->getFramePicker(), 'Invalid frame picker');
    }

    /** {@inheritdoc} */
    protected function createException()
    {
        return new FrameException($this->framePicker, $this->socket);
    }

    /** {@inheritdoc} */
    protected function setUp()
    {
        parent::setUp();
        $this->framePicker = $this->getMockBuilder('AsyncSockets\Frame\FramePickerInterface')
                                    ->getMockForAbstractClass();
    }
}
