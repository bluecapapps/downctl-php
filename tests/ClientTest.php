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
