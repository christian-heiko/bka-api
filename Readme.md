# Berner Kulturagenda API Client

This Guzzle based Client interacts with the Event-Related API of [Kulturagenda](https://bka.ch/) and [In-Situ](https://www.in-situ.org/de/).
Use it to read/create/update/delete Events and get other data such as audiences, categories and so on.

### API Credentials

You need a login to one of the platforms and API credentials:

- **API v2** — what the BKA profile issues today: one long-lived token, generated in the profile.
  Use `V2\Client`; see [API v2](#api-v2).
- **API v1** — Client ID, Client Secret, Access Token and Refresh Token, from the platform's support.
  v1 answers a v2 token with a 500.

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

## API v2

BKA runs a second, undocumented API next to v1: API Platform at `{host}/api/v2`. Tokens generated in
the BKA profile today are v2 tokens — one long-lived JWT, no client id, secret or refresh token — and
v1 answers such a token with a 500. Use `ChristianHeiko\Bka\V2\Client` for them:

```php
<?php

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\V2\Client;
use ChristianHeiko\Bka\V2\Data\Event;
use ChristianHeiko\Bka\V2\Data\Rate;
use ChristianHeiko\Bka\V2\Data\Ticketing;

$client = new Client('https://admin.bka.ch/api', $token);
// or: Client::makeFromEnv(); // BKA_URL + BKA_TOKEN

$zurich = new DateTimeZone('Europe/Zurich');

$event = new Event(
    'Some Party Title',
    EventStatus::confirmed,
    'your-place-slug',   // places are addressed by slug — see $client->places('keyword')
    [1, 3],              // category ids — $client->categories()
    new DateTime('2026-12-31 22:00', $zurich),
    new DateTime('2027-01-01 04:00', $zurich),
    Text::make('de', '<p>Best Party in Town</p>'),
    1,                   // audience id — $client->audiences()
    openingTime: new DateTime('2026-12-31 21:30', $zurich),
);

$event->attachRate(new Rate(25, Text::make('de', 'Normal')));
$event->attachTicketing(Ticketing::fromUrl('https://www.petzi.ch/de/events/1', Text::make('de', 'Vorverkauf')));
$event->attachImage($client->uploadImage('/path/to/image.jpg')->id);

$saved = $client->saveEvent($event, $knownSlug); // PATCH by slug, or create when none is known
// Keep $saved->slug: it changes when the event is renamed.
```

Full example with error handling: `examples/events-v2.php`.

```php
$client->events(page: 1, itemsPerPage: 30);   // the account's published events; lastTotal() has the count
$client->allEvents();
$client->event('slug');                        // null when not found — or a draft, see below
$client->createEvent($event);
$client->updateEvent('slug', $event);          // JSON merge patch
$client->saveEvent($event, 'slug' | null);
$client->deleteEvent('slug');
$client->uploadImage('/path/or/url.jpg');
$client->deleteImage($id);
$client->categories(); $client->audiences(); $client->affiliations(); $client->regions();
$client->places(keyword: 'bern'); $client->organizations(keyword: 'bern');
```

### What is different in v2

| | v1 | v2 |
|---|---|---|
| Auth | OAuth access token (1 h) + refresh token | one JWT from the BKA profile, no refresh |
| Responses | `{code, message, data}` | JSON-LD; collections carry `member` and `totalItems` |
| Events addressed by | numeric id, updated with `PUT` | slug, updated with `PATCH` (merge patch) |
| Relations | ids | IRIs, built by `V2\Iri` — places by **slug** |
| Dates | `Y-m-d\TH:i:s\Z` | RFC 3339 with offset: pass local times in their timezone |
| Images | base64 inside the event | `uploadImage()` first, then reference the id |
| Ticket link | `ticketingUrl` | `ticketings` (`V2\Data\Ticketing`) |
| Errors | Guzzle `ClientException` | `ApiException`, `ValidationException` (`$violations`), `NotFoundException`; `TransportException` when no answer came |

### Pitfalls the API does not tell you about

- **Drafts are invisible to reads.** `event()` answers null and `events()` leaves them out, while an
  update still finds them. Never choose between update and create on the strength of a lookup:
  `saveEvent()` updates when given a slug and creates only when given none.
- **A 404 on an update does not prove the event is gone.** BKA regenerates the slug on rename, so the
  stored slug may just be stale. `saveEvent()` throws `NotFoundException` rather than creating a
  second copy; look the event up by its id in `allEvents()` (published events only) and decide.
- **No answer is not the same as no effect.** After a `TransportException` a write may have been
  applied; keep the ids of fresh uploads and clean them up after the next successful save.
- `event_status`, `publication_status`, `recurrence` and `special_rate` are not validated by the
  API — an unknown value is stored. `ticketing_designation` is validated.
- `opening_time` and `publication_date` cannot be cleared through `Event`: they are left out of the
  payload when unset.
- **An update of an event that has images fails** with "You do not have permission to reassign the
  image" unless it replaces them — even when `images` is left out. Upload the images again for every
  update (or send none) and `deleteImage()` the replaced ones, which are only detached. Deleting an
  event deletes its current images.
- **The slug changes on rename.** Keep the one in the response.
- **Image legends cannot be set:** a multipart `labels[legend]` part makes the upload fail with a 500,
  a flat `legend` part is ignored, and images cannot be patched.
- **`show_in_print`, `organization` and `ticketing_url` are accepted and dropped.**
- `events()` lists only the account's own published events and ignores v1's filters (`keyword`,
  `place`, `limit`); page with `page` and `itemsPerPage`.
- A label cannot be removed by an update — `null` is rejected as a structural error.

## Create Instance (v1)

The sections from here on describe the v1 client.


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
