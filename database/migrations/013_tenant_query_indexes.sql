-- Sodoryar 013: additive indexes for tenant-scoped reports and ownership queries.
-- Take a backup; evaluate disk space and locking on staging before applying to production.
-- This migration does not delete data and can be rerun.

SET @sod_index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_invoices_tenant_user_date')=0,
  'ALTER TABLE `invoices` ADD INDEX `idx_invoices_tenant_user_date` (company_id,user_id,invoice_date,id)',
  'SELECT 1'
);
PREPARE sod_idx FROM @sod_index_sql;
EXECUTE sod_idx;
DEALLOCATE PREPARE sod_idx;

SET @sod_index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='activity_logs' AND INDEX_NAME='idx_activity_tenant_user_date')=0,
  'ALTER TABLE `activity_logs` ADD INDEX `idx_activity_tenant_user_date` (company_id,user_id,created_at,id)',
  'SELECT 1'
);
PREPARE sod_idx FROM @sod_index_sql;
EXECUTE sod_idx;
DEALLOCATE PREPARE sod_idx;

SET @sod_index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bill_payments' AND INDEX_NAME='idx_bills_tenant_user_date')=0,
  'ALTER TABLE `bill_payments` ADD INDEX `idx_bills_tenant_user_date` (company_id,user_id,created_at,id)',
  'SELECT 1'
);
PREPARE sod_idx FROM @sod_index_sql;
EXECUTE sod_idx;
DEALLOCATE PREPARE sod_idx;

SET @sod_index_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='commissions' AND INDEX_NAME='idx_comm_tenant_user_period')=0,
  'ALTER TABLE `commissions` ADD INDEX `idx_comm_tenant_user_period` (company_id,user_id,period_type,period_key)',
  'SELECT 1'
);
PREPARE sod_idx FROM @sod_index_sql;
EXECUTE sod_idx;
DEALLOCATE PREPARE sod_idx;
