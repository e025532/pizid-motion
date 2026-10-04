CREATE INDEX IF NOT EXISTS records_metric_interval_idx
    ON health.records (record_type, start_at, end_at)
    WHERE NOT is_deleted;

CREATE INDEX IF NOT EXISTS samples_route_record_time_idx
    ON health.samples (record_id, sample_at, sample_id)
    WHERE sample_type IN ('exercise_route', 'exercise_route_location');

ANALYZE health.records;
ANALYZE health.samples;
