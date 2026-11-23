-- 022: append-only manager reconciliation of computed discrepancies.
-- Production requires verified backup and human approval before migration.
CREATE TABLE IF NOT EXISTS financial_issue_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 issue_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 issue_type VARCHAR(120) NOT NULL,
 invoice_number VARCHAR(60) NOT NULL,
 issue_summary VARCHAR(700) NOT NULL,
 status ENUM('investigating','resolved','reopened') NOT NULL,
 note VARCHAR(500) NOT NULL,
 reviewed_by BIGINT UNSIGNED NOT NULL,
 reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_issue_reviews_latest (company_id,issue_key,id),
 INDEX idx_issue_reviews_tenant_day (company_id,reviewed_at),
 CONSTRAINT fk_issue_reviews_tenant FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_issue_reviews_actor FOREIGN KEY(reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
