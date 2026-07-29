<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/**
 * The API answered with something this client cannot make sense of — a non-JSON
 * body (a proxy error page, say) or an envelope missing its `data` property.
 */
class InvalidResponseException extends \RuntimeException implements BkaException {

    public static function notJson(string $body, ?\Throwable $previous = null): self {
        return new self(
            'API response was not valid JSON. First 200 bytes: ' . substr($body, 0, 200),
            previous: $previous
        );
    }

    public static function missingData(string $endpoint): self {
        return new self("API response for \"$endpoint\" contained no `data` property.");
    }

}
