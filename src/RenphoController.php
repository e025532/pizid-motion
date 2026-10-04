<?php

declare(strict_types=1);

final class RenphoController
{
    private const FIELDS = [
        'weight_kg' => 'Poids(kg)',
        'bmi' => 'IMC',
        'body_fat_percent' => 'Pourcentage de graisse corporelle(%)',
        'body_fat_mass_kg' => 'Masse grasse corporelle(kg)',
        'muscle_percent' => 'Pourcentage de masse musculaire(%)',
        'muscle_mass_kg' => 'Masse musculaire(kg)',
        'skeletal_muscle_percent' => 'Pourcentage des muscles squelettiques(%)',
        'skeletal_muscle_mass_kg' => 'Masse musculaire squelettique(kg)',
        'bone_percent' => 'Pourcentage osseux(%)',
        'bone_mass_kg' => 'Masse osseuse(kg)',
        'protein_percent' => 'Pourcentage de protéines(%)',
        'protein_mass_kg' => 'Masse protéique(kg)',
        'body_water_percent' => 'Pourcentage d’eau corporelle(%)',
        'body_water_mass_kg' => 'Masse d’eau corporelle(kg)',
        'fat_free_mass_kg' => 'Poids hors masse grasse(kg)',
        'subcutaneous_fat_percent' => 'Gras sous-cutané(%)',
        'visceral_fat_level' => 'Graisse viscérale',
        'basal_metabolic_rate_kcal' => 'Métabolisme de base(kcal)',
        'metabolic_age' => 'Âge métabolique',
        'waist_hip_ratio' => 'RTH (rapport taille-hanches)',
        'optimal_weight_kg' => 'Poids optimal(kg)',
        'optimal_weight_goal_kg' => 'Objectif de poids optimal(kg)',
        'optimal_muscle_goal_kg' => 'Objectif de masse musculaire optimale(kg)',
        'optimal_fat_goal_kg' => 'Objectif masse grasse optimale(kg)',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function ingest(array $device): never
    {
        [$body] = requestBody();
        $filename = basename(optionalString($body, 'filename', 255) ?: 'RENPHO-export');
        $encoded = optionalString($body, 'content_base64', 8_000_000);
        if ($encoded === null || $encoded === '') throw new ApiException(422, 'content_base64 is required');
        $raw = base64_decode($encoded, true);
        if ($raw === false) throw new ApiException(422, 'Invalid base64 content');
        if ($raw === '' || strlen($raw) > 5_000_000) throw new ApiException(413, 'RENPHO export is empty or too large');

        $result = $this->importCsv($raw, $filename);
        $result['status'] = 'imported';
        $result['device'] = $device['device_name'];
        $result['server_time'] = (new DateTimeImmutable())->format(DATE_ATOM);
        jsonResponse($result, 201);
    }

    private function importCsv(string $raw, string $filename): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to read RENPHO export');
        fwrite($stream, $raw);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (!is_array($headers)) throw new ApiException(422, 'RENPHO export is empty');
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        if (!in_array('La date', $headers, true) || !in_array('Temps', $headers, true) || !in_array('Poids(kg)', $headers, true)) {
            throw new ApiException(422, 'Unsupported RENPHO export format');
        }

        $columns = array_keys(self::FIELDS);
        $measurementColumns = implode(', ', $columns);
        $measurementValues = implode(', ', array_map(static fn(string $name): string => ':' . $name, $columns));
        $measurementUpdates = implode(', ', array_map(static fn(string $name): string => "{$name} = EXCLUDED.{$name}", $columns));
        $insertMeasurement = $this->pdo->prepare("INSERT INTO health.renpho_measurements (
            measured_at, local_date, source_file, source_row_number, {$measurementColumns},
            weight_level, body_type, notes, raw_payload
        ) VALUES (
            :measured_at, :local_date, :source_file, :source_row_number, {$measurementValues},
            :weight_level, :body_type, :notes, CAST(:raw_payload AS jsonb)
        ) ON CONFLICT (measured_at) DO UPDATE SET
            source_file = EXCLUDED.source_file, source_row_number = EXCLUDED.source_row_number,
            {$measurementUpdates}, weight_level = EXCLUDED.weight_level,
            body_type = EXCLUDED.body_type, notes = EXCLUDED.notes,
            raw_payload = EXCLUDED.raw_payload, imported_at = now()
        RETURNING measurement_id");
        $measurementExists = $this->pdo->prepare('SELECT measurement_id FROM health.renpho_measurements WHERE measured_at = :measured_at');

        $upsertRecord = $this->pdo->prepare("INSERT INTO health.records (
            record_type, record_key, source_table, source_row_id,
            source_app_package, source_app_name, recording_method,
            last_modified_at, start_at, end_at,
            start_zone_offset_seconds, end_zone_offset_seconds, local_date, payload
        ) VALUES (
            :record_type, :record_key, 'renpho_measurements', :source_row_id,
            'com.renpho.health', 'RENPHO Health', 0,
            :start_at, :start_at, :start_at,
            :zone_offset_seconds, :zone_offset_seconds, :local_date, CAST(:payload AS jsonb)
        ) ON CONFLICT (record_type, record_key) DO UPDATE SET
            source_row_id = EXCLUDED.source_row_id, payload = EXCLUDED.payload,
            last_modified_at = EXCLUDED.last_modified_at, start_at = EXCLUDED.start_at,
            end_at = EXCLUDED.end_at, local_date = EXCLUDED.local_date,
            is_deleted = false, updated_at = now()");

        $timezone = new DateTimeZone('Europe/Paris');
        $processed = $inserted = $updated = $normalized = 0;
        $rowNumber = 1;
        $this->pdo->beginTransaction();
        try {
            while (($values = fgetcsv($stream)) !== false) {
                ++$rowNumber;
                if ($values === [null] || $values === []) continue;
                $values = array_pad($values, count($headers), '');
                $row = array_combine($headers, array_slice($values, 0, count($headers)));
                if (!is_array($row)) throw new ApiException(422, "Malformed RENPHO row {$rowNumber}");
                $date = trim((string) ($row['La date'] ?? ''));
                $time = trim((string) ($row['Temps'] ?? ''));
                $measuredAt = DateTimeImmutable::createFromFormat('!Y.m.d H:i:s', "{$date} {$time}", $timezone);
                if (!$measuredAt) throw new ApiException(422, "Invalid RENPHO date at row {$rowNumber}");
                $measured = $measuredAt->format(DATE_ATOM);
                $measurementExists->execute(['measured_at' => $measured]);
                $exists = $measurementExists->fetchColumn() !== false;

                $params = [
                    'measured_at' => $measured,
                    'local_date' => $measuredAt->format('Y-m-d'),
                    'source_file' => $filename,
                    'source_row_number' => (int) ($this->number($row['N°'] ?? null) ?? $rowNumber - 1),
                    'weight_level' => $this->text($row['Niveau de poids'] ?? null),
                    'body_type' => $this->text($row['Type de corps'] ?? null),
                    'notes' => $this->text($row['Remarques'] ?? null),
                    'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ];
                foreach (self::FIELDS as $name => $header) $params[$name] = $this->number($row[$header] ?? null);
                $insertMeasurement->execute($params);
                $measurementId = (int) $insertMeasurement->fetchColumn();
                ++$processed;
                $exists ? ++$updated : ++$inserted;

                $records = [
                    'weight' => $params['weight_kg'] === null ? null : ['weight' => ['in_grams' => $params['weight_kg'] * 1000]],
                    'body_fat' => $params['body_fat_percent'] === null ? null : ['percentage' => ['value' => $params['body_fat_percent']]],
                    'lean_body_mass' => $params['fat_free_mass_kg'] === null ? null : ['mass' => ['in_grams' => $params['fat_free_mass_kg'] * 1000]],
                    'body_water_mass' => $params['body_water_mass_kg'] === null ? null : ['body_water_mass' => ['in_grams' => $params['body_water_mass_kg'] * 1000]],
                    'bone_mass' => $params['bone_mass_kg'] === null ? null : ['mass' => ['in_grams' => $params['bone_mass_kg'] * 1000]],
                    'basal_metabolic_rate' => $params['basal_metabolic_rate_kcal'] === null ? null : [
                        'basal_metabolic_rate' => ['in_watts' => $params['basal_metabolic_rate_kcal'] / 20.6362855],
                        'source_value_kcal_per_day' => $params['basal_metabolic_rate_kcal'],
                    ],
                ];
                foreach ($records as $recordType => $payload) {
                    if ($payload === null) continue;
                    $payload['zone_offset'] = $measuredAt->format('P');
                    $payload['renpho_measurement_id'] = $measurementId;
                    $upsertRecord->execute([
                        'record_type' => $recordType,
                        'record_key' => 'renpho-csv-' . hash('sha256', $measured . '|' . $recordType),
                        'source_row_id' => $measurementId,
                        'start_at' => $measured,
                        'zone_offset_seconds' => $measuredAt->getOffset(),
                        'local_date' => $params['local_date'],
                        'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ]);
                    ++$normalized;
                }
            }
            if ($processed === 0) throw new ApiException(422, 'RENPHO export contains no measurements');
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        } finally {
            fclose($stream);
        }

        return [
            'filename' => $filename,
            'measurements' => $processed,
            'inserted' => $inserted,
            'updated' => $updated,
            'normalized_records' => $normalized,
        ];
    }

    private function number(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '--') return null;
        if (!is_numeric($value)) throw new ApiException(422, "Invalid RENPHO numeric value: {$value}");
        return (float) $value;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);
        return ($value === '' || $value === '--') ? null : $value;
    }
}
