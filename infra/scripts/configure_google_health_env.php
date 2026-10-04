<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: configure_google_health_env.php <oauth-json> <env-file>\n");
    exit(2);
}

$oauthPath = $argv[1];
$envPath = $argv[2];
$existingOwner = file_exists($envPath) ? fileowner($envPath) : null;
$existingGroup = file_exists($envPath) ? filegroup($envPath) : null;
$oauth = json_decode((string) file_get_contents($oauthPath), true, 512, JSON_THROW_ON_ERROR);
$web = $oauth['web'] ?? null;

if (!is_array($web) || empty($web['client_id']) || empty($web['client_secret'])) {
    throw new RuntimeException('Invalid Google OAuth Web client JSON.');
}

$values = [
    'GOOGLE_HEALTH_CLIENT_ID' => (string) $web['client_id'],
    'GOOGLE_HEALTH_CLIENT_SECRET' => (string) $web['client_secret'],
    'GOOGLE_HEALTH_REDIRECT_URI' => 'https://health.home.pizid.org/api/v1/google-health/callback',
    'GOOGLE_HEALTH_CRYPTO_KEY_B64' => base64_encode(random_bytes(32)),
];

$current = file_exists($envPath) ? (string) file_get_contents($envPath) : '';
$lines = preg_split('/\R/', $current) ?: [];
$kept = [];

foreach ($lines as $line) {
    $isManaged = false;
    foreach (array_keys($values) as $key) {
        if (str_starts_with($line, $key . '=')) {
            $isManaged = true;
            break;
        }
    }
    if (!$isManaged && $line !== '') {
        $kept[] = $line;
    }
}

foreach ($values as $key => $value) {
    $kept[] = $key . '=' . $value;
}

$temporary = $envPath . '.new';
file_put_contents($temporary, implode("\n", $kept) . "\n", LOCK_EX);
chmod($temporary, 0640);
rename($temporary, $envPath);
if ($existingOwner !== null) {
    chown($envPath, $existingOwner);
}
if ($existingGroup !== null) {
    chgrp($envPath, $existingGroup);
}

fwrite(STDOUT, "Google Health OAuth configuration installed.\n");
