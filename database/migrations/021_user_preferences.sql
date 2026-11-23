-- 021: user-and-tenant-scoped interface and report preferences.
-- Apply after validated backup and migration 015.
CREATE TABLE IF NOT EXISTS user_preferences (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 pref_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 pref_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,user_id,pref_key),
 CONSTRAINT fk_user_preference_owner FOREIGN KEY(user_id,company_id) REFERENCES users(id,company_id) ON DELETE CASCADE,
 CONSTRAINT chk_user_preference_valid_json CHECK(JSON_VALID(pref_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
