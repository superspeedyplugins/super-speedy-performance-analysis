PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS runs (
    id TEXT PRIMARY KEY,
    plugin_slug TEXT NOT NULL,
    manifest_hash TEXT NOT NULL,
    runner_version TEXT NOT NULL,
    seed INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    status TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS sites (
    run_id TEXT NOT NULL REFERENCES runs(id),
    id TEXT NOT NULL,
    url TEXT NOT NULL,
    scenario TEXT NOT NULL,
    wordpress_version TEXT,
    php_version TEXT,
    database_version TEXT,
    theme TEXT,
    active_plugins_json TEXT NOT NULL,
    active_plugins_hash TEXT NOT NULL,
    plugin_version TEXT,
    recorder_version TEXT NOT NULL,
    debug_json TEXT NOT NULL,
    PRIMARY KEY (run_id, id)
);

CREATE TABLE IF NOT EXISTS targets (
    run_id TEXT NOT NULL REFERENCES runs(id),
    id TEXT NOT NULL,
    site_id TEXT NOT NULL,
    feature_id TEXT NOT NULL,
    area TEXT NOT NULL,
    page_type TEXT NOT NULL,
    path TEXT NOT NULL,
    role TEXT NOT NULL,
    readiness_selector TEXT,
    repetitions INTEGER NOT NULL,
    PRIMARY KEY (run_id, id)
);

CREATE TABLE IF NOT EXISTS run_features (
    run_id TEXT NOT NULL REFERENCES runs(id),
    feature_id TEXT NOT NULL,
    PRIMARY KEY (run_id, feature_id)
);

CREATE TABLE IF NOT EXISTS samples (
    id TEXT PRIMARY KEY,
    run_id TEXT NOT NULL REFERENCES runs(id),
    target_id TEXT NOT NULL,
    sequence INTEGER NOT NULL,
    php_wall_ms REAL,
    browser_navigation_ms REAL,
    peak_memory_bytes INTEGER,
    query_count INTEGER,
    http_status INTEGER,
    canary_ok INTEGER NOT NULL,
    readiness_ok INTEGER NOT NULL,
    fault_error_count INTEGER NOT NULL,
    fault_warning_count INTEGER NOT NULL,
    fault_notice_count INTEGER NOT NULL,
    valid INTEGER NOT NULL,
    invalid_reason TEXT,
    envelope_hash TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS faults (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sample_id TEXT NOT NULL REFERENCES samples(id),
    run_id TEXT NOT NULL,
    target_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    severity TEXT NOT NULL,
    fingerprint TEXT NOT NULL,
    message TEXT NOT NULL,
    file TEXT,
    line INTEGER,
    repeat_count INTEGER NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS samples_run_target ON samples(run_id, target_id);
CREATE INDEX IF NOT EXISTS faults_run_fingerprint ON faults(run_id, fingerprint);
