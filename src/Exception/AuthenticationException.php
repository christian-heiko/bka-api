<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/** The access token could not be refreshed — usually an expired or revoked refresh token. */
class AuthenticationException extends \RuntimeException implements BkaException {
}
