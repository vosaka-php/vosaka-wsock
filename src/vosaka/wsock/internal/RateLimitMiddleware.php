<?php

declare(strict_types=1);

namespace vosaka\wsock\internal;

use vosaka\wsock\connection\Connection;
use vosaka\wsock\contract\MiddlewareInterface;
use vosaka\wsock\frame\CloseCode;

/**
 * Middleware that limits the number of WebSocket connections per IP address.
 *
 * Useful for preventing connection exhaustion attacks. The limit is enforced
 * at the point of connection (before onOpen) and is maintained in-process.
 *
 * For multi-process deployments, replace the in-memory counter with a shared
 * APCu or Redis counter to enforce the limit across all worker processes.
 *
 * Usage:
 *
 *   $server->addMiddleware(new RateLimitMiddleware(maxPerIp: 10));
 */
final class RateLimitMiddleware implements MiddlewareInterface {
	/** @var array<string, int> IP → active connection count */
	private static array $counters = [];

	public function __construct(
		/** Maximum concurrent connections allowed per unique IP address. */
		private readonly int $maxPerIp = 5,
	) {
	}

	public function process(Connection $connection, callable $next): void {
		// Extract IP without port (e.g. "127.0.0.1" from "127.0.0.1:54321").
		$remoteAddr = $connection->upgradeRequest->remoteAddr;
		$ip         = strtok($remoteAddr, ':');

		$count = self::$counters[$ip] ?? 0;

		if ($count >= $this->maxPerIp) {
			$connection->close(
				CloseCode::RATE_LIMITED,
				"Too many connections from $ip (max: {$this->maxPerIp}).",
			);
			return;
		}

		self::$counters[$ip] = $count + 1;

		try {
			$next($connection);
		} finally {
			// Decrement on disconnect regardless of how the connection ended.
			self::$counters[$ip] = max(0, (self::$counters[$ip] ?? 1) - 1);
			if (self::$counters[$ip] === 0) {
				unset(self::$counters[$ip]);
			}
		}
	}
}
