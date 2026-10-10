<?php

declare(strict_types=1);

final class AnalyticsController
{
    private const ROUTE_TYPES = "('exercise_route', 'exercise_route_location')";

    private const ACTIVITY_SQL = <<<'SQL'
WITH session_candidates AS (
    SELECT r.record_id, r.record_key, r.start_at, r.end_at, r.source_app_name,
           r.source_app_package, r.source_table, r.updated_at, r.payload,
           CASE WHEN coalesce(r.payload->>'exercise_type', '') ~ '^\d+$'
                THEN (r.payload->>'exercise_type')::integer ELSE 0 END AS exercise_type
      FROM health.records r
     WHERE r.record_type = 'exercise_session' AND NOT r.is_deleted
       AND r.source_app_package <> 'com.strava'
       AND (:from_date::timestamptz IS NULL OR r.start_at >= :from_date::timestamptz)
       AND (:to_date::timestamptz IS NULL OR r.start_at < :to_date::timestamptz)
       /*ID_FILTER*/
), selected AS MATERIALIZED (
    SELECT record_id, record_key, start_at, end_at, source_app_name,
           source_app_package, payload, exercise_type
      FROM (
        SELECT c.*, row_number() OVER (
            PARTITION BY coalesce(c.source_app_package, ''), c.start_at
            ORDER BY (c.source_table = 'android_api') DESC,
                     (nullif(c.payload->>'title', '') IS NOT NULL) DESC,
                     (c.exercise_type IN (33, 53)) DESC,
                     c.updated_at DESC, c.record_id DESC
        ) AS duplicate_rank
          FROM session_candidates c
      ) ranked
     WHERE duplicate_rank = 1
     ORDER BY start_at DESC
     /*ROW_LIMIT*/
), route_ordered AS (
    SELECT s.record_id, s.sample_at, s.sample_id, s.latitude, s.longitude, s.altitude_m,
           lag(s.latitude) OVER (PARTITION BY s.record_id ORDER BY s.sample_at, s.sample_id) AS prev_lat,
           lag(s.longitude) OVER (PARTITION BY s.record_id ORDER BY s.sample_at, s.sample_id) AS prev_lon
      FROM health.samples s JOIN selected a ON a.record_id = s.record_id
     WHERE s.sample_type IN ('exercise_route', 'exercise_route_location')
       AND s.latitude IS NOT NULL AND s.longitude IS NOT NULL
), route_stats AS (
    SELECT record_id, count(*) AS route_points,
           sum(CASE WHEN prev_lat IS NULL THEN 0 ELSE
               6371000.0 * 2 * asin(sqrt(least(1.0,
                 power(sin(radians(latitude - prev_lat) / 2), 2) +
                 cos(radians(prev_lat)) * cos(radians(latitude)) *
                 power(sin(radians(longitude - prev_lon) / 2), 2)))) END) AS route_distance_m,
           min(altitude_m) AS min_altitude_m, max(altitude_m) AS max_altitude_m,
           (array_agg(latitude ORDER BY sample_at, sample_id))[1] AS start_latitude,
           (array_agg(longitude ORDER BY sample_at, sample_id))[1] AS start_longitude
      FROM route_ordered GROUP BY record_id
), altitude_buckets AS (
    SELECT record_id, floor(extract(epoch FROM sample_at) / 180) AS bucket,
           avg(altitude_m) AS altitude_m
      FROM route_ordered
     WHERE altitude_m IS NOT NULL
     GROUP BY record_id, floor(extract(epoch FROM sample_at) / 180)
), altitude_deltas AS (
    SELECT b.*,
           lag(b.altitude_m) OVER (PARTITION BY b.record_id ORDER BY b.bucket) AS previous_altitude_m
      FROM altitude_buckets b
), elevation_stats AS (
    SELECT record_id,
           sum(CASE WHEN altitude_m > previous_altitude_m
                    THEN altitude_m - previous_altitude_m ELSE 0 END) AS ascent_m
      FROM altitude_deltas
     GROUP BY record_id
)
SELECT a.record_id::text AS id, a.record_key, a.start_at, a.end_at,
       extract(epoch FROM (a.end_at - a.start_at))::integer AS duration_s,
       a.exercise_type,
       coalesce(nullif(a.payload->>'title', ''), nullif(a.payload->>'notes', '')) AS title,
       a.source_app_name, a.source_app_package,
       coalesce(rs.route_distance_m, rm.measured_distance_m, 0) AS distance_m,
       coalesce(es.ascent_m, rm.measured_ascent_m, 0) AS ascent_m,
       rs.min_altitude_m, rs.max_altitude_m, coalesce(rs.route_points, 0) AS route_points,
       rs.start_latitude, rs.start_longitude,
       coalesce(nullif(rm.active_calories_kcal, 0), rm.total_calories_kcal, 0) AS calories_kcal,
       coalesce(rm.steps, 0) AS steps,
       hm.avg_hr, hm.max_hr
  FROM selected a
  JOIN route_stats rs ON rs.record_id = a.record_id AND rs.route_points > 1
  LEFT JOIN elevation_stats es ON es.record_id = a.record_id
  LEFT JOIN LATERAL (
    WITH metric_candidates AS (
      SELECT m.*,
             CASE WHEN m.record_type IN ('active_calories_burned','total_calories_burned')
                  THEN greatest(0, extract(epoch FROM least(m.end_at, a.end_at) - greatest(m.start_at, a.start_at)))
                  ELSE 1 END AS coverage
        FROM health.records m
       WHERE m.record_type IN ('distance','active_calories_burned','total_calories_burned','elevation_gained','steps')
         AND NOT m.is_deleted AND m.source_app_package = a.source_app_package
         AND ((m.record_type IN ('active_calories_burned','total_calories_burned')
               AND m.start_at < a.end_at AND m.end_at > a.start_at)
           OR (m.record_type NOT IN ('active_calories_burned','total_calories_burned')
               AND m.start_at BETWEEN a.start_at AND a.end_at))
    ), metric_streams AS (
      SELECT record_type, source_table, sum(coverage) AS coverage,
             count(*) AS record_count, max(updated_at) AS newest
        FROM metric_candidates
       GROUP BY record_type, source_table
    ), preferred_streams AS (
      SELECT DISTINCT ON (record_type) record_type, source_table
        FROM metric_streams
       ORDER BY record_type, coverage DESC,
                (source_table = 'android_api') DESC, record_count DESC, newest DESC
    ), metrics AS (
      SELECT c.*, row_number() OVER (
                 PARTITION BY c.record_type, c.start_at, c.end_at
                 ORDER BY c.updated_at DESC, c.record_id DESC
             ) AS duplicate_rank
        FROM metric_candidates c
        JOIN preferred_streams p USING (record_type, source_table)
    )
    SELECT sum(CASE WHEN m.record_type = 'distance' THEN CASE
          WHEN jsonb_typeof(m.payload->'distance') = 'object' AND nullif(m.payload#>>'{distance,in_meters}', '') IS NOT NULL
            THEN (m.payload#>>'{distance,in_meters}')::double precision
          WHEN coalesce(m.payload->>'distance', '') ~ '^-?[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$'
            THEN (m.payload->>'distance')::double precision ELSE 0 END ELSE 0 END) AS measured_distance_m,
      sum(CASE WHEN m.record_type = 'active_calories_burned' THEN (CASE
          WHEN jsonb_typeof(m.payload->'energy') = 'object' AND nullif(m.payload#>>'{energy,in_calories}', '') IS NOT NULL
            THEN (m.payload#>>'{energy,in_calories}')::double precision
          WHEN coalesce(m.payload->>'energy', '') ~ '^-?[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$'
            THEN (m.payload->>'energy')::double precision ELSE 0 END)
          * greatest(0, extract(epoch FROM least(m.end_at, a.end_at) - greatest(m.start_at, a.start_at)))
          / nullif(extract(epoch FROM m.end_at - m.start_at), 0)
          * 0.001 ELSE 0 END) AS active_calories_kcal,
      sum(CASE WHEN m.record_type = 'total_calories_burned' THEN (CASE
          WHEN jsonb_typeof(m.payload->'energy') = 'object' AND nullif(m.payload#>>'{energy,in_calories}', '') IS NOT NULL
            THEN (m.payload#>>'{energy,in_calories}')::double precision
          WHEN coalesce(m.payload->>'energy', '') ~ '^-?[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$'
            THEN (m.payload->>'energy')::double precision ELSE 0 END)
          * greatest(0, extract(epoch FROM least(m.end_at, a.end_at) - greatest(m.start_at, a.start_at)))
          / nullif(extract(epoch FROM m.end_at - m.start_at), 0)
          * 0.001 ELSE 0 END) AS total_calories_kcal,
      sum(CASE WHEN m.record_type = 'elevation_gained' THEN CASE
          WHEN jsonb_typeof(m.payload->'elevation') = 'object' AND nullif(m.payload#>>'{elevation,in_meters}', '') IS NOT NULL
            THEN (m.payload#>>'{elevation,in_meters}')::double precision
          WHEN coalesce(m.payload->>'elevation', '') ~ '^-?[0-9]+([.][0-9]+)?([eE][+-]?[0-9]+)?$'
            THEN (m.payload->>'elevation')::double precision ELSE 0 END ELSE 0 END) AS measured_ascent_m,
      sum(CASE WHEN m.record_type = 'steps' AND coalesce(m.payload->>'count', '') ~ '^\d+$'
            THEN (m.payload->>'count')::bigint ELSE 0 END) AS steps
      FROM metrics m
     WHERE m.duplicate_rank = 1
  ) rm ON true
  LEFT JOIN LATERAL (
    SELECT avg(hs.numeric_value) AS avg_hr, max(hs.numeric_value) AS max_hr
      FROM health.samples hs
     WHERE hs.sample_type = 'heart_rate'
       AND hs.sample_at BETWEEN a.start_at AND a.end_at
  ) hm ON true
SQL;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function summary(): never
    {
        jsonResponse($this->summaryData(max(7, min(730, (int) ($_GET['days'] ?? 90)))));
    }

    public function summaryData(int $days = 90): array
    {
        $days = max(7, min(730, $days));
        $now = new DateTimeImmutable();
        $periodStart = $now->modify("-{$days} days");
        $previousStart = $periodStart->modify("-{$days} days");
        $yearStart = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0);
        $historyStart = $previousStart < $yearStart ? $previousStart : $yearStart;
        $all = $this->activitiesSince($historyStart->format(DATE_ATOM));
        $activities = array_values(array_filter($all, fn ($a) => new DateTimeImmutable($a['start_at']) >= $periodStart));
        $previous = array_values(array_filter($all, fn ($a) => new DateTimeImmutable($a['start_at']) >= $previousStart && new DateTimeImmutable($a['start_at']) < $periodStart));

        return [
            'period_days' => $days,
            'last_sync_at' => $this->lastSuccessfulSyncAt(),
            'totals' => $this->totals($activities),
            'previous_totals' => $this->totals($previous),
            'weekly' => $this->weekly($all, 16),
            'sports' => $this->sports($activities),
            'recent' => array_slice($activities, 0, 6),
            'records' => $this->records($all),
        ];
    }

    private function lastSuccessfulSyncAt(): ?string
    {
        $value = $this->pdo->query(
            "SELECT max(completed_at) FROM api.sync_batches WHERE status = 'completed'"
        )->fetchColumn();

        if ($value === false || $value === null || $value === '') return null;
        return (new DateTimeImmutable((string) $value))->format(DATE_ATOM);
    }

    public function activities(): never
    {
        $from = $this->dateParam('from');
        $to = $this->dateParam('to', true);
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
        $rows = $this->activityRows($from, $to, null, $limit);
        $type = isset($_GET['type']) ? (int) $_GET['type'] : null;
        $gps = ($_GET['gps'] ?? '') === '1';
        $search = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
        $rows = array_values(array_filter($rows, static function (array $row) use ($type, $gps, $search): bool {
            if ($type !== null && (int) $row['exercise_type'] !== $type) return false;
            if ($gps && (int) $row['route_points'] === 0) return false;
            if ($search !== '' && !str_contains(mb_strtolower(($row['title'] ?? '') . ' ' . ($row['source_app_name'] ?? '')), $search)) return false;
            return true;
        }));
        jsonResponse(['activities' => $rows, 'count' => count($rows)]);
    }

    public function activity(string $id): never
    {
        if (!ctype_digit($id)) throw new ApiException(404, 'Sortie introuvable');
        $rows = $this->activityRows(null, null, (int) $id);
        if (!$rows) throw new ApiException(404, 'Sortie introuvable');
        $activity = $rows[0];
        $route = $this->route((int) $id);
        $activity['route'] = $this->decimate($route, 3500);
        $activity['splits'] = $this->splits($this->smoothRouteAltitude($route));
        $activity['pace_profile'] = $this->paceProfile($route);
        $splitPaces = array_column($activity['splits'], 'pace_s_per_km');
        $activity['pace_stats'] = [
            'average' => $activity['pace_s_per_km'],
            'fastest' => $splitPaces ? min($splitPaces) : null,
            'slowest' => $splitPaces ? max($splitPaces) : null,
        ];
        $activity['heart_rate'] = $this->series('heart_rate', $activity['start_at'], $activity['end_at'], 1200);
        $cadence = $this->cadenceSeries($activity['start_at'], $activity['end_at'], 1200);
        $activity['cadence'] = $cadence['series'];
        $activity['cadence_stats'] = $cadence['stats'];
        $activity['speed'] = $this->routeSpeed($route, 1200);
        if (!$activity['speed']) $activity['speed'] = $this->series('speed', $activity['start_at'], $activity['end_at'], 1200);
        $activity['heart_rate_zones'] = $this->heartRateZones($activity['heart_rate']);
        $activity['similar_activities'] = $this->similarActivities($activity, $route);
        jsonResponse(['activity' => $activity]);
    }

    public function activityCadence(string $id): never
    {
        if (!ctype_digit($id)) throw new ApiException(404, 'Sortie introuvable');
        $rows = $this->activityRows(null, null, (int) $id);
        if (!$rows) throw new ApiException(404, 'Sortie introuvable');
        $activity = $rows[0];
        $cadence = $this->cadenceSeries($activity['start_at'], $activity['end_at'], 1200);

        jsonResponse([
            'activity_id' => $activity['id'],
            'title' => $activity['title'],
            'exercise_type' => $activity['exercise_type'],
            'start_at' => $activity['start_at'],
            'end_at' => $activity['end_at'],
            'unit' => 'steps_per_minute',
            'point_count' => count($cadence['series']),
            'cadence_stats' => $cadence['stats'],
            'cadence' => $cadence['series'],
        ]);
    }

    public function compare(): never
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($_GET['ids'] ?? ''))), 'ctype_digit')));
        if (count($ids) < 2 || count($ids) > 4) throw new ApiException(422, 'Sélectionnez entre 2 et 4 sorties');
        $items = [];
        foreach ($ids as $id) {
            $rows = $this->activityRows(null, null, (int) $id);
            if ($rows) {
                $row = $rows[0];
                $route = $this->route((int) $id);
                $row['splits'] = $this->splits($route);
                $items[] = $row;
            }
        }
        jsonResponse(['activities' => $items]);
    }

    public function health(): never
    {
        $days = max(7, min(1460, (int) ($_GET['days'] ?? 90)));
        header('Access-Control-Allow-Origin: *');
        jsonResponse($this->healthData($days));
    }

    public function healthCatalog(): never
    {
        $data = $this->healthData(7);
        $metrics = [];
        foreach ($data['sections'] as $section) {
            foreach ($section['metrics'] as $metric) {
                $metrics[] = [
                    'id' => $metric['id'],
                    'label' => $metric['label'],
                    'unit' => $metric['unit'],
                    'decimals' => $metric['decimals'],
                    'section' => ['id' => $section['id'], 'title' => $section['title']],
                    'endpoint' => '/api/v1/dashboard/health/' . $metric['id'],
                ];
            }
        }
        header('Access-Control-Allow-Origin: *');
        jsonResponse([
            'api_version' => 'v1',
            'count' => count($metrics),
            'period_days' => ['minimum' => 7, 'maximum' => 1460, 'default' => 90],
            'metrics' => $metrics,
        ]);
    }

    public function healthIndicator(string $id): never
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id)) throw new ApiException(404, 'Indicateur introuvable');
        $days = max(7, min(1460, (int) ($_GET['days'] ?? 90)));
        $data = $this->healthData($days);
        foreach ($data['sections'] as $section) {
            foreach ($section['metrics'] as $metric) {
                if ($metric['id'] !== $id) continue;
                header('Access-Control-Allow-Origin: *');
                jsonResponse([
                    'api_version' => 'v1',
                    'period_days' => $days,
                    'generated_at' => $data['generated_at'],
                    'section' => ['id' => $section['id'], 'title' => $section['title']],
                    'metric' => $metric,
                ]);
            }
        }
        throw new ApiException(404, 'Indicateur introuvable');
    }

    public function healthData(int $days = 90): array
    {
        $days = max(7, min(1460, $days));
        $from = (new DateTimeImmutable())->modify("-{$days} days")->format(DATE_ATOM);

        $number = static fn (string $key): string => "CASE WHEN jsonb_typeof(payload->'{$key}') = 'number' THEN (payload->>'{$key}')::double precision END";
        $object = static fn (string $key, string $unit): string => "CASE WHEN jsonb_typeof(payload->'{$key}') = 'object' THEN nullif(payload#>>'{{$key},{$unit}}', '')::double precision END";
        $numberOrObject = static fn (string $key, string $unit): string => "coalesce(CASE WHEN jsonb_typeof(payload->'{$key}') = 'object' THEN nullif(payload#>>'{{$key},{$unit}}', '')::double precision END, CASE WHEN jsonb_typeof(payload->'{$key}') = 'number' THEN (payload->>'{$key}')::double precision END)";

        $steps = $this->dailyRecordMetric('steps', $number('count'), 'sum', $from);
        $distance = $this->dailyRecordMetric('distance', $numberOrObject('distance', 'in_meters'), 'sum', $from, 0.001);
        $calories = $this->dailyPreferredIntervalMetric('total_calories_burned', $numberOrObject('energy', 'in_calories'), $from, 0.001);
        $floors = $this->dailyRecordMetric('floors_climbed', $number('floors'), 'sum', $from);

        $weight = $this->dailyRecordMetric('weight', $numberOrObject('weight', 'in_grams'), 'avg', $from, 0.001);
        $bodyFatExpression = 'coalesce(' . $object('percentage', 'value') . ', ' . $number('percentage') . ')';
        $bodyFat = $this->dailyRecordMetric('body_fat', $bodyFatExpression, 'avg', $from);
        $leanMass = $this->dailyRecordMetric('lean_body_mass', $numberOrObject('mass', 'in_grams'), 'avg', $from, 0.001);
        $waterMassExpression = 'coalesce(' . $numberOrObject('body_water_mass', 'in_grams') . ', ' . $numberOrObject('mass', 'in_grams') . ')';
        $waterMass = $this->dailyRecordMetric('body_water_mass', $waterMassExpression, 'avg', $from, 0.001);
        $boneMass = $this->dailyRecordMetric('bone_mass', $numberOrObject('mass', 'in_grams'), 'avg', $from, 0.001);
        $bmrWatts = 'coalesce(' . $object('basal_metabolic_rate', 'in_watts') . ', ' . $number('basal_metabolic_rate') . ')';
        $bmr = $this->dailyRecordMetric('basal_metabolic_rate', $bmrWatts, 'avg', $from, 20.6362855);

        $heightAll = $this->dailyRecordMetric('height', $numberOrObject('height', 'in_meters'), 'avg', '2000-01-01T00:00:00+00:00');
        $height = $heightAll ? (float) end($heightAll)['value'] : null;
        $bmi = $height && $height > 0 ? array_map(static fn (array $point): array => [
            'date' => $point['date'],
            'value' => round($point['value'] / ($height * $height), 2),
        ], $weight) : [];

        $restingHeart = $this->dailyRecordMetric('resting_heart_rate', $number('beats_per_minute'), 'avg', $from);
        $heart = $this->dailySampleMetric('heart_rate', 'avg', $from);
        $hrv = $this->dailyRecordMetric('heart_rate_variability_rmssd', $number('heart_rate_variability_millis'), 'avg', $from);
        $oxygen = $this->dailyRecordMetric('oxygen_saturation', $bodyFatExpression, 'avg', $from);
        $respiration = $this->dailyRecordMetric('respiratory_rate', $number('rate'), 'avg', $from);
        $skinTemperature = $this->dailySampleMetric('skin_temperature_delta', 'avg', $from);
        $vo2 = $this->dailyRecordMetric('vo2_max', $number('vo2_milliliters_per_minute_kilogram'), 'avg', $from);

        $sleep = $this->dailySleepMetric($from);
        $sleepAwake = $this->dailySleepStageMetric(1, $from);
        $sleepLight = $this->dailySleepStageMetric(4, $from);
        $sleepDeep = $this->dailySleepStageMetric(5, $from);
        $sleepRem = $this->dailySleepStageMetric(6, $from);
        $readiness = $this->dailyReadinessMetric($sleep, $hrv, $restingHeart);
        $nutritionEnergy = $this->dailyRecordMetric('nutrition', $numberOrObject('energy', 'in_calories'), 'sum', $from, 0.001);
        $protein = $this->dailyRecordMetric('nutrition', $numberOrObject('protein', 'in_grams'), 'sum', $from);
        $carbohydrates = $this->dailyRecordMetric('nutrition', $numberOrObject('total_carbohydrate', 'in_grams'), 'sum', $from);
        $fat = $this->dailyRecordMetric('nutrition', $numberOrObject('total_fat', 'in_grams'), 'sum', $from);
        $fiber = $this->dailyRecordMetric('nutrition', $numberOrObject('dietary_fiber', 'in_grams'), 'sum', $from);
        $hydration = $this->dailyRecordMetric('hydration', $numberOrObject('volume', 'in_liters'), 'sum', $from);

        return [
            'api_version' => 'v1',
            'period_days' => $days,
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
            'height_m' => $height,
            'links' => [
                'self' => "/api/v1/dashboard/health?days={$days}",
                'catalog' => '/api/v1/dashboard/health/catalog',
            ],
            'sections' => [
                [
                    'id' => 'activity', 'title' => 'Activité', 'icon' => 'directions_walk',
                    'description' => 'Tes mouvements et ta dépense énergétique au quotidien.',
                    'metrics' => [
                        $this->healthMetric('steps', 'Pas', 'pas', $steps, 0, '#1a73e8'),
                        $this->healthMetric('distance', 'Distance', 'km', $distance, 2, '#00a98f'),
                        $this->healthMetric('calories', 'Calories brûlées', 'kcal', $calories, 0, '#f4511e'),
                        $this->healthMetric('floors', 'Étages gravis', 'étages', $floors, 0, '#7b61c9'),
                    ],
                ],
                [
                    'id' => 'body', 'title' => 'Mensurations', 'icon' => 'monitor_weight',
                    'description' => 'Poids, composition corporelle et métabolisme.',
                    'metrics' => [
                        $this->healthMetric('weight', 'Poids', 'kg', $weight, 2, '#1a73e8'),
                        $this->healthMetric('bmi', 'IMC', '', $bmi, 1, '#5f6368'),
                        $this->healthMetric('body_fat', 'Masse grasse', '%', $bodyFat, 1, '#e85d75'),
                        $this->healthMetric('lean_mass', 'Masse maigre', 'kg', $leanMass, 1, '#00a98f'),
                        $this->healthMetric('water_mass', 'Masse hydrique', 'kg', $waterMass, 1, '#4285f4'),
                        $this->healthMetric('bone_mass', 'Masse osseuse', 'kg', $boneMass, 2, '#8d6e63'),
                        $this->healthMetric('bmr', 'Métabolisme basal', 'kcal/j', $bmr, 0, '#f9ab00'),
                    ],
                ],
                [
                    'id' => 'vitals', 'title' => 'Signes vitaux', 'icon' => 'favorite',
                    'description' => 'Indicateurs cardiorespiratoires et récupération.',
                    'metrics' => [
                        $this->healthMetric('resting_hr', 'Fréquence au repos', 'bpm', $restingHeart, 0, '#e53935'),
                        $this->healthMetric('heart_rate', 'Fréquence moyenne', 'bpm', $heart, 0, '#ef5350'),
                        $this->healthMetric('hrv', 'Variabilité cardiaque', 'ms', $hrv, 1, '#ab47bc'),
                        $this->healthMetric('oxygen', 'Oxygène sanguin', '%', $oxygen, 1, '#4285f4'),
                        $this->healthMetric('respiration', 'Fréquence respiratoire', 'resp./min', $respiration, 1, '#26a69a'),
                        $this->healthMetric('skin_temperature', 'Température cutanée', '°C écart', $skinTemperature, 2, '#ff7043'),
                        $this->healthMetric('vo2_max', 'VO₂ max', 'ml/kg/min', $vo2, 1, '#7e57c2'),
                    ],
                ],
                [
                    'id' => 'recovery', 'title' => 'Récupération', 'icon' => 'battery_heart',
                    'description' => 'Estimation Pizid fondée sur le sommeil, la VFC et la fréquence cardiaque au repos.',
                    'metrics' => [
                        $this->healthMetric('readiness', 'Aptitude quotidienne estimée', '/100', $readiness, 0, '#7e57c2'),
                    ],
                ],
                [
                    'id' => 'sleep', 'title' => 'Sommeil', 'icon' => 'bedtime',
                    'description' => 'Durée de ta principale session de sommeil chaque jour.',
                    'metrics' => [
                        $this->healthMetric('sleep_duration', 'Durée du sommeil', 'h', $sleep, 2, '#5c6bc0'),
                        $this->healthMetric('sleep_light', 'Sommeil léger', 'h', $sleepLight, 2, '#7986cb'),
                        $this->healthMetric('sleep_deep', 'Sommeil profond', 'h', $sleepDeep, 2, '#3949ab'),
                        $this->healthMetric('sleep_rem', 'Sommeil paradoxal', 'h', $sleepRem, 2, '#8e67c7'),
                        $this->healthMetric('sleep_awake', 'Temps éveillé', 'h', $sleepAwake, 2, '#f9ab00'),
                    ],
                ],
                [
                    'id' => 'nutrition', 'title' => 'Nutrition et hydratation', 'icon' => 'restaurant',
                    'description' => 'Apports consignés par tes applications connectées.',
                    'metrics' => [
                        $this->healthMetric('nutrition_energy', 'Énergie consommée', 'kcal', $nutritionEnergy, 0, '#f9ab00'),
                        $this->healthMetric('protein', 'Protéines', 'g', $protein, 1, '#e53935'),
                        $this->healthMetric('carbohydrates', 'Glucides', 'g', $carbohydrates, 1, '#fb8c00'),
                        $this->healthMetric('fat', 'Lipides', 'g', $fat, 1, '#8d6e63'),
                        $this->healthMetric('fiber', 'Fibres', 'g', $fiber, 1, '#43a047'),
                        $this->healthMetric('hydration', 'Hydratation', 'L', $hydration, 2, '#039be5'),
                    ],
                ],
            ],
        ];
    }

    private function dailyRecordMetric(string $type, string $expression, string $aggregate, string $from, float $multiplier = 1.0): array
    {
        $aggregate = in_array(strtolower($aggregate), ['sum', 'avg', 'max'], true) ? strtolower($aggregate) : 'avg';
        $sql = "WITH points AS (
                    SELECT (coalesce(start_at, end_at) AT TIME ZONE 'Europe/Paris')::date AS day,
                           ({$expression}) * :multiplier AS value
                      FROM health.records
                     WHERE record_type = :type AND NOT is_deleted
                       AND coalesce(start_at, end_at) >= :from_date::timestamptz
                 )
                 SELECT day::text AS date, round({$aggregate}(value)::numeric, 3)::double precision AS value
                   FROM points WHERE value IS NOT NULL
                  GROUP BY day ORDER BY day";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['type' => $type, 'from_date' => $from, 'multiplier' => $multiplier]);
        return array_map(static fn (array $row): array => ['date' => $row['date'], 'value' => (float) $row['value']], $statement->fetchAll());
    }

    private function dailyPreferredIntervalMetric(string $type, string $expression, string $from, float $multiplier = 1.0): array
    {
        $sql = "WITH candidates AS (
                    SELECT record_id, source_app_package, source_table, start_at, end_at, updated_at,
                           (coalesce(start_at, end_at) AT TIME ZONE 'Europe/Paris')::date AS day,
                           ({$expression}) * :multiplier AS value,
                           greatest(1, extract(epoch FROM end_at - start_at)) AS coverage
                      FROM health.records
                     WHERE record_type = :type AND NOT is_deleted
                       AND coalesce(start_at, end_at) >= :from_date::timestamptz
                 ), streams AS (
                    SELECT day, source_app_package, source_table,
                           sum(coverage) AS coverage, count(*) AS record_count,
                           max(updated_at) AS newest
                      FROM candidates WHERE value IS NOT NULL
                     GROUP BY day, source_app_package, source_table
                 ), preferred AS (
                    SELECT day, source_app_package, source_table
                      FROM (
                        SELECT s.*, row_number() OVER (
                            PARTITION BY day
                            ORDER BY coverage DESC, (source_table = 'android_api') DESC,
                                     record_count DESC, newest DESC
                        ) AS stream_rank
                          FROM streams s
                      ) ranked
                     WHERE stream_rank = 1
                 ), points AS (
                    SELECT c.*, row_number() OVER (
                        PARTITION BY c.day, c.start_at, c.end_at
                        ORDER BY c.updated_at DESC, c.record_id DESC
                    ) AS duplicate_rank
                      FROM candidates c
                      JOIN preferred p
                        ON p.day = c.day
                       AND p.source_table = c.source_table
                       AND p.source_app_package IS NOT DISTINCT FROM c.source_app_package
                 )
                 SELECT day::text AS date, round(sum(value)::numeric, 3)::double precision AS value
                   FROM points WHERE value IS NOT NULL AND duplicate_rank = 1
                  GROUP BY day ORDER BY day";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['type' => $type, 'from_date' => $from, 'multiplier' => $multiplier]);
        return array_map(static fn (array $row): array => ['date' => $row['date'], 'value' => (float) $row['value']], $statement->fetchAll());
    }

    private function dailySampleMetric(string $type, string $aggregate, string $from): array
    {
        $aggregate = in_array(strtolower($aggregate), ['sum', 'avg', 'max'], true) ? strtolower($aggregate) : 'avg';
        $statement = $this->pdo->prepare("SELECT (coalesce(sample_at, start_at) AT TIME ZONE 'Europe/Paris')::date::text AS date,
                                                 round({$aggregate}(numeric_value)::numeric, 3)::double precision AS value
                                            FROM health.samples
                                           WHERE sample_type = :type AND numeric_value IS NOT NULL
                                             AND coalesce(sample_at, start_at) >= :from_date::timestamptz
                                           GROUP BY 1 ORDER BY 1");
        $statement->execute(['type' => $type, 'from_date' => $from]);
        return array_map(static fn (array $row): array => ['date' => $row['date'], 'value' => (float) $row['value']], $statement->fetchAll());
    }

    private function dailySleepMetric(string $from): array
    {
        $statement = $this->pdo->prepare("SELECT (start_at AT TIME ZONE 'Europe/Paris')::date::text AS date,
                                                 round(max(extract(epoch FROM (end_at - start_at)) / 3600.0)::numeric, 3)::double precision AS value
                                            FROM health.records
                                           WHERE record_type = 'sleep_session' AND NOT is_deleted
                                             AND start_at >= :from_date::timestamptz AND end_at > start_at
                                             AND end_at - start_at < interval '20 hours'
                                           GROUP BY 1 ORDER BY 1");
        $statement->execute(['from_date' => $from]);
        return array_map(static fn (array $row): array => ['date' => $row['date'], 'value' => (float) $row['value']], $statement->fetchAll());
    }

    private function dailySleepStageMetric(int $category, string $from): array
    {
        $statement = $this->pdo->prepare("WITH sessions AS (
                                                SELECT DISTINCT ON ((start_at AT TIME ZONE 'Europe/Paris')::date)
                                                       record_id, (start_at AT TIME ZONE 'Europe/Paris')::date AS day
                                                  FROM health.records
                                                 WHERE record_type = 'sleep_session' AND NOT is_deleted
                                                   AND start_at >= :from_date::timestamptz AND end_at > start_at
                                                   AND end_at - start_at < interval '20 hours'
                                                 ORDER BY (start_at AT TIME ZONE 'Europe/Paris')::date,
                                                          (end_at - start_at) DESC, record_id DESC
                                           )
                                           SELECT sessions.day::text AS date,
                                                  round((sum(extract(epoch FROM (samples.end_at - samples.start_at))) / 3600.0)::numeric, 3)::double precision AS value
                                             FROM sessions
                                             JOIN health.samples samples ON samples.record_id = sessions.record_id
                                            WHERE samples.sample_type = 'sleep_stage' AND samples.category = :category
                                              AND samples.end_at > samples.start_at
                                            GROUP BY sessions.day ORDER BY sessions.day");
        $statement->execute(['from_date' => $from, 'category' => $category]);
        return array_map(static fn (array $row): array => ['date' => $row['date'], 'value' => (float) $row['value']], $statement->fetchAll());
    }

    private function dailyReadinessMetric(array $sleep, array $hrv, array $restingHeart): array
    {
        $sleepByDate = array_column($sleep, 'value', 'date');
        $hrvByDate = array_column($hrv, 'value', 'date');
        $restingByDate = array_column($restingHeart, 'value', 'date');
        $dates = array_values(array_intersect(array_keys($sleepByDate), array_keys($hrvByDate), array_keys($restingByDate)));
        sort($dates);
        $hrvHistory = [];
        $restingHistory = [];
        $series = [];
        foreach ($dates as $date) {
            $hrvValue = (float) $hrvByDate[$date];
            $restingValue = (float) $restingByDate[$date];
            $hrvBaseline = $this->median(array_slice($hrvHistory, -28));
            $restingBaseline = $this->median(array_slice($restingHistory, -28));
            if ($hrvBaseline !== null && $hrvBaseline > 0 && $restingBaseline !== null && $restingBaseline > 0) {
                $sleepScore = max(0.0, min(100.0, 100.0 - abs(8.0 - (float) $sleepByDate[$date]) * 20.0));
                $hrvScore = max(0.0, min(100.0, 50.0 + (($hrvValue / $hrvBaseline) - 1.0) * 100.0));
                $restingScore = max(0.0, min(100.0, 50.0 - (($restingValue / $restingBaseline) - 1.0) * 150.0));
                $series[] = ['date' => $date, 'value' => round($sleepScore * 0.5 + $hrvScore * 0.3 + $restingScore * 0.2, 1)];
            }
            $hrvHistory[] = $hrvValue;
            $restingHistory[] = $restingValue;
        }
        return $series;
    }

    private function median(array $values): ?float
    {
        if (count($values) < 5) return null;
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        return count($values) % 2
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2.0;
    }

    private function healthMetric(string $id, string $label, string $unit, array $series, int $decimals, string $color): array
    {
        $latest = $series ? $series[array_key_last($series)] : null;
        $first = $series ? $series[array_key_first($series)] : null;
        return [
            'id' => $id, 'label' => $label, 'unit' => $unit, 'decimals' => $decimals,
            'color' => $color, 'series' => $series, 'latest' => $latest,
            'change' => $latest && $first ? round($latest['value'] - $first['value'], max(1, $decimals), 3) : null,
        ];
    }

    private function activityRows(?string $from, ?string $to = null, ?int $id = null, int $limit = 10000): array
    {
        $limit = max(1, min(10000, $id === null ? $limit : 1));
        $sql = str_replace(
            ['/*ID_FILTER*/', '/*ROW_LIMIT*/'],
            [$id === null ? '' : 'AND r.record_id = :record_id', "LIMIT {$limit}"],
            self::ACTIVITY_SQL
        ) . ' ORDER BY a.start_at DESC';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':from_date', $from);
        $statement->bindValue(':to_date', $to);
        if ($id !== null) $statement->bindValue(':record_id', $id, PDO::PARAM_INT);
        $statement->execute();
        return array_map([$this, 'normalizeActivity'], $statement->fetchAll());
    }

    private function activitiesSince(?string $from): array
    {
        return $this->activityRows($from, null);
    }

    private function normalizeActivity(array $row): array
    {
        foreach (['duration_s', 'exercise_type', 'route_points', 'steps'] as $key) $row[$key] = (int) ($row[$key] ?? 0);
        foreach (['distance_m', 'ascent_m', 'min_altitude_m', 'max_altitude_m', 'start_latitude', 'start_longitude', 'calories_kcal', 'avg_hr', 'max_hr'] as $key) {
            $row[$key] = $row[$key] === null ? null : round((float) $row[$key], 2);
        }
        $row['pace_s_per_km'] = $row['distance_m'] > 50 ? round($row['duration_s'] / ($row['distance_m'] / 1000), 1) : null;
        return $row;
    }

    private function route(int $id): array
    {
        $statement = $this->pdo->prepare("SELECT sample_at AS time, latitude, longitude, altitude_m FROM health.samples WHERE record_id = :id AND sample_type IN ('exercise_route','exercise_route_location') AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY sample_at, sample_id");
        $statement->execute(['id' => $id]);
        return array_map(static fn ($p) => ['time' => $p['time'], 'lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude'], 'alt' => $p['altitude_m'] === null ? null : (float) $p['altitude_m']], $statement->fetchAll());
    }

    private function series(string $type, string $start, string $end, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT sample_at AS time, numeric_value AS value FROM health.samples WHERE sample_type = :type AND sample_at BETWEEN :start AND :end AND numeric_value IS NOT NULL ORDER BY sample_at, sample_id');
        $statement->execute(['type' => $type, 'start' => $start, 'end' => $end]);
        $rows = array_map(static fn ($p) => ['time' => $p['time'], 'value' => round((float) $p['value'], 2)], $statement->fetchAll());
        return $this->decimate($rows, $limit);
    }

    private function cadenceSeries(string $start, string $end, int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT sample_at AS time, numeric_value AS value
               FROM health.samples
              WHERE sample_type = 'steps_cadence'
                AND sample_at BETWEEN :start AND :end
                AND numeric_value BETWEEN 20 AND 300
              ORDER BY sample_at, sample_id"
        );
        $statement->execute(['start' => $start, 'end' => $end]);
        $rows = array_map(
            static fn ($point) => ['time' => $point['time'], 'value' => round((float) $point['value'], 1)],
            $statement->fetchAll()
        );
        $values = array_column($rows, 'value');

        return [
            'series' => $this->decimate($rows, $limit),
            'stats' => [
                'average' => $values ? round(array_sum($values) / count($values), 1) : null,
                'maximum' => $values ? max($values) : null,
            ],
        ];
    }

    private function decimate(array $points, int $limit): array
    {
        if (count($points) <= $limit) return $points;
        $step = count($points) / $limit;
        $result = [];
        for ($i = 0.0; (int) $i < count($points); $i += $step) $result[] = $points[(int) $i];
        return $result;
    }

    private function splits(array $route): array
    {
        if (count($route) < 2) return [];
        $splits = []; $distance = 0.0; $target = 1000.0;
        $splitStart = (float) (new DateTimeImmutable($route[0]['time']))->format('U.u');
        $gain = 0.0;
        for ($i = 1, $count = count($route); $i < $count; $i++) {
            $segment = $this->haversine($route[$i - 1], $route[$i]);
            $previousDistance = $distance;
            $distance += $segment;
            if ($route[$i]['alt'] !== null && $route[$i - 1]['alt'] !== null) $gain += max(0, $route[$i]['alt'] - $route[$i - 1]['alt']);
            if ($distance >= $target) {
                $previousTime = (float) (new DateTimeImmutable($route[$i - 1]['time']))->format('U.u');
                $currentTime = (float) (new DateTimeImmutable($route[$i]['time']))->format('U.u');
                $fraction = $segment > 0 ? max(0.0, min(1.0, ($target - $previousDistance) / $segment)) : 0.0;
                $splitEnd = $previousTime + $fraction * ($currentTime - $previousTime);
                $seconds = max(1, (int) round($splitEnd - $splitStart));
                $splits[] = ['km' => count($splits) + 1, 'duration_s' => $seconds, 'pace_s_per_km' => $seconds, 'ascent_m' => round($gain, 1)];
                $splitStart = $splitEnd; $gain = 0.0; $target += 1000.0;
            }
        }
        return $splits;
    }

    private function smoothRouteAltitude(array $route, int $radiusPoints = 180): array
    {
        if (count($route) < 2) return $route;
        $left = $right = 0;
        $sum = 0.0;
        $count = 0;
        $smoothed = $route;
        foreach ($route as $index => $point) {
            $minimum = max(0, $index - $radiusPoints);
            $maximum = min(count($route) - 1, $index + $radiusPoints);
            while ($left < $right && $left < $minimum) {
                if ($route[$left]['alt'] !== null) {
                    $sum -= (float) $route[$left]['alt'];
                    $count--;
                }
                $left++;
            }
            while ($right <= $maximum) {
                if ($route[$right]['alt'] !== null) {
                    $sum += (float) $route[$right]['alt'];
                    $count++;
                }
                $right++;
            }
            $smoothed[$index]['alt'] = $count > 0 ? $sum / $count : $point['alt'];
        }
        return $smoothed;
    }

    private function paceProfile(array $route, float $windowMeters = 600.0, int $limit = 500): array
    {
        if (count($route) < 2) return [];
        $count = count($route);
        $distance = [0.0];
        $timestamps = [(float) (new DateTimeImmutable($route[0]['time']))->format('U.u')];
        for ($i = 1; $i < $count; $i++) {
            $distance[$i] = $distance[$i - 1] + $this->haversine($route[$i - 1], $route[$i]);
            $timestamps[$i] = (float) (new DateTimeImmutable($route[$i]['time']))->format('U.u');
        }
        $halfWindow = $windowMeters / 2;
        $lo = $hi = 0;
        $profile = [];
        for ($i = 0; $i < $count; $i++) {
            while ($lo < $i && $distance[$i] - $distance[$lo] > $halfWindow) $lo++;
            while ($hi < $count - 1 && $distance[$hi] - $distance[$i] < $halfWindow) $hi++;
            $meters = $distance[$hi] - $distance[$lo];
            $seconds = $timestamps[$hi] - $timestamps[$lo];
            if ($meters < $windowMeters * 0.65 || $seconds <= 0) continue;
            $profile[] = [
                'distance_km' => round($distance[$i] / 1000, 3),
                'pace_s_per_km' => round($seconds / ($meters / 1000), 1),
            ];
        }
        return $this->decimate($profile, $limit);
    }

    private function haversine(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']); $dLon = deg2rad($b['lng'] - $a['lng']);
        $v = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLon / 2) ** 2;
        return 6371000 * 2 * asin(sqrt(min(1, $v)));
    }

    private function similarActivities(array $activity, array $route): array
    {
        if (count($route) < 2 || ($activity['distance_m'] ?? 0) < 100) return [];
        $targetDistance = (float) $activity['distance_m'];
        $targetShape = $this->routeShape($route);
        $targetEnds = [$route[0], $route[count($route) - 1]];
        $matches = [];

        foreach ($this->activityRows(null, null, null, 500) as $candidate) {
            if ($candidate['id'] === $activity['id'] || $candidate['distance_m'] < 100) continue;
            $distanceRatio = abs($candidate['distance_m'] - $targetDistance) / $targetDistance;
            if ($distanceRatio > 0.18 || $candidate['start_latitude'] === null || $candidate['start_longitude'] === null) continue;
            $candidateStart = ['lat' => $candidate['start_latitude'], 'lng' => $candidate['start_longitude']];
            if (min($this->haversine($targetEnds[0], $candidateStart), $this->haversine($targetEnds[1], $candidateStart)) > 600) continue;

            $candidateRoute = $this->route((int) $candidate['id']);
            if (count($candidateRoute) < 2) continue;
            $candidateShape = $this->routeShape($candidateRoute);
            $meanDistance = min(
                $this->shapeDistance($targetShape, $candidateShape),
                $this->shapeDistance($targetShape, array_reverse($candidateShape))
            );
            $similarity = (int) round(max(0, 100 - ($meanDistance / 5) - ($distanceRatio * 100)));
            if ($similarity < 75) continue;

            $candidate['similarity_pct'] = $similarity;
            $candidate['duration_delta_s'] = $candidate['duration_s'] - $activity['duration_s'];
            $candidate['pace_delta_s_per_km'] = $candidate['pace_s_per_km'] === null || $activity['pace_s_per_km'] === null
                ? null : round($candidate['pace_s_per_km'] - $activity['pace_s_per_km'], 1);
            $matches[] = $candidate;
        }

        usort($matches, static fn ($a, $b) => strcmp($a['start_at'], $b['start_at']));
        return $matches;
    }

    private function routeShape(array $route, int $points = 48): array
    {
        $last = count($route) - 1;
        $shape = [];
        for ($i = 0; $i < $points; $i++) {
            $shape[] = $route[(int) round($i * $last / ($points - 1))];
        }
        return $shape;
    }

    private function routeSpeed(array $route, int $limit, float $windowSeconds = 12.0): array
    {
        if (count($route) < 2) return [];
        $count = count($route);
        $cumulative = [0.0];
        $timestamps = [(float) (new DateTimeImmutable($route[0]['time']))->format('U.u')];
        for ($i = 1; $i < $count; $i++) {
            $cumulative[$i] = $cumulative[$i - 1] + $this->haversine($route[$i - 1], $route[$i]);
            $timestamps[$i] = (float) (new DateTimeImmutable($route[$i]['time']))->format('U.u');
        }

        $smoothed = $cumulative;
        $localSlopes = array_fill(0, $count, null);
        for ($i = 0; $i < $count; $i++) {
            $lo = $i;
            $hi = $i;
            while ($lo > 0 && $timestamps[$i] - $timestamps[$lo] < $windowSeconds) $lo--;
            while ($hi < $count - 1 && $timestamps[$hi] - $timestamps[$i] < $windowSeconds) $hi++;
            $segmentCount = $hi - $lo + 1;
            if ($segmentCount < 2) continue;
            $t0 = $timestamps[$lo];
            $sumT = $sumD = $sumTT = $sumTD = 0.0;
            for ($j = $lo; $j <= $hi; $j++) {
                $relativeTime = $timestamps[$j] - $t0;
                $sumT += $relativeTime;
                $sumD += $cumulative[$j];
                $sumTT += $relativeTime * $relativeTime;
                $sumTD += $relativeTime * $cumulative[$j];
            }
            $denominator = $segmentCount * $sumTT - $sumT * $sumT;
            if (abs($denominator) <= 1e-6) continue;
            $slope = ($segmentCount * $sumTD - $sumT * $sumD) / $denominator;
            $intercept = ($sumD - $slope * $sumT) / $segmentCount;
            $smoothed[$i] = $slope * ($timestamps[$i] - $t0) + $intercept;
            $localSlopes[$i] = max(0.0, $slope);
        }

        $smoothed[0] = $cumulative[0];
        $smoothed[$count - 1] = $cumulative[$count - 1];
        for ($i = 1; $i < $count; $i++) $smoothed[$i] = max($smoothed[$i], $smoothed[$i - 1]);
        if ($smoothed[$count - 2] > $smoothed[$count - 1]) $smoothed[$count - 2] = $smoothed[$count - 1];

        $speed = [];
        for ($i = 1; $i < $count; $i++) {
            $seconds = $timestamps[$i] - $timestamps[$i - 1];
            if ($seconds <= 0) continue;
            $metersPerSecond = $localSlopes[$i] ?? (($smoothed[$i] - $smoothed[$i - 1]) / $seconds);
            $speed[] = ['time' => $route[$i]['time'], 'value' => round($metersPerSecond, 2)];
        }
        return $this->decimate($speed, $limit);
    }

    private function shapeDistance(array $a, array $b): float
    {
        $total = 0.0;
        $count = min(count($a), count($b));
        for ($i = 0; $i < $count; $i++) $total += $this->haversine($a[$i], $b[$i]);
        return $count ? $total / $count : INF;
    }

    private function totals(array $rows): array
    {
        return ['activities' => count($rows), 'distance_m' => round(array_sum(array_column($rows, 'distance_m')), 1), 'duration_s' => array_sum(array_column($rows, 'duration_s')), 'ascent_m' => round(array_sum(array_column($rows, 'ascent_m')), 1), 'calories_kcal' => round(array_sum(array_column($rows, 'calories_kcal')), 0)];
    }

    private function weekly(array $rows, int $weeks): array
    {
        $result = [];
        $monday = (new DateTimeImmutable('monday this week'))->setTime(0, 0);
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $start = $monday->modify("-{$i} weeks");
            $result[$start->format('Y-m-d')] = ['week' => $start->format('Y-m-d'), 'activities' => 0, 'distance_m' => 0.0, 'duration_s' => 0, 'ascent_m' => 0.0, 'calories_kcal' => 0.0];
        }
        foreach ($rows as $row) {
            $date = new DateTimeImmutable($row['start_at']);
            $key = $date->modify('monday this week')->format('Y-m-d');
            if (!isset($result[$key])) continue;
            $result[$key]['activities']++; $result[$key]['distance_m'] += $row['distance_m']; $result[$key]['duration_s'] += $row['duration_s']; $result[$key]['ascent_m'] += $row['ascent_m']; $result[$key]['calories_kcal'] += $row['calories_kcal'];
        }
        return array_values($result);
    }

    private function sports(array $rows): array
    {
        $sports = [];
        foreach ($rows as $row) {
            $key = (string) $row['exercise_type'];
            $sports[$key] ??= ['exercise_type' => $row['exercise_type'], 'activities' => 0, 'distance_m' => 0.0, 'duration_s' => 0, 'calories_kcal' => 0.0];
            $sports[$key]['activities']++; $sports[$key]['distance_m'] += $row['distance_m']; $sports[$key]['duration_s'] += $row['duration_s']; $sports[$key]['calories_kcal'] += $row['calories_kcal'];
        }
        usort($sports, static fn ($a, $b) => $b['duration_s'] <=> $a['duration_s']);
        return array_values($sports);
    }

    private function records(array $rows): array
    {
        $gps = array_values(array_filter($rows, static fn ($r) => $r['distance_m'] > 100 && $r['duration_s'] > 0));
        $best = static fn (array $items, callable $score, bool $min = false) => $items ? array_reduce($items, static function ($winner, $item) use ($score, $min) {
            if ($winner === null) return $item;
            return ($min ? $score($item) < $score($winner) : $score($item) > $score($winner)) ? $item : $winner;
        }) : null;
        return [
            ['kind' => 'distance', 'activity' => $best($gps, fn ($r) => $r['distance_m'])],
            ['kind' => 'duration', 'activity' => $best($rows, fn ($r) => $r['duration_s'])],
            ['kind' => 'pace', 'activity' => $best(array_values(array_filter($gps, fn ($r) => in_array($r['exercise_type'], [33, 53], true))), fn ($r) => $r['pace_s_per_km'], true)],
            ['kind' => 'ascent', 'activity' => $best($gps, fn ($r) => $r['ascent_m'])],
            ['kind' => 'calories', 'activity' => $best(array_values(array_filter($rows, fn ($r) => $r['calories_kcal'] > 0)), fn ($r) => $r['calories_kcal'])],
            ['kind' => 'heart_rate', 'activity' => $best(array_values(array_filter($rows, fn ($r) => $r['max_hr'] !== null)), fn ($r) => $r['max_hr'])],
        ];
    }

    private function heartRateZones(array $series): array
    {
        $limits = [132, 165, 182, 194]; $seconds = [0, 0, 0, 0, 0];
        for ($i = 1, $n = count($series); $i < $n; $i++) {
            $duration = min(60, max(0, strtotime($series[$i]['time']) - strtotime($series[$i - 1]['time'])));
            $hr = $series[$i - 1]['value']; $zone = $hr < $limits[0] ? 0 : ($hr < $limits[1] ? 1 : ($hr < $limits[2] ? 2 : ($hr < $limits[3] ? 3 : 4)));
            $seconds[$zone] += $duration;
        }
        return array_map(static fn ($i, $s) => ['zone' => $i + 1, 'seconds' => $s], array_keys($seconds), $seconds);
    }

    private function dateParam(string $name, bool $endOfDay = false): ?string
    {
        $value = trim((string) ($_GET[$name] ?? ''));
        if ($value === '') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new ApiException(422, "Date {$name} invalide");
        return ($endOfDay ? $date->modify('+1 day') : $date)->format(DATE_ATOM);
    }
}
