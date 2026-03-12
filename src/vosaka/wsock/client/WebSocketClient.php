<?php

declare(strict_types=1);

namespace vosaka\wsock\client;

use vosaka\wsock\codec\FrameCodec;
use vosaka\wsock\codec\MessageAssembler;
use vosaka\wsock\connection\Message;
use vosaka\wsock\exception\HandshakeException;
use vosaka\wsock\frame\Frame;
use vosaka\wsock\frame\CloseCode;
use vosaka\wsock\frame\OpCode;
use vosaka\foroutines\AsyncIO;

/**
 * Asynchronous WebSocket client.
 *
 * Connects to a WebSocket server and enables sending/receiving messages
 * from within the foroutines cooperative scheduler.
 *
 * All I/O operations use {@see AsyncIO}, suspending only the calling Fiber.
 *
 * Example:
 *
 *   use function vosaka\foroutines\main;
 *   use vosaka\foroutines\{RunBlocking, Launch, Thread};
 *
 *   main(function () {
 *       RunBlocking::new(function () {
 *           Launch::new(function () {
 *               $client = WebSocketClient::connect('ws://echo.websocket.org');
 *               $client->sendText('Hello!');
 *               $msg = $client->receive();
 *               var_dump($msg?->payload);
 *               $client->close();
 *           });
 *           Thread::wait();
 *       });
 *   });
 */
final class WebSocketClient {
	private const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	private mixed $socket;
	private bool  $closed     = false;
	private string $readBuffer = '';

	private readonly FrameCodec       $codec;
	private readonly MessageAssembler $assembler;

	private function __construct(mixed $socket) {
		$this->socket    = $socket;
		$this->codec     = new FrameCodec();
		$this->assembler = new MessageAssembler();
	}


	/**
	 * Establishes a WebSocket connection to the given URL.
	 *
	 * Supports ws:// and wss:// schemes.
	 *
	 * @param string   $url          WebSocket URL (e.g. "ws://localhost:9000/chat").
	 * @param array    $headers      Extra HTTP headers to include in the upgrade request.
	 * @param string[] $subprotocols Subprotocols to offer (Sec-WebSocket-Protocol).
	 * @param int      $timeoutMs    TCP connection timeout in milliseconds.
	 *
	 * @throws \InvalidArgumentException On unsupported URL scheme.
	 * @throws HandshakeException        On failed server handshake.
	 * @throws \RuntimeException         On connection failure.
	 */
	public static function connect(
		string $url,
		array  $headers      = [],
		array  $subprotocols = [],
		int    $timeoutMs    = 5000,
	): self {
		$parts  = parse_url($url);
		$scheme = strtolower($parts['scheme'] ?? 'ws');
		$host   = $parts['host'] ?? 'localhost';
		$port   = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
		$path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

		$socket = match ($scheme) {
			'ws'  => AsyncIO::tcpConnect($host, $port, $timeoutMs),
			'wss' => AsyncIO::tlsConnect($host, $port, $timeoutMs),
			default => throw new \InvalidArgumentException("Unsupported WebSocket scheme: $scheme"),
		};

		$client = new self($socket->await());
		$client->performHandshake($host, $port, $path, $headers, $subprotocols);

		return $client;
	}


	/**
	 * Sends a UTF-8 text message.
	 * Client frames are masked as required by RFC 6455 §5.3.
	 */
	public function sendText(string $text): void {
		$this->sendFrame(Frame::text($text, masked: true, maskingKey: $this->randomMask()));
	}

	/**
	 * Sends a binary message (client-masked).
	 */
	public function sendBinary(string $data): void {
		$this->sendFrame(Frame::binary($data, masked: true, maskingKey: $this->randomMask()));
	}

	/**
	 * Encodes $data as JSON and sends it as a masked text message.
	 *
	 * @throws \JsonException On serialisation failure.
	 */
	public function sendJson(mixed $data, int $flags = JSON_THROW_ON_ERROR): void {
		$this->sendText(json_encode($data, $flags));
	}

	/**
	 * Sends a Ping frame (automatically responded to by a compliant server).
	 */
	public function ping(string $payload = ''): void {
		$this->sendFrame(Frame::ping($payload));
	}

	/**
	 * Initiates the close handshake.
	 */
	public function close(?CloseCode $code = CloseCode::NORMAL, string $reason = ''): void {
		if ($this->closed) return;
		$this->sendFrame(Frame::close($code, $reason));
		$this->closed = true;
	}


	/**
	 * Reads the next complete message from the server.
	 *
	 * Returns null when the server has closed the connection.
	 * Automatically handles Ping/Pong and Close frames.
	 */
	public function receive(): ?Message {
		while (true) {
			$result = $this->tryDecodeFrame();

			if ($result !== null) {
				[$opCode, $payload] = $result;

				if ($opCode === OpCode::PING) {
					$this->sendFrame(Frame::pong($payload));
					continue;
				}

				if ($opCode === OpCode::PONG) {
					continue; // Round-trip latency tracking could go here.
				}

				if ($opCode === OpCode::CLOSE) {
					// Echo back the close frame and mark as closed.
					$this->sendFrame(Frame::close());
					$this->closed = true;
					return null;
				}

				return new Message($opCode, $payload, 'client', microtime(true));
			}

			// Need more bytes — suspend the Fiber until data arrives.
			$chunk = AsyncIO::streamRead($this->socket, 8192)->await();
			if ($chunk === '' || $chunk === false) {
				$this->closed = true;
				return null;
			}

			$this->readBuffer .= $chunk;
		}
	}

	/** Returns true when the connection has been closed. */
	public function isClosed(): bool {
		return $this->closed;
	}


	/**
	 * Performs the HTTP/1.1 WebSocket upgrade handshake as a client.
	 */
	private function performHandshake(
		string $host,
		int    $port,
		string $path,
		array  $extraHeaders,
		array  $subprotocols,
	): void {
		// Generate a random 16-byte nonce, base64-encoded.
		$nonce = base64_encode(random_bytes(16));

		$request  = "GET $path HTTP/1.1\r\n";
		$request .= "Host: $host:$port\r\n";
		$request .= "Upgrade: websocket\r\n";
		$request .= "Connection: Upgrade\r\n";
		$request .= "Sec-WebSocket-Key: $nonce\r\n";
		$request .= "Sec-WebSocket-Version: 13\r\n";

		if ($subprotocols !== []) {
			$request .= 'Sec-WebSocket-Protocol: ' . implode(', ', $subprotocols) . "\r\n";
		}

		foreach ($extraHeaders as $name => $value) {
			$request .= "$name: $value\r\n";
		}

		$request .= "\r\n";

		AsyncIO::streamWrite($this->socket, $request)->await();

		// Read the server's response headers.
		$response = '';
		while (!str_contains($response, "\r\n\r\n")) {
			$chunk = AsyncIO::streamRead($this->socket, 4096)->await();
			if ($chunk === '' || $chunk === false) {
				throw new \RuntimeException('Server closed connection during handshake.');
			}
			$response .= $chunk;
		}

		// Validate the response.
		if (!str_contains($response, '101')) {
			throw HandshakeException::malformedRequest('Server did not respond with 101 Switching Protocols.');
		}

		$expectedAccept = base64_encode(sha1($nonce . self::HANDSHAKE_GUID, binary: true));
		if (!str_contains($response, $expectedAccept)) {
			throw HandshakeException::malformedRequest('Invalid Sec-WebSocket-Accept header from server.');
		}
	}

	private function sendFrame(Frame $frame): void {
		AsyncIO::streamWrite($this->socket, $this->codec->encode($frame))->await();
	}

	private function tryDecodeFrame(): ?array {
		while ($this->readBuffer !== '') {
			$decoded = $this->codec->decode($this->readBuffer);
			if ($decoded === null) return null;

			[$frame, $consumed]   = $decoded;
			$this->readBuffer     = substr($this->readBuffer, $consumed);
			$message              = $this->assembler->push($frame);
			if ($message !== null) return $message;
		}
		return null;
	}

	/** Generates a cryptographically random 4-byte masking key. */
	private function randomMask(): array {
		$bytes = random_bytes(4);
		return [ord($bytes[0]), ord($bytes[1]), ord($bytes[2]), ord($bytes[3])];
	}
}
