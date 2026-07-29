# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] - 2026-07-29

### Breaking

- **PHP 8.4 is now required** (was 8.2). Consumers on 8.2/8.3 can stay on 2.0.0.
- **Client credentials are no longer writable from outside the class.** `url`, `clientId`,
  `clientSecret`, `accessToken`, `refreshToken` and `version` are now `public protected(set)`.
  Reading them is unchanged; assigning to them from calling code now raises an `Error`.
- **`Image::makeFromPath()` throws `InvalidImageException` when a file cannot be read**, instead of
  base64-encoding `false` into an empty string and posting a blank image.
- **`callApi()` throws `InvalidResponseException`** for non-JSON bodies and envelopes without a
  `data` property, where it previously emitted a PHP warning and then a `TypeError`.
- **`isAccessTokenValid()` returns `false` for a malformed token** instead of throwing.
- **`getAccessToken()` and `refreshAccessToken()` now declare `: string`.** Subclasses overriding
  them must add the return type.

### Added

- Automatic token refresh: the client applies the access token per-request and, because the API
  expires tokens after an hour, refreshes and replays once on a `401`.
- `onTokenRefresh` constructor callback (also on `makeFromEnv()`), invoked with
  `(string $accessToken, string $refreshToken)` so callers can persist rotated credentials.
- `lastEnvelope` property and `lastTotal()` accessor, exposing the response envelope's `total` so
  paginated `events()` calls are actually usable.
- Exception hierarchy under the `BkaException` marker interface: `AuthenticationException`,
  `InvalidResponseException`, `ConfigurationException`, `InvalidImageException`,
  `InvalidTextException`. Each extends the SPL type it previously threw, so existing
  `catch (\Exception)` code keeps working.
- Default `timeout` (30s) and `connect_timeout` (10s), overridable through `$guzzleOptions`.
- Documented query filters that were missing: `free` on `events()`; `keyword`/`limit` on
  `organizations()`; `keyword`/`limit`/`place` on `places()`.
- Guzzle 8 support — the constraint is now `^7.9 || ^8.0`.
- Test suite (PHPUnit), GitHub Actions matrix across PHP 8.4/8.5 × Guzzle 7/8, and PHPStan.

### Fixed

- **`SubEvent` sent `opening_time`; the API specifies `openingTime`.** The value was silently
  discarded by the server on every request.
- `publicationDate` is sent as `Y-m-d`, matching the spec's `format: date` (was a full date-time).
- Image extensions are matched case-insensitively and query strings are stripped, so `.JPG` and
  `https://cdn.example/img.png?w=100` are accepted rather than rejected.
- `deleteEvent()` treats any 2xx envelope code as success; a `204` previously reported failure.
- `makeFromEnv()` fails fast naming the missing variable instead of silently building a client with
  an empty base URI.
- The token-refresh request now inherits the caller's transport options (proxy, TLS, timeouts).
- Constructing two clients from one `HandlerStack` no longer registers the auth middleware twice.
- The constructor no longer performs network I/O.
- `openingTime` is optional on both `Event` and `SubEvent`, matching the spec, and omitted when null.
- Implicitly-nullable parameters made explicit — 9 deprecation notices on PHP 8.4+ are gone.

### Security

- `Client::__debugInfo()` redacts `clientSecret`, `accessToken` and `refreshToken`, so `var_dump()`,
  `print_r()` and error-tracker serialisation no longer leak credentials in plaintext.

## [2.0.0] - 2024-09-25

- Implemented the new BKA event fields and updated the examples.

## [1.0.4] - 2024-05-07

- Fixed the access-token refresh.

## [1.0.3] - 2024-05-06

- Corrected the datetime format.

## [1.0.2] - 2024-05-06

- Improved the readme and example.

## [1.0.1] - 2024-05-06

- Extended the client with further endpoints.

## [1.0.0] - 2024-05-06

- Initial release.
