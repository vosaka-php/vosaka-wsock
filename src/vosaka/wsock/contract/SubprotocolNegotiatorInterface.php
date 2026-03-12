<?php

declare(strict_types=1);

namespace vosaka\wsock\contract;

/**
 * Interface for subprotocol negotiators.
 *
 * Subprotocols allow the server to advertise and negotiate application-level
 * protocols (e.g., "graphql-ws", "v2.stomp").
 */
interface SubprotocolNegotiatorInterface {
	/**
	 * Selects the best subprotocol from the client's offered list.
	 *
	 * @param list<string> $offered  Subprotocols the client proposed.
	 * @return string|null           The selected protocol, or null to accept without one.
	 */
	public function negotiate(array $offered): ?string;
}
