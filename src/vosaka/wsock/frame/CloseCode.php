<?php

declare(strict_types=1);

namespace vosaka\wsock\frame;

/**
 * WebSocket close status codes as defined in RFC 6455 Section 7.4.
 *
 * Status codes are sent in the first two bytes of a Close frame payload.
 * Codes 3000–3999 are reserved for libraries/frameworks.
 * Codes 4000–4999 are reserved for private use.
 */
enum CloseCode: int {
	/** Normal closure; the connection successfully completed its purpose. */
	case NORMAL = 1000;

	/** Endpoint is going away (server shutdown or browser navigation). */
	case GOING_AWAY = 1001;

	/** Protocol error encountered. */
	case PROTOCOL_ERROR = 1002;

	/** Received data of a type the endpoint cannot accept. */
	case UNSUPPORTED_DATA = 1003;

	/** Reserved; must not be set as a status code in a Close frame. */
	case NO_STATUS_RECEIVED = 1005;

	/** Reserved; used internally when connection closed abnormally. */
	case ABNORMAL_CLOSURE = 1006;

	/** Received text data that is not valid UTF-8. */
	case INVALID_PAYLOAD = 1007;

	/** Received a message that violates policy. */
	case POLICY_VIOLATION = 1008;

	/** Received a message too large to process. */
	case MESSAGE_TOO_BIG = 1009;

	/** Server did not negotiate a required extension. */
	case MISSING_EXTENSION = 1010;

	/** Server encountered an unexpected error. */
	case INTERNAL_ERROR = 1011;

	/** Server restarting; client should reconnect. */
	case SERVICE_RESTART = 1012;

	/** Server temporarily unavailable (try again later). */
	case TRY_AGAIN_LATER = 1013;

	/** Library-level: message rate limit exceeded. */
	case RATE_LIMITED = 3000;

	/** Library-level: authentication failed. */
	case UNAUTHORIZED = 3001;

	/** Library-level: connection idle timeout reached. */
	case IDLE_TIMEOUT = 3002;
}
