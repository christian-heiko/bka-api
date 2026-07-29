<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka;

use ChristianHeiko\Bka\Data\Event;
use ChristianHeiko\Bka\Exception\AuthenticationException;
use ChristianHeiko\Bka\Exception\ConfigurationException;
use ChristianHeiko\Bka\Exception\InvalidResponseException;
use ChristianHeiko\Bka\Interface\ToArray;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client for the In Situ / BKA event API.
 *
 * @phpstan-consistent-constructor Subclasses must keep the constructor signature; makeFromEnv() relies on it.
 */
class Client {

    public const DEFAULT_MAX_RESULT = 10;

    /** Seconds before the whole request is abandoned. Override via $guzzleOptions. */
    public const DEFAULT_TIMEOUT = 30.0;

    /** Seconds allowed for the TCP connect alone. Override via $guzzleOptions. */
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    /** Guarantees a 401 is only ever retried once per request. */
    private const RETRIED_OPTION = '_bka_token_retried';

    /**
     * Transport options forwarded to the token-refresh client.
     *
     * `handler` is deliberately excluded: reusing the API stack would route the
     * token request back through the auth middleware and recurse.
     */
    private const TOKEN_CLIENT_OPTIONS = ['timeout', 'connect_timeout', 'proxy', 'verify', 'cert', 'ssl_key'];

    public protected(set) string $tokenEndpoint;

    public protected(set) Guzzle $client;

    /** The full envelope of the most recent call — `code`, `message`, `data`, and `total` where present. */
    public protected(set) ?object $lastEnvelope = null;

    /** Called with (string $accessToken, string $refreshToken) whenever the token is refreshed. */
    public protected(set) ?\Closure $onTokenRefresh = null;

    public function __construct(
        public protected(set) string $url,
        public protected(set) string $clientId,
        public protected(set) string $clientSecret,
        public protected(set) string $accessToken,
        public protected(set) string $refreshToken,
        public protected(set) int $version = 1,
        public protected(set) array $guzzleOptions = [],
        ?callable $onTokenRefresh = null
    ) {
        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }

        $this->tokenEndpoint = $url . 'token';

        $url .= "v$version/";

        $this->onTokenRefresh = is_null($onTokenRefresh)
            ? null
            : \Closure::fromCallable($onTokenRefresh);

        // Clone rather than push onto a caller-supplied stack: pushing directly would
        // register the auth middleware twice if one stack is used to build two clients.
        // HandlerStack keeps its middleware in a plain array, so cloning detaches it.
        $handler = $guzzleOptions['handler'] ?? null;
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);

        // Pushed last, so it sits inside `http_errors` and can see a raw 401
        // before that middleware turns it into a ClientException.
        $stack->push($this->authMiddleware());

        $this->client = new Guzzle([
            // Defaults first so $guzzleOptions can override them.
            'timeout' => self::DEFAULT_TIMEOUT,
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT,
            ...$guzzleOptions,
            'handler' => $stack,
            'base_uri' => $url,
            // Merged, not replaced, so callers keep their own User-Agent etc.
            'headers' => ['Accept' => 'application/json'] + ($guzzleOptions['headers'] ?? []),
        ]);
    }

    /** @throws ConfigurationException if any required environment variable is missing. */
    public static function makeFromEnv(string $accessToken, string $prefix = 'BKA_', ?callable $onTokenRefresh = null): static {
        return new static(
            self::env($prefix . 'URL'),
            self::env($prefix . 'CLIENT_ID'),
            self::env($prefix . 'CLIENT_SECRET'),
            $accessToken,
            self::env($prefix . 'REFRESH_TOKEN'),
            onTokenRefresh: $onTokenRefresh,
        );
    }

    /** getenv() returns false when unset, which would otherwise coerce to "" and build a broken client. */
    private static function env(string $variable): string {
        $value = getenv($variable);

        if ($value === false || $value === '') {
            throw ConfigurationException::missingEnv($variable);
        }

        return $value;
    }

    /** Total number of records reported by the most recent call, where the endpoint provides one. */
    public function lastTotal(): ?int {
        return isset($this->lastEnvelope->total) ? (int)$this->lastEnvelope->total : null;
    }

    /** Keeps credentials out of var_dump()/print_r() and anything that serialises them into a log. */
    public function __debugInfo(): array {
        return [
            'url' => $this->url,
            'version' => $this->version,
            'tokenEndpoint' => $this->tokenEndpoint,
            'clientId' => $this->clientId,
            'clientSecret' => '***redacted***',
            'accessToken' => '***redacted***',
            'refreshToken' => '***redacted***',
        ];
    }

    /**
     * Applies the current access token to every request and, because the API
     * expires access tokens after an hour, refreshes and replays once on a 401.
     */
    private function authMiddleware(): callable {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $this->getAccessToken());

                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($request, $handler, $options) {
                        if ($response->getStatusCode() !== 401 || !empty($options[self::RETRIED_OPTION])) {
                            return $response;
                        }

                        $options[self::RETRIED_OPTION] = true;

                        // The first attempt consumed the body; rewind before replaying,
                        // otherwise an addEvent()/updateEvent() retry posts nothing.
                        $body = $request->getBody();

                        if ($body->isSeekable()) {
                            $body->rewind();
                        }

                        return $handler(
                            $request->withHeader('Authorization', 'Bearer ' . $this->refreshAccessToken()),
                            $options
                        );
                    }
                );
            };
        };
    }

    public function events(
        ?\DateTime $date_from = null,
        ?\DateTime $date_to = null,
        ?string $keyword = null,
        ?int $limit = null,
        ?bool $member = null,
        ?bool $multi_day = null,
        ?int $page = null,
        ?int $place = null,
        ?bool $free = null
    ): array
    {
        $query = [];

        if (!empty($date_from)) {
            $query['date_from'] = $date_from->format('Y-m-d');
        }

        if (!empty($date_to)) {
            $query['date_to'] = $date_to->format('Y-m-d');
        }

        if (!empty($keyword)) {
            $query['keyword'] = $keyword;
        }

        if (!empty($limit)) {
            $query['limit'] = $limit;
        }

        if (!empty($member)) {
            $query['member'] = $member;
        }

        if (!empty($multi_day)) {
            $query['multi_day'] = $multi_day;
        }

        if (!empty($page)) {
            $query['page'] = $page;
        }

        if (!empty($place)) {
            $query['place'] = $place;
        }

        if (!empty($free)) {
            $query['free'] = $free;
        }

        return $this->callApi(
            endpoint: 'events',
            queryParams: $query
        );
    }

    public function sponsoredEvents(): array {
        return $this->callApi(
            endpoint: 'events/sponsored'
        );
    }

    public function event(string $slug):? object {
        return $this->callApi(
            endpoint: "events/$slug"
        );
    }

    public function saveEvent(array|Event $eventData, string|null $id = null): object {
        return empty($id)
            ? $this->addEvent($eventData)
            : $this->updateEvent($id, $eventData);
    }

    public function addEvent(array|Event $eventData): object {
        return $this->callApi(
            endpoint: 'events/',
            formData: $eventData,
            method: 'POST'
        );
    }

    public function updateEvent(string $id, array|Event $eventData): object {
        return $this->callApi(
            endpoint: "events/$id",
            formData: $eventData,
            method: 'PUT'

        );
    }

    public function deleteEvent(string $id): bool {
        return $this->callApi(
            endpoint: "events/$id",
            method: 'DELETE',
        );
    }

    public function similarEvents(string $slug, int $max_result = self::DEFAULT_MAX_RESULT): array {
        return $this->callApi(
            endpoint: "events/similar/$slug",
            queryParams: [ 'max_result' => $max_result ]
        );
    }

    public function eventsForOrganization(string $slug, int $max_result = self::DEFAULT_MAX_RESULT): array {
        return $this->callApi(
            endpoint: "events/organization/$slug",
            queryParams: [ 'max_result' => $max_result ]
        );
    }

    public function eventsForPlace(string $slug, int $max_result = self::DEFAULT_MAX_RESULT): array {
        return $this->callApi(
            endpoint: "events/place/$slug",
            queryParams: [ 'max_result' => $max_result ]
        );
    }

    public function categories(): array {
        return $this->callApi(
            endpoint: 'categories',
        );
    }

    public function affiliations(): array {
        return $this->callApi(
            endpoint: 'affiliations',
        );
    }

    public function audiences(): array {
        return $this->callApi(
            endpoint: 'audiences',
        );
    }

    public function organizations(?string $keyword = null, ?int $limit = null): array {
        $query = [];

        if (!empty($keyword)) {
            $query['keyword'] = $keyword;
        }

        if (!empty($limit)) {
            $query['limit'] = $limit;
        }

        return (array)$this->callApi(
            endpoint: 'organizations',
            queryParams: $query
        );
    }

    public function organization(string $slug):? object {
        return $this->callApi(
            endpoint: "organizations/$slug",
        );
    }

    public function places(?string $keyword = null, ?int $limit = null, ?int $place = null): array {
        $query = [];

        if (!empty($keyword)) {
            $query['keyword'] = $keyword;
        }

        if (!empty($limit)) {
            $query['limit'] = $limit;
        }

        if (!empty($place)) {
            $query['place'] = $place;
        }

        return $this->callApi(
            endpoint: 'places',
            queryParams: $query
        );
    }

    public function place(string $slug):? object {
        return $this->callApi(
            endpoint: "places/$slug",
        );
    }

    public function regions(): array {
        return $this->callApi(
            endpoint: 'regions',
        );
    }

    public function getAccessToken(): string {
        if ($this->isAccessTokenValid()) {
            return $this->accessToken;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Transport settings the caller configured for the API client, reused for the
     * token endpoint so proxies and TLS options apply there too.
     */
    protected function tokenClientOptions(): array {
        return array_intersect_key(
            $this->guzzleOptions,
            array_flip(self::TOKEN_CLIENT_OPTIONS)
        ) + [
            'timeout' => self::DEFAULT_TIMEOUT,
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT,
        ];
    }

    /** A malformed token is simply not valid; it triggers a refresh rather than throwing. */
    public function isAccessTokenValid(): bool {
        $tokenParts = explode('.', $this->accessToken);

        if (count($tokenParts) !== 3) {
            return false;
        }

        $payload = base64_decode(strtr($tokenParts[1], '-_', '+/'), false);

        if ($payload === false) {
            return false;
        }

        $data = json_decode($payload, true);

        return is_array($data) && isset($data['exp']) && $data['exp'] > time();
    }

    /** Protected rather than private so it can be stubbed in tests. */
    protected function refreshAccessToken(): string {
        try {
            $response = (new Guzzle($this->tokenClientOptions()))->post($this->tokenEndpoint, [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $this->refreshToken,
                ],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);
        } catch (ClientException $e) {
            $error = json_decode((string)$e->getResponse()->getBody());
            throw new AuthenticationException('Refresh token error: ' . ($error->error ?? $e->getMessage()), previous: $e);
        }

        $data = json_decode((string)$response->getBody());

        if (isset($data->error)) {
            throw new AuthenticationException('Refresh token error: ' . $data->error);
        }

        if (!isset($data->access_token)) {
            throw new AuthenticationException('Token endpoint returned no access_token.');
        }

        $this->accessToken = $data->access_token;

        // The platform may rotate the refresh token alongside the access token.
        if (isset($data->refresh_token)) {
            $this->refreshToken = $data->refresh_token;
        }

        if (!is_null($this->onTokenRefresh)) {
            ($this->onTokenRefresh)($this->accessToken, $this->refreshToken);
        }

        return $this->accessToken;
    }

    public function callApi(string $endpoint, array $queryParams = [], array $requestOptions = [], array|Event|null $formData = null, string $method = 'GET'): object|array|bool {
        if (!empty($queryParams)) {
            $requestOptions['query'] = $queryParams;
        }

        if (!empty($formData)) {
            if ($formData instanceof ToArray) {
                $formData = $formData->toArray();
            }

            $requestOptions['headers'] = ['Content-Type' => 'application/json'];
            $requestOptions['body'] = json_encode($formData, JSON_THROW_ON_ERROR);
        }

        $response = $this->client->request($method, $endpoint, $requestOptions);

        $parsed = $this->json($response);

        $this->lastEnvelope = is_object($parsed) ? $parsed : null;

        if ($method === 'DELETE') {
            // Fall back to the HTTP status when the envelope carries no code of its own.
            $code = isset($parsed->code) ? (int)$parsed->code : $response->getStatusCode();

            return $code >= 200 && $code < 300;
        }

        if (!is_object($parsed) || !property_exists($parsed, 'data')) {
            throw InvalidResponseException::missingData($endpoint);
        }

        return $parsed->data;
    }

    /** @throws InvalidResponseException if the body is not valid JSON. */
    public function json(ResponseInterface $response): object|array {
        $body = $response->getBody()->getContents();

        try {
            $decoded = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw InvalidResponseException::notJson($body, $e);
        }

        if (!is_object($decoded) && !is_array($decoded)) {
            throw InvalidResponseException::notJson($body);
        }

        return $decoded;
    }

}
