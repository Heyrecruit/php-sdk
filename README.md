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
- [License](#license)

## Requirements

- PHP 7.4 or 8.x
- The `curl` and `json` extensions
- Client credentials for a registered Heyrecruit REST app

## Installation

```sh
composer require werbeagentur_artrevolver/scope-php-sdk
```

The package is published on Packagist as `werbeagentur_artrevolver/scope-php-sdk`; the public
repository is `Heyrecruit/php-sdk`. The two names differ on purpose — always require the
Packagist name.

Cloning the repository and requiring `src/Heyrecruit/HeyRestApi.php` directly works as well;
the class has no dependencies beyond the two extensions above.

## Configuration

`HeyRestApi` is configured through a single array. All three keys are required — a missing key
throws an `InvalidArgumentException`.

| Key | Type | Meaning |
|---|---|---|
| `SCOPE_URL` | string | API base URL, e.g. `https://app.heyrecruit.de/api/v2`. A trailing slash is stripped. |
| `SCOPE_CLIENT_ID` | int | The `company_id` of the registered REST app. |
| `SCOPE_CLIENT_SECRET` | string | The client secret, prefixed with `SCOPE!`. |

```php
$api = new Heyrecruit\HeyRestApi([
    'SCOPE_URL'           => 'https://app.heyrecruit.de/api/v2',
    'SCOPE_CLIENT_ID'     => 1234,
    'SCOPE_CLIENT_SECRET' => 'SCOPE!your-client-secret',
]);
```

The constructor authenticates immediately, so creating an instance performs one HTTP request.

**Token caching is optional and session-based.** If a PHP session is active, the access token is
cached in `$_SESSION['HEY_AUTH']` and reused across requests; the cache is discarded when the API
rejects the token. Without an active session every instance authenticates once — the SDK works
outside a web request (CLI, cron) but does not cache there.

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
| `setFilter(string $queryParams = ''): void` | Applies a query string to the job filter. Only known keys are taken over. |
| `getJobs(?int $companyId = null): array` | Active jobs matching the current filter. |
| `getJob(?int $companyId, int $jobId, int $companyLocationId): array` | A single job at one location. |
| `getCompanyDetail(int $companyId): array` | Company data by id. |
| `getCompanyDetailBySubDomain(string $subDomain): array` | Company data by configured subdomain. |
| `apply(array $data): array` | Submits an application. |
| `getAppointmentByToken(string $token): array` | RSVP landing data for an appointment token. |
| `respondToAppointment(string $token, string $action): array` | Sends the RSVP answer (`confirm` or `decline`) as POST. |
| `getGoogleTagCode(?string $publicId = ''): array` | Google Tag Manager snippets as `['head' => ..., 'body' => ...]`. Returns empty strings without an id. |
| `authenticate(bool $force = false): array` | Requests an access token. Pass `true` to bypass the session cache. |
| `setAuthConfig(array $config): void` | Replaces the client credentials. |
| `getAuthData(): array` | The current token and its expiration. |

### Job filter keys

`setFilter()` accepts a raw query string and takes over only these keys: `job_ids`,
`company_location_ids`, `departments`, `employments`, `internal_titles`, `language`, `search`,
`address`, `area_search_distance`, `limit`, `page`. Everything else is ignored. `language` and
`address` are single-valued; the remaining list keys accept repeated parameters.

## Response Shape

Every endpoint method returns the same envelope:

```php
[
    'response'    => array|null,  // decoded API body, null if the body was not valid JSON
    'status_code' => int,         // HTTP status, 0 when the request never completed
    'error'       => string|null, // transport or decoding error, null on success
]
```

Requests use a 5 second connect timeout and a 15 second overall timeout. On HTTP 401 with an
expired token the SDK re-authenticates once and retries, up to three attempts in total.

Authentication failures throw an `Exception`; transport failures do not — they arrive as
`status_code => 0` with a populated `error`.

## License

See the `license` field in `composer.json`.
