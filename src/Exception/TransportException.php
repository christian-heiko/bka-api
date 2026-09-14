<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/**
 * A request got no answer: no connection, a timeout, a failed TLS handshake.
 *
 * Unlike an ApiException, this leaves open whether BKA applied a write — a create or update
 * may have gone through even though its response never arrived.
 */
class TransportException extends \RuntimeException implements BkaException {

    public static function during(string $method, string $endpoint, \Throwable $previous): self {
        return new self("$method $endpoint got no answer: " . $previous->getMessage(), 0, $previous);
    }

}
