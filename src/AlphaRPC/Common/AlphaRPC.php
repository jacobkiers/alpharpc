<?php
/**
 * This file is part of AlphaRPC (http://alpharpc.net/)
 *
 * @license BSD-3 (please see the LICENSE file distributed with this source code.
 * @copyright Copyright (c) 2010-2013, Alphacomm Group B.V. (http://www.alphacomm.nl/)
 *
 * @author Reen Lokum <reen@alphacomm.nl>
 * @package AlphaRPC
 * @subpackage Common
 */

namespace AlphaRPC\Common;

/**
 * @author Reen Lokum <reen@alphacomm.nl>
 * @package AlphaRPC
 * @subpackage Common
 */
class AlphaRPC
{
    /**
     * Maximum time the handlers have to respond to a request
     * before it is considered to be down.
     *
     * NOTE:
     * This is the default value. And will be changed to 100 in the
     * next major release to keep compatibility.
     *
     * You are advised to change the manager_delay in the config to
     * 100ms.
     *
     * After that you also need to configure your client(s) and
     * worker(s) with the setDelay() method.
     */
    const MAX_MANAGER_DELAY = 1000;

    /**
     * Default blocking timeout for a client fetching a response.
     */
    const MAX_CLIENT_TIMEOUT = -1; // Never timeout.

    /**
     * Time between heartbeats from a client to the ClientHandler.
     */
    const CLIENT_PING = 500; // 0.5 seconds.

    /**
     * Time between heartbeats from a WorkerHandler to the ClientHandler.
     */
    const WORKER_HANDLER_TIMEOUT = 1000;

    private function __construct() {}
}
