<?php

declare(strict_types=1);

namespace vosaka\wsock\exception;

/**
 * Base exception for all vosaka/wsock errors.
 *
 * Catching this type covers all library-specific errors.
 */
class WsockException extends \RuntimeException {
}
