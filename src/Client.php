<?php

namespace ChristianHeiko\Bka;

use ChristianHeiko\Bka\Data\Event;
use ChristianHeiko\Bka\Interface\ToArray;
use Exception;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ClientException;
use Psr\Http\Message\ResponseInterface;

class Client {

    public const DEFAULT_MAX_RESULT = 10;

    public string $tokenEndpoint;

    public Guzzle $client;

    public function __construct(
        public string $url,
        public string $clientId,
        public string $clientSecret,
        public string $accessToken,
        public string $refreshToken,
        public int $version = 1,
        public array $guzzleOptions = []
    ) {
        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }

        $this->tokenEndpoint = "$url/token";

        $url .= "v$version/";

        $this->client = new Guzzle([
            ...$guzzleOptions,
            'base_uri' => $url,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAccessToken()
            ]
        ]);
    }

    public static function makeFromEnv(string $accessToken, string $prefix = 'BKA_'): static {
        return new static(
            getenv($prefix . 'URL'),
            getenv($prefix . 'CLIENT_ID'),
            getenv($prefix . 'CLIENT_SECRET'),
            $accessToken,
            getenv($prefix . 'REFRESH_TOKEN'),
        );
    }

    public function events(
        \DateTime $date_from = null,
        \DateTime $date_to = null,
        string $keyword = null,
        int $limit = null,
        bool $member = null,
        bool $multi_day = null,
        int $page = null,
        int $place = null
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

    public function organizations(): array {
        return (array)$this->callApi(
            endpoint: 'organizations',
        );
    }

    public function organization(string $slug):? object {
        return $this->callApi(
            endpoint: "organizations/$slug",
        );
    }

    public function places(): array {
        return $this->callApi(
            endpoint: 'places',
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

    public function getAccessToken() {
        if ($this->isAccessTokenValid()) {
            return $this->accessToken;
        }

        return $this->refreshAccessToken();
    }

    public function isAccessTokenValid(): bool {
        $tokenParts = explode('.', $this->accessToken);
        if (count($tokenParts) !== 3) {
            throw new Exception('Invalid access token format.');
        }

        $payload = base64_decode($tokenParts[1]);
        $data = json_decode($payload, true);

        return isset($data['exp']) && $data['exp'] > time();
    }

    private function refreshAccessToken() {
        try {
            $response = $this->client->post($this->tokenEndpoint, [
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
            $response = $e->getResponse();
            $error = json_decode($response->getBody());
            throw new Exception("Refresh token error: " . ($error->error ?? $e->getMessage()));
        }

        $data = json_decode($response->getBody());

        if (isset($data->error)) {
            throw new Exception("Refresh token error: " . $data->error);
        }

        $this->accessToken = $data->access_token;

        return $this->accessToken;
    }

    public function callApi(string $endpoint, array $queryParams = [], array $requestOptions = [], array|Event $formData = null, string $method = 'GET'): object|array|bool {
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

        return $method === 'DELETE'
            ? (int)$parsed->code === 200
            : $parsed->data;
    }

    public function json(ResponseInterface $response): object|array {
        return json_decode($response->getBody()->getContents());
    }

}
