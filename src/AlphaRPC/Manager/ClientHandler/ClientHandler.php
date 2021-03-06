<?php
/**
 * This file is part of AlphaRPC (http://alpharpc.net/)
 *
 * @license BSD-3 (please see the LICENSE file distributed with this source code.
 * @copyright Copyright (c) 2010-2013, Alphacomm Group B.V. (http://www.alphacomm.nl/)
 *
 * @author Reen Lokum <reen@alphacomm.nl>
 * @package AlphaRPC
 * @subpackage ClientHandler
 */

namespace AlphaRPC\Manager\ClientHandler;

use AlphaRPC\Client\Protocol\ExecuteRequest;
use AlphaRPC\Client\Protocol\ExecuteResponse;
use AlphaRPC\Client\Protocol\FetchRequest;
use AlphaRPC\Client\Protocol\FetchResponse;
use AlphaRPC\Client\Protocol\PoisonResponse;
use AlphaRPC\Client\Protocol\TimeoutResponse;
use AlphaRPC\Common\AlphaRPC;
use AlphaRPC\Common\MessageStream\MessageEvent;
use AlphaRPC\Common\MessageStream\StreamInterface;
use AlphaRPC\Common\Protocol\Message\MessageInterface;
use AlphaRPC\Common\Timer\TimeoutTimer;
use AlphaRPC\Manager\Protocol\ClientHandlerJobRequest;
use AlphaRPC\Manager\Protocol\ClientHandlerJobResponse;
use AlphaRPC\Manager\Protocol\WorkerHandlerStatus;
use AlphaRPC\Manager\Request;
use AlphaRPC\Manager\Storage\AbstractStorage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @author Reen Lokum <reen@alphacomm.nl>
 * @package AlphaRPC
 * @subpackage ClientHandler
 */
class ClientHandler implements LoggerAwareInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     *
     * @var StreamInterface[]
     */
    protected $streams = array();

    /**
     *
     * @var AbstractStorage
     */
    protected $storage;

    /**
     *
     * @var Request[]
     */
    protected $request = array();

    /**
     *
     * @var ClientBucket
     */
    protected $clients = null;

    /**
     *
     * @var int[]
     */
    protected $workerHandlers = array();

    /**
     *
     * @var string[]
     */
    protected $workerHandlerQueue = array();

    /**
     * Can we send to the worker socket?
     *
     * @var boolean
     */
    protected $workerHandlerReady = true;

    /**
     * The target execution time for a single handle() call.
     *
     * @var int
     */
    protected $delay = AlphaRPC::MAX_MANAGER_DELAY;

    /**
     * @param StreamInterface $clientStream
     * @param StreamInterface $workerHandlerStream
     * @param StreamInterface $workerHandlerStatusStream
     * @param AbstractStorage $storage
     * @param LoggerInterface $logger
     */
    public function __construct(
        StreamInterface $clientStream,
        StreamInterface $workerHandlerStream,
        StreamInterface $workerHandlerStatusStream,
        AbstractStorage $storage,
        LoggerInterface $logger = null
    ) {
        $this->storage = $storage;
        $this->clients = new ClientBucket();

        $this->setLogger($logger);

        $this->setStream('client',              $clientStream);
        $this->setStream('workerHandler',       $workerHandlerStream);
        $this->setStream('workerHandlerStatus', $workerHandlerStatusStream);
    }

    /**
     * Sets the target execution time of a single handle() call.
     *
     * @param int $delay in ms
     *
     * @throws \InvalidArgumentException
     * @return ClientHandler
     */
    public function setDelay($delay)
    {
        if (!ctype_digit((string)$delay)) {
            throw new \InvalidArgumentException('Delay must be a number.');
        }
        $this->delay = $delay;

        return $this;
    }

    /**
     * Add the given stream and register it to the EventDispatcher.
     *
     * @param string          $type
     * @param StreamInterface $stream
     *
     * @return ClientHandler
     */
    protected function setStream($type, StreamInterface $stream)
    {
        $this->streams[$type] = $stream;

        $callback = array($this, 'on'.ucfirst($type).'Message');

        $stream->addListener(
            StreamInterface::MESSAGE,
            $this->createEventListenerForStream($callback)
        );

        return $this;
    }

    /**
     * Creates an event listener for a stream.
     *
     * @param callable $callback
     *
     * @return callable
     */
    private function createEventListenerForStream($callback)
    {
        $logger = $this->getLogger();

        $function = function (MessageEvent $event) use ($callback, $logger) {
            $protocol = $event->getProtocolMessage();

            if ($protocol === null) {
                $logger->debug('Incompatable message: '.$event->getMessage());

                return;
            }

            $routing = $event->getMessage()->getRoutingInformation();

            call_user_func($callback, $protocol, $routing);

            return;
        };

        return $function;
    }

    /**
     * Returns the requested Stream.
     *
     * @param string $type
     *
     * @return StreamInterface
     */
    protected function getStream($type)
    {
        return $this->streams[$type];
    }

    /**
     * Handle a message from a Client.
     *
     * @param MessageInterface $msg
     * @param array            $routing
     */
    public function onClientMessage(MessageInterface $msg, $routing)
    {
        $client = $this->client(array_shift($routing));

        if ($msg instanceof ExecuteRequest) {
            $this->clientRequest($client, $msg);

            return;
        }

        if ($msg instanceof FetchRequest) {
            $this->clientFetch($client, $msg);

            return;
        }

        $this->getLogger()->info('Invalid message type: '.get_class($msg).'.');
    }

    /**
     * Handle the result of a Job that a WorkerHandler processed.
     *
     * @param ClientHandlerJobResponse $msg
     */
    public function onWorkerHandlerMessage(ClientHandlerJobResponse $msg)
    {
        $this->workerHandlerReady = true;

        $requestId = $msg->getRequestId();
        if (isset($this->workerHandlerQueue[$requestId])) {
            unset($this->workerHandlerQueue[$requestId]);
        }

        $workerHandler = $msg->getWorkerHandlerId();
        $request       = $this->getRequest($requestId);

        if ($request === null) {
            $this->getLogger()->info(
                sprintf(
                    'WorkerHandler %s accepted request %s, but request is unknown.',
                    $workerHandler,
                    $requestId
                )
            );

            return;
        }

        $this->getLogger()->debug(
            sprintf(
                'Worker-handler %s accepted request: %s.',
                $workerHandler,
                $requestId
            )
        );

        $request->setWorkerHandlerId($workerHandler);
    }

    /**
     * Handle a status message from the WorkerHandler.
     *
     * @param WorkerHandlerStatus $msg
     */
    public function onWorkerHandlerStatusMessage(WorkerHandlerStatus $msg)
    {
        $handlerId = $msg->getWorkerHandlerId();

        if (isset($this->workerHandlers[$handlerId])) {
            // Remove to make sure the handlers are ordered by time.
            unset($this->workerHandlers[$handlerId]);
        }

        $this->workerHandlers[$handlerId] = microtime(true);

        $requestId = $msg->getRequestId();

        if ($requestId === null) {
            return;
        }

        $this->getLogger()->debug('Storage has a result available for: '.$requestId.'.');

        $this->sendResponseToClients($requestId);
    }

    /**
     * Processes all new messages in the WorkerHandler queue.
     */
    public function handleWorkerHandlerQueue()
    {
        if (!$this->workerHandlerReady || count($this->workerHandlerQueue) == 0) {
            return;
        }
        $requestId = array_shift($this->workerHandlerQueue);
        $request   = $this->getRequest($requestId);

        if ($request === null) {
            return;
        }

        $this->getLogger()->debug('Sending request: '.$requestId.' to worker-handler.');

        $this->workerHandlerReady = false;

        $this->getStream('workerHandler')->send(
            new ClientHandlerJobRequest(
                $requestId,
                $request->getActionName(),
                $request->getParams()
            )
        );
    }

    /**
     * Checks whether the given WorkerHandler exists.
     *
     * @param $id
     *
     * @return boolean
     */
    public function hasWorkerHandler($id)
    {
        return isset($this->workerHandlers[$id]);
    }

    /**
     * Checks and removes all expired WorkerHandlers.
     *
     * @return boolean
     */
    public function hasExpiredWorkerHandler()
    {
        $hasExpired = false;
        $timeout    = AlphaRPC::WORKER_HANDLER_TIMEOUT;
        $validTime  = microtime(true) - ($timeout / 1000);

        foreach ($this->workerHandlers as $handlerId => $time) {
            if ($time >= $validTime) {
                break;
            }

            unset($this->workerHandlers[$handlerId]);
            $hasExpired = true;
        }

        return $hasExpired;
    }

    /**
     * Handle a request form a Client.
     *
     * @param Client         $client
     * @param ExecuteRequest $msg
     */
    public function clientRequest(Client $client, ExecuteRequest $msg)
    {
        $requestId = $msg->getRequestId();
        if (!$requestId) {
            // No requestId given, generate a unique one.
            do {
                $requestId = sha1(uniqid());
            } while (isset($this->request[$requestId]));
        }

        if ($this->storage->has($requestId)) {
            $this->getLogger()->info(
                sprintf(
                    'Client %s wants to execute request %s. '.
                    'Since there already is a result for that request, '.
                    'it will be sent back immediately.',
                    bin2hex($client->getId()),
                    $requestId
                )
            );

            $this->reply(
                $client,
                new ExecuteResponse(
                    $requestId,
                    $this->storage->get($requestId)
                )
            );

            return;
        }

        // The Client always needs to receive this
        // response, so just send it right away.
        $this->reply($client, new ExecuteResponse($requestId));

        // Now actually handle the message.
        $request = $this->getRequest($requestId);

        if ($request) {
            $this->getLogger()->info(
                sprintf(
                    'Client %s wants to execute already known request %s.',
                    bin2hex($client->getId()),
                    $requestId
                )
            );

            return;
        }

        $action = $msg->getAction();
        $params = $msg->getParams();

        $this->getLogger()->info(
            sprintf(
                'New request %s from client %s for action %s',
                $requestId,
                bin2hex($client->getId()),
                $action
            )
        );

        $this->addRequest(new Request($requestId, $action, $params));

        if (!$this->storage->has($requestId)) {
            $this->addWorkerQueue($requestId);
        }
    }

    /**
     * Handle a Fetch Request from a Client.
     *
     * @param Client       $client
     * @param FetchRequest $msg
     */
    protected function clientFetch(Client $client, FetchRequest $msg)
    {
        $requestId     = $msg->getRequestId();
        $waitForResult = $msg->getWaitForResult();

        $client->setRequest($requestId, $waitForResult);

        $this->getLogger()->debug(
            sprintf(
                'Client %s is requesting the result of request %s.',
                bin2hex($client->getId()),
                $requestId
            )
        );

        if ($this->storage->has($requestId)) {
            $this->sendResponseToClients($requestId);

            return;
        }

        $this->logger->debug(
            sprintf(
                'The result for request %s is not yet available, but '.
                'client %s is %s to wait for it.',
                $requestId,
                bin2hex($client->getId()),
                ($waitForResult) ? 'willing' : 'not willing'
            )
        );

        if (!$waitForResult) {
            $this->reply($client, new TimeoutResponse($requestId));;
        }
    }

    /**
     *
     * @param string $requestId
     *
     * @return null
     */
    protected function sendResponseToClients($requestId)
    {
        if (!$this->storage->has($requestId)) {
            $this->getLogger()->notice(
                'Storage does not have a result for request: '.$requestId.'.'
            );

            return;
        }

        $result = $this->storage->get($requestId);
        $this->removeRequest($requestId);

        $msg = new FetchResponse($requestId, $result);

        if ($this->isPoisonedResult($result)) {
            $msg = new PoisonResponse($requestId);
        }

        $clients   = $this->getClientsForRequest($requestId);
        $clientIds = array();
        foreach ($clients as $client) {
            $this->reply($client, $msg);
            $clientIds[] = bin2hex($client->getId());
        }

        $this->getLogger()->info(
            'Sending result for request '.$requestId.' to '
            .' client(s): '.implode(', ', $clientIds).'.'
        );
    }


    /**
     * Check for the magic word "STATUS:" that indicates the job did
     * not get an actual result.
     *
     * Format: STATUS:CODE
     *
     * @todo Fix this.
     *
     * @param string $result
     *
     * @return bool
     */
    private function isPoisonedResult($result)
    {
        if ('STATUS:' != substr($result, 0, 7)) {
            return false;
        }

        $parts = explode(':', $result, 3);
        $code  = (int) $parts[1];

        if ($code === 500) {
            return true;
        }

        return false;
    }

    /**
     * Contains the main loop of the Client Handler.
     *
     * This checks and handles new messages on the Client Handler sockets.
     */
    public function handle()
    {
        $this->getStream('client')->handle(new TimeoutTimer($this->delay/4));
        $this->getStream('workerHandler')->handle(new TimeoutTimer($this->delay/4));
        $this->handleWorkerHandlerQueue();
        $this->getStream('workerHandlerStatus')->handle(new TimeoutTimer($this->delay/4));
        $this->handleExpired();
        $this->handleExpiredWorkerHandlers();
    }

    /**
     * Send a reply to expired clients.
     */
    public function handleExpired()
    {
        $expired = $this->clients->getExpired(AlphaRPC::CLIENT_PING);
        foreach ($expired as $client) {
            $this->reply($client, new TimeoutResponse());
        }
    }

    /**
     * Queue requests again for expired WorkerHandlers.
     */
    public function handleExpiredWorkerHandlers()
    {
        if (!$this->hasExpiredWorkerHandler()) {
            return;
        }

        foreach ($this->request as $request) {
            $worker_handler_id = $request->getWorkerHandlerId();

            if ($this->hasWorkerHandler($worker_handler_id)) {
                continue;
            }

            $this->addWorkerQueue($request->getId());

            $this->getLogger()->info(
                sprintf(
                    'WorkerHandler %s for request %s is expired. '.
                    'Therefore, the request is queued again.',
                    $worker_handler_id,
                    $request->getId()
                )
            );
        }
    }

    /**
     * Returns the Request with the given ID.
     *
     * @param string $id
     *
     * @return null|Request
     */
    public function getRequest($id)
    {
        if (!isset($this->request[$id])) {
            return null;
        }

        return $this->request[$id];
    }

    /**
     * Add a request with the given ID.
     *
     * @param Request $request
     *
     * @return ClientHandler
     */
    public function addRequest(Request $request)
    {
        $this->request[$request->getId()] = $request;

        return $this;
    }

    /**
     * Remove the given request.
     *
     * @param string $requestId
     *
     * @return $this
     */
    public function removeRequest($requestId)
    {
        if (isset($this->request[$requestId])) {
            unset($this->request[$requestId]);
        }

        return $this;
    }

    /**
     * Add a request ID to the WorkerHandler queue.
     *
     * @param string $requestId
     *
     * @return ClientHandler
     */
    public function addWorkerQueue($requestId)
    {
        $this->workerHandlerQueue[$requestId] = $requestId;

        return $this;
    }

    /**
     * Returns a list of Clients for the given Request.
     *
     * @param string $requestId
     *
     * @return Client[]
     */
    public function getClientsForRequest($requestId)
    {
        return $this->clients->getClientsForRequest($requestId);
    }

    /**
     * Returns the client with the given ID.
     *
     * @param string $id
     *
     * @return Client
     */
    public function client($id)
    {
        return $this->clients->client($id);
    }

    /**
     * Remove the given Client.
     *
     * @param Client $client
     */
    public function remove(Client $client)
    {
        $this->clients->remove($client);
    }

    /**
     * Send the given message to the Client.
     *
     * @param Client           $client
     * @param MessageInterface $msg
     */
    public function reply(Client $client, MessageInterface $msg)
    {
        $this->getStream('client')->send($msg, $client->getId());
        $this->remove($client);
    }

    /**
     * Set the Logger
     *
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->getLogger()->info('ClientHandler is started with pid '.getmypid());
    }

    /**
     * Returns the Logger
     *
     * @return LoggerInterface
     */
    public function getLogger()
    {
        if (null === $this->logger) {
            $this->logger = new NullLogger();
        }

        return $this->logger;
    }
}
