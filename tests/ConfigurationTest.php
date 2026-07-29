<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests;

use ChristianHeiko\Bka\Client;
use ChristianHeiko\Bka\Exception\ConfigurationException;
use ChristianHeiko\Bka\Tests\Support\Jwt;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\Test;

/** Findings #1, #7, #8, #9 and #10. */
final class ConfigurationTest extends TestCase {

    #[Test]
    public function it_configures_timeouts_by_default(): void {
        // Regression for #1: both were NULL, so a hung API blocked the caller forever.
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'data' => []])]);
        $client->regions();

        self::assertSame(Client::DEFAULT_TIMEOUT, $this->lastOptions()['timeout']);
        self::assertSame(Client::DEFAULT_CONNECT_TIMEOUT, $this->lastOptions()['connect_timeout']);
    }

    #[Test]
    public function callers_can_override_the_default_timeouts(): void {
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'data' => []])], ['timeout' => 2.5]);
        $client->regions();

        self::assertSame(2.5, $this->lastOptions()['timeout']);
    }

    #[Test]
    public function caller_supplied_headers_are_merged_not_dropped(): void {
        $client = $this->client(
            [$this->jsonResponse(200, ['code' => 200, 'data' => []])],
            ['headers' => ['User-Agent' => 'my-app/1.0']]
        );
        $client->regions();

        $sent = $this->mock->getLastRequest();

        self::assertSame('application/json', $sent->getHeaderLine('Accept'));
        self::assertSame('my-app/1.0', $sent->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function make_from_env_names_the_missing_variable(): void {
        // Regression for #7: unset env silently produced url='' and base_uri='/v1/'.
        putenv('BKA_URL');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/BKA_URL/');

        Client::makeFromEnv(Jwt::valid());
    }

    #[Test]
    public function make_from_env_builds_a_client_when_everything_is_set(): void {
        putenv('BKA_URL=https://example.test/api');
        putenv('BKA_CLIENT_ID=cid');
        putenv('BKA_CLIENT_SECRET=secret');
        putenv('BKA_REFRESH_TOKEN=rt');

        try {
            $client = Client::makeFromEnv(Jwt::valid());

            self::assertSame('https://example.test/api', $client->url);
            self::assertSame('https://example.test/api/token', $client->tokenEndpoint);
        } finally {
            foreach (['BKA_URL', 'BKA_CLIENT_ID', 'BKA_CLIENT_SECRET', 'BKA_REFRESH_TOKEN'] as $var) {
                putenv($var);
            }
        }
    }

    #[Test]
    public function transport_options_reach_the_token_client_but_the_handler_does_not(): void {
        // Regression for #8: the token call ignored proxy/TLS/timeout settings entirely.
        // `handler` must stay out, or the token request would recurse through auth middleware.
        $client = $this->client(guzzleOptions: ['proxy' => 'tcp://proxy.test:8080', 'timeout' => 5.0]);
        $client->getAccessToken();
        $client->forceRefresh();

        self::assertSame('tcp://proxy.test:8080', $client->tokenOptions['proxy']);
        self::assertSame(5.0, $client->tokenOptions['timeout']);
        self::assertArrayNotHasKey('handler', $client->tokenOptions);
    }

    #[Test]
    public function building_two_clients_from_one_stack_does_not_mutate_it(): void {
        // Regression for #9: the auth middleware was pushed onto the caller's stack,
        // so a second client stacked a second copy of it.
        $stack = HandlerStack::create(new MockHandler());

        // HandlerStack has no public introspection (and Guzzle 8 dropped __toString),
        // so count the registered middleware directly.
        $count = static function (HandlerStack $s): int {
            return count((new \ReflectionProperty(HandlerStack::class, 'stack'))->getValue($s));
        };

        $before = $count($stack);

        new Client('https://example.test/api', 'c', 's', Jwt::valid(), 'rt', guzzleOptions: ['handler' => $stack]);
        new Client('https://example.test/api', 'c', 's', Jwt::valid(), 'rt', guzzleOptions: ['handler' => $stack]);

        self::assertSame($before, $count($stack), 'The caller\'s HandlerStack was mutated.');
    }

    #[Test]
    public function debug_output_redacts_credentials(): void {
        // Regression for #10: print_r() exposed clientSecret and refreshToken in plaintext.
        $dump = print_r($this->client(), true);

        self::assertStringNotContainsString('client-secret', $dump);
        self::assertStringNotContainsString('refresh-token', $dump);
        self::assertStringContainsString('***redacted***', $dump);
        self::assertStringContainsString('client-id', $dump, 'Non-secret context should survive.');
    }

    #[Test]
    public function credentials_are_readable_but_not_writable_from_outside(): void {
        $client = $this->client();

        // Reads keep working, so narrowing the write side is not a breaking change.
        self::assertSame('client-secret', $client->clientSecret);
        self::assertSame('client-id', $client->clientId);
        self::assertSame('https://example.test/api', $client->url);

        // Writes are blocked at the language level (PHP 8.4 asymmetric visibility).
        foreach (['url', 'clientId', 'clientSecret', 'accessToken', 'refreshToken', 'version'] as $name) {
            self::assertTrue(
                (new \ReflectionProperty(Client::class, $name))->isProtectedSet(),
                "Client::\$$name should be protected(set) so callers cannot corrupt client state."
            );
        }
    }

}
