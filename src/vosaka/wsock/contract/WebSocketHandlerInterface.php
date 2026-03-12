<?php

declare(strict_types=1);

namespace vosaka\wsock\contract;

use vosaka\wsock\connection\Connection;
use vosaka\wsock\connection\Message;
use vosaka\wsock\frame\CloseCode;
use vosaka\wsock\handshake\UpgradeRequest;

/**
 * Handler interface for WebSocket server lifecycle events.
 *
 * Implement this interface and pass it to {@see \vosaka\wsock\Server\WebSocketServer}
 * to receive callbacks for connection, message, and error events.
 *
 * All callbacks are invoked from within a Fiber on the DEFAULT dispatcher,
 * so they may call AsyncIO methods, Channel sends, or use Delay without
 * leaving the structured-concurrency context.
 */
interface WebSocketHandlerInterface {
	/**
	 * Called when a new client connects and the HTTP upgrade handshake succeeds.
	 *
	 * This is the right place to:
	 *  - Authenticate the connection (check cookies/headers via $connection->upgradeRequest).
	 *  - Set connection attributes ($connection->setAttribute('user', $user)).
	 *  - Join the connection to one or more rooms.
	 *  - Send a welcome message.
	 *
	 * @param Connection $connection  The newly opened connection.
	 */
	public function onOpen(Connection $connection): void;

	/**
	 * Called when a complete WebSocket message is received.
	 *
	 * @param Connection $connection  The connection that sent the message.
	 * @param Message    $message     The received message (text or binary).
	 */
	public function onMessage(Connection $connection, Message $message): void;

	/**
	 * Called when a connection is cleanly closed (either side initiated the close handshake).
	 *
	 * @param Connection   $connection  The closed connection.
	 * @param CloseCode    $code        The negotiated close status code.
	 * @param string       $reason      Optional human-readable reason.
	 */
	public function onClose(Connection $connection, CloseCode $code, string $reason): void;

	/**
	 * Called when an unexpected error occurs on a connection.
	 *
	 * After this callback returns, the connection is forcibly closed.
	 *
	 * @param Connection $connection  The affected connection.
	 * @param \Throwable $error       The exception that caused the error.
	 */
	public function onError(Connection $connection, \Throwable $error): void;

	/**
	 * Called before the HTTP upgrade response is sent, allowing the handler to
	 * accept, reject, or modify the handshake.
	 *
	 * Return true to accept the connection, false to reject it (the server will
	 * respond with HTTP 403 Forbidden).
	 *
	 * This is the ideal place to validate Origin headers, check API keys,
	 * or apply rate-limiting before a WebSocket connection is established.
	 *
	 * @param UpgradeRequest $request  The parsed HTTP upgrade request.
	 * @return bool  True to accept; false to reject.
	 */
	public function onHandshake(UpgradeRequest $request): bool;
}
