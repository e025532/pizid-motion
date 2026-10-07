<?php

declare(strict_types=1);

if ($argc !== 4) {
    fwrite(STDERR, "Usage: configure_google_health_env.php <oauth-json> <env-file> <redirect-uri>\n");
    exit(2);
}

$oauthPath = $argv[1];
$envPath = $argv[2];
$redirectUri = $argv[3];

if (!filter_var($redirectUri, FILTER_VALIDATE_URL)) {
    throw new RuntimeException('Invalid Google Health redirect URI.');
}
$scheme = strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME));
if (!in_array($scheme, ['https', 'http'], true)) {
    throw new RuntimeException('Google Health redirect URI must use http or https.');
}

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
    'GOOGLE_HEALTH_REDIRECT_URI' => $redirectUri,
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
