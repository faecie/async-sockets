<?php
/**
 * Async sockets
 *
 * @copyright Copyright (c) 2015, Efimov Evgenij <edefimov.it@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace AsyncSockets\RequestExecutor\Pipeline;

use AsyncSockets\Event\AcceptEvent;
use AsyncSockets\Event\Event;
use AsyncSockets\Event\EventType;
use AsyncSockets\Event\ReadEvent;
use AsyncSockets\Event\WriteEvent;
use AsyncSockets\Exception\AcceptException;
use AsyncSockets\Exception\SocketException;
use AsyncSockets\RequestExecutor\Metadata\OperationMetadata;
use AsyncSockets\RequestExecutor\Metadata\SocketBag;
use AsyncSockets\RequestExecutor\OperationInterface;
use AsyncSockets\RequestExecutor\ReadOperation;
use AsyncSockets\RequestExecutor\RequestExecutorInterface;
use AsyncSockets\Socket\AcceptResponse;
use AsyncSockets\Socket\ChunkSocketResponse;
use AsyncSockets\Socket\SelectContext;
use AsyncSockets\Socket\SocketInterface;

/**
 * Class IoStage
 */
class IoStage extends AbstractTimeAwareStage
{
    /**
     * Io was completely done
     */
    const IO_STATE_DONE = 0;

    /**
     * Partial i/o result
     */
    const IO_STATE_PARTIAL = 1;

    /**
     * Exception during I/O processing
     */
    const IO_STATE_EXCEPTION = 2;

    /**
     * Process I/O operation
     *
     * @param SocketBag     $socketBag Socket bag
     * @param SelectContext $context Select context
     *
     * @return array
     */
    public function processIo(SocketBag $socketBag, SelectContext $context)
    {
        return array_merge(
            $this->processSingleIoEvent($socketBag, $context->getRead(), EventType::READ),
            $this->processSingleIoEvent($socketBag, $context->getWrite(), EventType::WRITE)
        );
    }

    /**
     * Process ready to curtain I/O operation sockets
     *
     * @param SocketBag         $socketBag Socket bag
     * @param SocketInterface[] $sockets Array of sockets, ready for certain operation
     * @param string            $eventType Event name of I/O operation
     *
     * @return OperationMetadata[] Completed operations
     */
    private function processSingleIoEvent(SocketBag $socketBag, array $sockets, $eventType)
    {
        $result = [];
        foreach ($sockets as $socket) {
            $key          = $socketBag->requireOperationKey($socket);
            $item         = $socketBag->requireOperation($socket);
            $meta         = $item->getMetadata();
            $wasConnected = $meta[ RequestExecutorInterface::META_CONNECTION_FINISH_TIME ] !== null;
            $this->setSocketOperationTime($item, RequestExecutorInterface::META_CONNECTION_FINISH_TIME);
            if (!$wasConnected) {
                $event = new Event(
                    $this->executor,
                    $socket,
                    $meta[ RequestExecutorInterface::META_USER_CONTEXT ],
                    EventType::CONNECTED
                );

                try {
                    $this->callSocketSubscribers($item, $event);
                } catch (SocketException $e) {
                    $this->callExceptionSubscribers($item, $e, $event);
                    $result[$key] = $item;
                    continue;
                }
            }

            if ($eventType === EventType::READ) {
                $ioState = $this->processReadIo($item, $nextOperation);
            } else {
                $ioState = $this->processWriteIo($item, $nextOperation);
            }

            switch ($ioState) {
                case self::IO_STATE_DONE:
                    if ($nextOperation === null) {
                        $result[$key] = $item;
                    } else {
                        $item->setOperation($nextOperation);
                        $item->setMetadata(
                            [
                                RequestExecutorInterface::META_LAST_IO_START_TIME => null,
                            ]
                        );
                    }
                    break;
                case self::IO_STATE_PARTIAL:
                    continue;
                case self::IO_STATE_EXCEPTION:
                    $result[$key] = $item;
                    break;
            }
        }

        return $result;
    }

    /**
     * Process reading operation
     *
     * @param OperationMetadata       $operationMetadata Metadata
     * @param OperationInterface|null &$nextOperation Next operation to perform on socket
     *
     * @return int One of IO_STATE_* consts
     */
    private function processReadIo(OperationMetadata $operationMetadata, OperationInterface &$nextOperation = null)
    {
        $meta      = $operationMetadata->getMetadata();
        $socket    = $operationMetadata->getSocket();
        $operation = $operationMetadata->getOperation();

        $event = null;
        try {
            /** @var ReadOperation $operation */
            $response = $socket->read($operation->getFrame(), $operationMetadata->getPreviousResponse());
            switch (true) {
                case $response instanceof ChunkSocketResponse:
                    $operationMetadata->setPreviousResponse($response);
                    return self::IO_STATE_PARTIAL;
                case $response instanceof AcceptResponse:
                    $event = new AcceptEvent(
                        $this->executor,
                        $socket,
                        $meta[ RequestExecutorInterface::META_USER_CONTEXT ],
                        $response->getClientSocket(),
                        $response->getClientAddress()
                    );

                    $this->callSocketSubscribers($operationMetadata, $event);
                    $nextOperation = new ReadOperation();
                    return self:: IO_STATE_DONE;
                default:
                    $event = new ReadEvent(
                        $this->executor,
                        $socket,
                        $meta[ RequestExecutorInterface::META_USER_CONTEXT ],
                        $response
                    );

                    $this->callSocketSubscribers($operationMetadata, $event);
                    $nextOperation = $event->getNextOperation();
                    return self::IO_STATE_DONE;
            }
        } catch (AcceptException $e) {
            $this->callExceptionSubscribers($operationMetadata, $e, null);
            $nextOperation = new ReadOperation();

            return self::IO_STATE_DONE;
        } catch (SocketException $e) {
            $this->callExceptionSubscribers(
                $operationMetadata,
                $e,
                $event ?: new ReadEvent($this->executor, $socket, $meta[ RequestExecutorInterface::META_USER_CONTEXT ])
            );

            return self::IO_STATE_EXCEPTION;
        }
    }

    /**
     * Process write operation
     *
     * @param OperationMetadata       $operationMetadata Metadata
     * @param OperationInterface|null &$nextOperation Next operation to perform on socket
     *
     * @return int One of IO_STATE_* consts
     */
    private function processWriteIo(OperationMetadata $operationMetadata, OperationInterface &$nextOperation = null)
    {
        $meta   = $operationMetadata->getMetadata();
        $socket = $operationMetadata->getSocket();
        $event  = new WriteEvent(
            $operationMetadata->getOperation(),
            $this->executor,
            $socket,
            $meta[ RequestExecutorInterface::META_USER_CONTEXT ]
        );
        try {
            $this->callSocketSubscribers($operationMetadata, $event);
            if ($event->getOperation()->hasData()) {
                $socket->write($event->getOperation()->getData());
            }
            $nextOperation = $event->getNextOperation();
            return self::IO_STATE_DONE;
        } catch (SocketException $e) {
            $this->callExceptionSubscribers($operationMetadata, $e, $event);
            return self::IO_STATE_EXCEPTION;
        }
    }
}