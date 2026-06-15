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

    /**
     * Send a simple heartbeat ping for the given cron monitor token.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function pingCron(string $token, array $metadata = []): void
    {
        $this->sendCronPing($token, '', $metadata);
    }

    /**
     * Notify Downctl that the monitored job has started.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function pingCronStarted(string $token, array $metadata = []): void
    {
        $this->sendCronPing($token, '/started', $metadata);
    }

    /**
     * Notify Downctl that the monitored job completed successfully.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function pingCronFinished(string $token, array $metadata = []): void
    {
        $this->sendCronPing($token, '/finished', $metadata);
    }

    /**
     * Notify Downctl that the monitored job failed.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function pingCronFailed(string $token, array $metadata = []): void
    {
        $this->sendCronPing($token, '/failed', $metadata);
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

    /** @param  array<string, mixed>  $metadata */
    private function sendCronPing(string $token, string $suffix, array $metadata): void
    {
        $this->send(function () use ($token, $suffix, $metadata): void {
            $url = rtrim($this->config->url, '/').'/ping/cron/'.$token.$suffix;

            $status = $metadata !== []
                ? $this->transport->post(url: $url, headers: [], body: $metadata)
                : $this->transport->get(url: $url, headers: []);

            if ($status < 200 || $status >= 300) {
                throw new TransportException("Cron ping returned HTTP {$status}.");
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
