<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015-2017, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Tests\AsyncSockets\Socket;

use AsyncSockets\Frame\FixedLengthFramePicker;
use AsyncSockets\Frame\PartialFrame;
use AsyncSockets\Frame\RawFramePicker;
use AsyncSockets\Socket\ClientSocket;
use Tests\Application\Mock\PhpFunctionMocker;

/**
 * Class ClientSocketTest
 */
class ClientSocketTest extends AbstractSocketTest
{
    /**
     * testExceptionWillBeThrowsOnCreateFailWithErrNo
     *
     * @return void
     * @expectedException \AsyncSockets\Exception\NetworkSocketException
     * @expectedExceptionCode 500
     * @expectedExceptionMessage Tested socket creation
     */
    public function testExceptionWillBeThrowsOnCreateFailWithErrNo()
    {
        $mocker = PhpFunctionMocker::getPhpFunctionMocker('stream_socket_client');
        $mocker->setCallable(function ($remoteSocket, &$errno, &$errstr) {
            self::assertEquals('php://temp', $remoteSocket, 'Incorrect address passed to stream_socket_client');
            $errno  = 500;
            $errstr = 'Tested socket creation';
            return false;
        });

        $this->socket->open('php://temp');
    }

    /**
     * testExceptionWillBeThrowsOnCreateFail
     *
     * @return void
     * @expectedException \AsyncSockets\Exception\NetworkSocketException
     */
    public function testExceptionWillBeThrowsOnCreateFail()
    {
        $mocker = PhpFunctionMocker::getPhpFunctionMocker('stream_socket_client');
        $mocker->setCallable(function ($remoteSocket, &$errno, &$errstr) {
            $errno  = 0;
            $errstr = '';
            return false;
        });

        $this->socket->open('php://temp');
    }

    /**
     * testNothingHappenIfNotSelected
     *
     * @return void
     */
    public function testNothingHappenIfNotSelected()
    {
        $mocker = PhpFunctionMocker::getPhpFunctionMocker('stream_select');
        $mocker->setCallable(function () {
            return 0;
        });

        $this->socket->open('it has no meaning here');
        $frame = $this->socket->read(new RawFramePicker());
        self::assertInstanceOf(
            'AsyncSockets\Frame\FrameInterface',
            $frame,
            'Strange response'
        );
    }

    /** {@inheritdoc} */
    protected function setUp()
    {
        parent::setUp();

        $mocker = PhpFunctionMocker::getPhpFunctionMocker('stream_socket_client');
        $mocker->setCallable(function () {
            return fopen('php://temp', 'rw');
        });

        PhpFunctionMocker::getPhpFunctionMocker('stream_get_meta_data')->setCallable(
            function ($resource) {
                $data = \stream_get_meta_data($resource);
                $data['stream_type'] = 'tcp_socket';
                return $data;
            }
        );
    }

    /** {@inheritdoc} */
    protected function tearDown()
    {
        parent::tearDown();
        PhpFunctionMocker::getPhpFunctionMocker('stream_socket_client')->restoreNativeHandler();
        PhpFunctionMocker::getPhpFunctionMocker('stream_get_meta_data')->restoreNativeHandler();
    }

    /** {@inheritdoc} */
    protected function createSocketInterface()
    {
        return new ClientSocket();
    }

    /**
     * testReadingPartialContent
     *
     * @return void
     */
    public function testReadingPartialContent()
    {
        $testString = "HTTP 200 OK\nServer: test-reader\n\n";
        $counter    = 0;

        $mocker = PhpFunctionMocker::getPhpFunctionMocker('fread');
        $mocker->setCallable(function () use ($testString, &$counter) {
            return $counter < strlen($testString) ? $testString[$counter++] : '';
        });

        PhpFunctionMocker::getPhpFunctionMocker('stream_socket_recvfrom')->setCallable(
            function () use ($testString, &$counter) {
                return $counter < strlen($testString) ? $testString[$counter] : '';
            }
        );

        $this->socket->open('it has no meaning here');
        $retString = $this->socket->read(new FixedLengthFramePicker(strlen($testString)))->getData();
        self::assertEquals($testString, $retString, 'Unexpected result was read');
    }

    /**
     * testChunkReading
     *
     * @return void
     */
    public function testChunkReading()
    {
        $isFinished = false;
        $data       = 'I will pass this test';
        $splitData  = array_merge(str_split($data, 1), array_fill(0, 20, ''));
        $freadMock  = $this->getMockBuilder('Countable')->setMethods([ 'count' ])->getMockForAbstractClass();
        $freadMock->expects(self::any())
            ->method('count')
            ->will(new \PHPUnit_Framework_MockObject_Stub_ConsecutiveCalls($splitData));

        PhpFunctionMocker::getPhpFunctionMocker('fread')->setCallable(function () use ($freadMock, &$isFinished) {
            /** @var \Countable $freadMock */
            $result     = $freadMock->count();
            $isFinished = $result === '';
            return $result;
        });

        PhpFunctionMocker::getPhpFunctionMocker('stream_socket_recvfrom')->setCallable(
            function () use (&$isFinished) {
                return $isFinished ? '' : false;
            }
        );

        $responseText = '';
        $this->socket->open('it has no meaning here');
        do {
            $response      = $this->socket->read(new FixedLengthFramePicker(strlen($data)));
            $responseText .= (string) $response;
        } while ($response instanceof PartialFrame);

        self::assertEquals($data, $responseText, 'Received data are incorrect');
    }

    /**
     * testUnhandledDataFromFirstFrameWillBePassedToSecond
     *
     * @param int      $frames Number of frames
     * @param int[]    $lengths Length for frames
     * @param string[] $chunks Chunks with data
     * @param string[] $expectedFrames Expected data in frames
     *
     * @dataProvider sequentialDataProvider
     */
    public function testUnhandledDataFromFirstFrameWillBePassedToSecond(
        $frames,
        array $lengths,
        array $chunks,
        array $expectedFrames
    ) {
        $index = 0;
        $freadMock = $this->getMockBuilder('\Countable')->setMethods(['count'])->getMockForAbstractClass();
        $freadMock->expects(self::any())->method('count')->willReturnCallback(
            function () use (&$index, $chunks) {
                return isset($chunks[$index]) ? $chunks[$index++] : '';
            }
        );

        /** @var \Countable $freadMock */
        PhpFunctionMocker::getPhpFunctionMocker('fread')->setCallable([$freadMock, 'count']);
        PhpFunctionMocker::getPhpFunctionMocker('stream_socket_recvfrom')->setCallable(
            function () {
                return false;
            }
        );

        $pickers = [];
        for ($i = 0; $i < $frames; $i++) {
            $pickers[] = new FixedLengthFramePicker($lengths[$i]);
        }

        $frameResponse = [];
        $this->socket->open('php://temp');
        while ($pickers) {
            $currentPicker   = array_shift($pickers);
            $frameResponse[] = $this->socket->read($currentPicker);
        }

        self::assertGreaterThanOrEqual(count($expectedFrames), count($frameResponse), 'Too few frames received');
        foreach ($expectedFrames as $key => $expectedFrame) {
            self::assertEquals($expectedFrame, (string) $frameResponse[$key], "Frame at {$key} index is invalid");
        }
    }

    /**
     * testFlags
     *
     * @return void
     */
    public function testFlags()
    {
        self::assertFalse($this->socket->isServer(), 'Incorrect server flag');
        self::assertTrue($this->socket->isClient(), 'Incorrect client flag');
    }

    /**
     * sequentialDataProvider
     *
     * @param string $targetMethod Target test method
     *
     * @return array[]
     */
    public function sequentialDataProvider($targetMethod)
    {
        return $this->dataProviderFromYaml(__DIR__, __CLASS__, __FUNCTION__, $targetMethod);
    }
}
