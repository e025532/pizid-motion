<?php

declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }

    $path = getenv('HEALTH_CONNECT_ENV') ?: '/etc/health-connect/app.env';
    $values = @parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($values)) {
        throw new RuntimeException('Configuration unavailable');
    }

    foreach (['DB_DSN', 'DB_USER', 'DB_PASSWORD_B64'] as $required) {
        if (!isset($values[$required]) || $values[$required] === '') {
            throw new RuntimeException("Missing configuration: {$required}");
        }
    }

    $password = base64_decode($values['DB_PASSWORD_B64'], true);
    if ($password === false) {
        throw new RuntimeException('Invalid database password encoding');
    }

    $config = [
        'db_dsn' => $values['DB_DSN'],
        'db_user' => $values['DB_USER'],
        'db_password' => $password,
        'max_body_bytes' => (int) ($values['MAX_BODY_BYTES'] ?? 16777216),
        'max_records' => (int) ($values['MAX_RECORDS'] ?? 1000),
        'max_deletions' => (int) ($values['MAX_DELETIONS'] ?? 1000),
        'max_samples_per_record' => (int) ($values['MAX_SAMPLES_PER_RECORD'] ?? 20000),
        'web_password_hash_b64' => $values['WEB_PASSWORD_HASH_B64'] ?? '',
        'google_health_client_id' => $values['GOOGLE_HEALTH_CLIENT_ID'] ?? '',
        'google_health_client_secret' => $values['GOOGLE_HEALTH_CLIENT_SECRET'] ?? '',
        'google_health_redirect_uri' => $values['GOOGLE_HEALTH_REDIRECT_URI'] ?? '',
        'google_health_crypto_key' => isset($values['GOOGLE_HEALTH_CRYPTO_KEY_B64'])
            ? base64_decode($values['GOOGLE_HEALTH_CRYPTO_KEY_B64'], true) : null,
    ];

    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = config();
    $pdo = new PDO(
        $config['db_dsn'],
        $config['db_user'],
        $config['db_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function requestBody(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > config()['max_body_bytes']) {
        throw new ApiException(413, 'Request body is too large');
    }

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        throw new ApiException(415, 'Content-Type must be application/json');
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        throw new ApiException(400, 'Request body is empty');
    }
    if (strlen($raw) > config()['max_body_bytes']) {
        throw new ApiException(413, 'Request body is too large');
    }

    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiException(400, 'Invalid JSON');
    }
    if (!is_array($data)) {
        throw new ApiException(400, 'JSON root must be an object');
    }

    return [$data, $raw];
}

function bearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{32,256})$/', trim($header), $matches)) {
        throw new ApiException(401, 'Missing or invalid bearer token');
    }

    return $matches[1];
}

function authenticatedDevice(PDO $pdo): array
{
    $tokenHash = hash('sha256', bearerToken());
    $statement = $pdo->prepare(
        'SELECT device_id::text, device_name
           FROM api.devices
          WHERE token_hash = :token_hash AND enabled = true'
    );
    $statement->execute(['token_hash' => $tokenHash]);
    $device = $statement->fetch();
    if (!$device) {
        throw new ApiException(401, 'Invalid or disabled device token');
    }

    $update = $pdo->prepare('UPDATE api.devices SET last_seen_at = now() WHERE device_id = :device_id');
    $update->execute(['device_id' => $device['device_id']]);

    return $device;
}

function validType(mixed $value, string $field = 'type'): string
{
    if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,99}$/', $value)) {
        throw new ApiException(422, "Invalid {$field}");
    }
    return $value;
}

function optionalString(array $data, string $key, int $maxLength = 255): ?string
{
    if (!array_key_exists($key, $data) || $data[$key] === null) {
        return null;
    }
    if (!is_string($data[$key]) || mb_strlen($data[$key]) > $maxLength) {
        throw new ApiException(422, "Invalid {$key}");
    }
    return $data[$key];
}

function optionalTimestamp(array $data, string $key): ?string
{
    $value = optionalString($data, $key, 64);
    if ($value === null) {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    } catch (Exception) {
        throw new ApiException(422, "Invalid {$key}");
    }
}
