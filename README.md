# HeyRestApi

PHP client for the Heyrecruit REST API (`/api/v2`). It handles authentication, token renewal
and the job, company, application and appointment endpoints.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Methods](#methods)
- [Response Shape](#response-shape)
- [Architecture](#architecture)
- [Upgrading from 2.x](#upgrading-from-2x)
- [Tests](#tests)
- [License](#license)

## Requirements

- PHP 8.1 or newer
- The `curl` and `json` extensions
- Client credentials for a registered Heyrecruit REST app

## Installation

```sh
composer require werbeagentur_artrevolver/scope-php-sdk
```

The package is published on Packagist as `werbeagentur_artrevolver/scope-php-sdk`; the public
repository is `Heyrecruit/php-sdk`. The two names differ on purpose — always require the
Packagist name.

## Configuration

`HeyRestApi` is configured through a single array. All three keys are required — a missing key
throws an `InvalidArgumentException`.

| Key | Type | Meaning |
|---|---|---|
| `SCOPE_URL` | string | API base URL, e.g. `https://app.heyrecruit.de/api/v2`. Trailing slashes are stripped. |
| `SCOPE_CLIENT_ID` | int | The `company_id` of the registered REST app. |
| `SCOPE_CLIENT_SECRET` | string | The client secret, prefixed with `SCOPE!`. |

```php
$api = new Heyrecruit\HeyRestApi([
    'SCOPE_URL'           => 'https://app.heyrecruit.de/api/v2',
    'SCOPE_CLIENT_ID'     => 1234,
    'SCOPE_CLIENT_SECRET' => 'SCOPE!your-client-secret',
]);
```

The constructor authenticates immediately, so creating an instance performs one HTTP request and
throws right away when the credentials are wrong.

**Token caching.** With an active PHP session the access token is cached in
`$_SESSION['HEY_AUTH_<fingerprint>']` and reused across requests. The fingerprint is derived from
the client id and secret, so two installations sharing one session — two career pages under the
same hostname, for instance — never share a token. Without a session the token lives for the
lifetime of the instance, so CLI and cron work without authenticating per call. Either way the
cache is discarded as soon as the API rejects the token.

## Usage

```php
// Read the job filter from the current query string, then fetch jobs.
$api->setFilter($_SERVER['QUERY_STRING']);
$result = $api->getJobs($companyId);

if ($result['status_code'] === 200) {
    $jobs = $result['response']['data'];
}
```

## Methods

| Method | Purpose |
|---|---|
| `setFilter(string $queryParams = ''): void` | Applies a query string to the job filter. Only known keys are taken over. Repeated calls **add** to the filter. |
| `replaceFilter(string $queryParams = ''): void` | Like `setFilter()`, but discards everything set before. |
| `getJobs(?int $companyId = null): array` | Active jobs matching the current filter. |
| `getJob(?int $companyId, int $jobId, int $companyLocationId): array` | A single job at one location. |
| `getCompanyDetail(int $companyId): array` | Company data by id. |
| `getCompanyDetailBySubDomain(string $subDomain): array` | Company data by configured subdomain. |
| `apply(array $data): array` | Submits an application. |
| `getAppointmentByToken(string $token): array` | RSVP landing data for an appointment token. |
| `respondToAppointment(string $token, string $action): array` | Sends the RSVP answer (`confirm` or `decline`) as POST. |
| `getGoogleTagCode(?string $publicId = ''): array` | Google Tag Manager snippets as `['head' => ..., 'body' => ...]`. Returns empty strings without an id. |
| `authenticate(bool $force = false): array` | Requests an access token. Pass `true` to bypass the cache. |
| `setAuthConfig(array $config): void` | Replaces the client credentials and drops a token issued for the old ones. |
| `getAuthData(): array` | The current token and its expiration. |

### Job filter keys

`setFilter()` accepts a raw query string and takes over only these keys: `job_ids`,
`company_location_ids`, `departments`, `employments`, `internal_titles`, `language`, `search`,
`address`, `area_search_distance`, `limit`, `page`. Everything else is ignored.

`language`, `search` and `address` are single-valued — a repeated parameter keeps its first
value. `area_search_distance`, `limit` and `page` are cast to integers with a floor of 1. The
remaining keys are lists and accept repeated parameters.

## Response Shape

Every endpoint method returns the same envelope:

```php
[
    'response'    => array|null,  // decoded API body, null if the body was not valid JSON
    'status_code' => int,         // HTTP status, 0 when the request never completed
    'error'       => string|null, // transport or decoding error, null on success
]
```

Requests use a 5 second connect timeout and a 15 second overall timeout; `apply()` gets 50 seconds,
because the API processes an application synchronously. On HTTP 401 with an
expired token the SDK discards the cached token, re-authenticates and retries — up to three
attempts in total.

Authentication failures throw an `Exception` carrying the API message from the `errors` key of the
response. Transport failures do not throw: they arrive as
`status_code => 0` with a populated `error`, so a stalled API cannot take a career page down
with an uncaught error.

## Architecture

`HeyRestApi` is a facade. The parts behind it are separately usable and replaceable:

| Class | Role |
|---|---|
| `Http\Transport` | The HTTP boundary as an interface. |
| `Http\CurlTransport` | cURL implementation with timeouts and evaluated errors. |
| `Http\ApiResponse` | Immutable result: status, decoded body, error. |
| `Auth\Authenticator` | Swaps credentials for a token and keeps it fresh. |
| `Auth\AccessToken` | The token with its expiration. |
| `Auth\TokenStore` | Where a token is remembered, as an interface. |
| `Auth\SessionTokenStore` | Session-backed store, inert without a session. |
| `Auth\ArrayTokenStore` | Per-instance store for CLI, cron and tests. |
| `Job\JobFilter` | The job filter whitelist and query-string parsing. |
| `Tag\GoogleTagManager` | The GTM snippets. |

Both collaborators can be injected, which is what makes the client testable without a server:

```php
$api = new Heyrecruit\HeyRestApi($config, $myTransport, $myTokenStore);
```

## Upgrading from 2.x

The configuration array, every endpoint method and the response envelope are unchanged — for
most consumers the upgrade is a version bump. What changed:

- **PHP 8.1 is now the minimum** (was 7.4).
- **`printH()` was removed.** It printed and called `die` — not something a client library
  should offer.
- **The global `DS` constant is no longer used.** Until 2.3 every request built its URL with it,
  so the SDK only worked inside a host that happened to define it; in a CakePHP application,
  where `DS` is `DIRECTORY_SEPARATOR`, the URL broke on Windows.
- **`search` is sent as a scalar.** It used to go out as `search[]=…` because only `language`
  and `address` were unwrapped.
- **`preview` is gone from the filter.** The key was never in the whitelist, so it was always
  sent as `0`; `jobs/index` does not read it.
- **The retry ceiling returns the normal envelope**, not a separate `['status_code', 'success',
  'message']` array.
- **`getAuthData()` can now throw**, because it returns a guaranteed-valid token.
- **The session key is no longer the fixed `HEY_AUTH`** but carries a fingerprint of the
  credentials. An existing cached token from 2.x is simply ignored, so the first request after
  the upgrade authenticates once more.
- **Auth error messages now arrive.** The old code read a `message` key that the API never
  sends — every failure degraded to the bare HTTP status. It reads `errors` first now.
- `authenticate()` and `setAuthConfig()` keep their signatures; `authenticate()` gained an
  optional `$force` flag, and `replaceFilter()` is new.

## Tests

```sh
composer install
vendor/bin/phpunit
```

The suite runs without a network: `Http\Transport` is faked. Only `CurlTransportTest` touches
the socket layer, and only against a closed local port.

## License

See the `license` field in `composer.json`.
