<?php

declare(strict_types=1);

namespace vosaka\wsock\exception;

/**
 * Thrown when a connection-level error occurs (I/O failure, timeout, etc.).
 */
class ConnectionException extends WsockException {
	public static function writeFailed(string $connId, string $detail = ''): self {
		$msg = "Write failed on connection $connId";
		return new self($detail !== '' ? "$msg: $detail" : $msg);
	}

	public static function readFailed(string $connId, string $detail = ''): self {
		$msg = "Read failed on connection $connId";
		return new self($detail !== '' ? "$msg: $detail" : $msg);
	}

	public static function alreadyClosed(string $connId): self {
		return new self("Connection $connId is already closed.");
	}

	public static function sendOnClosed(string $connId): self {
		return new self("Cannot send on closed connection $connId.");
	}
}
