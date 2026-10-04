CREATE TABLE IF NOT EXISTS api.google_health_oauth_states (
    state_hash char(64) PRIMARY KEY,
    code_verifier text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL
);

CREATE TABLE IF NOT EXISTS api.google_health_credentials (
    singleton_id smallint PRIMARY KEY DEFAULT 1 CHECK (singleton_id = 1),
    access_token_encrypted text,
    refresh_token_encrypted text NOT NULL,
    access_token_expires_at timestamptz,
    granted_scopes text,
    connected_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    last_sync_at timestamptz,
    last_sync_status text,
    last_sync_error text
);

ALTER TABLE api.google_health_oauth_states OWNER TO healthconnect;
ALTER TABLE api.google_health_credentials OWNER TO healthconnect;
