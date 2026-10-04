<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This importer is CLI-only.\n");
    exit(1);
}

$path = $argv[1] ?? '';
if ($path === '' || !is_readable($path)) {
    fwrite(STDERR, "Usage: php infra/import/import_renpho.php <RENPHO-export.csv>\n");
    exit(1);
}

function renphoNumber(?string $value): ?float
{
    $value = trim((string) $value);
    if ($value === '' || $value === '--') {
        return null;
    }
    if (!is_numeric($value)) {
        throw new RuntimeException("Invalid numeric value: {$value}");
    }
    return (float) $value;
}

function renphoText(?string $value): ?string
{
    $value = trim((string) $value);
    return ($value === '' || $value === '--') ? null : $value;
}

function renphoValue(array $row, string $column): ?string
{
    return array_key_exists($column, $row) ? (string) $row[$column] : null;
}

$handle = fopen($path, 'rb');
if ($handle === false) {
    throw new RuntimeException('Unable to open RENPHO export');
}

$headers = fgetcsv($handle);
if (!is_array($headers)) {
    throw new RuntimeException('RENPHO export is empty');
}
$headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);

$pdo = db();
$pdo->beginTransaction();

$insertMeasurement = $pdo->prepare(<<<'SQL'
INSERT INTO health.renpho_measurements (
    measured_at, local_date, source_file, source_row_number,
    weight_kg, bmi, body_fat_percent, body_fat_mass_kg,
    muscle_percent, muscle_mass_kg, skeletal_muscle_percent,
    skeletal_muscle_mass_kg, bone_percent, bone_mass_kg,
    protein_percent, protein_mass_kg, body_water_percent,
    body_water_mass_kg, fat_free_mass_kg, subcutaneous_fat_percent,
    visceral_fat_level, basal_metabolic_rate_kcal, metabolic_age,
    waist_hip_ratio, optimal_weight_kg, weight_level, body_type,
    optimal_weight_goal_kg, optimal_muscle_goal_kg, optimal_fat_goal_kg,
    notes, raw_payload
) VALUES (
    :measured_at, :local_date, :source_file, :source_row_number,
    :weight_kg, :bmi, :body_fat_percent, :body_fat_mass_kg,
    :muscle_percent, :muscle_mass_kg, :skeletal_muscle_percent,
    :skeletal_muscle_mass_kg, :bone_percent, :bone_mass_kg,
    :protein_percent, :protein_mass_kg, :body_water_percent,
    :body_water_mass_kg, :fat_free_mass_kg, :subcutaneous_fat_percent,
    :visceral_fat_level, :basal_metabolic_rate_kcal, :metabolic_age,
    :waist_hip_ratio, :optimal_weight_kg, :weight_level, :body_type,
    :optimal_weight_goal_kg, :optimal_muscle_goal_kg, :optimal_fat_goal_kg,
    :notes, CAST(:raw_payload AS jsonb)
)
ON CONFLICT (measured_at) DO UPDATE SET
    source_file = EXCLUDED.source_file,
    source_row_number = EXCLUDED.source_row_number,
    weight_kg = EXCLUDED.weight_kg,
    bmi = EXCLUDED.bmi,
    body_fat_percent = EXCLUDED.body_fat_percent,
    body_fat_mass_kg = EXCLUDED.body_fat_mass_kg,
    muscle_percent = EXCLUDED.muscle_percent,
    muscle_mass_kg = EXCLUDED.muscle_mass_kg,
    skeletal_muscle_percent = EXCLUDED.skeletal_muscle_percent,
    skeletal_muscle_mass_kg = EXCLUDED.skeletal_muscle_mass_kg,
    bone_percent = EXCLUDED.bone_percent,
    bone_mass_kg = EXCLUDED.bone_mass_kg,
    protein_percent = EXCLUDED.protein_percent,
    protein_mass_kg = EXCLUDED.protein_mass_kg,
    body_water_percent = EXCLUDED.body_water_percent,
    body_water_mass_kg = EXCLUDED.body_water_mass_kg,
    fat_free_mass_kg = EXCLUDED.fat_free_mass_kg,
    subcutaneous_fat_percent = EXCLUDED.subcutaneous_fat_percent,
    visceral_fat_level = EXCLUDED.visceral_fat_level,
    basal_metabolic_rate_kcal = EXCLUDED.basal_metabolic_rate_kcal,
    metabolic_age = EXCLUDED.metabolic_age,
    waist_hip_ratio = EXCLUDED.waist_hip_ratio,
    optimal_weight_kg = EXCLUDED.optimal_weight_kg,
    weight_level = EXCLUDED.weight_level,
    body_type = EXCLUDED.body_type,
    optimal_weight_goal_kg = EXCLUDED.optimal_weight_goal_kg,
    optimal_muscle_goal_kg = EXCLUDED.optimal_muscle_goal_kg,
    optimal_fat_goal_kg = EXCLUDED.optimal_fat_goal_kg,
    notes = EXCLUDED.notes,
    raw_payload = EXCLUDED.raw_payload,
    imported_at = now()
RETURNING measurement_id
SQL);

$existingRecord = $pdo->prepare(<<<'SQL'
SELECT 1
  FROM health.records
 WHERE record_type = :record_type
   AND source_app_package = 'com.renpho.health'
   AND start_at = CAST(:start_at AS timestamptz)
   AND NOT is_deleted
 LIMIT 1
SQL);

$insertRecord = $pdo->prepare(<<<'SQL'
INSERT INTO health.records (
    record_type, record_key, source_table, source_row_id,
    source_app_package, source_app_name, recording_method,
    last_modified_at, start_at, end_at,
    start_zone_offset_seconds, end_zone_offset_seconds,
    local_date, payload
) VALUES (
    :record_type, :record_key, 'renpho_measurements', :source_row_id,
    'com.renpho.health', 'RENPHO Health', 0,
    :start_at, :start_at, :start_at, :zone_offset_seconds, :zone_offset_seconds,
    :local_date, CAST(:payload AS jsonb)
)
ON CONFLICT (record_type, record_key) DO UPDATE SET
    source_row_id = EXCLUDED.source_row_id,
    payload = EXCLUDED.payload,
    updated_at = now(),
    is_deleted = false
SQL);

$timezone = new DateTimeZone('Europe/Paris');
$sourceFile = basename($path);
$rowNumber = 1;
$measurementCount = 0;
$recordCount = 0;

try {
    while (($values = fgetcsv($handle)) !== false) {
        ++$rowNumber;
        if ($values === [null] || $values === []) {
            continue;
        }
        $values = array_pad($values, count($headers), '');
        $row = array_combine($headers, array_slice($values, 0, count($headers)));
        if (!is_array($row)) {
            throw new RuntimeException("Malformed CSV row {$rowNumber}");
        }

        $date = trim((string) renphoValue($row, 'La date'));
        $time = trim((string) renphoValue($row, 'Temps'));
        $measuredAt = DateTimeImmutable::createFromFormat('!Y.m.d H:i:s', "{$date} {$time}", $timezone);
        if (!$measuredAt) {
            throw new RuntimeException("Invalid date at CSV row {$rowNumber}");
        }

        $params = [
            'measured_at' => $measuredAt->format(DateTimeInterface::ATOM),
            'local_date' => $measuredAt->format('Y-m-d'),
            'source_file' => $sourceFile,
            'source_row_number' => (int) (renphoNumber(renphoValue($row, 'N°')) ?? $rowNumber - 1),
            'weight_kg' => renphoNumber(renphoValue($row, 'Poids(kg)')),
            'bmi' => renphoNumber(renphoValue($row, 'IMC')),
            'body_fat_percent' => renphoNumber(renphoValue($row, 'Pourcentage de graisse corporelle(%)')),
            'body_fat_mass_kg' => renphoNumber(renphoValue($row, 'Masse grasse corporelle(kg)')),
            'muscle_percent' => renphoNumber(renphoValue($row, 'Pourcentage de masse musculaire(%)')),
            'muscle_mass_kg' => renphoNumber(renphoValue($row, 'Masse musculaire(kg)')),
            'skeletal_muscle_percent' => renphoNumber(renphoValue($row, 'Pourcentage des muscles squelettiques(%)')),
            'skeletal_muscle_mass_kg' => renphoNumber(renphoValue($row, 'Masse musculaire squelettique(kg)')),
            'bone_percent' => renphoNumber(renphoValue($row, 'Pourcentage osseux(%)')),
            'bone_mass_kg' => renphoNumber(renphoValue($row, 'Masse osseuse(kg)')),
            'protein_percent' => renphoNumber(renphoValue($row, 'Pourcentage de protéines(%)')),
            'protein_mass_kg' => renphoNumber(renphoValue($row, 'Masse protéique(kg)')),
            'body_water_percent' => renphoNumber(renphoValue($row, 'Pourcentage d’eau corporelle(%)')),
            'body_water_mass_kg' => renphoNumber(renphoValue($row, 'Masse d’eau corporelle(kg)')),
            'fat_free_mass_kg' => renphoNumber(renphoValue($row, 'Poids hors masse grasse(kg)')),
            'subcutaneous_fat_percent' => renphoNumber(renphoValue($row, 'Gras sous-cutané(%)')),
            'visceral_fat_level' => renphoNumber(renphoValue($row, 'Graisse viscérale')),
            'basal_metabolic_rate_kcal' => renphoNumber(renphoValue($row, 'Métabolisme de base(kcal)')),
            'metabolic_age' => renphoNumber(renphoValue($row, 'Âge métabolique')),
            'waist_hip_ratio' => renphoNumber(renphoValue($row, 'RTH (rapport taille-hanches)')),
            'optimal_weight_kg' => renphoNumber(renphoValue($row, 'Poids optimal(kg)')),
            'weight_level' => renphoText(renphoValue($row, 'Niveau de poids')),
            'body_type' => renphoText(renphoValue($row, 'Type de corps')),
            'optimal_weight_goal_kg' => renphoNumber(renphoValue($row, 'Objectif de poids optimal(kg)')),
            'optimal_muscle_goal_kg' => renphoNumber(renphoValue($row, 'Objectif de masse musculaire optimale(kg)')),
            'optimal_fat_goal_kg' => renphoNumber(renphoValue($row, 'Objectif masse grasse optimale(kg)')),
            'notes' => renphoText(renphoValue($row, 'Remarques')),
            'raw_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        $insertMeasurement->execute($params);
        $measurementId = (int) $insertMeasurement->fetchColumn();
        ++$measurementCount;

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
            if ($payload === null) {
                continue;
            }
            $existingRecord->execute([
                'record_type' => $recordType,
                'start_at' => $params['measured_at'],
            ]);
            if ($existingRecord->fetchColumn()) {
                continue;
            }
            $payload['zone_offset'] = $measuredAt->format('P');
            $payload['renpho_measurement_id'] = $measurementId;
            $insertRecord->execute([
                'record_type' => $recordType,
                'record_key' => 'renpho-csv-' . hash('sha256', $params['measured_at'] . '|' . $recordType),
                'source_row_id' => $measurementId,
                'start_at' => $params['measured_at'],
                'zone_offset_seconds' => $measuredAt->getOffset(),
                'local_date' => $params['local_date'],
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            ++$recordCount;
        }
    }

    $pdo->commit();
    fclose($handle);
    fwrite(STDOUT, "Imported {$measurementCount} RENPHO measurements and {$recordCount} normalized records.\n");
} catch (Throwable $exception) {
    fclose($handle);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
