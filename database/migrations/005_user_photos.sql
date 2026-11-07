-- Private profile pictures, served only through an authorized PHP route.
CREATE TABLE IF NOT EXISTS user_photos (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 storage_key CHAR(36) NOT NULL UNIQUE,
 mime_type VARCHAR(40) NOT NULL DEFAULT 'image/jpeg',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_user_photo_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
