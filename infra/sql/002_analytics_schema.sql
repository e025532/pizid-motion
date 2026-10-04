CREATE SCHEMA IF NOT EXISTS health AUTHORIZATION healthconnect;

CREATE TABLE IF NOT EXISTS health.sources (
    package_name text PRIMARY KEY,
    app_name text,
    latest_snapshot_id bigint REFERENCES ingestion.snapshots(snapshot_id),
    payload jsonb NOT NULL DEFAULT '{}'::jsonb,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS health.record_types (
    record_type text PRIMARY KEY,
    source_table text NOT NULL,
    record_kind text NOT NULL CHECK (record_kind IN ('instant', 'interval')),
    latest_snapshot_id bigint REFERENCES ingestion.snapshots(snapshot_id),
    latest_row_count bigint NOT NULL DEFAULT 0,
    source_columns jsonb NOT NULL DEFAULT '[]'::jsonb,
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS health.records (
    record_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type text NOT NULL REFERENCES health.record_types(record_type),
    record_key text NOT NULL,
    source_table text NOT NULL,
    source_row_id bigint NOT NULL,
    source_app_package text REFERENCES health.sources(package_name),
    source_app_name text,
    device_info_id bigint,
    recording_method integer,
    client_record_id text,
    client_record_version text,
    last_modified_at timestamptz,
    start_at timestamptz,
    end_at timestamptz,
    start_zone_offset_seconds integer,
    end_zone_offset_seconds integer,
    local_date date,
    first_seen_snapshot_id bigint NOT NULL REFERENCES ingestion.snapshots(snapshot_id),
    latest_snapshot_id bigint NOT NULL REFERENCES ingestion.snapshots(snapshot_id),
    is_deleted boolean NOT NULL DEFAULT false,
    payload jsonb NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (record_type, record_key)
);

CREATE TABLE IF NOT EXISTS health.samples (
    sample_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_snapshot_id bigint NOT NULL REFERENCES ingestion.snapshots(snapshot_id),
    record_id bigint REFERENCES health.records(record_id) ON DELETE CASCADE,
    parent_record_type text NOT NULL,
    source_parent_row_id bigint NOT NULL,
    sample_type text NOT NULL,
    sample_at timestamptz,
    start_at timestamptz,
    end_at timestamptz,
    numeric_value double precision,
    numeric_value_2 double precision,
    category integer,
    latitude double precision,
    longitude double precision,
    altitude_m double precision,
    payload jsonb NOT NULL
);

CREATE INDEX IF NOT EXISTS records_type_start_idx
    ON health.records (record_type, start_at);
CREATE INDEX IF NOT EXISTS records_local_date_idx
    ON health.records (local_date, record_type);
CREATE INDEX IF NOT EXISTS records_source_app_idx
    ON health.records (source_app_package, record_type);
CREATE INDEX IF NOT EXISTS samples_type_time_idx
    ON health.samples (sample_type, sample_at);
CREATE INDEX IF NOT EXISTS samples_record_idx
    ON health.samples (record_id);

CREATE OR REPLACE VIEW health.v_weight AS
SELECT
    record_id,
    start_at AS measured_at,
    local_date,
    source_app_name,
    (payload ->> 'weight')::double precision / 1000.0 AS weight_kg
FROM health.records
WHERE record_type = 'weight' AND NOT is_deleted;

CREATE OR REPLACE VIEW health.v_body_fat AS
SELECT
    record_id,
    start_at AS measured_at,
    local_date,
    source_app_name,
    (payload ->> 'percentage')::double precision AS percentage
FROM health.records
WHERE record_type = 'body_fat' AND NOT is_deleted;

CREATE OR REPLACE VIEW health.v_steps AS
SELECT
    record_id,
    start_at,
    end_at,
    local_date,
    source_app_name,
    (payload ->> 'count')::bigint AS steps
FROM health.records
WHERE record_type = 'steps' AND NOT is_deleted;

CREATE OR REPLACE VIEW health.v_nutrition AS
SELECT
    record_id,
    start_at,
    end_at,
    local_date,
    source_app_name,
    payload ->> 'meal_name' AS meal_name,
    nullif(payload ->> 'energy', '')::double precision / 1000.0 AS energy_kcal,
    nullif(payload ->> 'protein', '')::double precision AS protein_g,
    nullif(payload ->> 'total_carbohydrate', '')::double precision AS carbohydrate_g,
    nullif(payload ->> 'total_fat', '')::double precision AS fat_g,
    payload AS nutrients
FROM health.records
WHERE record_type = 'nutrition' AND NOT is_deleted;

CREATE OR REPLACE VIEW health.v_heart_rate_samples AS
SELECT
    s.sample_id,
    s.record_id,
    s.sample_at AS measured_at,
    s.numeric_value::integer AS bpm
FROM health.samples s
WHERE s.sample_type = 'heart_rate';

CREATE OR REPLACE VIEW health.v_sleep_stages AS
SELECT
    s.sample_id,
    s.record_id,
    s.start_at,
    s.end_at,
    s.category AS stage_type
FROM health.samples s
WHERE s.sample_type = 'sleep_stage';

ALTER TABLE health.sources OWNER TO healthconnect;
ALTER TABLE health.record_types OWNER TO healthconnect;
ALTER TABLE health.records OWNER TO healthconnect;
ALTER TABLE health.samples OWNER TO healthconnect;
ALTER VIEW health.v_weight OWNER TO healthconnect;
ALTER VIEW health.v_body_fat OWNER TO healthconnect;
ALTER VIEW health.v_steps OWNER TO healthconnect;
ALTER VIEW health.v_nutrition OWNER TO healthconnect;
ALTER VIEW health.v_heart_rate_samples OWNER TO healthconnect;
ALTER VIEW health.v_sleep_stages OWNER TO healthconnect;
