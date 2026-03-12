<?php

declare(strict_types=1);

namespace vosaka\wsock\contract;

use vosaka\wsock\connection\Connection;

/**
 * Optional middleware interface for intercepting connections before they reach
 * the main handler.
 *
 * Middlewares are applied in registration order. Each middleware may:
 *  - Modify the connection (e.g. set attributes).
 *  - Short-circuit and close the connection.
 *  - Pass control to the next middleware via $next().
 */
interface MiddlewareInterface {
	/**
	 * Processes an incoming connection.
	 *
	 * @param Connection $connection  The connection being processed.
	 * @param callable   $next        The next middleware or handler to invoke.
	 */
	public function process(Connection $connection, callable $next): void;
}
