<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\V2;

use ChristianHeiko\Bka\Exception\ApiException;
use ChristianHeiko\Bka\Exception\AuthenticationException;
use ChristianHeiko\Bka\Exception\ConfigurationException;
use ChristianHeiko\Bka\Exception\InvalidImageException;
use ChristianHeiko\Bka\Exception\InvalidResponseException;
use ChristianHeiko\Bka\Exception\InvalidTextException;
use ChristianHeiko\Bka\Exception\NotFoundException;
use ChristianHeiko\Bka\Exception\TransportException;
use ChristianHeiko\Bka\Interface\ToArray;
use ChristianHeiko\Bka\V2\Data\Event;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Client for version 2 of the In Situ / BKA API.
 *
 * v2 is an API Platform application and differs from v1 in almost every respect, which is
 * why it is a class of its own rather than a `$version` switch on the v1 client:
 *
 * - Authentication is one long-lived JWT generated in the BKA profile. There is no client
 *   id, no secret and no refresh flow.
 * - Responses are JSON-LD documents instead of the `{code, message, data}` envelope;
 *   collections carry `member` and `totalItems`.
 * - Events are addressed by slug and updated with PATCH (JSON merge patch); PUT is not routed.
 * - Relations are IRIs (see {@see Iri}); images are uploaded on their own and referenced.
 * - Errors are problem documents; a 422 lists per-field violations.
 *
 * There is no published v2 specification. The write contract encoded here was established
 * against the live API — see CLAUDE.md for what was verified and how.
 *
 * Every exception thrown implements BkaException: ApiException (and its NotFound/Validation
 * subclasses) when BKA answered with an error, TransportException when no answer came.
 *
 * @phpstan-consistent-constructor Subclasses must keep the constructor signature; makeFromEnv() relies on it.
 */
class Client implements \JsonSerializable {

    /** Seconds before the whole request is abandoned. Override via $guzzleOptions. */
    public const DEFAULT_TIMEOUT = 30.0;

    /** Seconds allowed for the TCP connect alone. Override via $guzzleOptions. */
    public const DEFAULT_CONNECT_TIMEOUT = 10.0;

    private const JSON_LD = 'application/ld+json';

    private const MERGE_PATCH = 'application/merge-patch+json';

    /** Zero fractions are kept so a price of 25 still reaches the API as the float it requires. */
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    public protected(set) Guzzle $client;

    /** `totalItems` of the most recent collection call. */
    protected ?int $lastTotal = null;

    /**
     * The token is valid for years and cannot be revoked through the API. Wrapped, so that no
     * dump, array cast, get_object_vars() or var_export() of the client can reveal it.
     */
    private \SensitiveParameterValue $token;

    public function __construct(
        public protected(set) string $url,
        #[\SensitiveParameter]
        string $token,
        public protected(set) array $guzzleOptions = [],
    ) {
        $this->token = new \SensitiveParameterValue($token);

        // Cloned rather than pushed onto directly, for the same reason as the v1 client:
        // one caller-supplied stack must not collect the auth middleware twice.
        $handler = $guzzleOptions['handler'] ?? null;
        $stack = $handler instanceof HandlerStack ? clone $handler : HandlerStack::create($handler);

        // Applied per request rather than as a default header, so the token never sits in
        // Guzzle's config where a dump of the transport would print it.
        $stack->push($this->authMiddleware());

        $this->client = new Guzzle([
            // Defaults first so $guzzleOptions can override them.
            'timeout' => self::DEFAULT_TIMEOUT,
            'connect_timeout' => self::DEFAULT_CONNECT_TIMEOUT,
            ...$guzzleOptions,
            'handler' => $stack,
            'base_uri' => rtrim($url, '/') . '/v2/',
            // Statuses are translated into package exceptions in call().
            'http_errors' => false,
            // The API 301s URLs with a trailing slash, and following a redirect turns a POST
            // into a GET. Nothing here produces one, so a redirect is a bug worth surfacing.
            'allow_redirects' => false,
            // JSON-LD rather than plain JSON: it carries `totalItems` and `@id`, and the image
            // endpoint answers nothing else.
            'headers' => ['Accept' => self::JSON_LD] + ($guzzleOptions['headers'] ?? []),
        ]);
    }

    /** @throws ConfigurationException if `{prefix}URL` or `{prefix}TOKEN` is missing. */
    public static function makeFromEnv(string $prefix = 'BKA_', array $guzzleOptions = []): static {
        return new static(
            self::env($prefix . 'URL'),
            self::env($prefix . 'TOKEN'),
            $guzzleOptions,
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

    /** What var_dump() and print_r() show — never the token. */
    public function __debugInfo(): array {
        return [
            'url' => $this->url,
            'token' => '***redacted***',
            'tokenExpiresAt' => $this->tokenExpiresAt()?->format(DATE_ATOM),
        ];
    }

    /** Loggers serialise context objects to JSON; they get the same redacted view. */
    public function jsonSerialize(): array {
        return $this->__debugInfo();
    }

    private function authMiddleware(): callable {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                return $handler($request->withHeader('Authorization', 'Bearer ' . $this->token->getValue()), $options);
            };
        };
    }

    /** Expiry from the token's `exp` claim; null when it carries none or cannot be read. */
    public function tokenExpiresAt(): ?\DateTimeImmutable {
        $claims = self::claims($this->token->getValue());

        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int)$claims['exp']);
    }

    /**
     * Checked locally, without a request. A malformed token is invalid; one without `exp`
     * is left for the server to judge.
     */
    public function isTokenValid(): bool {
        $claims = self::claims($this->token->getValue());

        if (is_null($claims)) {
            return false;
        }

        return !isset($claims['exp']) || (int)$claims['exp'] > time();
    }

    private static function claims(string $token): ?array {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), false);

        if ($payload === false) {
            return null;
        }

        $data = json_decode($payload, true);

        return is_array($data) ? $data : null;
    }

    /** Total number of records reported by the most recent collection call. */
    public function lastTotal(): ?int {
        return $this->lastTotal;
    }

    /**
     * One page of the account's own published events — drafts are not listed. `$filters`
     * is passed through as query parameters.
     */
    public function events(int $page = 1, ?int $itemsPerPage = null, array $filters = []): array {
        $query = ['page' => $page];

        if (!is_null($itemsPerPage)) {
            $query['itemsPerPage'] = $itemsPerPage;
        }

        return $this->collection('events', $query + $filters);
    }

    /** Every published event of the account, following the pagination. */
    public function allEvents(int $itemsPerPage = 100): array {
        $events = [];

        for ($page = 1; ; $page++) {
            $batch = $this->events($page, $itemsPerPage);
            $events = [...$events, ...$batch];

            if (count($batch) < $itemsPerPage || (!is_null($this->lastTotal) && count($events) >= $this->lastTotal)) {
                return $events;
            }
        }
    }

    /**
     * The event with that slug, or null if there is none.
     *
     * Only a 404 means "none"; every other failure throws. The API answers 404 for the
     * account's own drafts too, so null does not prove an event is gone — never decide
     * between update and create on this.
     */
    public function event(string $slug): ?object {
        if ($slug === '') {
            return null;
        }

        try {
            return $this->item('GET', 'events/' . rawurlencode($slug));
        } catch (NotFoundException) {
            return null;
        }
    }

    public function categories(): array {
        return $this->collection('categories');
    }

    public function audiences(): array {
        return $this->collection('audiences');
    }

    public function affiliations(): array {
        return $this->collection('affiliations');
    }

    public function regions(): array {
        return $this->collection('regions');
    }

    /** Places the account may use. v2 identifies a place by its `slug` — see {@see Iri::place()}. */
    public function places(?string $keyword = null): array {
        return $this->collection('places', is_null($keyword) ? [] : ['keyword' => $keyword]);
    }

    public function organizations(?string $keyword = null): array {
        return $this->collection('organizations', is_null($keyword) ? [] : ['keyword' => $keyword]);
    }

    public function createEvent(Event|array $event): object {
        return $this->item('POST', 'events', [
            'headers' => ['Content-Type' => self::JSON_LD],
            'body' => $this->encode($event),
        ]);
    }

    /**
     * JSON merge patch: fields left out keep their value on BKA, a null clears one.
     * Event::toArray() sends null for `special_rate` and always sends every collection;
     * `opening_time` and `publication_date` are left out when unset, so they cannot be
     * cleared this way.
     *
     * Images are the exception to "left out keeps its value". The API refuses any update of
     * an event that still has images attached ("You do not have permission to reassign the
     * image") unless the update replaces them — even when `images` is left out. So every
     * update has to send freshly uploaded images, or none; the replaced ones stay behind
     * unattached until removed with deleteImage().
     *
     * The slug changes when the event is renamed; keep the one in the response.
     */
    public function updateEvent(string $slug, Event|array $event): object {
        return $this->item('PATCH', 'events/' . rawurlencode($slug), [
            'headers' => ['Content-Type' => self::MERGE_PATCH],
            'body' => $this->encode($event),
        ]);
    }

    /**
     * Updates the event with that slug, or creates it when no slug is known.
     *
     * A 404 on the update propagates as NotFoundException instead of being answered with a
     * create: the slug may merely be stale (BKA regenerates it on rename), and creating the
     * event then would publish it twice. Store the returned `slug`.
     */
    public function saveEvent(Event|array $event, ?string $slug = null): object {
        return empty($slug)
            ? $this->createEvent($event)
            : $this->updateEvent($slug, $event);
    }

    /** True once deleted, false if there was no such event. Its images are deleted with it. */
    public function deleteEvent(string $slug): bool {
        try {
            $this->call('DELETE', 'events/' . rawurlencode($slug));
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * Uploads a local file or URL and returns the created image. Reference its `@id` from
     * Event::$images — the API does not accept images inline, and an image uploaded by
     * another account cannot be attached.
     *
     * v2 offers no way to give an image a legend: a multipart `labels[legend][…]` part makes
     * the upload fail with a 500, a flat `legend` part is ignored, and images cannot be
     * patched afterwards (405). So the file is all that is sent.
     *
     * @throws InvalidImageException if the file cannot be read or is empty (a directory, say).
     */
    public function uploadImage(string $filePath): object {
        $contents = @file_get_contents($filePath);

        // Guard for the same reason as the v1 Image: posting nothing would upload an empty file.
        if ($contents === false || $contents === '') {
            throw InvalidImageException::unreadable($filePath);
        }

        return $this->item('POST', 'images', ['multipart' => [[
            'name' => 'file',
            'contents' => $contents,
            'filename' => basename(parse_url($filePath, PHP_URL_PATH) ?: $filePath),
        ]]]);
    }

    /**
     * Deletes an uploaded image, by id or IRI. Needed for images an update replaced: they
     * are only detached, whereas deleting an event takes its current images with it.
     *
     * True once deleted, false if there was no such image.
     */
    public function deleteImage(int|string $image): bool {
        try {
            $this->call('DELETE', 'images/' . rawurlencode(basename((string)$image)));
        } catch (NotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * Sends a request and returns the decoded body, or null for an empty one.
     *
     * @throws TransportException when no answer came — the outcome of a write is then unknown.
     * @throws AuthenticationException on a 401 — the token is expired or revoked.
     * @throws ApiException on any other non-2xx status (NotFoundException, ValidationException).
     * @throws InvalidResponseException if a body is not JSON.
     */
    public function call(string $method, string $endpoint, array $options = []): object|array|null {
        try {
            $response = $this->client->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            throw TransportException::during($method, $endpoint, $e);
        }

        $status = $response->getStatusCode();

        if ($status === 401) {
            $error = json_decode((string)$response->getBody(), true);
            $reason = is_array($error) ? ($error['message'] ?? $error['detail'] ?? null) : null;

            throw new AuthenticationException(
                'BKA rejected the API token' . (is_string($reason) ? " ($reason)" : '')
                . '. Generate a new one in the BKA profile.',
                401,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($method, $endpoint, $response);
        }

        return $this->decode($response);
    }

    /** @throws InvalidResponseException if the body is not valid JSON. */
    public function decode(ResponseInterface $response): object|array|null {
        $body = (string)$response->getBody();

        if (trim($body) === '') {
            return null;
        }

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

    private function item(string $method, string $endpoint, array $options = []): object {
        $data = $this->call($method, $endpoint, $options);

        if (!is_object($data)) {
            throw new InvalidResponseException("API response for \"$endpoint\" was not a single resource.");
        }

        return $data;
    }

    /** Unwraps a JSON-LD collection (either key style) or passes a plain JSON list through. */
    private function collection(string $endpoint, array $query = []): array {
        $data = $this->call('GET', $endpoint, $query === [] ? [] : ['query' => $query]);

        $this->lastTotal = null;

        if (is_array($data)) {
            return $data;
        }

        foreach (['member' => 'totalItems', 'hydra:member' => 'hydra:totalItems'] as $members => $total) {
            if (isset($data->{$members}) && is_array($data->{$members})) {
                $this->lastTotal = isset($data->{$total}) ? (int)$data->{$total} : null;

                return $data->{$members};
            }
        }

        throw new InvalidResponseException("API response for \"$endpoint\" was not a collection.");
    }

    /** @throws InvalidTextException if a text is not valid UTF-8. */
    private function encode(Event|array $event): string {
        try {
            return json_encode($event instanceof ToArray ? $event->toArray() : $event, self::JSON_FLAGS);
        } catch (\JsonException $e) {
            throw new InvalidTextException('The event could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

}
