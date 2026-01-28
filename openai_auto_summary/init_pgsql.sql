-- Table to store article summaries
CREATE TABLE IF NOT EXISTS ttrss_summary (
    guid TEXT NOT NULL,
    owner_uid INTEGER NOT NULL,
    summary TEXT,
    PRIMARY KEY (guid, owner_uid)
);

-- Table to store job queue (article GUIDs to be processed)
CREATE TABLE IF NOT EXISTS ttrss_summary_queue (
    guid TEXT NOT NULL,
    owner_uid INTEGER NOT NULL,
    failure_count INTEGER DEFAULT 0,
    last_failed TIMESTAMP,
    PRIMARY KEY (guid, owner_uid)
);
