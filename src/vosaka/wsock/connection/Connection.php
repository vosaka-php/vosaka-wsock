<?php

declare(strict_types=1);

namespace vosaka\wsock\connection;

use vosaka\wsock\codec\FrameCodec;
use vosaka\wsock\codec\MessageAssembler;
use vosaka\wsock\frame\Frame;
use vosaka\wsock\frame\CloseCode;
use vosaka\wsock\frame\OpCode;
use vosaka\wsock\exception\ConnectionException;
use vosaka\wsock\handshake\UpgradeRequest;
use vosaka\foroutines\AsyncIO;

/**
 * Represents an established WebSocket connection on the server side.
 *
 * Each Connection instance owns:
 *  - A raw socket resource managed by {@see AsyncIO}.
 *  - A {@see FrameCodec} for encoding/decoding frames.
 *  - A {@see MessageAssembler} for reassembling fragmented messages.
 *  - Per-connection metadata (ID, state, attributes, upgrade info).
 *
 * All I/O methods are non-blocking: they use {@see AsyncIO::streamRead()} and
 * {@see AsyncIO::streamWrite()}, which suspend the current Fiber until the
 * underlying socket is ready, yielding control back to the scheduler.
 *
 * Thread safety: A Connection must not be shared across Fibers concurrently.
 * Use Channels or Flows to pass messages between Fibers.
 */
final class Connection {
	/** Current lifecycle state. */
	private ConnectionState $state = ConnectionState::CONNECTING;

	/** Raw socket resource. */
	private mixed $socket;

	/** Unique identifier for this connection (UUID-like string). */
	public readonly string $id;

	/** Timestamp when the connection was accepted (unix microtime). */
	public readonly float $connectedAt;

	/** User-defined metadata bag (e.g. authenticated user, session data). */
	private array $attributes = [];

	/** Internal read buffer for partial frame data. */
	private string $readBuffer = '';

	private readonly FrameCodec       $codec;
	private readonly MessageAssembler $assembler;

	public function __construct(
		mixed                         $socket,
		/** Parsed HTTP upgrade request (headers, path, subprotocol, etc.). */
		public readonly UpgradeRequest $upgradeRequest,
		/** Negotiated subprotocol (null if none). */
		public readonly ?string        $subprotocol = null,
		/** Maximum payload size in bytes (configurable per server). */
		private readonly int           $maxPayloadSize = Frame::DEFAULT_MAX_PAYLOAD,
	) {
		$this->socket      = $socket;
		$this->id          = $this->generateId();
		$this->connectedAt = microtime(true);
		$this->codec       = new FrameCodec();
		$this->assembler   = new MessageAssembler();
	}


	public function getState(): ConnectionState {
		return $this->state;
	}

	/** Marks the connection as fully open (called after handshake completes). */
	public function markOpen(): void {
		$this->state = ConnectionState::OPEN;
	}

	/** Returns true if data frames may be sent. */
	public function isOpen(): bool {
		return $this->state === ConnectionState::OPEN;
	}


	/**
	 * Sends a UTF-8 text message.
	 *
	 * Suspends the current Fiber via AsyncIO until the socket is writable.
	 *
	 * @throws ConnectionException When the connection is not open.
	 */
	public function sendText(string $text): void {
		$this->sendFrame(Frame::text($text));
	}

	/**
	 * Sends a binary message.
	 *
	 * @throws ConnectionException When the connection is not open.
	 */
	public function sendBinary(string $data): void {
		$this->sendFrame(Frame::binary($data));
	}

	/**
	 * Encodes an array/object as JSON and sends it as a text message.
	 *
	 * @throws ConnectionException When the connection is not open.
	 * @throws \JsonException      On serialisation failure.
	 */
	public function sendJson(mixed $data, int $flags = JSON_THROW_ON_ERROR): void {
		$this->sendText(json_encode($data, $flags));
	}

	/**
	 * Sends a Ping frame; the remote end should respond with a Pong.
	 */
	public function ping(string $payload = ''): void {
		$this->sendFrame(Frame::ping($payload));
	}

	/**
	 * Sends a Pong frame (typically in response to a received Ping).
	 */
	public function pong(string $payload = ''): void {
		$this->sendFrame(Frame::pong($payload));
	}

	/**
	 * Initiates the closing handshake.
	 *
	 * Sends a Close frame and transitions to CLOSING state.
	 * The connection moves to CLOSED once the remote Close frame is received
	 * or the socket is fully shut down.
	 */
	public function close(?CloseCode $code = CloseCode::NORMAL, string $reason = ''): void {
		if ($this->state === ConnectionState::CLOSED) return;

		$this->sendFrame(Frame::close($code, $reason));
		$this->state = ConnectionState::CLOSING;
	}

	/**
	 * Low-level: encodes and writes a single Frame to the socket.
	 *
	 * @throws ConnectionException When the connection is not open or write fails.
	 */
	public function sendFrame(Frame $frame): void {
		if (!$this->state->canSend() && !$frame->opCode->isControl()) {
			throw ConnectionException::sendOnClosed($this->id);
		}

		$bytes = $this->codec->encode($frame);

		try {
			AsyncIO::streamWrite($this->socket, $bytes)->await();
		} catch (\Throwable $e) {
			throw ConnectionException::writeFailed($this->id, $e->getMessage());
		}
	}


	/**
	 * Reads and returns the next complete WebSocket message.
	 *
	 * Suspends the Fiber between reads via {@see AsyncIO::streamRead()}.
	 * Returns null when the connection is closed cleanly by the remote end.
	 *
	 * @return Message|null  The next message, or null on clean close.
	 * @throws ConnectionException On I/O errors.
	 */
	public function receive(): ?Message {
		while (true) {
			// Try to decode a complete frame from the buffered bytes.
			$result = $this->tryDecodeFrame();

			if ($result !== null) {
				[$opCode, $payload] = $result;

				// Handle control frames internally.
				if ($opCode === OpCode::PING) {
					$this->pong($payload);
					continue;
				}

				if ($opCode === OpCode::PONG) {
					// Pong received — update keep-alive tracking if needed.
					continue;
				}

				if ($opCode === OpCode::CLOSE) {
					$this->handleRemoteClose();
					return null;
				}

				return new Message($opCode, $payload, $this->id, microtime(true));
			}

			// Need more bytes — read from socket (suspends Fiber).
			$chunk = $this->readChunk();
			if ($chunk === null) {
				// Remote closed the TCP connection without a Close frame.
				$this->state = ConnectionState::CLOSED;
				return null;
			}

			$this->readBuffer .= $chunk;
		}
	}


	/** Stores an arbitrary value under the given key (e.g. authenticated user). */
	public function setAttribute(string $key, mixed $value): void {
		$this->attributes[$key] = $value;
	}

	/** Retrieves a stored attribute value, or $default if not set. */
	public function getAttribute(string $key, mixed $default = null): mixed {
		return $this->attributes[$key] ?? $default;
	}

	/** Returns all stored attributes. */
	public function getAttributes(): array {
		return $this->attributes;
	}


	/**
	 * Tries to decode one complete frame from the internal read buffer.
	 *
	 * Returns [OpCode, payload] when a complete message is assembled,
	 * or null when more bytes are needed.
	 */
	private function tryDecodeFrame(): ?array {
		while ($this->readBuffer !== '') {
			$decoded = $this->codec->decode($this->readBuffer, $this->maxPayloadSize);

			if ($decoded === null) {
				return null; // Need more bytes.
			}

			[$frame, $consumed] = $decoded;
			$this->readBuffer = substr($this->readBuffer, $consumed);

			$message = $this->assembler->push($frame);
			if ($message !== null) {
				return $message;
			}
		}

		return null;
	}

	/**
	 * Reads a chunk of bytes from the socket via AsyncIO (non-blocking).
	 *
	 * Returns null when the remote end has closed the connection.
	 */
	private function readChunk(): ?string {
		try {
			$data = AsyncIO::streamRead($this->socket, 8192)->await();
			return ($data === '' || $data === false) ? null : $data;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Handles a received Close frame: echoes it back and updates state.
	 */
	private function handleRemoteClose(): void {
		if ($this->state === ConnectionState::OPEN) {
			// Echo back the close frame (RFC 6455 §5.5.1).
			$this->sendFrame(Frame::close());
		}
		$this->state = ConnectionState::CLOSED;
	}

	/** Generates a simple unique connection ID. */
	private function generateId(): string {
		return sprintf(
			'%04x%04x-%04x-4%03x-%04x-%012x',
			random_int(0, 0xFFFF),
			random_int(0, 0xFFFF),
			random_int(0, 0xFFFF),
			random_int(0, 0x0FFF),
			random_int(0x8000, 0xBFFF),
			random_int(0, 0xFFFFFFFFFFFF),
		);
	}
}
