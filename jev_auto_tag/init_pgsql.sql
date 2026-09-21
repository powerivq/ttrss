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

CREATE TABLE IF NOT EXISTS ttrss_jev_feed_settings (
    owner_uid INTEGER NOT NULL,
    feed_id INTEGER NOT NULL,
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
