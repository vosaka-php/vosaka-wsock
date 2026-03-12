<?php

declare(strict_types=1);

namespace vosaka\wsock\internal;

use vosaka\wsock\connection\Connection;
use vosaka\wsock\contract\MiddlewareInterface;
use vosaka\wsock\frame\CloseCode;

/**
 * Example middleware that validates a Bearer token from the HTTP upgrade
 * request's Authorization header.
 *
 * This class demonstrates the {@see MiddlewareInterface} contract.
 * Replace the token-validation logic with your own auth mechanism
 * (JWT verification, database lookup, session cookie check, etc.).
 *
 * Usage:
 *
 *   $server->addMiddleware(new BearerTokenMiddleware(
 *       validator: fn(string $token) => MyAuth::verify($token),
 *   ));
 */
final class BearerTokenMiddleware implements MiddlewareInterface {
	/**
	 * @param callable(string): mixed $validator
	 *   A callable that receives a Bearer token string and returns a truthy
	 *   user/payload on success, or null/false to reject the connection.
	 */
	public function __construct(
		private readonly \Closure $validator,
		private readonly string   $attribute = 'auth_user',
	) {
	}

	public function process(Connection $connection, callable $next): void {
		$authorization = $connection->upgradeRequest->headers['authorization'] ?? '';
		$token         = '';

		if (str_starts_with($authorization, 'Bearer ')) {
			$token = substr($authorization, 7);
		}

		// Also check ?token= query parameter as a fallback (common for browser WS).
		if ($token === '') {
			parse_str($connection->upgradeRequest->queryString, $query);
			$token = $query['token'] ?? '';
		}

		$user = ($this->validator)($token);

		if (!$user) {
			$connection->close(CloseCode::UNAUTHORIZED, 'Authentication required.');
			return;
		}

		$connection->setAttribute($this->attribute, $user);

		$next($connection);
	}
}
