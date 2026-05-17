<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl\Http;

use Bluecapapps\Downctl\Exception\TransportException;

interface TransportInterface
{
    /**
     * Send a POST request with a JSON body and return the HTTP status code.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>   $body
     *
     * @throws TransportException
     */
    public function post(string $url, array $headers, array $body): int;

    /**
     * Send a GET request and return the HTTP status code.
     *
     * @param  array<string, string>  $headers
     *
     * @throws TransportException
     */
    public function get(string $url, array $headers): int;
}
