<?php

declare(strict_types=1);

namespace vosaka\wsock\connection;

use vosaka\wsock\frame\CloseCode;

/**
 * Central registry of all currently active {@see Connection} instances.
 *
 * The registry is used by the server and Room layer to look up, broadcast to,
 * and iterate over connections. It is a single-process, in-memory store.
 *
 * Access is intentionally synchronous (no Fiber/Channel overhead) because
 * the server runs all connection handling within the DEFAULT dispatcher —
 * the registry is always accessed from one Fiber at a time via the
 * cooperative scheduler.
 */
final class ConnectionRegistry {
	/** @var array<string, Connection> */
	private array $connections = [];

	/** @var array<string, list<string>> room → [connectionIds] */
	private array $rooms = [];


	/** Adds a connection to the registry. */
	public function add(Connection $connection): void {
		$this->connections[$connection->id] = $connection;
	}

	/** Removes a connection and cleans up all room memberships. */
	public function remove(string $id): void {
		unset($this->connections[$id]);

		foreach ($this->rooms as $room => &$members) {
			$members = array_values(array_filter($members, fn($cid) => $cid !== $id));
			if ($members === []) {
				unset($this->rooms[$room]);
			}
		}
	}

	/** Returns a connection by ID, or null if not found. */
	public function get(string $id): ?Connection {
		return $this->connections[$id] ?? null;
	}

	/** Returns true when the given connection ID is registered. */
	public function has(string $id): bool {
		return isset($this->connections[$id]);
	}

	/** Returns the total number of active connections. */
	public function count(): int {
		return count($this->connections);
	}

	/** Returns all active connections as an iterable. */
	public function all(): iterable {
		yield from $this->connections;
	}


	/**
	 * Sends a text message to every open connection except the given exclusion list.
	 *
	 * @param string   $text    UTF-8 text to send.
	 * @param string[] $exclude Connection IDs to skip.
	 */
	public function broadcastText(string $text, array $exclude = []): void {
		foreach ($this->connections as $id => $conn) {
			if (!in_array($id, $exclude, true) && $conn->isOpen()) {
				$conn->sendText($text);
			}
		}
	}

	/**
	 * Sends a binary message to every open connection except the given exclusion list.
	 */
	public function broadcastBinary(string $data, array $exclude = []): void {
		foreach ($this->connections as $id => $conn) {
			if (!in_array($id, $exclude, true) && $conn->isOpen()) {
				$conn->sendBinary($data);
			}
		}
	}

	/**
	 * Closes all connections with the given close code.
	 */
	public function closeAll(?CloseCode $code = CloseCode::GOING_AWAY, string $reason = 'Server shutting down'): void {
		foreach ($this->connections as $conn) {
			if ($conn->isOpen()) {
				$conn->close($code, $reason);
			}
		}
		$this->connections = [];
		$this->rooms       = [];
	}


	/** Adds a connection to a named room. */
	public function joinRoom(string $connectionId, string $room): void {
		if (!isset($this->rooms[$room])) {
			$this->rooms[$room] = [];
		}
		if (!in_array($connectionId, $this->rooms[$room], true)) {
			$this->rooms[$room][] = $connectionId;
		}
	}

	/** Removes a connection from a named room. */
	public function leaveRoom(string $connectionId, string $room): void {
		if (!isset($this->rooms[$room])) return;

		$this->rooms[$room] = array_values(
			array_filter($this->rooms[$room], fn($id) => $id !== $connectionId)
		);

		if ($this->rooms[$room] === []) {
			unset($this->rooms[$room]);
		}
	}

	/**
	 * Returns all Connection objects that are members of the given room.
	 *
	 * @return Connection[]
	 */
	public function getRoom(string $room): array {
		$members = $this->rooms[$room] ?? [];
		return array_values(
			array_filter(
				array_map(fn($id) => $this->connections[$id] ?? null, $members),
				fn($c) => $c !== null,
			)
		);
	}

	/** Returns the list of room names a connection belongs to. */
	public function getRoomsOf(string $connectionId): array {
		$result = [];
		foreach ($this->rooms as $room => $members) {
			if (in_array($connectionId, $members, true)) {
				$result[] = $room;
			}
		}
		return $result;
	}

	/** Returns all room names that have at least one member. */
	public function roomNames(): array {
		return array_keys($this->rooms);
	}
}
