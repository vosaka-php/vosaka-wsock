<?php

declare(strict_types=1);

namespace vosaka\wsock\exception;

/**
 * Thrown when the HTTP upgrade handshake cannot be completed.
 */
class HandshakeException extends WsockException {
	public static function malformedRequest(string $detail): self {
		return new self("Malformed WebSocket upgrade request: $detail");
	}

	public static function invalidMethod(string $method): self {
		return new self("WebSocket upgrade requires GET; received $method.");
	}

	public static function missingHeader(string $header): self {
		return new self("Required header missing or invalid: $header");
	}

	public static function invalidSecKey(string $key): self {
		return new self("Invalid Sec-WebSocket-Key: '$key' (must decode to 16 bytes).");
	}

	public static function unsupportedVersion(string $version): self {
		return new self("Unsupported Sec-WebSocket-Version: '$version' (only 13 is supported).");
	}

	public static function rejected(string $reason): self {
		return new self("Handshake rejected: $reason");
	}
}
