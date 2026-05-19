<?php

declare(strict_types=1);

namespace Bluecapapps\Downctl\Payload;

use Bluecapapps\Downctl\Support\UrlSanitizer;

final class ErrorPayload
{
    private const VALID_LEVELS = ['error', 'warning', 'info'];

    public readonly ?string $url;

    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly ?string $stackTrace = null,
        ?string $url = null,
        public readonly ?string $userAgent = null,
        public readonly ?array $context = null,
    ) {
        $this->url = UrlSanitizer::stripQuery($url);

        if (! in_array($this->level, self::VALID_LEVELS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Level must be one of: %s.', implode(', ', self::VALID_LEVELS))
            );
        }

        if ($this->message === '') {
            throw new \InvalidArgumentException('Message must not be empty.');
        }
    }

    public static function fromThrowable(\Throwable $e, string $level = 'error', array $context = []): self
    {
        $url = null;

        if (isset($_SERVER['REQUEST_URI'])) {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url    = $scheme.'://'.$host.$_SERVER['REQUEST_URI'];
        }

        return new self(
            level: $level,
            message: $e->getMessage(),
            stackTrace: $e->getTraceAsString(),
            url: $url,
            userAgent: $_SERVER['HTTP_USER_AGENT'] ?? null,
            context: $context !== [] ? $context : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'level'       => $this->level,
            'message'     => $this->message,
            'stack_trace' => $this->stackTrace,
            'url'         => $this->url,
            'user_agent'  => $this->userAgent,
            'context'     => $this->context,
        ], fn ($v) => $v !== null);
    }
}
