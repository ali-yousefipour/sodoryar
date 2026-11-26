-- Additive current-period correction documents. Original expenses and money are immutable.
CREATE TABLE IF NOT EXISTS expense_corrections (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 correction_date DATE NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_expense_correction_document(company_id,expense_id),
 UNIQUE KEY uq_expense_correction_request(company_id,request_key),
 INDEX idx_expense_correction_date(company_id,correction_date),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 FOREIGN KEY(actor_id,company_id) REFERENCES users(id,company_id),
 CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
