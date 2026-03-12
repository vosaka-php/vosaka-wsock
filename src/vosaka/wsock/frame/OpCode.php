<?php

declare(strict_types=1);

namespace vosaka\wsock\frame;

/**
 * WebSocket frame opcodes as defined in RFC 6455 Section 5.2.
 *
 * Opcodes determine the interpretation of the payload data.
 * Values 0x3–0x7 and 0xB–0xF are reserved for future use.
 */
enum OpCode: int {
	/** Continuation frame – payload is a fragment of a previous message */
	case CONTINUATION = 0x0;

	/** Text frame – payload is UTF-8 encoded text */
	case TEXT = 0x1;

	/** Binary frame – payload is arbitrary binary data */
	case BINARY = 0x2;

	/** Connection close frame – initiates or acknowledges close handshake */
	case CLOSE = 0x8;

	/** Ping frame – used for keep-alive / round-trip measurement */
	case PING = 0x9;

	/** Pong frame – response to a Ping frame */
	case PONG = 0xA;

	/**
	 * Returns true if this opcode represents a control frame.
	 *
	 * Control frames (Close, Ping, Pong) must not be fragmented
	 * and must have a payload ≤ 125 bytes per RFC 6455.
	 */
	public function isControl(): bool {
		return match ($this) {
			self::CLOSE, self::PING, self::PONG => true,
			default => false,
		};
	}

	/**
	 * Returns true if this opcode represents a data frame.
	 */
	public function isData(): bool {
		return match ($this) {
			self::TEXT, self::BINARY, self::CONTINUATION => true,
			default => false,
		};
	}
}
