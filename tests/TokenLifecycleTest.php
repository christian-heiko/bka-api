<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests;

use ChristianHeiko\Bka\Tests\Support\EventFactory;
use ChristianHeiko\Bka\Tests\Support\Jwt;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * The API expires access tokens after an hour, so the client refreshes on a local
 * expiry check and replays once on a 401.
 */
final class TokenLifecycleTest extends TestCase {

    #[Test]
    public function the_constructor_performs_no_network_io(): void {
        $client = $this->client(accessToken: Jwt::expired());

        self::assertSame(0, $client->refreshes, 'Construction must stay side-effect-free.');
    }

    #[Test]
    public function an_expired_token_is_refreshed_before_the_request_goes_out(): void {
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'data' => []])], accessToken: Jwt::expired());
        $client->regions();

        self::assertSame(1, $client->refreshes);
    }

    #[Test]
    public function a_401_is_refreshed_and_replayed_once(): void {
        $client = $this->client([
            $this->jsonResponse(401, ['code' => 401, 'message' => 'Expired']),
            $this->jsonResponse(200, ['code' => 200, 'data' => [['id' => 1]]]),
        ]);
        $original = $client->accessToken;

        $data = $client->events();

        self::assertSame(1, $data[0]->id);
        self::assertSame(1, $client->refreshes);
        self::assertSame(0, $this->mock->count(), 'Both queued responses should have been consumed.');
        self::assertSame(
            'Bearer ' . $client->accessToken,
            $this->mock->getLastRequest()->getHeaderLine('Authorization')
        );
        self::assertNotSame('Bearer ' . $original, $this->mock->getLastRequest()->getHeaderLine('Authorization'));
    }

    #[Test]
    public function a_repeated_401_surfaces_instead_of_looping(): void {
        $client = $this->client([
            $this->jsonResponse(401, ['code' => 401]),
            $this->jsonResponse(401, ['code' => 401]),
        ]);

        $this->expectException(ClientException::class);

        try {
            $client->events();
        } finally {
            self::assertSame(1, $client->refreshes, 'Exactly one refresh attempt, then give up.');
        }
    }

    #[Test]
    public function a_replayed_post_resends_its_body(): void {
        // A retry must not send an empty payload: the first attempt may have
        // consumed the body stream, which matters for payloads over 1MB where
        // Guzzle streams the body rather than materialising it as a string.
        $client = $this->client([
            $this->jsonResponse(401, ['code' => 401]),
            $this->jsonResponse(201, ['code' => 201, 'data' => ['id' => 9]]),
        ]);

        $created = $client->addEvent(EventFactory::complete());

        $body = (string)$this->mock->getLastRequest()->getBody();

        self::assertSame(9, $created->id);
        self::assertNotSame('', $body);
        self::assertSame('Some Party Title', json_decode($body, true)['name']);
    }

    #[Test]
    public function the_refresh_callback_receives_the_new_credentials(): void {
        $seen = [];
        $client = $this->client(
            [$this->jsonResponse(200, ['code' => 200, 'data' => []])],
            onTokenRefresh: function (string $access, string $refresh) use (&$seen): void {
                $seen[] = [$access, $refresh];
            },
            accessToken: Jwt::expired(),
        );

        $client->regions();

        self::assertCount(1, $seen);
        self::assertSame($client->accessToken, $seen[0][0]);
        self::assertSame('refresh-token', $seen[0][1]);
    }

    #[Test]
    public function a_malformed_token_is_invalid_rather_than_fatal(): void {
        // Regression for #14: this used to throw, so a predicate could not be called safely.
        $client = $this->client(accessToken: 'not-a-jwt');

        self::assertFalse($client->isAccessTokenValid());
    }

    #[Test]
    public function a_valid_token_is_reported_valid_and_reused(): void {
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'data' => []])]);

        self::assertTrue($client->isAccessTokenValid());

        $client->regions();

        self::assertSame(0, $client->refreshes, 'A live token must not trigger a refresh.');
    }

}
