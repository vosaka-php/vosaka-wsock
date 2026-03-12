<?php

/**
 * Example: Multi-room WebSocket chat server using vennv/vosaka-wsock.
 *
 * Run with:
 *   php examples/chat_server.php
 *
 * Connect from a browser console:
 *   const ws = new WebSocket('ws://localhost:9000/chat');
 *   ws.send(JSON.stringify({ room: 'general', text: 'Hello!' }));
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use vosaka\wsock\connection\Connection;
use vosaka\wsock\connection\ConnectionRegistry;
use vosaka\wsock\connection\Message;
use vosaka\wsock\frame\CloseCode;
use vosaka\wsock\handshake\UpgradeRequest;
use vosaka\wsock\room\Room;
use vosaka\wsock\server\AbstractWebSocketHandler;
use vosaka\wsock\server\ServerConfig;
use vosaka\wsock\server\WebSocketServer;
use vosaka\wsock\internal\RateLimitMiddleware;
use vosaka\foroutines\RunBlocking;
use vosaka\foroutines\Thread;
use function vosaka\foroutines\main;

// ── Chat handler ───────────────────────────────────────────────────────────────

final class ChatHandler extends AbstractWebSocketHandler {
	private readonly ConnectionRegistry $registry;
	private array $rooms = []; // name → Room

	public function __construct(ConnectionRegistry $registry) {
		$this->registry = $registry;
	}

	public function onHandshake(UpgradeRequest $request): bool {
		// Reject connections that don't target /chat.
		return $request->path === '/chat';
	}

	public function onOpen(Connection $connection): void {
		// Place every new connection into the 'general' room by default.
		$this->getRoom('general')->join($connection);

		$connection->sendJson([
			'event' => 'welcome',
			'id'    => $connection->id,
			'rooms' => $this->registry->getRoomsOf($connection->id),
		]);

		echo "[open]  {$connection->id} from {$connection->upgradeRequest->remoteAddr}\n";
	}

	public function onMessage(Connection $connection, Message $message): void {
		if (!$message->isText()) return;

		try {
			$data = $message->json();
		} catch (\JsonException) {
			$connection->sendJson(['event' => 'error', 'message' => 'Invalid JSON.']);
			return;
		}

		$event = $data['event'] ?? 'message';

		match ($event) {
			'join'    => $this->handleJoin($connection, $data),
			'leave'   => $this->handleLeave($connection, $data),
			'message' => $this->handleMessage($connection, $data),
			default   => $connection->sendJson(['event' => 'error', 'message' => "Unknown event: $event"]),
		};
	}

	public function onClose(Connection $connection, CloseCode $code, string $reason): void {
		echo "[close] {$connection->id} ({$code->name})\n";
	}

	// ── Event handlers ────────────────────────────────────────────────────────

	private function handleJoin(Connection $conn, array $data): void {
		$roomName = trim($data['room'] ?? '');
		if ($roomName === '') return;

		$room = $this->getRoom($roomName);
		$room->join($conn);

		$room->broadcastJson([
			'event' => 'joined',
			'id'    => $conn->id,
			'room'  => $roomName,
		]);
	}

	private function handleLeave(Connection $conn, array $data): void {
		$roomName = trim($data['room'] ?? '');
		if ($roomName === '') return;

		$room = $this->getRoom($roomName);
		$room->leave($conn);

		$room->broadcastJson([
			'event' => 'left',
			'id'    => $conn->id,
			'room'  => $roomName,
		]);
	}

	private function handleMessage(Connection $conn, array $data): void {
		$roomName = trim($data['room'] ?? 'general');
		$text     = trim($data['text'] ?? '');
		if ($text === '') return;

		$this->getRoom($roomName)->broadcastJson([
			'event'  => 'message',
			'from'   => $conn->id,
			'room'   => $roomName,
			'text'   => $text,
		]);
	}

	private function getRoom(string $name): Room {
		return $this->rooms[$name] ??= new Room($name, $this->registry);
	}
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

main(function () {
	RunBlocking::new(function () {
		$config = new ServerConfig(
			host: '0.0.0.0',
			port: 9000,
			idleTimeoutSec: 120,
			pingIntervalSec: 30,
			debug: true,
		);

		$server = new WebSocketServer(
			config: $config,
			handler: new ChatHandler($registry = new ConnectionRegistry()),
			registry: $registry,
		);

		// Apply rate-limiting: max 5 concurrent connections per IP.
		$server->addMiddleware(new RateLimitMiddleware(maxPerIp: 5));

		$server->start();

		echo "Chat server started on ws://0.0.0.0:9000/chat\n";

		// Keep the scheduler alive indefinitely.
		Thread::await();
	});
});
