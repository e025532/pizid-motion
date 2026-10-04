CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE SCHEMA IF NOT EXISTS api AUTHORIZATION healthconnect;

CREATE TABLE IF NOT EXISTS api.devices (
    device_id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    device_name text NOT NULL,
    token_hash char(64) NOT NULL UNIQUE,
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz
);

CREATE TABLE IF NOT EXISTS api.sync_batches (
    batch_id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    device_id uuid NOT NULL REFERENCES api.devices(device_id),
    batch_key text NOT NULL,
    request_sha256 char(64) NOT NULL,
    sent_at timestamptz,
    received_at timestamptz NOT NULL DEFAULT now(),
    completed_at timestamptz,
    status text NOT NULL CHECK (status IN ('processing', 'completed', 'failed')),
    record_count integer NOT NULL DEFAULT 0,
    sample_count integer NOT NULL DEFAULT 0,
    deletion_count integer NOT NULL DEFAULT 0,
    error_message text,
    response jsonb,
    UNIQUE (device_id, batch_key)
);

CREATE TABLE IF NOT EXISTS api.sync_cursors (
    device_id uuid NOT NULL REFERENCES api.devices(device_id) ON DELETE CASCADE,
    record_type text NOT NULL,
    change_token text NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (device_id, record_type)
);

CREATE TABLE IF NOT EXISTS api.record_deletions (
    deletion_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    batch_id uuid NOT NULL REFERENCES api.sync_batches(batch_id) ON DELETE CASCADE,
    record_type text NOT NULL,
    record_key text NOT NULL,
    deleted_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE health.records
    ALTER COLUMN source_row_id DROP NOT NULL,
    ALTER COLUMN first_seen_snapshot_id DROP NOT NULL,
    ALTER COLUMN latest_snapshot_id DROP NOT NULL;

ALTER TABLE health.records
    ADD COLUMN IF NOT EXISTS source_device_id uuid REFERENCES api.devices(device_id),
    ADD COLUMN IF NOT EXISTS first_seen_batch_id uuid REFERENCES api.sync_batches(batch_id),
    ADD COLUMN IF NOT EXISTS latest_batch_id uuid REFERENCES api.sync_batches(batch_id);

ALTER TABLE health.samples
    ALTER COLUMN source_snapshot_id DROP NOT NULL,
    ALTER COLUMN source_parent_row_id DROP NOT NULL;

ALTER TABLE health.samples
    ADD COLUMN IF NOT EXISTS source_batch_id uuid REFERENCES api.sync_batches(batch_id);

CREATE INDEX IF NOT EXISTS sync_batches_device_received_idx
    ON api.sync_batches (device_id, received_at DESC);
CREATE INDEX IF NOT EXISTS record_deletions_record_idx
    ON api.record_deletions (record_type, record_key);
CREATE INDEX IF NOT EXISTS records_source_device_idx
    ON health.records (source_device_id, updated_at DESC);
CREATE INDEX IF NOT EXISTS samples_source_batch_idx
    ON health.samples (source_batch_id);

ALTER TABLE api.devices OWNER TO healthconnect;
ALTER TABLE api.sync_batches OWNER TO healthconnect;
ALTER TABLE api.sync_cursors OWNER TO healthconnect;
ALTER TABLE api.record_deletions OWNER TO healthconnect;
