-- Sodoryar 015: enforce same-company user ownership for IAM assignments/scopes.
-- Additive only; back up and inspect existing data before applying in production.
-- If legacy rows reference a user from a different company, the constraint
-- intentionally fails rather than silently removing or reassigning any data.
SET @sod_user_tenant_idx = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uq_users_id_company')=0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_id_company (id,company_id)',
  'SELECT 1'
);
PREPARE sod_stmt FROM @sod_user_tenant_idx;
EXECUTE sod_stmt;
DEALLOCATE PREPARE sod_stmt;

SET @sod_assignment_fk = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='user_role_assignments'
     AND CONSTRAINT_NAME='fk_assignment_user_tenant')=0,
  'ALTER TABLE user_role_assignments ADD CONSTRAINT fk_assignment_user_tenant FOREIGN KEY (user_id,company_id) REFERENCES users(id,company_id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE sod_stmt FROM @sod_assignment_fk;
EXECUTE sod_stmt;
DEALLOCATE PREPARE sod_stmt;

SET @sod_scope_fk = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='user_document_scopes'
     AND CONSTRAINT_NAME='fk_scope_user_tenant')=0,
  'ALTER TABLE user_document_scopes ADD CONSTRAINT fk_scope_user_tenant FOREIGN KEY (user_id,company_id) REFERENCES users(id,company_id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE sod_stmt FROM @sod_scope_fk;
EXECUTE sod_stmt;
DEALLOCATE PREPARE sod_stmt;
