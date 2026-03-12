<?php

declare(strict_types=1);

namespace vosaka\wsock\connection;

use vosaka\wsock\frame\OpCode;

/**
 * Represents a complete, reassembled WebSocket message.
 *
 * Unlike a {@see Frame}, a Message is a full logical unit of data
 * (potentially assembled from multiple continuation frames).
 *
 * Messages are immutable once created.
 */
final class Message {
	public function __construct(
		/** The opcode of the first frame: TEXT or BINARY. */
		public readonly OpCode $type,
		/** Full payload bytes after reassembly and unmasking. */
		public readonly string $payload,
		/** Connection ID that sent this message. */
		public readonly string $connectionId,
		/** Unix timestamp (with microsecond precision) when assembled. */
		public readonly float  $receivedAt,
	) {
	}


	public static function text(string $text, string $connId): self {
		return new self(OpCode::TEXT, $text, $connId, microtime(true));
	}

	public static function binary(string $data, string $connId): self {
		return new self(OpCode::BINARY, $data, $connId, microtime(true));
	}


	/** Returns true when this is a text message. */
	public function isText(): bool {
		return $this->type === OpCode::TEXT;
	}

	/** Returns true when this is a binary message. */
	public function isBinary(): bool {
		return $this->type === OpCode::BINARY;
	}

	/** Returns payload length in bytes. */
	public function length(): int {
		return strlen($this->payload);
	}

	/**
	 * Decodes the payload as JSON and returns the result.
	 *
	 * @param bool $assoc  When true, objects are decoded as associative arrays.
	 * @return mixed       Decoded value.
	 * @throws \JsonException On invalid JSON.
	 */
	public function json(bool $assoc = true): mixed {
		return json_decode($this->payload, $assoc, flags: JSON_THROW_ON_ERROR);
	}
}
