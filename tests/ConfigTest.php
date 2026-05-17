<?php

use Bluecapapps\Downctl\Config;

test('config stores required fields', function () {
    $config = new Config(apiKey: 'abc123');

    expect($config->url)->toBe('https://downctl.com')
        ->and($config->apiKey)->toBe('abc123')
        ->and($config->silent)->toBeTrue()
        ->and($config->queue)->toBeFalse();
});

test('config rejects empty api key', function () {
    new Config(apiKey: '');
})->throws(\InvalidArgumentException::class);

test('fromEnv reads environment variables', function () {
    $_ENV['DOWNCTL_API_KEY'] = 'key-from-env';

    $config = Config::fromEnv();

    expect($config->url)->toBe('https://downctl.com')
        ->and($config->apiKey)->toBe('key-from-env');

    unset($_ENV['DOWNCTL_API_KEY']);
});
