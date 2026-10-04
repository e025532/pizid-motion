BEGIN;

CREATE TABLE IF NOT EXISTS health.renpho_measurements (
    measurement_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    measured_at timestamptz NOT NULL,
    local_date date NOT NULL,
    source_file text NOT NULL,
    source_row_number integer,
    weight_kg double precision,
    bmi double precision,
    body_fat_percent double precision,
    body_fat_mass_kg double precision,
    muscle_percent double precision,
    muscle_mass_kg double precision,
    skeletal_muscle_percent double precision,
    skeletal_muscle_mass_kg double precision,
    bone_percent double precision,
    bone_mass_kg double precision,
    protein_percent double precision,
    protein_mass_kg double precision,
    body_water_percent double precision,
    body_water_mass_kg double precision,
    fat_free_mass_kg double precision,
    subcutaneous_fat_percent double precision,
    visceral_fat_level double precision,
    basal_metabolic_rate_kcal double precision,
    metabolic_age double precision,
    waist_hip_ratio double precision,
    optimal_weight_kg double precision,
    weight_level text,
    body_type text,
    optimal_weight_goal_kg double precision,
    optimal_muscle_goal_kg double precision,
    optimal_fat_goal_kg double precision,
    notes text,
    raw_payload jsonb NOT NULL,
    imported_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (measured_at)
);

CREATE INDEX IF NOT EXISTS renpho_measurements_local_date_idx
    ON health.renpho_measurements (local_date DESC);

INSERT INTO health.sources (package_name, app_name, payload)
VALUES (
    'com.renpho.health',
    'RENPHO Health',
    '{"import":"renpho_csv"}'::jsonb
)
ON CONFLICT (package_name) DO UPDATE SET
    app_name = coalesce(health.sources.app_name, EXCLUDED.app_name),
    payload = health.sources.payload || EXCLUDED.payload,
    updated_at = now();

ALTER TABLE health.renpho_measurements OWNER TO healthconnect;

COMMIT;
