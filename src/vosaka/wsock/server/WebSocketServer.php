<?php

declare(strict_types=1);

namespace vosaka\wsock\server;

use vosaka\wsock\connection\Connection;
use vosaka\wsock\connection\ConnectionRegistry;
use vosaka\wsock\contract\WebSocketHandlerInterface;
use vosaka\wsock\contract\MiddlewareInterface;
use vosaka\wsock\exception\ServerException;
use vosaka\wsock\frame\CloseCode;
use vosaka\wsock\handshake\UpgradeRequest;
use vosaka\wsock\handshake\UpgradeResponse;
use vosaka\foroutines\AsyncIO;
use vosaka\foroutines\Launch;
use vosaka\foroutines\Delay;

/**
 * Asynchronous WebSocket server built on top of vosaka-foroutines.
 *
 * Architecture overview:
 *
 *   WebSocketServer::start()
 *     └─ Launch (accept loop)
 *          └─ AsyncIO::tcpConnect (listen socket)
 *          └─ foreach incoming socket:
 *               └─ Launch (per-connection Fiber)
 *                    ├─ Read HTTP upgrade headers via AsyncIO
 *                    ├─ Validate & respond (UpgradeRequest / UpgradeResponse)
 *                    ├─ handler->onOpen()
 *                    └─ loop: conn->receive() → handler->onMessage()
 *                         └─ conn->close() → handler->onClose()
 *
 * Each accepted connection runs in its own Fiber on the DEFAULT dispatcher,
 * so thousands of concurrent connections are handled cooperatively within a
 * single PHP process — no threads or child processes required for I/O.
 *
 * Use {@see ServerConfig} to customise host, port, TLS, timeouts, and limits.
 *
 * Basic usage:
 *
 *   use function vosaka\foroutines\main;
 *   use vosaka\foroutines\RunBlocking;
 *
 *   main(function () {
 *       RunBlocking::new(function () {
 *           $server = new WebSocketServer(
 *               config:  new ServerConfig(port: 9000),
 *               handler: new MyHandler(),
 *           );
 *           $server->start();
 *       });
 *   });
 */
final class WebSocketServer {
	private bool $running = false;

	/** @var list<MiddlewareInterface> */
	private array $middlewares = [];

	private readonly ConnectionRegistry $registry;

	public function __construct(
		private readonly ServerConfig             $config,
		private readonly WebSocketHandlerInterface $handler,
		?ConnectionRegistry                       $registry = null,
	) {
		$this->registry = $registry ?? new ConnectionRegistry();
	}


	/**
	 * Registers a middleware that is applied to every new connection.
	 * Middlewares are executed in the order they are added.
	 */
	public function addMiddleware(MiddlewareInterface $middleware): self {
		$this->middlewares[] = $middleware;
		return $this;
	}

	/**
	 * Returns the connection registry (useful for broadcasting from outside the handler).
	 */
	public function getRegistry(): ConnectionRegistry {
		return $this->registry;
	}

	/**
	 * Starts the server accept loop.
	 *
	 * This method launches a Fiber for the TCP accept loop and returns
	 * immediately — control returns to the foroutines scheduler.
	 *
	 * The server runs until {@see stop()} is called or the process exits.
	 *
	 * @throws ServerException When the socket cannot be bound.
	 */
	public function start(): void {
		if ($this->running) {
			throw ServerException::alreadyRunning();
		}

		$this->running = true;

		$serverSocket = $this->createServerSocket();

		$this->log("WebSocket server listening on {$this->config->bindUri()}");

		// Accept loop runs in its own Fiber so it does not block the scheduler.
		Launch::new(function () use ($serverSocket) {
			$this->acceptLoop($serverSocket);
		});

		// Optional: periodic ping ticker.
		if ($this->config->pingIntervalSec > 0) {
			Launch::new(function () {
				$this->pingLoop();
			});
		}
	}

	/**
	 * Gracefully shuts down the server, closing all connections.
	 */
	public function stop(string $reason = 'Server shutting down'): void {
		$this->running = false;
		$this->registry->closeAll(CloseCode::GOING_AWAY, $reason);
		$this->log("Server stopped: $reason");
	}


	/**
	 * Main accept loop — waits for incoming TCP connections and spawns a Fiber
	 * per connection.
	 *
	 * Uses AsyncIO::streamRead() to yield control between accept() calls so the
	 * cooperative scheduler can service other Fibers.
	 */
	private function acceptLoop(mixed $serverSocket): void {
		while ($this->running) {
			// AsyncIO suspends here until the server socket is readable (new connection).
			$clientSocket = @stream_socket_accept($serverSocket, timeout: 0);

			if ($clientSocket === false) {
				// No connection ready — yield to the scheduler.
				Delay::new(1);
				continue;
			}

			// Add a small delay between connection accepted and handling to avoid
			// some race conditions in PHP on extremely high load.
			Delay::new(1);

			// Enforce max-connections limit.
			if (
				$this->config->maxConnections > 0
				&& $this->registry->count() >= $this->config->maxConnections
			) {
				$this->log('Max connections reached; refusing new connection.');
				fclose($clientSocket);
				continue;
			}

			stream_set_blocking($clientSocket, false);

			// Each connection gets its own Fiber.
			Launch::new(function () use ($clientSocket) {
				$this->handleConnection($clientSocket);
			});
		}
	}


	/**
	 * Manages the full lifecycle of one WebSocket connection.
	 *
	 * Runs inside a dedicated Fiber — all I/O suspends this Fiber only,
	 * not the entire process.
	 */
	private function handleConnection(mixed $socket): void {
		$remoteAddr = (string) stream_socket_get_name($socket, true);
		$connection = null;

		try {
			$request = $this->readUpgradeRequest($socket);

			// Allow the handler to accept or reject the handshake.
			if (!$this->handler->onHandshake($request)) {
				AsyncIO::streamWrite(
					$socket,
					UpgradeResponse::buildError(403, 'Forbidden', 'Connection rejected by handler.'),
				)->await();
				fclose($socket);
				return;
			}

			$subprotocol = $this->negotiateSubprotocol($request);
			$response    = UpgradeResponse::build($request, $subprotocol, extraHeaders: $this->config->extraHeaders);

			AsyncIO::streamWrite($socket, $response)->await();

			$connection = new Connection($socket, $request, $subprotocol, $this->config->maxPayloadSize);
			$connection->markOpen();

			$this->registry->add($connection);

			$this->applyMiddlewares($connection, function (Connection $conn) {
				$this->handler->onOpen($conn);

				$this->messageLoop($conn);
			});
		} catch (\Throwable $e) {
			if ($connection !== null) {
				$this->handler->onError($connection, $e);
				$connection->close(CloseCode::INTERNAL_ERROR);
			} else {
				$this->log("Handshake error from $remoteAddr: " . $e->getMessage());
			}
		} finally {
			if ($connection !== null) {
				$this->registry->remove($connection->id);
			}
			@fclose($socket);
		}
	}

	/**
	 * Continuously receives messages from a connection and dispatches them to
	 * the handler until the connection closes.
	 */
	private function messageLoop(Connection $connection): void {
		$closeCode   = CloseCode::NORMAL;
		$closeReason = '';

		try {
			while (true) {
				$message = $connection->receive();

				if ($message === null) {
					// Connection closed cleanly by the remote end.
					break;
				}

				$this->handler->onMessage($connection, $message);
			}
		} catch (\Throwable $e) {
			$this->handler->onError($connection, $e);
			$closeCode   = CloseCode::INTERNAL_ERROR;
			$closeReason = $e->getMessage();
		} finally {
			$this->handler->onClose($connection, $closeCode, $closeReason);
		}
	}


	/**
	 * Periodically pings all open connections to keep TCP connections alive
	 * and detect dead clients.
	 */
	private function pingLoop(): void {
		while ($this->running) {
			Delay::new($this->config->pingIntervalSec * 1000);

			foreach ($this->registry->all() as $conn) {
				/** @var Connection $conn */
				if ($conn->isOpen()) {
					try {
						$conn->ping();
					} catch (\Throwable) {
						// Dead connection will be caught by the message loop.
					}
				}
			}
		}
	}


	/**
	 * Reads the HTTP upgrade request from a raw socket using AsyncIO.
	 *
	 * Suspends the Fiber until the full HTTP header block is available.
	 *
	 * @throws \RuntimeException On read failure or malformed request.
	 */
	private function readUpgradeRequest(mixed $socket): UpgradeRequest {
		$buffer     = '';
		$remoteAddr = (string) stream_socket_get_name($socket, true);

		while (!UpgradeRequest::isComplete($buffer)) {
			$chunk = AsyncIO::streamRead($socket, 4096)->await();
			if ($chunk === '' || $chunk === false) {
				throw new \RuntimeException("Connection closed before upgrade handshake completed.");
			}
			$buffer .= $chunk;
		}

		// Only pass the headers portion to the parser (before \r\n\r\n).
		$headerEnd = strpos($buffer, "\r\n\r\n");
		$headers   = substr($buffer, 0, $headerEnd);

		return UpgradeRequest::parse($headers, $remoteAddr);
	}

	/**
	 * Selects the best subprotocol from the client's offered list.
	 */
	private function negotiateSubprotocol(UpgradeRequest $request): ?string {
		if ($this->config->subprotocols === []) {
			return $request->subprotocols[0] ?? null;
		}

		foreach ($request->subprotocols as $offered) {
			if (in_array($offered, $this->config->subprotocols, true)) {
				return $offered;
			}
		}

		return null;
	}

	/**
	 * Applies the registered middleware chain to a connection.
	 *
	 * Middlewares are chained in registration order, with $final as the innermost handler.
	 */
	private function applyMiddlewares(Connection $connection, callable $final): void {
		$chain = $final;

		foreach (array_reverse($this->middlewares) as $middleware) {
			$next  = $chain;
			$chain = fn(Connection $c) => $middleware->process($c, $next);
		}

		$chain($connection);
	}

	/**
	 * Creates and binds the server TCP socket.
	 *
	 * @throws ServerException When binding fails.
	 */
	private function createServerSocket(): mixed {
		$context = stream_context_create([
			'socket' => [
				'backlog'      => 511,
				'so_reuseaddr' => true,
				'so_reuseport' => true, // Enables multi-process accept on Linux.
			],
			'ssl' => $this->config->tlsContext,
		]);

		$socket = @stream_socket_server(
			$this->config->bindUri(),
			$errno,
			$errstr,
			STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
			$context,
		);

		if ($socket === false) {
			throw ServerException::bindFailed($this->config->host, $this->config->port, "$errno: $errstr");
		}

		stream_set_blocking($socket, false);

		return $socket;
	}

	private function log(string $message): void {
		if ($this->config->debug) {
			echo '[wsock] ' . date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
		}
	}
}
