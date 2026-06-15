<?php

use Bluecapapps\Downctl\Client;
use Bluecapapps\Downctl\Config;
use Bluecapapps\Downctl\Exception\TransportException;
use Bluecapapps\Downctl\Http\TransportInterface;

function makeTransport(int $postStatus = 201, int $getStatus = 200, bool $throws = false): TransportInterface
{
    return new class($postStatus, $getStatus, $throws) implements TransportInterface {
        public array $postCalls = [];
        public array $getCalls  = [];

        public function __construct(
            private int $postStatus,
            private int $getStatus,
            private bool $throws,
        ) {}

        public function post(string $url, array $headers, array $body): int
        {
            $this->postCalls[] = compact('url', 'headers', 'body');

            if ($this->throws) {
                throw new TransportException('connection refused');
            }

            return $this->postStatus;
        }

        public function get(string $url, array $headers): int
        {
            $this->getCalls[] = compact('url', 'headers');

            return $this->getStatus;
        }
    };
}

// ── report ────────────────────────────────────────────────────────────────────

test('report sends to errors endpoint', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->report('Something broke');

    expect($transport->postCalls)->toHaveCount(1)
        ->and($transport->postCalls[0]['url'])->toBe('https://downctl.com/api/v1/errors')
        ->and($transport->postCalls[0]['headers'])->toBe(['X-Downctl-Key' => 'key-abc'])
        ->and($transport->postCalls[0]['body']['message'])->toBe('Something broke')
        ->and($transport->postCalls[0]['body']['level'])->toBe('error');
});

test('report includes optional fields when provided', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->report('Slow render', 'warning', null, 'https://app.test/page?token=secret#section', ['user_id' => 99]);

    $body = $transport->postCalls[0]['body'];

    expect($body['level'])->toBe('warning')
        ->and($body['url'])->toBe('https://app.test/page#section')
        ->and($body['context'])->toBe(['user_id' => 99]);
});

// ── captureException ──────────────────────────────────────────────────────────

test('captureException extracts message and trace from throwable', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $e = new \RuntimeException('Test exception');
    $client->captureException($e);

    $body = $transport->postCalls[0]['body'];

    expect($body['message'])->toBe('Test exception')
        ->and($body['stack_trace'])->toContain('#0');
});

test('captureException strips request query strings from captured urls', function () {
    $_SERVER['HTTPS']       = 'on';
    $_SERVER['HTTP_HOST']   = 'app.test';
    $_SERVER['REQUEST_URI'] = '/checkout?access_token=secret&order=123';

    try {
        $transport = makeTransport();
        $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

        $client->captureException(new \RuntimeException('Payment failed'));

        expect($transport->postCalls[0]['body']['url'])->toBe('https://app.test/checkout');
    } finally {
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
    }
});

// ── silent mode ───────────────────────────────────────────────────────────────

test('silent mode swallows transport exceptions by default', function () {
    $transport = makeTransport(throws: true);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    // Must not throw
    $client->report('Something broke');

    expect(true)->toBeTrue();
});

test('non-silent mode rethrows transport exceptions', function () {
    $transport = makeTransport(throws: true);
    $client    = new Client(new Config(apiKey: 'key-abc', silent: false), $transport);

    $client->report('Something broke');
})->throws(TransportException::class);

test('non-silent mode rethrows on non-2xx response', function () {
    $transport = makeTransport(postStatus: 401);
    $client    = new Client(new Config(apiKey: 'key-abc', silent: false), $transport);

    $client->report('Something broke');
})->throws(TransportException::class);

// ── ping ──────────────────────────────────────────────────────────────────────

test('ping returns true when health endpoint responds 200', function () {
    $transport = makeTransport(getStatus: 200);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    expect($client->ping())->toBeTrue()
        ->and($transport->getCalls[0]['url'])->toBe('https://downctl.com/api/v1/health');
});

test('ping returns false when health endpoint fails', function () {
    $transport = makeTransport(getStatus: 503);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    expect($client->ping())->toBeFalse();
});

// ── cron pings ───────────────────────────────────────────────────────────────

test('pingCron sends a GET to the cron ping URL', function () {
    $transport = makeTransport(getStatus: 200);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCron('abc123');

    expect($transport->getCalls)->toHaveCount(1)
        ->and($transport->getCalls[0]['url'])->toBe('https://downctl.com/ping/cron/abc123');
});

test('pingCron with metadata sends a POST', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCron('abc123', ['runtime' => 4.2]);

    expect($transport->postCalls)->toHaveCount(1)
        ->and($transport->postCalls[0]['url'])->toBe('https://downctl.com/ping/cron/abc123')
        ->and($transport->postCalls[0]['body'])->toBe(['runtime' => 4.2]);
});

test('pingCronStarted sends to /started suffix', function () {
    $transport = makeTransport(getStatus: 200);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCronStarted('abc123');

    expect($transport->getCalls[0]['url'])->toBe('https://downctl.com/ping/cron/abc123/started');
});

test('pingCronFinished sends to /finished suffix with metadata', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCronFinished('abc123', ['runtime' => 12.5, 'memory' => 52428800]);

    expect($transport->postCalls[0]['url'])->toBe('https://downctl.com/ping/cron/abc123/finished')
        ->and($transport->postCalls[0]['body'])->toBe(['runtime' => 12.5, 'memory' => 52428800]);
});

test('pingCronFailed sends to /failed suffix', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCronFailed('abc123', ['exit_code' => 1, 'failure_message' => 'Timed out']);

    expect($transport->postCalls[0]['url'])->toBe('https://downctl.com/ping/cron/abc123/failed')
        ->and($transport->postCalls[0]['body']['exit_code'])->toBe(1)
        ->and($transport->postCalls[0]['body']['failure_message'])->toBe('Timed out');
});

test('pingCron does not include the API key in request headers', function () {
    $transport = makeTransport(getStatus: 200);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCron('abc123');

    expect($transport->getCalls[0]['headers'])->toBe([]);
});

test('cron ping swallows transport exceptions in silent mode', function () {
    $transport = makeTransport(throws: true);
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $client->pingCron('abc123', ['runtime' => 1.0]);

    expect(true)->toBeTrue();
});

test('cron ping rethrows in non-silent mode on transport error', function () {
    $transport = makeTransport(throws: true);
    $client    = new Client(new Config(apiKey: 'key-abc', silent: false), $transport);

    $client->pingCron('abc123', ['runtime' => 1.0]);
})->throws(TransportException::class);

test('cron ping rethrows in non-silent mode on non-2xx response', function () {
    $transport = makeTransport(getStatus: 429);
    $client    = new Client(new Config(apiKey: 'key-abc', silent: false), $transport);

    $client->pingCron('abc123');
})->throws(TransportException::class);

// ── reportMetrics ─────────────────────────────────────────────────────────────

test('reportMetrics sends to metrics endpoint with api key', function () {
    $transport = makeTransport();
    $client    = new Client(new Config(apiKey: 'key-abc'), $transport);

    $metrics = new \Bluecapapps\Downctl\Payload\MetricsPayload(
        cpuPercent: 42.5,
        memoryPercent: 61.0,
        memoryTotalMb: 8192,
        memoryUsedMb: 5000,
        diskPercent: 38.0,
        diskTotalGb: 100,
        diskUsedGb: 38,
        loadAvg1m: 1.2,
    );

    $client->reportMetrics($metrics);

    $call = $transport->postCalls[0];

    expect($call['url'])->toBe('https://downctl.com/api/v1/metrics')
        ->and($call['headers'])->toBe(['X-Downctl-Key' => 'key-abc'])
        ->and($call['body']['cpu_percent'])->toBe(42.5)
        ->and($call['body']['load_avg_1m'])->toBe(1.2);
});
