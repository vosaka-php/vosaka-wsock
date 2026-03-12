<?php

declare(strict_types=1);

namespace vosaka\wsock\codec;

use vosaka\wsock\frame\Frame;
use vosaka\wsock\frame\OpCode;
use vosaka\wsock\exception\FrameException;

/**
 * Encodes and decodes WebSocket frames to/from the RFC 6455 wire format.
 *
 * This class is intentionally stateless. Fragmented-message reassembly
 * is handled at the higher {@see MessageAssembler} layer.
 *
 * Wire format (RFC 6455 §5.2):
 *
 *  Byte 0:  FIN(1) RSV1(1) RSV2(1) RSV3(1) OPCODE(4)
 *  Byte 1:  MASK(1) PAYLOAD_LEN(7)
 *  Bytes 2–9: Extended payload length (if PAYLOAD_LEN is 126 or 127)
 *  Bytes N–N+3: Masking key (if MASK bit is set)
 *  Bytes M–end: (Masked) payload data
 *
 *  See also: https://github.com/websockets/ws/blob/master/doc/ws.md
 */
final class FrameCodec {
	/**
	 * Encodes a Frame object into its binary wire representation.
	 *
	 * @param Frame $frame  The frame to encode.
	 * @return string       The raw bytes to write to the socket.
	 */
	public function encode(Frame $frame): string {
		$payload = $frame->payload;
		$length  = strlen($payload);

		$byte0  = ($frame->fin  ? 0x80 : 0x00);
		$byte0 |= ($frame->rsv1 ? 0x40 : 0x00);
		$byte0 |= ($frame->rsv2 ? 0x20 : 0x00);
		$byte0 |= ($frame->rsv3 ? 0x10 : 0x00);
		$byte0 |= $frame->opCode->value;

		$maskBit = $frame->masked ? 0x80 : 0x00;

		if ($length <= 125) {
			$header = pack('CC', $byte0, $maskBit | $length);
		} elseif ($length <= 65535) {
			$header = pack('CCn', $byte0, $maskBit | 126, $length);
		} else {
			// 8-byte extended length; PHP's pack does not support 64-bit 'J'
			// so we split into high/low 32-bit words.
			$high   = ($length >> 32) & 0xFFFFFFFF;
			$low    = $length & 0xFFFFFFFF;
			$header = pack('CCNN', $byte0, $maskBit | 127, $high, $low);
		}

		if ($frame->masked) {
			$key    = $frame->maskingKey;
			$header .= pack('CCCC', $key[0], $key[1], $key[2], $key[3]);
			$payload = $this->applyMask($payload, $key);
		}

		return $header . $payload;
	}

	/**
	 * Attempts to decode one frame from a raw byte buffer.
	 *
	 * Returns a decoded Frame and the number of bytes consumed from $buffer,
	 * or null when the buffer does not yet contain a complete frame.
	 *
	 * @param string $buffer        Raw bytes received from the socket.
	 * @param int    $maxPayload    Maximum allowed payload size in bytes.
	 *
	 * @return array{Frame, int}|null  [frame, bytesConsumed] or null.
	 * @throws FrameException When the frame violates the protocol.
	 */
	public function decode(string $buffer, int $maxPayload = Frame::DEFAULT_MAX_PAYLOAD): ?array {
		$len = strlen($buffer);

		// Need at least 2 bytes for the base header.
		if ($len < 2) {
			return null;
		}

		$byte0 = ord($buffer[0]);
		$byte1 = ord($buffer[1]);

		$fin    = (bool) ($byte0 & 0x80);
		$rsv1   = (bool) ($byte0 & 0x40);
		$rsv2   = (bool) ($byte0 & 0x20);
		$rsv3   = (bool) ($byte0 & 0x10);
		$opBits = $byte0 & 0x0F;

		$opCode = OpCode::tryFrom($opBits)
			?? throw FrameException::unknownOpCode($opBits);

		$masked     = (bool) ($byte1 & 0x80);
		$payloadLen = $byte1 & 0x7F;

		$offset = 2;

		if ($payloadLen === 126) {
			if ($len < $offset + 2) return null;
			$payloadLen = unpack('n', substr($buffer, $offset, 2))[1];
			$offset += 2;
		} elseif ($payloadLen === 127) {
			if ($len < $offset + 8) return null;
			[$high, $low] = array_values(unpack('NN', substr($buffer, $offset, 8)));
			$payloadLen = ($high << 32) | $low;
			$offset += 8;
		}

		if ($payloadLen > $maxPayload) {
			throw FrameException::payloadTooLarge($payloadLen, $maxPayload);
		}

		$maskingKey = [];
		if ($masked) {
			if ($len < $offset + 4) return null;
			$maskingKey = array_values(unpack('C4', substr($buffer, $offset, 4)));
			$offset += 4;
		}

		if ($len < $offset + $payloadLen) {
			return null; // Incomplete frame; wait for more data.
		}

		$payload = substr($buffer, $offset, $payloadLen);
		$offset += $payloadLen;

		if ($masked) {
			$payload = $this->applyMask($payload, $maskingKey);
		}

		$frame = new Frame($opCode, $fin, $masked, $payload, $maskingKey, $rsv1, $rsv2, $rsv3);

		return [$frame, $offset];
	}


	/**
	 * Applies (or removes) the XOR masking transformation to a payload.
	 *
	 * The same function is used for both masking and unmasking because
	 * XOR is its own inverse.
	 *
	 * @param string $data Raw payload bytes.
	 * @param int[]  $key  4-byte masking key as an integer array.
	 * @return string      Transformed bytes.
	 */
	private function applyMask(string $data, array $key): string {
		$len    = strlen($data);
		$result = '';
		for ($i = 0; $i < $len; $i++) {
			$result .= chr(ord($data[$i]) ^ $key[$i % 4]);
		}
		return $result;
	}
}
