-- 019: irreversible accounting period locks with tenant-scoped date ranges.
-- Migration must be executed only after a verified backup.
CREATE TABLE IF NOT EXISTS financial_period_locks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 locked_by BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_accounting_period_tenant_range (company_id,start_date,end_date),
 INDEX idx_accounting_period_tenant_dates (company_id,start_date,end_date),
 CONSTRAINT fk_accounting_period_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE RESTRICT,
 CONSTRAINT fk_accounting_period_lock_user FOREIGN KEY(locked_by) REFERENCES users(id) ON DELETE RESTRICT,
 CONSTRAINT chk_accounting_period_dates CHECK(end_date>=start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
