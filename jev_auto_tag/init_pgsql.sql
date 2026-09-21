CREATE TABLE IF NOT EXISTS ttrss_jev_label_attempts (
    guid TEXT NOT NULL,
    owner_uid INTEGER NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    status TEXT NOT NULL DEFAULT 'processing',
    model TEXT NOT NULL,
    config_hash TEXT NOT NULL,
    selected_labels TEXT,
    error TEXT,
    PRIMARY KEY (guid, owner_uid)
);
