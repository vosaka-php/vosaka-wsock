<?php

declare(strict_types=1);

namespace vosaka\wsock\server;

use vosaka\wsock\connection\Connection;
use vosaka\wsock\connection\Message;
use vosaka\wsock\contract\WebSocketHandlerInterface;
use vosaka\wsock\frame\CloseCode;
use vosaka\wsock\handshake\UpgradeRequest;

/**
 * Convenience base class with no-op implementations of {@see WebSocketHandlerInterface}.
 *
 * Extend this class and override only the callbacks you need:
 *
 *   class ChatHandler extends AbstractWebSocketHandler
 *   {
 *       public function onMessage(Connection $connection, Message $message): void
 *       {
 *           // broadcast to everyone
 *           $this->registry->broadcastText($message->payload, exclude: [$connection->id]);
 *       }
 *   }
 */
abstract class AbstractWebSocketHandler implements WebSocketHandlerInterface {
	/**
	 * Called when a new connection is established.
	 * Default implementation does nothing.
	 */
	public function onOpen(Connection $connection): void {
	}

	/**
	 * Called when a message is received.
	 * Default implementation echoes the message back to the sender.
	 */
	public function onMessage(Connection $connection, Message $message): void {
		if ($message->isText()) {
			$connection->sendText($message->payload);
		} else {
			$connection->sendBinary($message->payload);
		}
	}

	/**
	 * Called when a connection closes.
	 * Default implementation does nothing.
	 */
	public function onClose(Connection $connection, CloseCode $code, string $reason): void {
	}

	/**
	 * Called when an error occurs on a connection.
	 * Default implementation logs the error to stderr.
	 */
	public function onError(Connection $connection, \Throwable $error): void {
		fwrite(STDERR, sprintf(
			"[wsock] Error on connection %s: %s\n",
			$connection->id,
			$error->getMessage(),
		));
	}

	/**
	 * Called during the WebSocket handshake.
	 * Default implementation accepts all connections.
	 */
	public function onHandshake(UpgradeRequest $request): bool {
		return true;
	}
}
