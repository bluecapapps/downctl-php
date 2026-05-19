<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl\Support;

final class UrlSanitizer
{
    public static function stripQuery(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $queryStart = strpos($url, '?');

        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);

        if ($fragmentStart === false) {
            return substr($url, 0, $queryStart);
        }

        return substr($url, 0, $queryStart).substr($url, $fragmentStart);
    }
}
