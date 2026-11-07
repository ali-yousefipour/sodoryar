-- Optional permissions granted to operators. Import after 002.
CREATE TABLE IF NOT EXISTS user_permissions (
 user_id BIGINT UNSIGNED NOT NULL,
 permission_key VARCHAR(80) NOT NULL,
 PRIMARY KEY(user_id,permission_key),
 CONSTRAINT fk_permission_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
