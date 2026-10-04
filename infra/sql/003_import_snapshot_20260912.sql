CREATE EXTENSION IF NOT EXISTS postgres_fdw;

-- Metadata for the historical snapshot used by this instance. The generic
-- ingestion schema remains data-free in 001_ingestion_snapshots.sql.
INSERT INTO ingestion.snapshots (
    source_captured_at, source_filename, source_sha256, source_bytes,
    sqlite_user_version, source_table_count, imported_table_count,
    imported_row_count, archive_database, archive_path, status, verification
) VALUES (
    '2026-09-12 05:01:12 Europe/Paris',
    'health_connect_export.db',
    '66ae100df6f71a7c376251ad783bbd3173a2b034688e913b5fbf89891acb7969',
    473194496, 26, 78, 77, 7728919,
    'health_connect_raw_20260912_0501',
    '/srv/health-connect/imports/2026-09-12_0501/health_connect_export.db',
    'verified',
    jsonb_build_object(
        'sqlite_integrity_check', 'ok',
        'source_data_tables', 77,
        'postgres_data_tables', 77,
        'source_rows', 7728919,
        'postgres_rows', 7728919,
        'table_count_hash_sha256', '283de464448333e061373a173ab109c7bdf1cb20488ba097ed6970afb7e7afe7',
        'real_cast', 'float8',
        'constraints_rebuilt', false,
        'indexes_rebuilt', false
    )
)
ON CONFLICT (source_sha256) DO UPDATE SET
    status = EXCLUDED.status,
    verification = EXCLUDED.verification,
    imported_at = now();

DROP SERVER IF EXISTS health_connect_raw_20260912 CASCADE;
CREATE SERVER health_connect_raw_20260912
    FOREIGN DATA WRAPPER postgres_fdw
    OPTIONS (host '/var/run/postgresql', dbname 'health_connect_raw_20260912_0501');
CREATE USER MAPPING FOR postgres
    SERVER health_connect_raw_20260912
    OPTIONS (user 'postgres');

DROP SCHEMA IF EXISTS raw_snapshot_20260912 CASCADE;
CREATE SCHEMA raw_snapshot_20260912;
IMPORT FOREIGN SCHEMA public
    FROM SERVER health_connect_raw_20260912
    INTO raw_snapshot_20260912;

DO $etl$
DECLARE
    v_snapshot_id bigint;
    t record;
    v_record_type text;
    v_kind text;
    v_start_expr text;
    v_end_expr text;
    v_start_offset_expr text;
    v_end_offset_expr text;
BEGIN
    SELECT snapshot_id INTO STRICT v_snapshot_id
    FROM ingestion.snapshots
    WHERE source_sha256 = '66ae100df6f71a7c376251ad783bbd3173a2b034688e913b5fbf89891acb7969';

    INSERT INTO health.sources (package_name, app_name, latest_snapshot_id, payload)
    SELECT
        coalesce(package_name, 'unknown:' || row_id::text),
        app_name,
        v_snapshot_id,
        to_jsonb(a)
    FROM raw_snapshot_20260912.application_info_table a
    ON CONFLICT (package_name) DO UPDATE SET
        app_name = EXCLUDED.app_name,
        latest_snapshot_id = EXCLUDED.latest_snapshot_id,
        payload = EXCLUDED.payload,
        updated_at = now();

    FOR t IN
        SELECT
            ft.foreign_table_name AS table_name,
            bool_or(c.column_name = 'start_time') AS has_start,
            bool_or(c.column_name = 'end_time') AS has_end,
            bool_or(c.column_name = 'time') AS has_time,
            bool_or(c.column_name = 'start_zone_offset') AS has_start_offset,
            bool_or(c.column_name = 'end_zone_offset') AS has_end_offset,
            bool_or(c.column_name = 'zone_offset') AS has_zone_offset,
            jsonb_agg(
                jsonb_build_object('name', c.column_name, 'type', c.data_type)
                ORDER BY c.ordinal_position
            ) AS columns
        FROM information_schema.foreign_tables ft
        JOIN information_schema.columns c
          ON c.table_schema = ft.foreign_table_schema
         AND c.table_name = ft.foreign_table_name
        WHERE ft.foreign_table_schema = 'raw_snapshot_20260912'
          AND EXISTS (
              SELECT 1
              FROM information_schema.columns u
              WHERE u.table_schema = ft.foreign_table_schema
                AND u.table_name = ft.foreign_table_name
                AND u.column_name = 'uuid'
          )
        GROUP BY ft.foreign_table_name
        ORDER BY ft.foreign_table_name
    LOOP
        v_record_type := CASE t.table_name
            WHEN 'cyclingpedalingcadencerecordtable' THEN 'cycling_pedaling_cadence'
            WHEN 'powerrecordtable' THEN 'power'
            WHEN 'speedrecordtable' THEN 'speed'
            WHEN 'stepscadencerecordtable' THEN 'steps_cadence'
            ELSE regexp_replace(t.table_name, '_record_table$', '')
        END;
        v_kind := CASE WHEN t.has_start THEN 'interval' ELSE 'instant' END;

        INSERT INTO health.record_types (
            record_type, source_table, record_kind, latest_snapshot_id,
            latest_row_count, source_columns
        )
        VALUES (
            v_record_type,
            t.table_name,
            v_kind,
            v_snapshot_id,
            0,
            t.columns
        )
        ON CONFLICT (record_type) DO UPDATE SET
            source_table = EXCLUDED.source_table,
            record_kind = EXCLUDED.record_kind,
            latest_snapshot_id = EXCLUDED.latest_snapshot_id,
            source_columns = EXCLUDED.source_columns,
            updated_at = now();

        v_start_expr := CASE
            WHEN t.has_start THEN 'to_timestamp(src.start_time / 1000.0)'
            WHEN t.has_time THEN 'to_timestamp(src.time / 1000.0)'
            ELSE 'NULL::timestamptz'
        END;
        v_end_expr := CASE
            WHEN t.has_end THEN 'to_timestamp(src.end_time / 1000.0)'
            WHEN t.has_time THEN 'to_timestamp(src.time / 1000.0)'
            ELSE v_start_expr
        END;
        v_start_offset_expr := CASE
            WHEN t.has_start_offset THEN 'src.start_zone_offset::integer'
            WHEN t.has_zone_offset THEN 'src.zone_offset::integer'
            ELSE 'NULL::integer'
        END;
        v_end_offset_expr := CASE
            WHEN t.has_end_offset THEN 'src.end_zone_offset::integer'
            WHEN t.has_zone_offset THEN 'src.zone_offset::integer'
            ELSE 'NULL::integer'
        END;

        EXECUTE format($sql$
            INSERT INTO health.records (
                record_type, record_key, source_table, source_row_id,
                source_app_package, source_app_name, device_info_id,
                recording_method, client_record_id, client_record_version,
                last_modified_at, start_at, end_at,
                start_zone_offset_seconds, end_zone_offset_seconds, local_date,
                first_seen_snapshot_id, latest_snapshot_id, is_deleted, payload
            )
            SELECT
                %L,
                coalesce(encode(src.uuid, 'hex'), 'source:' || %s || ':' || %L || ':' || src.row_id),
                %L,
                src.row_id,
                ai.package_name,
                ai.app_name,
                src.device_info_id,
                src.recording_method::integer,
                src.client_record_id,
                src.client_record_version,
                to_timestamp(src.last_modified_time / 1000.0),
                %s,
                %s,
                %s,
                %s,
                date '1970-01-01' + src.local_date::integer,
                %s,
                %s,
                false,
                to_jsonb(src)
            FROM raw_snapshot_20260912.%I src
            LEFT JOIN raw_snapshot_20260912.application_info_table ai
              ON ai.row_id = src.app_info_id
            ON CONFLICT (record_type, record_key) DO UPDATE SET
                source_table = EXCLUDED.source_table,
                source_row_id = EXCLUDED.source_row_id,
                source_app_package = EXCLUDED.source_app_package,
                source_app_name = EXCLUDED.source_app_name,
                device_info_id = EXCLUDED.device_info_id,
                recording_method = EXCLUDED.recording_method,
                client_record_id = EXCLUDED.client_record_id,
                client_record_version = EXCLUDED.client_record_version,
                last_modified_at = EXCLUDED.last_modified_at,
                start_at = EXCLUDED.start_at,
                end_at = EXCLUDED.end_at,
                start_zone_offset_seconds = EXCLUDED.start_zone_offset_seconds,
                end_zone_offset_seconds = EXCLUDED.end_zone_offset_seconds,
                local_date = EXCLUDED.local_date,
                latest_snapshot_id = EXCLUDED.latest_snapshot_id,
                is_deleted = false,
                payload = EXCLUDED.payload,
                updated_at = now()
        $sql$,
            v_record_type,
            v_snapshot_id,
            t.table_name,
            t.table_name,
            v_start_expr,
            v_end_expr,
            v_start_offset_expr,
            v_end_offset_expr,
            v_snapshot_id,
            v_snapshot_id,
            t.table_name
        );

        EXECUTE format(
            'UPDATE health.record_types SET latest_row_count = (SELECT count(*) FROM raw_snapshot_20260912.%I) WHERE record_type = %L',
            t.table_name,
            v_record_type
        );
    END LOOP;

    DELETE FROM health.samples WHERE source_snapshot_id = v_snapshot_id;

    INSERT INTO health.samples (
        source_snapshot_id, record_id, parent_record_type, source_parent_row_id,
        sample_type, sample_at, numeric_value, payload
    )
    SELECT v_snapshot_id, r.record_id, 'heart_rate', s.parent_key,
           'heart_rate', to_timestamp(s.epoch_millis / 1000.0),
           s.beats_per_minute, to_jsonb(s)
    FROM raw_snapshot_20260912.heart_rate_record_series_table s
    LEFT JOIN health.records r ON r.record_type = 'heart_rate'
                              AND r.source_row_id = s.parent_key
                              AND r.latest_snapshot_id = v_snapshot_id;

    INSERT INTO health.samples (
        source_snapshot_id, record_id, parent_record_type, source_parent_row_id,
        sample_type, start_at, end_at, category, payload
    )
    SELECT v_snapshot_id, r.record_id, 'sleep_session', s.parent_key,
           'sleep_stage', to_timestamp(s.stage_start_time / 1000.0),
           to_timestamp(s.stage_end_time / 1000.0), s.stage_type::integer, to_jsonb(s)
    FROM raw_snapshot_20260912.sleep_stages_table s
    LEFT JOIN health.records r ON r.record_type = 'sleep_session'
                              AND r.source_row_id = s.parent_key
                              AND r.latest_snapshot_id = v_snapshot_id;

    INSERT INTO health.samples (
        source_snapshot_id, record_id, parent_record_type, source_parent_row_id,
        sample_type, sample_at, latitude, longitude, altitude_m, payload
    )
    SELECT v_snapshot_id, r.record_id, 'exercise_session', s.parent_key,
           'exercise_route', to_timestamp(s.timestamp_millis / 1000.0),
           s.latitude, s.longitude, s.altitude, to_jsonb(s)
    FROM raw_snapshot_20260912.exercise_route_table s
    LEFT JOIN health.records r ON r.record_type = 'exercise_session'
                              AND r.source_row_id = s.parent_key
                              AND r.latest_snapshot_id = v_snapshot_id;

    INSERT INTO health.samples (
        source_snapshot_id, record_id, parent_record_type, source_parent_row_id,
        sample_type, start_at, end_at, numeric_value, payload
    )
    SELECT v_snapshot_id, r.record_id, 'exercise_session', s.parent_key,
           'exercise_lap', to_timestamp(s.lap_start_time / 1000.0),
           to_timestamp(s.lap_end_time / 1000.0), s.lap_length, to_jsonb(s)
    FROM raw_snapshot_20260912.exercise_laps_table s
    LEFT JOIN health.records r ON r.record_type = 'exercise_session'
                              AND r.source_row_id = s.parent_key
                              AND r.latest_snapshot_id = v_snapshot_id;

    INSERT INTO health.samples (
        source_snapshot_id, record_id, parent_record_type, source_parent_row_id,
        sample_type, start_at, end_at, numeric_value, numeric_value_2, category, payload
    )
    SELECT v_snapshot_id, r.record_id, 'exercise_session', s.parent_key,
           'exercise_segment', to_timestamp(s.segment_start_time / 1000.0),
           to_timestamp(s.segment_end_time / 1000.0), s.repetitions_count,
           s.weight_grams, s.segment_type::integer, to_jsonb(s)
    FROM raw_snapshot_20260912.exercise_segments_table s
    LEFT JOIN health.records r ON r.record_type = 'exercise_session'
                              AND r.source_row_id = s.parent_key
                              AND r.latest_snapshot_id = v_snapshot_id;

    INSERT INTO health.samples (
        source_snapshot_id, record_id, parent_record_type, source_parent_row_id,
        sample_type, sample_at, numeric_value, payload
    )
    SELECT v_snapshot_id, r.record_id, x.parent_record_type, x.parent_key,
           x.sample_type, to_timestamp(x.epoch_millis / 1000.0), x.value, x.payload
    FROM (
        SELECT 'speed'::text parent_record_type, parent_key, 'speed'::text sample_type,
               epoch_millis, speed value, to_jsonb(s) payload
        FROM raw_snapshot_20260912.speed_record_table s
        UNION ALL
        SELECT 'steps_cadence', parent_key, 'steps_cadence',
               epoch_millis, rate, to_jsonb(s)
        FROM raw_snapshot_20260912.steps_cadence_record_table s
        UNION ALL
        SELECT 'cycling_pedaling_cadence', parent_key, 'cycling_pedaling_cadence',
               epoch_millis, revolutions_per_minute, to_jsonb(s)
        FROM raw_snapshot_20260912.cycling_pedaling_cadence_record_table s
        UNION ALL
        SELECT 'power', parent_key, 'power',
               epoch_millis, power, to_jsonb(s)
        FROM raw_snapshot_20260912.power_record_table s
        UNION ALL
        SELECT 'skin_temperature', parent_key, 'skin_temperature_delta',
               epoch_millis, delta, to_jsonb(s)
        FROM raw_snapshot_20260912.skin_temperature_delta_table s
    ) x
    LEFT JOIN health.records r ON r.record_type = x.parent_record_type
                              AND r.source_row_id = x.parent_key
                              AND r.latest_snapshot_id = v_snapshot_id;
END
$etl$;

ANALYZE health.sources;
ANALYZE health.record_types;
ANALYZE health.records;
ANALYZE health.samples;

UPDATE ingestion.snapshots
SET verification = verification || jsonb_build_object(
        'analytic_record_types', (SELECT count(*) FROM health.record_types),
        'analytic_records', (SELECT count(*) FROM health.records),
        'analytic_samples', (SELECT count(*) FROM health.samples),
        'analytic_orphan_samples', (
            SELECT count(*) FROM health.samples WHERE record_id IS NULL
        )
    )
WHERE source_sha256 = '66ae100df6f71a7c376251ad783bbd3173a2b034688e913b5fbf89891acb7969';

DROP SERVER health_connect_raw_20260912 CASCADE;
DROP SCHEMA IF EXISTS raw_snapshot_20260912 CASCADE;
