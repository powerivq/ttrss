CREATE TABLE IF NOT EXISTS ttrss_auto_tag_feed_settings (
    owner_uid INTEGER NOT NULL,
    feed_id INTEGER NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT FALSE,
    prompt TEXT NOT NULL,
    permissible_tags TEXT NOT NULL,
    PRIMARY KEY (owner_uid, feed_id),
    FOREIGN KEY (feed_id) REFERENCES ttrss_feeds(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ttrss_auto_tag_queue (
    guid TEXT NOT NULL,
    owner_uid INTEGER NOT NULL,
    failure_count INTEGER NOT NULL DEFAULT 0,
    last_failed TIMESTAMP,
    PRIMARY KEY (guid, owner_uid)
);
