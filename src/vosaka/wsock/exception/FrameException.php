<?php

declare(strict_types=1);

namespace vosaka\wsock\exception;

use vosaka\wsock\frame\OpCode;

/**
 * Thrown when a WebSocket frame violates the RFC 6455 framing rules.
 */
class FrameException extends WsockException {
	public static function controlPayloadTooLarge(int $actual): self {
		return new self(
			"Control frame payload must be ≤ 125 bytes; got $actual bytes."
		);
	}

	public static function controlFrameFragmented(OpCode $opCode): self {
		return new self(
			"Control frame (opcode={$opCode->name}) must not be fragmented (FIN=false)."
		);
	}

	public static function invalidMaskingKey(): self {
		return new self('Masked frame must have exactly 4 masking-key bytes.');
	}

	public static function unknownOpCode(int $value): self {
		return new self(sprintf('Unknown WebSocket opcode 0x%X.', $value));
	}

	public static function payloadTooLarge(int $actual, int $max): self {
		return new self(
			"Frame payload ($actual bytes) exceeds the configured maximum ($max bytes)."
		);
	}

	public static function unexpectedContinuation(): self {
		return new self(
			'Received a CONTINUATION frame without a preceding non-final data frame.'
		);
	}

	public static function unexpectedDataFrame(OpCode $opCode): self {
		return new self(
			"Received a new {$opCode->name} frame while a fragmented message was still pending."
		);
	}
}
