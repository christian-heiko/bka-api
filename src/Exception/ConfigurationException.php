<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/** The client was built with missing or unusable configuration. */
class ConfigurationException extends \RuntimeException implements BkaException {

    public static function missingEnv(string $variable): self {
        return new self("Environment variable \"$variable\" is not set or empty.");
    }

}
