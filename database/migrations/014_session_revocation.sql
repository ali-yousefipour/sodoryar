-- Sodoryar 014: optional revocation of old sessions after credentials/access changes.
-- Back up users table first. Idempotent, does not alter existing passwords.
-- Existing sessions lacking the version will be redirected to login after activation.
SET @sod_auth_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_version')=0,
  'ALTER TABLE users ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE sod_auth_stmt FROM @sod_auth_sql;
EXECUTE sod_auth_stmt;
DEALLOCATE PREPARE sod_auth_stmt;
