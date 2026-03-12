<?php

declare(strict_types=1);

namespace vosaka\wsock\exception;

/**
 * Thrown when the WebSocket server itself encounters an error.
 */
class ServerException extends WsockException {
	public static function bindFailed(string $host, int $port, string $detail = ''): self {
		$addr = "$host:$port";
		return new self($detail !== '' ? "Failed to bind $addr: $detail" : "Failed to bind $addr");
	}

	public static function alreadyRunning(): self {
		return new self('Server is already running.');
	}
}
