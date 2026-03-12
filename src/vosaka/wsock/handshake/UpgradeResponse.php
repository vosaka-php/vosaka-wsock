<?php

declare(strict_types=1);

namespace vosaka\wsock\handshake;

/**
 * Builds the HTTP 101 Switching Protocols response for a WebSocket upgrade.
 *
 * Per RFC 6455 §4.2.2, the server must:
 *  1. Respond with HTTP/1.1 101 Switching Protocols.
 *  2. Include "Upgrade: websocket" and "Connection: Upgrade".
 *  3. Include Sec-WebSocket-Accept: base64(SHA-1(key + GUID)).
 *  4. Optionally include a negotiated subprotocol and extensions.
 */
final class UpgradeResponse {
	/** Magic GUID appended to the client's Sec-WebSocket-Key (RFC 6455 §1.3). */
	private const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	/**
	 * Computes the Sec-WebSocket-Accept value from a client's key.
	 *
	 * @param string $clientKey  Raw value of the Sec-WebSocket-Key header.
	 * @return string            Base64-encoded SHA-1 digest.
	 */
	public static function computeAccept(string $clientKey): string {
		return base64_encode(sha1($clientKey . self::HANDSHAKE_GUID, binary: true));
	}

	/**
	 * Builds the full HTTP 101 response string ready to be written to the socket.
	 *
	 * @param UpgradeRequest $request     The parsed client request.
	 * @param string|null    $subprotocol Negotiated subprotocol to echo back,
	 *                                   or null to omit the header.
	 * @param array          $extensions  Negotiated extensions (e.g. 'permessage-deflate').
	 * @param array          $extraHeaders Additional response headers to append.
	 *
	 * @return string  The raw HTTP response bytes (including trailing \r\n\r\n).
	 */
	public static function build(
		UpgradeRequest $request,
		?string        $subprotocol = null,
		array          $extensions  = [],
		array          $extraHeaders = [],
	): string {
		$accept = self::computeAccept($request->secKey);

		$headers  = "HTTP/1.1 101 Switching Protocols\r\n";
		$headers .= "Upgrade: websocket\r\n";
		$headers .= "Connection: Upgrade\r\n";
		$headers .= "Sec-WebSocket-Accept: $accept\r\n";

		if ($subprotocol !== null) {
			$headers .= "Sec-WebSocket-Protocol: $subprotocol\r\n";
		}

		if ($extensions !== []) {
			$headers .= 'Sec-WebSocket-Extensions: ' . implode(', ', $extensions) . "\r\n";
		}

		foreach ($extraHeaders as $name => $value) {
			$headers .= "$name: $value\r\n";
		}

		$headers .= "\r\n"; // Blank line terminates headers.

		return $headers;
	}

	/**
	 * Builds an HTTP error response (for failed handshakes).
	 *
	 * @param int    $code    HTTP status code (e.g. 400, 403).
	 * @param string $reason  HTTP reason phrase.
	 * @param string $body    Optional response body.
	 */
	public static function buildError(int $code, string $reason, string $body = ''): string {
		$length   = strlen($body);
		$response = "HTTP/1.1 $code $reason\r\n";
		$response .= "Content-Length: $length\r\n";
		$response .= "Content-Type: text/plain\r\n";
		$response .= "Connection: close\r\n";
		$response .= "\r\n";
		$response .= $body;
		return $response;
	}
}
