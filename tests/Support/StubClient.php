<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\Support;

use ChristianHeiko\Bka\Client;

/**
 * Replaces the one network call the auth flow makes that a MockHandler cannot
 * intercept — refreshAccessToken() builds its own Guzzle instance.
 *
 * This is why Client::refreshAccessToken() is protected rather than private.
 */
final class StubClient extends Client {

    public int $refreshes = 0;

    /** Options the real implementation would have passed to the token client. */
    public array $tokenOptions = [];

    /** Exposes the protected refresh for tests that assert on it directly. */
    public function forceRefresh(): string {
        return $this->refreshAccessToken();
    }

    protected function refreshAccessToken(): string {
        $this->refreshes++;
        $this->tokenOptions = $this->tokenClientOptions();
        $this->accessToken = Jwt::valid('refreshed-' . $this->refreshes);

        if (!is_null($this->onTokenRefresh)) {
            ($this->onTokenRefresh)($this->accessToken, $this->refreshToken);
        }

        return $this->accessToken;
    }

}
