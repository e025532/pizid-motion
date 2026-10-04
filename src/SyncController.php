<?php

declare(strict_types=1);

final class SyncController
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function status(array $device): never
    {
        $statement = $this->pdo->prepare(
            'SELECT batch_key, status, record_count, sample_count, deletion_count,
                    received_at, completed_at
               FROM api.sync_batches
              WHERE device_id = :device_id
              ORDER BY received_at DESC
              LIMIT 1'
        );
        $statement->execute(['device_id' => $device['device_id']]);

        jsonResponse([
            'status' => 'ready',
            'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'device' => $device,
            'last_batch' => $statement->fetch() ?: null,
        ]);
    }

    public function ingest(array $device): never
    {
        [$body, $rawBody] = requestBody();
        $batchKey = optionalString($body, 'batch_id', 128);
        if ($batchKey === null || $batchKey === '') {
            throw new ApiException(422, 'batch_id is required');
        }

        $records = $body['records'] ?? [];
        $deletions = $body['deletions'] ?? [];
        $cursors = $body['cursors'] ?? [];
        if (!is_array($records) || !array_is_list($records)) {
            throw new ApiException(422, 'records must be an array');
        }
        if (!is_array($deletions) || !array_is_list($deletions)) {
            throw new ApiException(422, 'deletions must be an array');
        }
        if (!is_array($cursors) || ($cursors !== [] && array_is_list($cursors))) {
            throw new ApiException(422, 'cursors must be an object');
        }
        if (count($records) > config()['max_records']) {
            throw new ApiException(422, 'Too many records in one batch');
        }
        if (count($deletions) > config()['max_deletions']) {
            throw new ApiException(422, 'Too many deletions in one batch');
        }

        $requestHash = hash('sha256', $rawBody);
        $existing = $this->findBatch($device['device_id'], $batchKey);
        if ($existing !== null) {
            if (!hash_equals($existing['request_sha256'], $requestHash)) {
                throw new ApiException(409, 'batch_id was already used with different content');
            }
            if ($existing['status'] === 'completed') {
                $response = json_decode($existing['response'], true, 512, JSON_THROW_ON_ERROR);
                $response['replayed'] = true;
                jsonResponse($response);
            }
            throw new ApiException(409, 'batch_id is already processing or failed');
        }

        $sentAt = optionalTimestamp($body, 'sent_at');
        $insertBatch = $this->pdo->prepare(
            "INSERT INTO api.sync_batches
                (device_id, batch_key, request_sha256, sent_at, status)
             VALUES (:device_id, :batch_key, :request_hash, :sent_at, 'processing')
             RETURNING batch_id::text"
        );
        $insertBatch->execute([
            'device_id' => $device['device_id'],
            'batch_key' => $batchKey,
            'request_hash' => $requestHash,
            'sent_at' => $sentAt,
        ]);
        $batchId = $insertBatch->fetchColumn();

        try {
            $this->pdo->beginTransaction();
            $recordCount = 0;
            $sampleCount = 0;
            $deletionCount = 0;

            foreach ($records as $index => $record) {
                if (!is_array($record)) {
                    throw new ApiException(422, "records[{$index}] must be an object");
                }
                [$recordId, $insertedSamples] = $this->upsertRecord(
                    $device['device_id'],
                    $batchId,
                    $record,
                    $index
                );
                ++$recordCount;
                $sampleCount += $insertedSamples;
            }

            foreach ($deletions as $index => $deletion) {
                if (!is_array($deletion)) {
                    throw new ApiException(422, "deletions[{$index}] must be an object");
                }
                $this->applyDeletion($batchId, $deletion, $index);
                ++$deletionCount;
            }

            foreach ($cursors as $recordType => $changeToken) {
                $this->upsertCursor($device['device_id'], (string) $recordType, $changeToken);
            }

            $response = [
                'status' => 'accepted',
                'batch_id' => $batchKey,
                'records' => $recordCount,
                'samples' => $sampleCount,
                'deletions' => $deletionCount,
                'replayed' => false,
                'server_time' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];
            $complete = $this->pdo->prepare(
                "UPDATE api.sync_batches
                    SET status = 'completed', completed_at = now(),
                        record_count = :records, sample_count = :samples,
                        deletion_count = :deletions, response = CAST(:response AS jsonb)
                  WHERE batch_id = :batch_id"
            );
            $complete->execute([
                'records' => $recordCount,
                'samples' => $sampleCount,
                'deletions' => $deletionCount,
                'response' => json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'batch_id' => $batchId,
            ]);
            $this->pdo->commit();

            jsonResponse($response, 201);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $failed = $this->pdo->prepare(
                "UPDATE api.sync_batches
                    SET status = 'failed', completed_at = now(), error_message = :error
                  WHERE batch_id = :batch_id"
            );
            $failed->execute([
                'error' => mb_substr($exception->getMessage(), 0, 1000),
                'batch_id' => $batchId,
            ]);
            throw $exception;
        }
    }

    private function findBatch(string $deviceId, string $batchKey): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT request_sha256, status, response::text
               FROM api.sync_batches
              WHERE device_id = :device_id AND batch_key = :batch_key'
        );
        $statement->execute(['device_id' => $deviceId, 'batch_key' => $batchKey]);
        return $statement->fetch() ?: null;
    }

    private function upsertRecord(
        string $deviceId,
        string $batchId,
        array $record,
        int $index
    ): array {
        $type = validType($record['type'] ?? null, "records[{$index}].type");
        $recordKey = optionalString($record, 'id', 255);
        if ($recordKey === null || $recordKey === '') {
            throw new ApiException(422, "records[{$index}].id is required");
        }
        $payload = $record['payload'] ?? [];
        if (!is_array($payload)) {
            throw new ApiException(422, "records[{$index}].payload must be an object");
        }

        $startAt = optionalTimestamp($record, 'start_at');
        $endAt = optionalTimestamp($record, 'end_at') ?? $startAt;
        $lastModifiedAt = optionalTimestamp($record, 'last_modified_at');
        $kind = $record['kind'] ?? (($startAt !== null && $endAt !== $startAt) ? 'interval' : 'instant');
        if (!in_array($kind, ['instant', 'interval'], true)) {
            throw new ApiException(422, "records[{$index}].kind is invalid");
        }

        $source = $record['source'] ?? [];
        if (!is_array($source)) {
            throw new ApiException(422, "records[{$index}].source must be an object");
        }
        $packageName = optionalString($source, 'package_name', 255);
        $appName = optionalString($source, 'app_name', 255);
        if ($packageName !== null && $packageName !== '') {
            $insertSource = $this->pdo->prepare(
                'INSERT INTO health.sources (package_name, app_name, payload)
                 VALUES (:package_name, :app_name, CAST(:payload AS jsonb))
                 ON CONFLICT (package_name) DO UPDATE SET
                    app_name = coalesce(EXCLUDED.app_name, health.sources.app_name),
                    payload = health.sources.payload || EXCLUDED.payload,
                    updated_at = now()'
            );
            $insertSource->execute([
                'package_name' => $packageName,
                'app_name' => $appName,
                'payload' => json_encode($source, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
        } else {
            $packageName = null;
        }

        $recordType = $this->pdo->prepare(
            "INSERT INTO health.record_types
                (record_type, source_table, record_kind, latest_row_count)
             VALUES (:type, 'android_api', :kind, 0)
             ON CONFLICT (record_type) DO UPDATE SET
                record_kind = EXCLUDED.record_kind,
                updated_at = now()"
        );
        $recordType->execute(['type' => $type, 'kind' => $kind]);

        $localDate = optionalString($record, 'local_date', 10);
        if ($localDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
            throw new ApiException(422, "records[{$index}].local_date is invalid");
        }

        $upsert = $this->pdo->prepare(
            "INSERT INTO health.records (
                record_type, record_key, source_table, source_row_id,
                source_app_package, source_app_name, source_device_id,
                recording_method, client_record_id, client_record_version,
                last_modified_at, start_at, end_at,
                start_zone_offset_seconds, end_zone_offset_seconds, local_date,
                first_seen_batch_id, latest_batch_id, is_deleted, payload
             ) VALUES (
                :type, :record_key, 'android_api', NULL,
                :package_name, :app_name, :device_id,
                :recording_method, :client_record_id, :client_record_version,
                :last_modified_at, :start_at, :end_at,
                :start_offset, :end_offset, :local_date,
                :batch_id, :batch_id_latest, false, CAST(:payload AS jsonb)
             )
             ON CONFLICT (record_type, record_key) DO UPDATE SET
                source_table = 'android_api',
                source_app_package = EXCLUDED.source_app_package,
                source_app_name = EXCLUDED.source_app_name,
                source_device_id = EXCLUDED.source_device_id,
                recording_method = EXCLUDED.recording_method,
                client_record_id = EXCLUDED.client_record_id,
                client_record_version = EXCLUDED.client_record_version,
                last_modified_at = EXCLUDED.last_modified_at,
                start_at = EXCLUDED.start_at,
                end_at = EXCLUDED.end_at,
                start_zone_offset_seconds = EXCLUDED.start_zone_offset_seconds,
                end_zone_offset_seconds = EXCLUDED.end_zone_offset_seconds,
                local_date = EXCLUDED.local_date,
                latest_batch_id = EXCLUDED.latest_batch_id,
                is_deleted = false,
                payload = EXCLUDED.payload,
                updated_at = now()
             RETURNING record_id"
        );
        $upsert->execute([
            'type' => $type,
            'record_key' => $recordKey,
            'package_name' => $packageName,
            'app_name' => $appName,
            'device_id' => $deviceId,
            'recording_method' => isset($record['recording_method']) ? (int) $record['recording_method'] : null,
            'client_record_id' => optionalString($record, 'client_record_id', 255),
            'client_record_version' => optionalString($record, 'client_record_version', 255),
            'last_modified_at' => $lastModifiedAt,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'start_offset' => isset($record['start_zone_offset_seconds']) ? (int) $record['start_zone_offset_seconds'] : null,
            'end_offset' => isset($record['end_zone_offset_seconds']) ? (int) $record['end_zone_offset_seconds'] : null,
            'local_date' => $localDate,
            'batch_id' => $batchId,
            'batch_id_latest' => $batchId,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $recordId = (int) $upsert->fetchColumn();

        $samples = $record['samples'] ?? null;
        if ($samples === null) {
            return [$recordId, 0];
        }
        if (!is_array($samples) || !array_is_list($samples)) {
            throw new ApiException(422, "records[{$index}].samples must be an array");
        }
        if (count($samples) > config()['max_samples_per_record']) {
            throw new ApiException(422, "records[{$index}] contains too many samples");
        }

        // A normal Health Connect exercise upsert can contain laps/segments but
        // not the separately consented GPS route. Replace only the sample types
        // present in this payload so that refreshing one collection never erases
        // another collection already stored for the same session.
        $sampleTypes = [];
        foreach ($samples as $sampleIndex => $sample) {
            if (!is_array($sample)) {
                throw new ApiException(422, "records[{$index}].samples[{$sampleIndex}] must be an object");
            }
            $sampleTypes[] = validType(
                $sample['type'] ?? $type,
                "records[{$index}].samples[{$sampleIndex}].type"
            );
        }
        $sampleTypes = array_values(array_unique($sampleTypes));
        if ($sampleTypes !== []) {
            $parameters = ['record_id' => $recordId];
            $placeholders = [];
            foreach ($sampleTypes as $sampleTypeIndex => $sampleType) {
                $parameter = "sample_type_{$sampleTypeIndex}";
                $placeholders[] = ":{$parameter}";
                $parameters[$parameter] = $sampleType;
            }
            $deleteSamples = $this->pdo->prepare(
                'DELETE FROM health.samples
                  WHERE record_id = :record_id
                    AND sample_type IN (' . implode(', ', $placeholders) . ')'
            );
            $deleteSamples->execute($parameters);
        }
        foreach ($samples as $sampleIndex => $sample) {
            $this->insertSample($batchId, $recordId, $type, $sample, $index, $sampleIndex);
        }

        return [$recordId, count($samples)];
    }

    private function insertSample(
        string $batchId,
        int $recordId,
        string $parentType,
        array $sample,
        int $recordIndex,
        int $sampleIndex
    ): void {
        $sampleType = validType(
            $sample['type'] ?? $parentType,
            "records[{$recordIndex}].samples[{$sampleIndex}].type"
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO health.samples (
                source_batch_id, record_id, parent_record_type, source_parent_row_id,
                sample_type, sample_at, start_at, end_at,
                numeric_value, numeric_value_2, category,
                latitude, longitude, altitude_m, payload
             ) VALUES (
                :batch_id, :record_id, :parent_type, NULL,
                :sample_type, :sample_at, :start_at, :end_at,
                :value, :value_2, :category,
                :latitude, :longitude, :altitude, CAST(:payload AS jsonb)
             )'
        );
        $statement->execute([
            'batch_id' => $batchId,
            'record_id' => $recordId,
            'parent_type' => $parentType,
            'sample_type' => $sampleType,
            'sample_at' => optionalTimestamp($sample, 'at'),
            'start_at' => optionalTimestamp($sample, 'start_at'),
            'end_at' => optionalTimestamp($sample, 'end_at'),
            'value' => isset($sample['value']) ? (float) $sample['value'] : null,
            'value_2' => isset($sample['value_2']) ? (float) $sample['value_2'] : null,
            'category' => isset($sample['category']) ? (int) $sample['category'] : null,
            'latitude' => isset($sample['latitude']) ? (float) $sample['latitude'] : null,
            'longitude' => isset($sample['longitude']) ? (float) $sample['longitude'] : null,
            'altitude' => isset($sample['altitude_m']) ? (float) $sample['altitude_m'] : null,
            'payload' => json_encode($sample, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    private function applyDeletion(string $batchId, array $deletion, int $index): void
    {
        $type = validType($deletion['type'] ?? null, "deletions[{$index}].type");
        $recordKey = optionalString($deletion, 'id', 255);
        if ($recordKey === null || $recordKey === '') {
            throw new ApiException(422, "deletions[{$index}].id is required");
        }
        $deletedAt = optionalTimestamp($deletion, 'deleted_at');

        $find = $this->pdo->prepare(
            'SELECT record_id, last_modified_at
               FROM health.records
              WHERE record_type = :type AND record_key = :record_key'
        );
        $find->execute(['type' => $type, 'record_key' => $recordKey]);
        $existing = $find->fetch();
        $recordId = $existing['record_id'] ?? false;
        $deletionIsCurrent = $existing === false
            || $deletedAt === null
            || $existing['last_modified_at'] === null
            || new DateTimeImmutable($deletedAt) > new DateTimeImmutable($existing['last_modified_at']);
        if ($recordId !== false && $deletionIsCurrent) {
            $deleteSamples = $this->pdo->prepare('DELETE FROM health.samples WHERE record_id = :record_id');
            $deleteSamples->execute(['record_id' => $recordId]);
            $mark = $this->pdo->prepare(
                'UPDATE health.records
                    SET is_deleted = true, latest_batch_id = :batch_id, updated_at = now()
                  WHERE record_id = :record_id'
            );
            $mark->execute(['batch_id' => $batchId, 'record_id' => $recordId]);
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO api.record_deletions (batch_id, record_type, record_key, deleted_at)
             VALUES (:batch_id, :type, :record_key, coalesce(:deleted_at, now()))'
        );
        $insert->execute([
            'batch_id' => $batchId,
            'type' => $type,
            'record_key' => $recordKey,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function upsertCursor(string $deviceId, string $recordType, mixed $changeToken): void
    {
        $type = validType($recordType, 'cursor record type');
        if (!is_string($changeToken) || $changeToken === '' || strlen($changeToken) > 8192) {
            throw new ApiException(422, "Invalid cursor for {$type}");
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO api.sync_cursors (device_id, record_type, change_token)
             VALUES (:device_id, :record_type, :change_token)
             ON CONFLICT (device_id, record_type) DO UPDATE SET
                change_token = EXCLUDED.change_token,
                updated_at = now()'
        );
        $statement->execute([
            'device_id' => $deviceId,
            'record_type' => $type,
            'change_token' => $changeToken,
        ]);
    }
}
