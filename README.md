# bluecapapps/downctl-php

PHP SDK for reporting errors and server metrics to Downctl. Zero external dependencies - uses PHP's built-in `curl` extension.

## Requirements

- PHP 8.2+
- `curl` extension (enabled by default in most PHP distributions)

## Installation

```bash
composer require bluecapapps/downctl-php
```

## Configuration

The client is configured via a `Config` object. You can build it from environment variables or construct it directly.

### From environment variables (recommended)

Set these in your server environment or `.env` file:

```ini
DOWNCTL_API_KEY=your-secret-api-key
DOWNCTL_PUBLIC_KEY=your-public-key   # optional, for frontend reporting
```

Then instantiate the client:

```php
use Bluecapapps\Downctl\Client;
use Bluecapapps\Downctl\Config;

$client = new Client(Config::fromEnv());
```

### Direct construction

```php
use Bluecapapps\Downctl\Client;
use Bluecapapps\Downctl\Config;

$client = new Client(new Config(
    apiKey: 'your-secret-api-key',
));
```

### All config options

| Option | Type | Default | Description |
|---|---|---|---|
| `apiKey` | `string` | - | **Required.** Server-side API key (keep secret) |
| `publicKey` | `?string` | `null` | Public key for frontend use (safe to expose) |
| `timeoutSeconds` | `int` | `5` | HTTP request timeout |
| `silent` | `bool` | `true` | Swallow transport errors - see [Silent mode](#silent-mode) |

All SDK requests are sent to `https://downctl.com`.

## Usage

### Capture an exception

The most common use case. Extracts the message, stack trace, and current request URL automatically. Query strings are stripped from captured URLs before sending, so request secrets such as tokens, codes, and signed URL parameters are not included in SDK payloads.

```php
try {
    riskyOperation();
} catch (\Throwable $e) {
    $client->captureException($e);
}
```

Capture with a custom severity level and additional context:

```php
$client->captureException($e, level: 'warning', context: [
    'user_id' => $userId,
    'order_id' => $orderId,
]);
```

### Report a message

Send a plain message without an exception object:

```php
$client->report('Payment gateway timed out', level: 'error');
```

Include a manually built stack trace, URL, or context:

```php
$client->report(
    message: 'Slow database query detected',
    level: 'warning',
    stackTrace: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
    url: 'https://yourapp.com/checkout',
    context: ['query_time_ms' => 4200],
);
```

Manually provided URLs are also sent without their query string. Context values are sent as provided by the base PHP SDK; integrations such as `bluecapapps/downctl-laravel` may add framework-specific context redaction before calling this client.

Valid levels: `error`, `warning`, `info`.

### Report server metrics

For applications that run alongside the Go agent and want to report metrics directly from PHP:

```php
use Bluecapapps\Downctl\Payload\MetricsPayload;

$metrics = new MetricsPayload(
    cpuPercent: 42.5,
    memoryPercent: 61.0,
    memoryTotalMb: 8192,
    memoryUsedMb: 5000,
    diskPercent: 38.0,
    diskTotalGb: 100,
    diskUsedGb: 38,
    loadAvg1m: 1.2,   // optional
    loadAvg5m: 0.9,   // optional
    loadAvg15m: 0.8,  // optional
);

$client->reportMetrics($metrics);
```

### Verify connectivity

Returns `true` if the Downctl API is reachable, `false` otherwise. Safe to call during a startup health check.

```php
if (! $client->ping()) {
    error_log('Downctl API is unreachable');
}
```

## Cron Monitoring

Cron monitoring lets Downctl alert you when a scheduled PHP script misses its expected window, runs too long, or exits with an error. Each monitor has a unique ping token; your script hits that URL to prove it ran.

### 1. Create a monitor in Downctl

Open your site in Downctl, navigate to **Cron monitors**, and add a monitor. Set the schedule (cron expression or a simple minute frequency) and a grace period. Copy the ping token shown on the monitor card.

### 2. Add pings to your script

For simple scripts that just need a heartbeat:

```php
<?php

require_once '/path/to/vendor/autoload.php';

use Bluecapapps\Downctl\Client;
use Bluecapapps\Downctl\Config;

$downctl = new Client(Config::fromEnv());

$downctl->pingCronStarted('your-ping-token');

try {
    // your job logic here
    runImport();

    $downctl->pingCronFinished('your-ping-token', [
        'runtime' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
    ]);
} catch (\Throwable $e) {
    $downctl->pingCronFailed('your-ping-token', [
        'exit_code'       => 1,
        'failure_message' => $e->getMessage(),
    ]);

    throw $e;
}
```

Or use a wrapper to capture start time automatically:

```php
$token = 'your-ping-token';
$start = microtime(true);

$downctl->pingCronStarted($token);

try {
    runImport();
    $downctl->pingCronFinished($token, ['runtime' => round(microtime(true) - $start, 4)]);
} catch (\Throwable $e) {
    $downctl->pingCronFailed($token, ['exit_code' => 1, 'failure_message' => $e->getMessage()]);
    exit(1);
}
```

For jobs that don't need lifecycle tracking, a single heartbeat is enough:

```php
runImport();

$downctl->pingCron('your-ping-token');
```

### Available methods

| Method | Ping URL suffix | When to call |
|---|---|---|
| `pingCron($token, $metadata)` | _(none)_ | Simple heartbeat — job ran and succeeded |
| `pingCronStarted($token, $metadata)` | `/started` | Immediately before the job runs |
| `pingCronFinished($token, $metadata)` | `/finished` | After a successful run |
| `pingCronFailed($token, $metadata)` | `/failed` | After a failed run |

### Accepted metadata

All fields are optional in every method:

| Field | Type | Description |
|---|---|---|
| `runtime` | float | Job duration in seconds |
| `memory` | int | Peak memory usage in bytes |
| `exit_code` | int | Process exit code (non-zero is always treated as failure) |
| `failure_message` | string | Human-readable failure reason (max 255 chars) |

### Without Composer

If you can't use Composer, ping the URL directly from your shell script — no API key needed:

```bash
#!/bin/bash

TOKEN="your-ping-token"
BASE="https://downctl.com/ping/cron"

curl -s "${BASE}/${TOKEN}/started"

php /path/to/import.php
EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    curl -s -X POST "${BASE}/${TOKEN}/finished" \
         -H "Content-Type: application/json" \
         -d "{\"exit_code\": 0}"
else
    curl -s -X POST "${BASE}/${TOKEN}/failed" \
         -H "Content-Type: application/json" \
         -d "{\"exit_code\": ${EXIT_CODE}, \"failure_message\": \"Script exited with code ${EXIT_CODE}\"}"
fi
```

## Silent mode

By default `silent: true`. Any exception thrown during an HTTP call - network timeouts, DNS failures, invalid API keys - is caught internally and discarded. **Your application will never crash because of the SDK.**

Set `silent: false` during local development to catch misconfiguration early:

```php
$client = new Client(new Config(
    apiKey: 'your-key',
    silent: false,  // let TransportException bubble up
));
```

## Custom HTTP transport

The `CurlTransport` is used by default. To substitute your own (e.g. for testing, or to use Guzzle), implement `TransportInterface` and pass it as the second constructor argument:

```php
use Bluecapapps\Downctl\Http\TransportInterface;
use Bluecapapps\Downctl\Exception\TransportException;

class GuzzleTransport implements TransportInterface
{
    public function __construct(private \GuzzleHttp\Client $guzzle) {}

    public function post(string $url, array $headers, array $body): int
    {
        try {
            $response = $this->guzzle->post($url, [
                'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
                'json'    => $body,
            ]);
            return $response->getStatusCode();
        } catch (\Exception $e) {
            throw new TransportException($e->getMessage(), previous: $e);
        }
    }

    public function get(string $url, array $headers): int
    {
        try {
            return $this->guzzle->get($url, ['headers' => $headers])->getStatusCode();
        } catch (\Exception $e) {
            throw new TransportException($e->getMessage(), previous: $e);
        }
    }
}

$client = new Client($config, new GuzzleTransport(new \GuzzleHttp\Client()));
```

## Legacy PHP integration

For applications without a PSR-4 autoloader, require Composer's autoloader manually:

```php
require_once '/path/to/vendor/autoload.php';

use Bluecapapps\Downctl\Client;
use Bluecapapps\Downctl\Config;

$downctl = new Client(Config::fromEnv());

set_exception_handler(function (\Throwable $e) use ($downctl): void {
    $downctl->captureException($e);
    // Re-display or log the error as appropriate for your app
});
```

## Troubleshooting

### Nothing appears in Downctl after calling `report()`

1. **Check silent mode.** Set `silent: false` temporarily and wrap the call in a try/catch to surface any `TransportException`.
2. **Verify credentials.** Make sure `DOWNCTL_API_KEY` matches the key shown in your site dashboard.
3. **Call `ping()`.** If it returns `false`, the Downctl API is unreachable from the host running PHP.
4. **Check `curl` is enabled.** Run `php -m | grep curl`. If it's missing, install the `php-curl` package for your distribution.

### `InvalidArgumentException: Downctl API key must not be empty`

`Config::fromEnv()` couldn't find `DOWNCTL_API_KEY` in `$_ENV` or `getenv()`. Confirm the variable is exported to the PHP process - values in a `.env` file must be loaded by your application (e.g. via `vlucas/phpdotenv`) before the SDK reads them.

### `TransportException: curl error [6]: Could not resolve host`

The Downctl API hostname can't be resolved from the server running PHP. Check DNS, firewall rules, and whether `https://downctl.com` is accessible from that host.

### `TransportException: Errors endpoint returned HTTP 401`

The API key is missing or wrong. Confirm `DOWNCTL_API_KEY` matches the secret key on the site's settings page, not the public key.

### `TransportException: Errors endpoint returned HTTP 422`

The payload failed server-side validation. Most common causes:
- `level` is not one of `error`, `warning`, `info`
- `message` exceeds 5,000 characters
- `stack_trace` exceeds 50,000 characters

## Running the tests

```bash
composer install
./vendor/bin/pest
```
