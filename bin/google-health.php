#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/GoogleHealthController.php';

$controller = new GoogleHealthController(db());
$command = $argv[1] ?? 'status';
$result = match ($command) {
    'authorize' => ['authorization_url' => $controller->createAuthorizationUrl()],
    'sync' => $controller->synchronize(),
    'status' => $controller->status(),
    default => throw new InvalidArgumentException('Usage: google-health.php [authorize|sync|status]'),
};
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
