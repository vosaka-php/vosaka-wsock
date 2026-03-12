<?php

declare(strict_types=1);

namespace vosaka\wsock\codec;

use vosaka\wsock\frame\Frame;
use vosaka\wsock\frame\OpCode;
use vosaka\wsock\exception\FrameException;

/**
 * Reassembles a stream of WebSocket frames into complete logical messages.
 *
 * WebSocket messages may be fragmented across multiple frames (RFC 6455 §5.4).
 * The first fragment has a non-CONTINUATION opcode and FIN=false.
 * Subsequent fragments have opcode=CONTINUATION.
 * The last fragment has FIN=true.
 *
 * Control frames (Ping, Pong, Close) are never fragmented and may interleave
 * with data fragments; this assembler passes them through immediately.
 *
 * Usage example:
 *
 *   $assembler = new MessageAssembler();
 *   foreach ($incomingFrames as $frame) {
 *       if ($message = $assembler->push($frame)) {
 *           // $message is a complete [opcode, payload] tuple
 *       }
 *   }
 */
final class MessageAssembler {
	/** @var string|null Accumulated payload of a fragmented message in progress. */
	private ?string $buffer = null;

	/** @var OpCode|null Opcode of the first fragment (TEXT or BINARY). */
	private ?OpCode $messageType = null;

	/**
	 * Feeds one frame into the assembler.
	 *
	 * @param Frame $frame Incoming frame from the wire.
	 *
	 * @return array{OpCode, string}|null
	 *     A [opCode, fullPayload] tuple when a complete message is ready,
	 *     or null when still waiting for more fragments.
	 *
	 * @throws FrameException On illegal fragmentation sequences.
	 */
	public function push(Frame $frame): ?array {
		// Control frames are never fragmented; pass through immediately.
		if ($frame->opCode->isControl()) {
			return [$frame->opCode, $frame->payload];
		}

		if ($frame->opCode === OpCode::CONTINUATION) {
			// A continuation without a preceding start frame is a protocol error.
			if ($this->buffer === null) {
				throw FrameException::unexpectedContinuation();
			}
			$this->buffer .= $frame->payload;
		} else {
			// New data frame — must not arrive while a fragmented message is pending.
			if ($this->buffer !== null) {
				throw FrameException::unexpectedDataFrame($frame->opCode);
			}
			$this->messageType = $frame->opCode;
			$this->buffer      = $frame->payload;
		}

		if ($frame->fin) {
			// Message complete — flush and reset state.
			$opCode  = $this->messageType;
			$payload = $this->buffer;

			$this->buffer      = null;
			$this->messageType = null;

			return [$opCode, $payload];
		}

		return null; // Still waiting for more fragments.
	}

	/**
	 * Returns true when a fragmented message is currently being assembled.
	 */
	public function isAccumulating(): bool {
		return $this->buffer !== null;
	}

	/**
	 * Resets assembler state (e.g., on connection close).
	 */
	public function reset(): void {
		$this->buffer      = null;
		$this->messageType = null;
	}
}
