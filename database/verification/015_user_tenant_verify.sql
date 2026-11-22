-- Sodoryar migration 015: read-only production verification.
-- Run in the intended Sodoryar database using phpMyAdmin.
-- No DDL, DML, write, or foreign_key_checks changes.
SELECT DATABASE() AS selected_database;

-- Both required foreign keys must appear as separate rows, referencing users(id, company_id).
SELECT k.TABLE_NAME, k.CONSTRAINT_NAME,
       GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR ',') AS child_columns,
       k.REFERENCED_TABLE_NAME,
       GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION SEPARATOR ',') AS parent_columns
FROM information_schema.KEY_COLUMN_USAGE AS k
WHERE k.CONSTRAINT_SCHEMA = DATABASE()
  AND (
      (k.TABLE_NAME = 'user_role_assignments' AND k.CONSTRAINT_NAME = 'fk_assignment_user_tenant')
      OR (k.TABLE_NAME = 'user_document_scopes' AND k.CONSTRAINT_NAME = 'fk_scope_user_tenant')
  )
GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME
ORDER BY k.TABLE_NAME;

-- Detect missing, wrong-company, or unexpected null user ownership.
-- Expected: all counts are zero.
SELECT 'user_role_assignments' AS audited_table,
       COUNT(*) AS invalid_rows
FROM user_role_assignments AS a
LEFT JOIN users AS u ON u.id = a.user_id
WHERE u.id IS NULL OR u.company_id IS NULL OR a.company_id IS NULL
   OR NOT (u.company_id <=> a.company_id)
UNION ALL
SELECT 'user_document_scopes' AS audited_table,
       COUNT(*) AS invalid_rows
FROM user_document_scopes AS s
LEFT JOIN users AS u ON u.id = s.user_id
WHERE u.id IS NULL OR u.company_id IS NULL OR s.company_id IS NULL
   OR NOT (u.company_id <=> s.company_id);

-- Verify composite unique parent index exists and has the correct order.
SELECT INDEX_NAME,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS index_columns,
       MIN(NON_UNIQUE) AS non_unique
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
  AND INDEX_NAME = 'uq_users_id_company'
GROUP BY INDEX_NAME;
