CREATE SCHEMA IF NOT EXISTS ingestion AUTHORIZATION healthconnect;

CREATE TABLE IF NOT EXISTS ingestion.snapshots (
    snapshot_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_captured_at timestamptz NOT NULL,
    source_filename text NOT NULL,
    source_sha256 char(64) NOT NULL UNIQUE,
    source_bytes bigint NOT NULL CHECK (source_bytes >= 0),
    sqlite_user_version integer NOT NULL,
    source_table_count integer NOT NULL,
    imported_table_count integer NOT NULL,
    imported_row_count bigint NOT NULL,
    archive_database text NOT NULL UNIQUE,
    archive_path text NOT NULL,
    status text NOT NULL CHECK (status IN ('importing', 'verified', 'failed')),
    verification jsonb NOT NULL DEFAULT '{}'::jsonb,
    imported_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE ingestion.snapshots OWNER TO healthconnect;
