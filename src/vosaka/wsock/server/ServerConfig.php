<?php

declare(strict_types=1);

namespace vosaka\wsock\server;

use vosaka\wsock\frame\Frame;

/**
 * Immutable configuration for a {@see WebSocketServer}.
 *
 * All durations are in milliseconds unless otherwise noted.
 * Use the fluent builder methods to create customised instances.
 */
final class ServerConfig {
	public function __construct(
		/** Address to bind (e.g. "0.0.0.0" or "127.0.0.1"). */
		public readonly string $host = '0.0.0.0',

		/** TCP port to listen on. */
		public readonly int $port = 8080,

		/**
		 * Maximum payload size in bytes for a single WebSocket frame.
		 * Frames exceeding this limit will cause the connection to be closed
		 * with CloseCode::MESSAGE_TOO_BIG.
		 */
		public readonly int $maxPayloadSize = Frame::DEFAULT_MAX_PAYLOAD,

		/**
		 * Maximum size of the incoming TCP buffer per connection (bytes).
		 * This caps memory usage per connection when reading large messages.
		 */
		public readonly int $readBufferSize = 8192,

		/**
		 * Idle connection timeout in seconds.
		 * Connections that neither send nor receive within this window are
		 * closed with CloseCode::IDLE_TIMEOUT. Set to 0 to disable.
		 */
		public readonly int $idleTimeoutSec = 60,

		/**
		 * Ping interval in seconds.
		 * When > 0, the server sends a Ping frame to every idle connection
		 * at this interval to keep TCP alive.
		 */
		public readonly int $pingIntervalSec = 30,

		/**
		 * Maximum concurrent connections.
		 * New connections are refused (TCP accepted then immediately closed)
		 * when this limit is reached. Set to 0 for unlimited.
		 */
		public readonly int $maxConnections = 0,

		/**
		 * Accepted subprotocols (e.g. ['chat', 'json']).
		 * When the client requests a subprotocol not in this list, the
		 * handshake is accepted without a subprotocol header. When empty,
		 * all subprotocols are accepted and echoed as-is.
		 */
		public readonly array $subprotocols = [],

		/**
		 * TLS context options for stream_context_create().
		 * When non-empty, the server socket is wrapped with TLS (wss://).
		 * Example:
		 *   ['local_cert' => '/path/to/cert.pem', 'local_pk' => '/path/to/key.pem']
		 */
		public readonly array $tlsContext = [],

		/**
		 * Extra HTTP response headers added to every successful upgrade response.
		 * Useful for CORS or custom headers.
		 */
		public readonly array $extraHeaders = [],

		/**
		 * When true, the server logs frame-level debug information.
		 * Do not enable in production.
		 */
		public readonly bool $debug = false,
	) {
	}


	public function withHost(string $host): self {
		return new self(...array_merge($this->toArray(), ['host' => $host]));
	}

	public function withPort(int $port): self {
		return new self(...array_merge($this->toArray(), ['port' => $port]));
	}

	public function withMaxPayloadSize(int $bytes): self {
		return new self(...array_merge($this->toArray(), ['maxPayloadSize' => $bytes]));
	}

	public function withIdleTimeout(int $seconds): self {
		return new self(...array_merge($this->toArray(), ['idleTimeoutSec' => $seconds]));
	}

	public function withPingInterval(int $seconds): self {
		return new self(...array_merge($this->toArray(), ['pingIntervalSec' => $seconds]));
	}

	public function withMaxConnections(int $max): self {
		return new self(...array_merge($this->toArray(), ['maxConnections' => $max]));
	}

	public function withSubprotocols(array $protocols): self {
		return new self(...array_merge($this->toArray(), ['subprotocols' => $protocols]));
	}

	public function withTls(array $ctx): self {
		return new self(...array_merge($this->toArray(), ['tlsContext' => $ctx]));
	}

	public function withDebug(bool $debug = true): self {
		return new self(...array_merge($this->toArray(), ['debug' => $debug]));
	}

	/** Checks whether TLS is configured. */
	public function isTls(): bool {
		return $this->tlsContext !== [];
	}

	/** Returns the bind URI string for stream_socket_server(). */
	public function bindUri(): string {
		$scheme = $this->isTls() ? 'ssl' : 'tcp';
		return "{$scheme}://{$this->host}:{$this->port}";
	}

	private function toArray(): array {
		return [
			'host'           => $this->host,
			'port'           => $this->port,
			'maxPayloadSize' => $this->maxPayloadSize,
			'readBufferSize' => $this->readBufferSize,
			'idleTimeoutSec' => $this->idleTimeoutSec,
			'pingIntervalSec' => $this->pingIntervalSec,
			'maxConnections' => $this->maxConnections,
			'subprotocols'   => $this->subprotocols,
			'tlsContext'     => $this->tlsContext,
			'extraHeaders'   => $this->extraHeaders,
			'debug'          => $this->debug,
		];
	}
}
