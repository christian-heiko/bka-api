<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\V2;

use ChristianHeiko\Bka\Tests\Support\Jwt;
use ChristianHeiko\Bka\V2\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase {

    protected MockHandler $mock;

    /** Per-request options as Guzzle resolved them, captured by a spy middleware. */
    protected array $seenOptions = [];

    /**
     * A v2 client wired to a queue of canned responses. Nothing here touches the network.
     *
     * Requests are asserted through MockHandler::getLastRequest() — it sees them after the
     * client's own auth middleware — and options through the spy, never Client::getConfig().
     *
     * @param list<Response|\Throwable> $responses A Throwable is thrown instead of answering, as MockHandler does.
     */
    protected function client(array $responses = [], array $guzzleOptions = [], ?string $token = null): Client {
        $this->mock = new MockHandler($responses);
        $this->seenOptions = [];

        $stack = HandlerStack::create($this->mock);
        $stack->push(function (callable $next): callable {
            return function (RequestInterface $request, array $options) use ($next) {
                $this->seenOptions[] = $options;

                return $next($request, $options);
            };
        });

        return new Client('https://example.test/api', $token ?? Jwt::valid(), ['handler' => $stack] + $guzzleOptions);
    }

    protected function lastRequest(): RequestInterface {
        $request = $this->mock->getLastRequest();
        self::assertNotNull($request, 'No request was sent.');

        return $request;
    }

    /** Options Guzzle applied to the most recent request. */
    protected function lastOptions(): array {
        return $this->seenOptions[array_key_last($this->seenOptions)] ?? [];
    }

    protected function jsonLd(int $status, array $body): Response {
        return new Response($status, ['Content-Type' => 'application/ld+json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** An RFC 7807 problem document as API Platform returns it. */
    protected function problem(int $status, string $detail, array $violations = []): Response {
        $body = ['title' => 'An error occurred', 'detail' => $detail, 'status' => $status, 'type' => "/errors/$status"];

        if ($violations !== []) {
            $body['violations'] = $violations;
        }

        return new Response($status, ['Content-Type' => 'application/problem+json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $members */
    protected function collection(array $members, ?int $total = null): Response {
        return $this->jsonLd(200, [
            '@context' => '/api/v2/contexts/Event',
            '@id' => '/api/v2/events',
            '@type' => 'Collection',
            'totalItems' => $total ?? count($members),
            'member' => $members,
        ]);
    }

}
