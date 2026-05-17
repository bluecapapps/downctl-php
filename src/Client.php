<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl;

use Bluecapapps\Downctl\Exception\TransportException;
use Bluecapapps\Downctl\Http\CurlTransport;
use Bluecapapps\Downctl\Http\TransportInterface;
use Bluecapapps\Downctl\Payload\ErrorPayload;
use Bluecapapps\Downctl\Payload\MetricsPayload;

class Client
{
    private readonly TransportInterface $transport;

    public function __construct(
        private readonly Config $config,
        ?TransportInterface $transport = null,
    ) {
        $this->transport = $transport ?? new CurlTransport($config->timeoutSeconds);
    }

    /**
     * Capture a thrown exception and report it as an error.
     *
     * @param  array<string, mixed>  $context
     */
    public function captureException(\Throwable $e, string $level = 'error', array $context = []): void
    {
        $this->reportError(ErrorPayload::fromThrowable($e, $level, $context));
    }

    /**
     * Report an arbitrary error message.
     *
     * @param  array<string, mixed>  $context
     */
    public function report(
        string $message,
        string $level = 'error',
        ?string $stackTrace = null,
        ?string $url = null,
        array $context = [],
    ): void {
        $this->reportError(new ErrorPayload(
            level: $level,
            message: $message,
            stackTrace: $stackTrace,
            url: $url,
            context: $context !== [] ? $context : null,
        ));
    }

    public function reportMetrics(MetricsPayload $metrics): void
    {
        $this->send(function () use ($metrics): void {
            $status = $this->transport->post(
                url: rtrim($this->config->url, '/').'/api/v1/metrics',
                headers: ['X-Downctl-Key' => $this->config->apiKey],
                body: $metrics->toArray(),
            );

            if ($status < 200 || $status >= 300) {
                throw new TransportException("Metrics endpoint returned HTTP {$status}.");
            }
        });
    }

    /**
     * Check that the API is reachable and the key is valid.
     * Returns false on any connectivity or auth failure.
     */
    public function ping(): bool
    {
        try {
            $status = $this->transport->get(
                url: rtrim($this->config->url, '/').'/api/v1/health',
                headers: [],
            );

            return $status === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    private function reportError(ErrorPayload $payload): void
    {
        $this->send(function () use ($payload): void {
            $headers = ['X-Downctl-Key' => $this->config->apiKey];

            $status = $this->transport->post(
                url: rtrim($this->config->url, '/').'/api/v1/errors',
                headers: $headers,
                body: $payload->toArray(),
            );

            if ($status < 200 || $status >= 300) {
                throw new TransportException("Errors endpoint returned HTTP {$status}.");
            }
        });
    }

    /**
     * Execute a transport callback, swallowing exceptions when silent mode is on.
     */
    private function send(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            if (! $this->config->silent) {
                throw $e;
            }
        }
    }
}
