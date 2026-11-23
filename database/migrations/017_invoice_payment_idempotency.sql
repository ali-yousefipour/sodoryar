-- 017: one-time authorization keys for subsequent receipt collection.
-- Apply only after a verified backup and after migration 016.
-- Both operations are repeatable on MySQL/MariaDB via INFORMATION_SCHEMA.
SET @stmt_sql = IF(
 (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND COLUMN_NAME='request_key')=0,
 'ALTER TABLE invoice_payments ADD COLUMN request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;

SET @stmt_sql = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND INDEX_NAME='uq_invoice_payment_request')=0,
 'ALTER TABLE invoice_payments ADD UNIQUE KEY uq_invoice_payment_request (company_id,request_key)',
 'SELECT 1'
);
PREPARE sod_stmt FROM @stmt_sql; EXECUTE sod_stmt; DEALLOCATE PREPARE sod_stmt;
