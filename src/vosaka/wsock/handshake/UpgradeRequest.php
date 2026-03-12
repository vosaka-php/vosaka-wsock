<?php

declare(strict_types=1);

namespace vosaka\wsock\handshake;

use vosaka\wsock\exception\HandshakeException;

/**
 * Parses and validates the HTTP/1.1 WebSocket upgrade request (RFC 6455 §4.2.1).
 *
 * A valid upgrade request must contain at minimum:
 *  - GET method with HTTP/1.1
 *  - Upgrade: websocket header
 *  - Connection: Upgrade header
 *  - Sec-WebSocket-Key: <base64, 16 random bytes>
 *  - Sec-WebSocket-Version: 13
 */
final class UpgradeRequest {
	/** The WebSocket version this library supports. */
	public const SUPPORTED_VERSION = '13';

	private function __construct(
		/** HTTP request path (e.g., "/chat"). */
		public readonly string $path,
		/** Host header value. */
		public readonly string $host,
		/** Raw value of the Sec-WebSocket-Key header. */
		public readonly string $secKey,
		/** Client-proposed subprotocols (ordered preference list). */
		public readonly array  $subprotocols,
		/** Client-proposed extensions. */
		public readonly array  $extensions,
		/** All HTTP headers (lowercased names). */
		public readonly array  $headers,
		/** Raw query string (without leading '?'). */
		public readonly string $queryString,
		/** Remote IP address of the connecting client. */
		public readonly string $remoteAddr,
	) {
	}

	/**
	 * Parses a raw HTTP upgrade request from a connected socket stream.
	 *
	 * @param string $rawRequest  The bytes read from the socket before the blank line.
	 * @param string $remoteAddr  IP address of the remote end.
	 *
	 * @throws HandshakeException When the request is not a valid WS upgrade.
	 */
	public static function parse(string $rawRequest, string $remoteAddr = ''): self {
		$lines = explode("\r\n", rtrim($rawRequest));

		if (count($lines) < 1) {
			throw HandshakeException::malformedRequest('Empty request');
		}

		$parts = explode(' ', $lines[0], 3);
		if (count($parts) !== 3) {
			throw HandshakeException::malformedRequest('Invalid request line');
		}

		[$method, $requestUri, $httpVersion] = $parts;

		if (strtoupper($method) !== 'GET') {
			throw HandshakeException::invalidMethod($method);
		}

		if (!str_starts_with($httpVersion, 'HTTP/1.1')) {
			throw HandshakeException::malformedRequest('WebSocket requires HTTP/1.1');
		}

		// Split URI into path and query string.
		$uriParts    = explode('?', $requestUri, 2);
		$path        = $uriParts[0];
		$queryString = $uriParts[1] ?? '';

		$headers = [];
		for ($i = 1; $i < count($lines); $i++) {
			$line = $lines[$i];
			if ($line === '') break;

			$colonPos = strpos($line, ':');
			if ($colonPos === false) continue;

			$name  = strtolower(trim(substr($line, 0, $colonPos)));
			$value = trim(substr($line, $colonPos + 1));
			$headers[$name] = $value;
		}

		$upgrade    = strtolower($headers['upgrade'] ?? '');
		$connection = strtolower($headers['connection'] ?? '');

		if ($upgrade !== 'websocket') {
			throw HandshakeException::missingHeader('Upgrade: websocket');
		}

		if (!str_contains($connection, 'upgrade')) {
			throw HandshakeException::missingHeader('Connection: Upgrade');
		}

		$secKey = $headers['sec-websocket-key'] ?? '';
		if ($secKey === '' || strlen(base64_decode($secKey, true)) !== 16) {
			throw HandshakeException::invalidSecKey($secKey);
		}

		$version = $headers['sec-websocket-version'] ?? '';
		if ($version !== self::SUPPORTED_VERSION) {
			throw HandshakeException::unsupportedVersion($version);
		}

		$host = $headers['host'] ?? '';

		$subprotocols = self::parseCommaList($headers['sec-websocket-protocol'] ?? '');
		$extensions   = self::parseCommaList($headers['sec-websocket-extensions'] ?? '');

		return new self($path, $host, $secKey, $subprotocols, $extensions, $headers, $queryString, $remoteAddr);
	}

	/**
	 * Checks whether the request headers contain a complete HTTP request
	 * (i.e., ends with the blank-line terminator "\r\n\r\n").
	 */
	public static function isComplete(string $buffer): bool {
		return str_contains($buffer, "\r\n\r\n");
	}

	private static function parseCommaList(string $value): array {
		if ($value === '') return [];
		return array_map('trim', explode(',', $value));
	}
}
