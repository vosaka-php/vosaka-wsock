<?php

declare(strict_types=1);

namespace vosaka\wsock\contract;

use vosaka\wsock\connection\Connection;

/**
 * Optional ping/pong handler for connections that need keep-alive tracking.
 *
 * Implement alongside {@see WebSocketHandlerInterface} to receive callbacks
 * when Ping and Pong frames are exchanged.
 */
interface PingPongHandlerInterface {
	/**
	 * Called when a Ping frame is received from the client.
	 *
	 * The server automatically sends a Pong response; this callback is
	 * informational (e.g., for latency tracking).
	 *
	 * @param Connection $connection  The connection that sent the Ping.
	 * @param string     $payload     Ping payload (up to 125 bytes).
	 */
	public function onPing(Connection $connection, string $payload): void;

	/**
	 * Called when a Pong frame is received from the client.
	 *
	 * @param Connection $connection  The connection that responded.
	 * @param string     $payload     Pong payload (echoed from the Ping).
	 */
	public function onPong(Connection $connection, string $payload): void;
}
