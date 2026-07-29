<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\Support;

/** Builds unsigned JWTs; the client only ever reads the `exp` claim locally. */
final class Jwt {

    public static function make(int $expiresAt, string $marker = 'initial'): string {
        return self::segment(['alg' => 'HS256', 'typ' => 'JWT'])
            . '.' . self::segment(['exp' => $expiresAt, 'jti' => $marker])
            . '.signature';
    }

    public static function valid(string $marker = 'initial'): string {
        return self::make(time() + 3600, $marker);
    }

    public static function expired(): string {
        return self::make(time() - 60, 'expired');
    }

    private static function segment(array $claims): string {
        return rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

}
