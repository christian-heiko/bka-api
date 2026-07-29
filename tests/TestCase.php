<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests;

use ChristianHeiko\Bka\Tests\Support\Jwt;
use ChristianHeiko\Bka\Tests\Support\StubClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {

    protected MockHandler $mock;

    /** Per-request options as Guzzle resolved them, captured by a spy middleware. */
    protected array $seenOptions = [];

    /**
     * A client wired to a queue of canned responses. Nothing here touches the network.
     *
     * Options are observed through a spy middleware rather than Client::getConfig(),
     * which is deprecated on Guzzle 7 and would trip failOnDeprecation in CI.
     *
     * @param list<Response> $responses
     */
    protected function client(array $responses = [], array $guzzleOptions = [], ?callable $onTokenRefresh = null, ?string $accessToken = null): StubClient {
        $this->mock = new MockHandler($responses);
        $this->seenOptions = [];

        $stack = HandlerStack::create($this->mock);
        $stack->push(function (callable $next): callable {
            return function ($request, array $options) use ($next) {
                $this->seenOptions[] = $options;

                return $next($request, $options);
            };
        });

        return new StubClient(
            'https://example.test/api',
            'client-id',
            'client-secret',
            $accessToken ?? Jwt::valid(),
            'refresh-token',
            guzzleOptions: ['handler' => $stack] + $guzzleOptions,
            onTokenRefresh: $onTokenRefresh,
        );
    }

    /** Options Guzzle applied to the most recent request. */
    protected function lastOptions(): array {
        return $this->seenOptions[array_key_last($this->seenOptions)] ?? [];
    }

    protected function jsonResponse(int $status, array $envelope): Response {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
    }

}
