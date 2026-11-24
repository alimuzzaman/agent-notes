ALTER TABLE notes
    ADD COLUMN kind VARCHAR(4) GENERATED ALWAYS AS (IF(format = 'html', 'page', 'note')) STORED,
    ADD COLUMN collection VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN token_name VARCHAR(100) NULL;
ALTER TABLE notes
    ADD FULLTEXT INDEX idx_notes_search (title, content),
    ADD INDEX idx_notes_collection (user_id, collection, filename);

CREATE TABLE mockups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    current_version INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mockup_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mockup_id INT UNSIGNED NOT NULL,
    number INT UNSIGNED NOT NULL,
    message VARCHAR(500) NOT NULL,
    token_name VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (mockup_id, number),
    FOREIGN KEY (mockup_id) REFERENCES mockups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mockup_files (
    version_id INT UNSIGNED NOT NULL,
    path VARBINARY(512) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    PRIMARY KEY (version_id, path),
    INDEX idx_mockup_file_hash (content_hash),
    FOREIGN KEY (version_id) REFERENCES mockup_versions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mockup_draft_files (
    mockup_id INT UNSIGNED NOT NULL,
    draft VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    path VARBINARY(512) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    PRIMARY KEY (mockup_id, draft, path),
    INDEX idx_mockup_draft_hash (content_hash),
    FOREIGN KEY (mockup_id) REFERENCES mockups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE share_links
    MODIFY COLUMN note_id INT UNSIGNED NULL;
ALTER TABLE share_links
    ADD COLUMN mockup_version_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_share_mockup_version FOREIGN KEY (mockup_version_id) REFERENCES mockup_versions(id) ON DELETE CASCADE;
ALTER TABLE share_link_events
    MODIFY COLUMN note_id INT UNSIGNED NULL,
    ADD COLUMN mockup_version_id INT UNSIGNED NULL;
