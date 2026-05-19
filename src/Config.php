<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl;

final class Config
{
    public const DEFAULT_URL = 'https://downctl.com';

    public readonly string $url;

    public function __construct(
        public readonly string $apiKey,
        public readonly ?string $publicKey = null,
        public readonly int $timeoutSeconds = 5,
        /** Swallow all transport errors so the SDK never crashes the host app. */
        public readonly bool $silent = true,
        /** Dispatch reports via a queue job instead of blocking HTTP calls. */
        public readonly bool $queue = false,
    ) {
        $this->url = self::DEFAULT_URL;

        if ($this->apiKey === '') {
            throw new \InvalidArgumentException('Downctl API key must not be empty.');
        }

        if (preg_match('/[\r\n]/', $this->apiKey) === 1) {
            throw new \InvalidArgumentException('Downctl API key must not contain line breaks.');
        }
    }

    public static function fromEnv(): self
    {
        $apiKey = (string) ($_ENV['DOWNCTL_API_KEY'] ?? getenv('DOWNCTL_API_KEY') ?: '');
        $pubKey = (string) ($_ENV['DOWNCTL_PUBLIC_KEY'] ?? getenv('DOWNCTL_PUBLIC_KEY') ?: '') ?: null;

        return new self(apiKey: $apiKey, publicKey: $pubKey);
    }
}
