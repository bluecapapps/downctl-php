<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl\Http;

use Bluecapapps\Downctl\Exception\TransportException;

final class CurlTransport implements TransportInterface
{
    public function __construct(private readonly int $timeoutSeconds = 5) {}

    public function post(string $url, array $headers, array $body): int
    {
        $ch = $this->init($url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $headerLines   = ['Content-Type: application/json'];
        $headerLines   = array_merge($headerLines, $this->formatHeaders($headers));

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        return $this->execute($ch);
    }

    public function get(string $url, array $headers): int
    {
        $ch = $this->init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));

        return $this->execute($ch);
    }

    /** @return \CurlHandle */
    private function init(string $url): \CurlHandle
    {
        if (! extension_loaded('curl')) {
            throw new TransportException('The curl extension is required.');
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new TransportException('curl_init() failed.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_USERAGENT, 'bluecapapps-downctl-php/1.0');

        return $ch;
    }

    /** @return array<string> */
    private function formatHeaders(array $headers): array
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name) || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name) !== 1) {
                throw new TransportException('Invalid HTTP header name.');
            }

            if (! is_scalar($value) || preg_match('/[\r\n]/', (string) $value) === 1) {
                throw new TransportException('Invalid HTTP header value.');
            }

            $lines[] = "{$name}: {$value}";
        }

        return $lines;
    }

    private function execute(\CurlHandle $ch): int
    {
        curl_exec($ch);

        $errno = curl_errno($ch);

        if ($errno !== 0) {
            $error = curl_error($ch);

            throw new TransportException("curl error [{$errno}]: {$error}");
        }

        return (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
}
