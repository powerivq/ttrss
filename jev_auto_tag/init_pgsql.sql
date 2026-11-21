CREATE TABLE IF NOT EXISTS ttrss_jev_label_attempts (
    guid TEXT NOT NULL,
    owner_uid INTEGER NOT NULL,
    attempted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at TIMESTAMPTZ,
    status TEXT NOT NULL DEFAULT 'processing',
    model TEXT NOT NULL,
    config_hash TEXT NOT NULL,
    selected_labels TEXT,
    score_modifier INTEGER,
    error TEXT,
    PRIMARY KEY (guid, owner_uid)
);

CREATE TABLE IF NOT EXISTS ttrss_jev_feed_settings (
    owner_uid INTEGER NOT NULL,
    feed_id INTEGER NOT NULL,
    label_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    rank_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    rank_question TEXT NOT NULL DEFAULT '',
    rank_levels TEXT NOT NULL DEFAULT '',
    auto_read_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    auto_read_question TEXT NOT NULL DEFAULT '',
    auto_read_threshold DOUBLE PRECISION NOT NULL DEFAULT 0.8,
    PRIMARY KEY (owner_uid, feed_id),
    FOREIGN KEY (feed_id) REFERENCES ttrss_feeds(id) ON DELETE CASCADE,
    CHECK (auto_read_threshold >= 0 AND auto_read_threshold <= 1)
);

ALTER TABLE ttrss_jev_feed_settings
    ADD COLUMN IF NOT EXISTS label_enabled BOOLEAN NOT NULL DEFAULT FALSE;

CREATE TABLE IF NOT EXISTS ttrss_jev_queue (
    guid TEXT NOT NULL,
    owner_uid INTEGER NOT NULL,
    feed_id INTEGER NOT NULL,
    queued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    available_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    retry_count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (guid, owner_uid),
    FOREIGN KEY (feed_id) REFERENCES ttrss_feeds(id) ON DELETE CASCADE
);

ALTER TABLE ttrss_jev_label_attempts
    ADD COLUMN IF NOT EXISTS score_modifier INTEGER;

ALTER TABLE ttrss_jev_queue
    ADD COLUMN IF NOT EXISTS retry_count INTEGER NOT NULL DEFAULT 0;
