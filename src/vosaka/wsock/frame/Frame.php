<?php

declare(strict_types=1);

namespace vosaka\wsock\frame;

use vosaka\wsock\exception\FrameException;

/**
 * Immutable value object representing a single WebSocket frame.
 *
 * A frame is the atomic unit of the WebSocket framing protocol (RFC 6455 §5).
 * Messages may be split across multiple frames (fragmentation). This class
 * represents one such frame, not a complete logical message.
 *
 * Constraints enforced at construction time:
 *  - Control frames must have payload ≤ 125 bytes and must not be fragmented.
 *  - Payload length must not exceed the configured maximum.
 */
final class Frame {
	/** Maximum payload size for control frames (RFC 6455 §5.5). */
	public const MAX_CONTROL_PAYLOAD = 125;

	/** Default maximum data-frame payload (16 MB). Configurable per server. */
	public const DEFAULT_MAX_PAYLOAD = 16_777_216;

	/**
	 * @param OpCode $opCode     Frame type identifier.
	 * @param bool   $fin        True when this is the final fragment of a message.
	 * @param bool   $masked     True when the payload is masked (client → server).
	 * @param string $payload    Raw (already unmasked) payload bytes.
	 * @param int[]  $maskingKey 4-byte masking key; empty array when unmasked.
	 * @param bool   $rsv1       Reserved bit 1 (used by permessage-deflate).
	 * @param bool   $rsv2       Reserved bit 2 (currently unused).
	 * @param bool   $rsv3       Reserved bit 3 (currently unused).
	 *
	 * @throws FrameException When frame constraints are violated.
	 */
	public function __construct(
		public readonly OpCode $opCode,
		public readonly bool   $fin,
		public readonly bool   $masked,
		public readonly string $payload,
		public readonly array  $maskingKey = [],
		public readonly bool   $rsv1 = false,
		public readonly bool   $rsv2 = false,
		public readonly bool   $rsv3 = false,
	) {
		if ($opCode->isControl()) {
			if (strlen($payload) > self::MAX_CONTROL_PAYLOAD) {
				throw FrameException::controlPayloadTooLarge(strlen($payload));
			}
			if (!$fin) {
				throw FrameException::controlFrameFragmented($opCode);
			}
		}

		if ($masked && count($maskingKey) !== 4) {
			throw FrameException::invalidMaskingKey();
		}
	}


	/**
	 * Creates a final, unmasked text frame.
	 */
	public static function text(string $text, bool $masked = false, array $maskingKey = []): self {
		return new self(OpCode::TEXT, fin: true, masked: $masked, payload: $text, maskingKey: $maskingKey);
	}

	/**
	 * Creates a final, unmasked binary frame.
	 */
	public static function binary(string $data, bool $masked = false, array $maskingKey = []): self {
		return new self(OpCode::BINARY, fin: true, masked: $masked, payload: $data, maskingKey: $maskingKey);
	}

	/**
	 * Creates a Ping frame with an optional payload (≤ 125 bytes).
	 */
	public static function ping(string $payload = ''): self {
		return new self(OpCode::PING, fin: true, masked: false, payload: $payload);
	}

	/**
	 * Creates a Pong frame responding to a Ping.
	 */
	public static function pong(string $payload = ''): self {
		return new self(OpCode::PONG, fin: true, masked: false, payload: $payload);
	}

	/**
	 * Creates a Close frame with an optional status code and reason.
	 *
	 * @param CloseCode|null $code   Status code to include in the payload.
	 * @param string         $reason Human-readable UTF-8 reason (max 123 bytes after the 2-byte code).
	 */
	public static function close(?CloseCode $code = null, string $reason = ''): self {
		$payload = '';
		if ($code !== null) {
			$payload = pack('n', $code->value) . $reason;
		}
		return new self(OpCode::CLOSE, fin: true, masked: false, payload: $payload);
	}


	/** Returns the payload length in bytes. */
	public function payloadLength(): int {
		return strlen($this->payload);
	}

	/** Returns true if this is a continuation fragment (not the final frame). */
	public function isContinuation(): bool {
		return $this->opCode === OpCode::CONTINUATION;
	}

	/**
	 * Extracts the close status code from the payload, if present.
	 */
	public function closeCode(): ?CloseCode {
		if ($this->opCode !== OpCode::CLOSE || strlen($this->payload) < 2) {
			return null;
		}
		$code = unpack('n', substr($this->payload, 0, 2))[1] ?? null;
		return $code !== null ? CloseCode::tryFrom($code) : null;
	}

	/**
	 * Extracts the close reason string from the payload, if present.
	 */
	public function closeReason(): string {
		if ($this->opCode !== OpCode::CLOSE || strlen($this->payload) < 3) {
			return '';
		}
		return substr($this->payload, 2);
	}
}
