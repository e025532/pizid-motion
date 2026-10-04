<?php

declare(strict_types=1);

final class GoogleHealthController
{
    private const SCOPE = 'https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://health.googleapis.com/v4';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createAuthorizationUrl(): string
    {
        $this->requireConfiguration();
        $state = $this->randomUrlSafe(32);
        $verifier = $this->randomUrlSafe(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->pdo->exec("DELETE FROM api.google_health_oauth_states WHERE expires_at < now()");
        $statement = $this->pdo->prepare(
            "INSERT INTO api.google_health_oauth_states (state_hash, code_verifier, expires_at)
             VALUES (:state_hash, :code_verifier, now() + interval '15 minutes')"
        );
        $statement->execute([
            'state_hash' => hash('sha256', $state),
            'code_verifier' => $verifier,
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config()['google_health_client_id'],
            'redirect_uri' => config()['google_health_redirect_uri'],
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function callback(): never
    {
        $this->requireConfiguration();
        $state = $_GET['state'] ?? '';
        $code = $_GET['code'] ?? '';
        $error = $_GET['error'] ?? '';
        if (is_string($error) && $error !== '') {
            throw new ApiException(400, 'Google authorization denied: ' . $error);
        }
        if (!is_string($state) || $state === '' || !is_string($code) || $code === '') {
            throw new ApiException(400, 'Missing Google authorization response');
        }

        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'DELETE FROM api.google_health_oauth_states
                  WHERE state_hash = :state_hash AND expires_at >= now()
                  RETURNING code_verifier'
            );
            $statement->execute(['state_hash' => hash('sha256', $state)]);
            $verifier = $statement->fetchColumn();
            if (!is_string($verifier) || $verifier === '') {
                throw new ApiException(400, 'Invalid or expired Google authorization state');
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }

        $tokens = $this->formRequest(self::TOKEN_URL, [
            'client_id' => config()['google_health_client_id'],
            'client_secret' => config()['google_health_client_secret'],
            'redirect_uri' => config()['google_health_redirect_uri'],
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
        ]);
        $refreshToken = $tokens['refresh_token'] ?? null;
        $accessToken = $tokens['access_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '' || !is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google did not return the required OAuth tokens');
        }

        $statement = $this->pdo->prepare(
            "INSERT INTO api.google_health_credentials
                (singleton_id, access_token_encrypted, refresh_token_encrypted,
                 access_token_expires_at, granted_scopes, connected_at, updated_at)
             VALUES (1, :access_token, :refresh_token,
                     now() + make_interval(secs => :expires_in), :scopes, now(), now())
             ON CONFLICT (singleton_id) DO UPDATE SET
                access_token_encrypted = EXCLUDED.access_token_encrypted,
                refresh_token_encrypted = EXCLUDED.refresh_token_encrypted,
                access_token_expires_at = EXCLUDED.access_token_expires_at,
                granted_scopes = EXCLUDED.granted_scopes,
                connected_at = now(), updated_at = now(), last_sync_error = NULL"
        );
        $statement->execute([
            'access_token' => $this->encrypt($accessToken),
            'refresh_token' => $this->encrypt($refreshToken),
            'expires_in' => max(60, (int) ($tokens['expires_in'] ?? 3600)),
            'scopes' => is_string($tokens['scope'] ?? null) ? $tokens['scope'] : self::SCOPE,
        ]);

        $this->synchronize();
        header('Location: /dashboard#health', true, 302);
        exit;
    }

    public function status(): array
    {
        $statement = $this->pdo->query(
            'SELECT connected_at, updated_at, last_sync_at, last_sync_status, last_sync_error
               FROM api.google_health_credentials WHERE singleton_id = 1'
        );
        $row = $statement->fetch();
        return ['configured' => $this->isConfigured(), 'connected' => (bool) $row, 'details' => $row ?: null];
    }

    public function synchronize(): array
    {
        $accessToken = $this->accessToken();
        try {
            $points = $this->listDataPoints($accessToken, 'daily-oxygen-saturation',
                'daily_oxygen_saturation.date >= "2026-06-01"');
            $imported = 0;
            foreach ($points as $point) {
                if ($this->upsertDailyOxygen($point)) ++$imported;
            }
            $this->markSync('completed', null);
            return ['status' => 'completed', 'received' => count($points), 'imported' => $imported];
        } catch (Throwable $exception) {
            $this->markSync('failed', mb_substr($exception->getMessage(), 0, 1000));
            throw $exception;
        }
    }

    private function listDataPoints(string $accessToken, string $dataType, string $filter): array
    {
        $all = [];
        $pageToken = null;
        do {
            $query = ['pageSize' => 10000, 'filter' => $filter];
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $response = $this->jsonRequest(
                self::API_BASE . '/users/me/dataTypes/' . rawurlencode($dataType) . '/dataPoints?' .
                http_build_query($query, '', '&', PHP_QUERY_RFC3986),
                $accessToken
            );
            $points = $response['dataPoints'] ?? [];
            if (!is_array($points)) throw new RuntimeException('Invalid Google Health data response');
            foreach ($points as $point) if (is_array($point)) $all[] = $point;
            $pageToken = is_string($response['nextPageToken'] ?? null) && $response['nextPageToken'] !== ''
                ? $response['nextPageToken'] : null;
        } while ($pageToken !== null);
        return $all;
    }

    private function upsertDailyOxygen(array $point): bool
    {
        $oxygen = $point['dailyOxygenSaturation'] ?? null;
        if (!is_array($oxygen) || !isset($oxygen['date'], $oxygen['averagePercentage']) || !is_array($oxygen['date'])) {
            return false;
        }
        $date = sprintf('%04d-%02d-%02d', (int) ($oxygen['date']['year'] ?? 0),
            (int) ($oxygen['date']['month'] ?? 0), (int) ($oxygen['date']['day'] ?? 0));
        $localDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Europe/Paris'));
        if (!$localDate || $localDate->format('Y-m-d') !== $date) return false;
        $at = $localDate->setTime(12, 0)->format(DateTimeInterface::ATOM);
        $name = is_string($point['name'] ?? null) ? $point['name'] : $date;
        $payload = [
            'percentage' => (float) $oxygen['averagePercentage'],
            'lower_bound_percentage' => isset($oxygen['lowerBoundPercentage']) ? (float) $oxygen['lowerBoundPercentage'] : null,
            'upper_bound_percentage' => isset($oxygen['upperBoundPercentage']) ? (float) $oxygen['upperBoundPercentage'] : null,
            'standard_deviation_percentage' => isset($oxygen['standardDeviationPercentage']) ? (float) $oxygen['standardDeviationPercentage'] : null,
            'google_health_name' => $name,
        ];

        $this->pdo->exec(
            "INSERT INTO health.sources (package_name, app_name, payload)
             VALUES ('google.health.api', 'Google Health API', '{}'::jsonb)
             ON CONFLICT (package_name) DO UPDATE SET app_name = EXCLUDED.app_name, updated_at = now()"
        );
        $this->pdo->exec(
            "INSERT INTO health.record_types (record_type, source_table, record_kind, latest_row_count)
             VALUES ('oxygen_saturation', 'google_health_api', 'instant', 0)
             ON CONFLICT (record_type) DO UPDATE SET updated_at = now()"
        );
        $statement = $this->pdo->prepare(
            "INSERT INTO health.records
                (record_type, record_key, source_table, source_app_package, source_app_name,
                 last_modified_at, start_at, end_at, local_date, is_deleted, payload)
             VALUES ('oxygen_saturation', :record_key, 'google_health_api', 'google.health.api',
                     'Google Health API', now(), :at, :at, :local_date, false, CAST(:payload AS jsonb))
             ON CONFLICT (record_type, record_key) DO UPDATE SET
                source_table = EXCLUDED.source_table,
                source_app_package = EXCLUDED.source_app_package,
                source_app_name = EXCLUDED.source_app_name,
                last_modified_at = now(), start_at = EXCLUDED.start_at, end_at = EXCLUDED.end_at,
                local_date = EXCLUDED.local_date, is_deleted = false, payload = EXCLUDED.payload,
                updated_at = now()"
        );
        $statement->execute([
            'record_key' => 'google-health:daily-oxygen-saturation:' . hash('sha256', $name),
            'at' => $at,
            'local_date' => $date,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return true;
    }

    private function accessToken(): string
    {
        $this->requireConfiguration();
        $statement = $this->pdo->query(
            'SELECT access_token_encrypted, refresh_token_encrypted, access_token_expires_at
               FROM api.google_health_credentials WHERE singleton_id = 1'
        );
        $row = $statement->fetch();
        if (!$row) throw new RuntimeException('Google Health is not connected');
        if ($row['access_token_encrypted'] && $row['access_token_expires_at'] &&
            new DateTimeImmutable($row['access_token_expires_at']) > new DateTimeImmutable('+2 minutes')) {
            return $this->decrypt($row['access_token_encrypted']);
        }

        $tokens = $this->formRequest(self::TOKEN_URL, [
            'client_id' => config()['google_health_client_id'],
            'client_secret' => config()['google_health_client_secret'],
            'refresh_token' => $this->decrypt($row['refresh_token_encrypted']),
            'grant_type' => 'refresh_token',
        ]);
        $accessToken = $tokens['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') throw new RuntimeException('Google token refresh failed');
        $update = $this->pdo->prepare(
            'UPDATE api.google_health_credentials
                SET access_token_encrypted = :access_token,
                    access_token_expires_at = now() + make_interval(secs => :expires_in), updated_at = now()
              WHERE singleton_id = 1'
        );
        $update->execute([
            'access_token' => $this->encrypt($accessToken),
            'expires_in' => max(60, (int) ($tokens['expires_in'] ?? 3600)),
        ]);
        return $accessToken;
    }

    private function formRequest(string $url, array $fields): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        return $this->executeJson($curl);
    }

    private function jsonRequest(string $url, string $accessToken): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        return $this->executeJson($curl);
    }

    private function executeJson(CurlHandle $curl): array
    {
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body)) throw new RuntimeException('Google request failed: ' . $error);
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error_description'] ?? $body) : $body;
            throw new RuntimeException('Google API HTTP ' . $status . ': ' . mb_substr((string) $message, 0, 500));
        }
        if (!is_array($decoded)) throw new RuntimeException('Invalid JSON from Google');
        return $decoded;
    }

    private function markSync(string $status, ?string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE api.google_health_credentials
                SET last_sync_at = now(), last_sync_status = :status, last_sync_error = :error, updated_at = now()
              WHERE singleton_id = 1'
        );
        $statement->execute(['status' => $status, 'error' => $error]);
    }

    private function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, config()['google_health_crypto_key']));
    }

    private function decrypt(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Invalid encrypted Google token');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce, config()['google_health_crypto_key']);
        if ($plaintext === false) throw new RuntimeException('Unable to decrypt Google token');
        return $plaintext;
    }

    private function requireConfiguration(): void
    {
        if (!$this->isConfigured()) throw new RuntimeException('Google Health OAuth is not configured');
    }

    private function isConfigured(): bool
    {
        $cfg = config();
        return $cfg['google_health_client_id'] !== '' && $cfg['google_health_client_secret'] !== ''
            && $cfg['google_health_redirect_uri'] !== ''
            && is_string($cfg['google_health_crypto_key'])
            && strlen($cfg['google_health_crypto_key']) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    private function randomUrlSafe(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
