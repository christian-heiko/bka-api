<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/**
 * An image could not be read, or carries an extension the API will not accept.
 *
 * Extends \InvalidArgumentException, which is a \Exception, so callers already
 * catching \Exception around Image::makeFromPath() keep working.
 */
class InvalidImageException extends \InvalidArgumentException implements BkaException {

    public static function unreadable(string $path): self {
        return new self("Could not read image \"$path\".");
    }

    /** @param list<string> $allowed */
    public static function unsupportedExtension(string $extension, array $allowed): self {
        return new self(
            "Unsupported file extension \"$extension\". Should be one of " . implode(', ', $allowed) . '.'
        );
    }

}
