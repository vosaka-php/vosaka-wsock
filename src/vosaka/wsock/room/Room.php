<?php

declare(strict_types=1);

namespace vosaka\wsock\room;

use vosaka\wsock\connection\Connection;
use vosaka\wsock\connection\ConnectionRegistry;

/**
 * A Room groups a named set of connections and provides targeted broadcast helpers.
 *
 * Rooms are a thin facade over the {@see ConnectionRegistry} room APIs.
 * They do not hold their own membership list — all state lives in the registry.
 *
 * Typical usage inside a handler:
 *
 *   public function onOpen(Connection $connection): void
 *   {
 *       $room = new Room('lobby', $this->registry);
 *       $room->join($connection);
 *       $room->broadcastText("{$connection->id} joined.");
 *   }
 */
final class Room {
	public function __construct(
		/** Unique name identifying this room. */
		public readonly string             $name,
		private readonly ConnectionRegistry $registry,
	) {
	}


	/** Adds a connection to this room. */
	public function join(Connection $connection): void {
		$this->registry->joinRoom($connection->id, $this->name);
	}

	/** Removes a connection from this room. */
	public function leave(Connection $connection): void {
		$this->registry->leaveRoom($connection->id, $this->name);
	}

	/** Returns true when the given connection is a member. */
	public function has(Connection $connection): bool {
		return in_array($connection->id, $this->memberIds(), true);
	}

	/** Returns all current member connections. */
	public function members(): array {
		return $this->registry->getRoom($this->name);
	}

	/** Returns all current member connection IDs. */
	public function memberIds(): array {
		return array_map(fn(Connection $c) => $c->id, $this->members());
	}

	/** Returns the number of members currently in this room. */
	public function count(): int {
		return count($this->members());
	}

	/** Returns true when the room has no members. */
	public function isEmpty(): bool {
		return $this->count() === 0;
	}


	/**
	 * Sends a text message to all members except the given exclusion list.
	 *
	 * @param string   $text    UTF-8 text message.
	 * @param string[] $exclude Connection IDs to skip (e.g. the sender).
	 */
	public function broadcastText(string $text, array $exclude = []): void {
		foreach ($this->members() as $conn) {
			if (!in_array($conn->id, $exclude, true) && $conn->isOpen()) {
				$conn->sendText($text);
			}
		}
	}

	/**
	 * Sends a binary message to all members except the given exclusion list.
	 */
	public function broadcastBinary(string $data, array $exclude = []): void {
		foreach ($this->members() as $conn) {
			if (!in_array($conn->id, $exclude, true) && $conn->isOpen()) {
				$conn->sendBinary($data);
			}
		}
	}

	/**
	 * Encodes $data as JSON and broadcasts it as a text message.
	 *
	 * @throws \JsonException On serialisation failure.
	 */
	public function broadcastJson(mixed $data, array $exclude = [], int $flags = JSON_THROW_ON_ERROR): void {
		$json = json_encode($data, $flags);
		$this->broadcastText($json, $exclude);
	}
}
