-- Sodoryar 014: optional revocation of all other sessions after password/role/status changes.
-- Non-destructive. Existing sessions without auth_version are asked to log in again.
ALTER TABLE users ADD COLUMN auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1;
