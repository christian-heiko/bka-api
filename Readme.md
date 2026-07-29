# Berner Kulturagenda API Client

This Guzzle based Client interacts with the Event-Related API of [Kulturagenda](https://bka.ch/) and [In-Situ](https://www.in-situ.org/de/).
Use it to read/create/update/delete Events and get other data such as audiences, categories and so on.

### API Credentials

To use it you will need a login to one of the Platform as well as API-Credentials:
- Client ID
- Client Secret
- Access Token
- Refresh Token

To obtain these credentials please get in Contact with the Support of one of these pages.

### Support
This is an unofficial package and neither this nor me are related or working for one of the organizations or related companies. Therefore, I can not offer support for api-sided issues but im happy to support issues related to this package.

This is my first open source package; feel free to contribute or give feedback ✌️


### Roadmap
A Wordpress Plugin for common Event Plugins could be an option. Please drop me a message if you would like to help develop such a plugin.

## Installation

```shell
composer require christian-heiko/bka-api
```

Requires PHP 8.4+ and works with either Guzzle 7 or Guzzle 8.

The official API documentation is published at
[admin.bka.ch/api/doc](https://admin.bka.ch/api/doc) / [admin.insitu.live/api/doc](https://admin.insitu.live/api/doc)
(OpenAPI source at `/openapi.yaml`).

## Create Instance


### Direct

```php
<?php

use ChristianHeiko\Bka\Client

$client = new Client(
    'url',
    'clientId',
    'clientSecret',
    'accessToken',
    'refreshToken'
);
```

### Via .env
```dotenv
#.env
BKA_URL =
BKA_CLIENT_ID =
BKA_CLIENT_SECRET =
BKA_REFRESH_TOKEN =
```

```php
<?php

use ChristianHeiko\Bka\Client
$accessToken = 'Load From DB or where-ever';
$client = Client::makeFromEnv($accessToken);
```

## Access Tokens

The API issues access tokens that are valid for **1 hour**, and refresh tokens valid for **6 months**.

The client applies the access token to every request and refreshes it automatically — both when it
notices the token has already expired, and when the API answers a request with `401`, in which case
the request is replayed once with the new token. You do not need to handle this yourself.

Because a refreshed token is only useful if you keep it, pass `onTokenRefresh` to be notified:

```php
<?php

use ChristianHeiko\Bka\Client;

$client = new Client(
    'url', 'clientId', 'clientSecret', 'accessToken', 'refreshToken',
    onTokenRefresh: function (string $accessToken, string $refreshToken): void {
        // Persist these; pass the access token back in on the next request.
    }
);

// Also available on the .env constructor:
$client = Client::makeFromEnv($accessToken, onTokenRefresh: $callback);
```

Without the callback the refreshed token still works for the lifetime of the instance, but is lost
once it goes out of scope, so the next process starts by refreshing again.

## Events

Full CRUD Example at `examples/events.php`

## Implemented Endpoints

```php
<?php

use ChristianHeiko\Bka\Client

$client = Client::makeFromEnv('...');

$client->events(date_from: new DateTime(), free: true);
$client->event('slug-to-event');
$client->addEvent(['data for event']);
$client->updateEvent('id', ['data for event']);
$client->saveEvent(['data for event'], 'id'|null); // Shortcut to add/update
$client->deleteEvent('id');

$client->sponsoredEvents();
$client->similarEvents('slug-to-event');
$client->eventsForOrganization('slug-to-organization');
$client->eventsForPlace('slug-to-place');

$client->affiliations();
$client->audiences();
$client->categories();
$client->organizations(keyword: 'bern', limit: 10);
$client->organization('slug-to-organization');
$client->places(keyword: 'bern', limit: 10, place: 1);
$client->place('slug-to-place');
$client->regions();
```

All arguments are optional. `events()` accepts `date_from`, `date_to`, `keyword`, `limit`, `member`,
`multi_day`, `page`, `place` and `free`; `page` and `limit` only apply where the pagination feature
is enabled.

## Pagination

List endpoints return only their `data`. The rest of the envelope stays reachable, which is what
makes `limit`/`page` usable:

```php
<?php

$page = $client->events(limit: 50, page: 1);

$client->lastTotal();     // e.g. 874 — total records, or null if the endpoint reports none
$client->lastEnvelope;    // the full envelope: code, message, data, total
```

Both reflect the most recent call.

## Errors

Every exception this package throws implements `ChristianHeiko\Bka\Exception\BkaException`, so you
can catch the lot with one clause, or narrow to the case you care about:

```php
<?php

use ChristianHeiko\Bka\Exception\AuthenticationException;
use ChristianHeiko\Bka\Exception\BkaException;
use ChristianHeiko\Bka\Exception\InvalidResponseException;
use GuzzleHttp\Exception\ClientException;

try {
    $client->saveEvent($eventData);
} catch (AuthenticationException $e) {
    // The refresh token is expired or revoked — the user has to re-authorise.
} catch (InvalidResponseException $e) {
    // The API replied with something unparseable, e.g. a proxy error page.
} catch (ClientException $e) {
    // A 4xx from the API itself. Read the body for the validation errors:
    var_dump($client->json($e->getResponse()));
} catch (BkaException $e) {
    // Anything else originating from this package.
}
```

Each class extends the SPL exception it makes sense as (`AuthenticationException` is a
`RuntimeException`, `InvalidImageException` an `InvalidArgumentException`), so broader
`catch (\Exception $e)` blocks keep working.

## Timeouts

Requests default to a 30 second timeout and a 10 second connect timeout. Override them — along with
proxies, TLS options and anything else Guzzle accepts — via `$guzzleOptions`; they are forwarded to
the token endpoint too:

```php
<?php

$client = new Client(
    'url', 'clientId', 'clientSecret', 'accessToken', 'refreshToken',
    guzzleOptions: ['timeout' => 5.0, 'proxy' => 'tcp://proxy.example:8080']
);
```
