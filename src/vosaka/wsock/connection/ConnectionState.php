<?php

declare(strict_types=1);

namespace vosaka\wsock\connection;

/**
 * Lifecycle states of a single WebSocket connection.
 *
 * Transitions:
 *   CONNECTING → OPEN → CLOSING → CLOSED
 *
 * A connection can only move forward through these states, never backward.
 */
enum ConnectionState {
	/** TCP connected, HTTP upgrade handshake in progress. */
	case CONNECTING;

	/** Handshake complete; frames can be sent and received. */
	case OPEN;

	/** Close frame sent or received; awaiting the reciprocal close frame. */
	case CLOSING;

	/** Connection is fully closed; the socket has been released. */
	case CLOSED;

	/** Returns true when the connection can send/receive data frames. */
	public function canSend(): bool {
		return $this === self::OPEN;
	}

	/** Returns true when the connection is no longer usable. */
	public function isClosed(): bool {
		return $this === self::CLOSED;
	}
}
