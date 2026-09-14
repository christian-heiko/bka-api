# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

An unofficial PHP 8.4+ Composer library (`christian-heiko/bka-api`) wrapping the event API of [Berner Kulturagenda](https://bka.ch/) / [In-Situ](https://www.in-situ.org/de/). Published as a library — there is no application, no framework, and no runtime of its own. Supports Guzzle 7 and 8.

**The authoritative API spec is online — read it before changing any payload or endpoint:**

```bash
curl -A "Mozilla/5.0" https://admin.bka.ch/openapi.yaml -o /tmp/bka.yaml
```

OpenAPI 3.0, ~1800 lines, browsable at [admin.bka.ch/api/doc](https://admin.bka.ch/api/doc). `admin.insitu.live` serves a byte-identical copy; the older `admin.in-situ.org` host now 301s to it behind a login. Two gotchas: plain `curl`/WebFetch without a browser User-Agent gets a **403**, and the spec is *generated from Symfony form types*, so its `required` lists contain artifacts — `MultilangCollectionType` lists `_token` (a CSRF field) as required. Treat `required` as advisory; treat property **names** as authoritative.

`ApiEventType` is the event write schema; `Event2` is the read schema. As of spec 1.0.8 every documented path has a client method and the `Event` DTO covers every documented write field.

## Commands

```bash
composer install          # install deps (Guzzle only)
composer dump-autoload    # after adding/renaming classes under src/
php examples/events.php   # v1 example; needs real v1 credentials to do anything
php examples/events-v2.php  # v2 example; needs BKA_TOKEN — and writes to the public agenda
```

```bash
composer test             # PHPUnit, v1 and v2 — no credentials or network needed
composer stan             # PHPStan
composer check            # both
vendor/bin/phpunit --filter it_exposes_the_envelope_total   # single test
```

**The whole suite runs offline** via `MockHandler`, so there is no excuse for not running it. The one thing it cannot prove is that the server accepts a payload — only a live round-trip through `examples/events.php` with real credentials does that.

Verify both halves of the Guzzle constraint after touching dependencies (CI does this automatically):

```bash
composer update --with=guzzlehttp/guzzle:^7.9 --prefer-lowest && composer test
composer update && composer test     # back to 8.x
```

### Writing tests here

- Extend `tests/TestCase`, and build clients with `$this->client([...responses])` — it wires a `MockHandler` plus a spy middleware that records resolved request options (`$this->lastOptions()`).
- `Support\StubClient` overrides `refreshAccessToken()`, the one network call a `MockHandler` cannot intercept because it builds its own Guzzle instance. That is why the method is `protected`, not `private`.
- `Support\EventFactory` holds the sample payloads; `Support\Jwt` mints tokens with a chosen `exp`.
- **Do not assert via `Client::getConfig()`** — it is deprecated on Guzzle 7 and `phpunit.xml` sets `failOnDeprecation`, so it breaks the Guzzle 7 CI job. Use `lastOptions()` or `$this->mock->getLastRequest()` instead.

PHPStan sits at **level 5, deliberately**. Level 6 requires a value type for every `array`, which cannot be stated honestly while `callApi()` returns `object|array|bool` and responses are raw `stdClass`. Raise it when typed response models land.

## Architecture (v1)

This section and the conventions further down describe the v1 client (`src/Client.php`, `src/Data/*`). v2 has its own section below; where a rule differs — dates, nulls, updates — the v2 section wins.

Two layers, connected by one interface:

- **`src/Client.php`** — the whole HTTP surface. One thin public method per API endpoint, all funnelling into `callApi()`. Adding an endpoint means adding one method here plus a line in the Readme's "Implemented Endpoints" block.
- **`src/Data/*`** — request payload builders (`Event`, `SubEvent`, `Rate`, `Image`, `Text`), all implementing `src/Interface/ToArray.php`. They exist purely to serialise *outbound* writes. Responses are **not** hydrated into these classes — `callApi()` returns raw `json_decode` output (`stdClass`/array).
- **`src/Enum/*`** — backed string enums for the API's fixed vocabularies (`EventStatus`, `PublicationStatus`, `Recurrence`, `SpecialRate`).

### Request/response contract

`callApi()` unwraps the API envelope and returns `$parsed->data` — callers never see the outer object. The full envelope is retained on `$client->lastEnvelope`, with `lastTotal()` for the `total` field; without that, `limit`/`page` pagination is unusable, which it was until recently. `DELETE` instead returns `bool`, true for any 2xx.

Two failure modes, deliberately distinct:
- **4xx/5xx from the API** → Guzzle's `ClientException`, unchanged. The intended caller pattern (see `examples/events.php`) is to catch it and read the error body via `$client->json($e->getResponse())`.
- **A response this client cannot parse** → `InvalidResponseException` (non-JSON body, or an envelope with no `data`). Previously these surfaced as a `TypeError` about return types, which told the caller nothing.

All package exceptions implement the `BkaException` marker interface while extending the SPL type they previously threw, so `catch (\Exception)` in existing consumer code still works. Add new ones the same way.

Write methods accept `array|Event`. The runtime check is `instanceof ToArray`, not `instanceof Event` — any `ToArray` implementation works despite the narrower type hint.

### Auth flow

Access tokens are valid **1 hour**, refresh tokens **6 months** (per the spec's `oAuth2` section). The token is validated **locally** by base64-decoding the JWT payload and comparing `exp` against `time()` (`isAccessTokenValid()`); malformed tokens throw. If expired, `refreshAccessToken()` does a `refresh_token` grant against `{url}/token`.

This is handled by `authMiddleware()`, pushed onto the Guzzle `HandlerStack`. It sets `Authorization` per-request from the *current* token and, on a `401`, refreshes once and replays the request — guarded by the `RETRIED_OPTION` flag so it cannot loop.

Three consequences worth knowing:
- **The middleware is pushed last, which makes it innermost** — inside `http_errors`. That is deliberate and load-bearing: it must see the raw `401` response *before* `http_errors` converts it into a `ClientException`. A successful retry surfaces to outer middleware as a plain 200, so an outer middleware cannot observe the retry at all. Instrument at the handler (`MockHandler::getLastRequest()`) when testing this, not via a pushed middleware.
- **A caller-supplied `HandlerStack` is cloned, never pushed onto.** Pushing directly would register the auth middleware twice if one stack builds two clients. `HandlerStack` keeps its middleware in a plain array, so `clone` detaches it — and `clone` is used rather than `resolve()` because `resolve()` throws on a stack with no handler set.
- **The constructor performs no network I/O.** Construction is side-effect-free; the first refresh happens on first request. So `$client->accessToken` is not guaranteed fresh straight after construction — pass `onTokenRefresh` (last constructor arg, also on `makeFromEnv()`) to observe and persist new tokens.

`refreshAccessToken()` is `protected` specifically so tests can stub it — it builds its own `Guzzle` instance internally and is otherwise unmockable. It receives a **whitelist** of transport options (`timeout`, `connect_timeout`, `proxy`, `verify`, `cert`, `ssl_key`) from `$guzzleOptions`; `handler` is deliberately excluded, since reusing the API stack would route the token request back through the auth middleware and recurse.

Credentials are `public protected(set)` (PHP 8.4 asymmetric visibility): readable, so nothing broke for consumers, but not writable from outside. `__debugInfo()` redacts them — keep it updated when adding any secret-bearing property, or it will leak into logs and error trackers.

### URL construction quirks

The constructor mutates the local `$url` after promotion, so:
- `$this->url` keeps the **original, un-normalised** value passed in.
- Guzzle's `base_uri` is `{url}/v{version}/` (version defaults to 1).
- `$this->tokenEndpoint` is `{url}/token` — outside the versioned path. Given a base of `https://admin.bka.ch/api` that resolves to `https://admin.bka.ch/api/token`, which is what the spec documents.

## API v2 (`src/V2/`)

The BKA profile now issues **v2 tokens**: a LexikJWT with `username`/`roles`/`uuid` claims and years of validity. v1 is a league/oauth2-server resource server: it accepts that token's signature, then fails on the missing `jti`, so **every v1 call answers 500**. v2 lives at `{host}/api/v2`, is API Platform, and has **no published spec** — `/api/doc?_format=…` serves the v1 page. The JSON-LD contexts at `/api/v2/contexts/{Resource}` list the property names. `V2\Client` is deliberately a separate class, not a `$version` switch: auth, envelope, addressing, update verb, relations, images and errors all differ.

Everything in `src/V2` was established against production on 2026-09-14:

- **Write model** (from 422 violations plus draft round trips): required `name`, `event_status`, `place`, `categories`, `date_from`, `date_to`, `audience`, `labels` (which must hold `description`). Relations are IRIs: places **by slug** (`/api/v2/places/81` is rejected), categories, audiences and images by id. Rates are `{price: float, labels: {designation}}` (a string price is a 400). Ticket links are `ticketings[{ticketing_designation, labels: {ticketingUrl, designation}}]`. `organization`, `show_in_print`/`showInPrint` and `ticketing_url` are accepted and **dropped**. `event_status`, `publication_status`, `recurrence` and `special_rate` are **not validated** server-side (an unknown value gets stored), so send only enum values; `ticketing_designation` is validated.
- **Drafts are invisible to GET** (item and collection) but can be patched. So `saveEvent()` never decides on a lookup: it updates when given a slug and creates when given none. It deliberately does **not** answer a 404 with a create — BKA regenerates the slug on rename, so a 404 may only mean the stored slug is stale, and a create would publish the event twice.
- **No answer ≠ no effect.** Guzzle transport errors become `TransportException`: a write may have been applied although its response was lost. Callers must not treat fresh uploads as orphans in that case.
- **Any PATCH of an event with attached images is a 422** ("reassign the image") unless `images` is replaced by fresh uploads or `[]`, even when `images` is omitted. Replaced images stay detached until deleted; deleting the event deletes the attached ones. Rates and ticketings are replaced, not appended. The slug is regenerated on rename.
- **Image upload**: multipart `file` only, with `Accept: application/ld+json` (anything else is a 406). A `labels[legend][…]` part gives a 500, a flat `legend` part is ignored, and PATCH on an image is a 405.
- `date_from` must precede `date_to` — a class-level violation with an empty `propertyPath`.
- A trailing slash answers 301, so the client disables redirects: a POST can never silently turn into a GET.

**Probing safely.** A POST to `/api/v2/events` without `name` always fails validation (422) and persists nothing. Answer format questions by sending one candidate field at a time and reading `violations`. Anything that needs a real write needs a draft test event that is deleted in a `finally`.

The v2 tests live in `tests/V2` with their own `TestCase` (MockHandler, `lastRequest()` sees requests after the auth middleware) and `EventFactory`.

## Conventions that matter

**Payload keys are the API's, not ours — check the spec, don't pattern-match.** `SubEvent::toArray()` emits `date_from` and `date_to` in snake_case but `openingTime` in camelCase. That is genuinely what `ApiSubEventType` specifies; it is not a typo, and "fixing" `openingTime` to `opening_time` silently drops the value server-side (that was a real bug, fixed in 2.1.0). Verify any key change against `openapi.yaml` before making it.

**Date formatting is centralised on `Event`.** `Event::DATE_FORMAT` (`Y-m-d\TH:i:s\Z`), `Event::TIME_FORMAT` (`H:i`), and `Event::PUBLICATION_DATE_FORMAT` (`Y-m-d` — `publicationDate` is `format: date`, not `date-time`); `SubEvent` references those constants. Never format dates inline.

**Optional fields are conditionally omitted, not sent as null.** In `Event::toArray()`, `specialRate` and `publicationDate` are only added when non-null. Follow that pattern for new nullable fields — the API rejects some nulls.

**`Text` is a language map, not a string.** Every human-readable field (`description`, `printDescription`, image `legend`, rate `designation`) is a `Text` built with `Text::make($lang, $string)`, serialising to `['de' => '...']`. Only `de` is currently supported upstream. `setText()` throws `InvalidTextException` (an `InvalidArgumentException`) on empty strings.

**`Image` fails loudly on read, lazily on extension.** `Image::makeFromPath()` reads and base64-encodes immediately and throws `InvalidImageException` if the file is unreadable — it must not silently encode `false` into an empty payload, which is exactly the bug that let blank images reach the API. The extension check (`.jpg`, `.jpeg`, `.png`) still only runs in `toArray()`, so a bad extension surfaces at send time. Extensions are normalised: query strings are stripped and case is ignored, so `.JPG` and `https://cdn.example/img.png?w=100` both work.

### Adding a field to `Event`

1. Add a promoted constructor property (required ones before the `// Optional:` marker, optional ones after with a default).
2. Map it in `toArray()` — conditionally if nullable.
3. Update `examples/events.php` if it belongs in the canonical usage flow, and the Readme if it changes the public API.
