CREATE TABLE IF NOT EXISTS expense_settlements (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 method ENUM('cash','bank') NOT NULL,
 account_id BIGINT UNSIGNED NULL,
 cash_shift_id BIGINT UNSIGNED NULL,
 cash_adjustment_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_es_request(company_id,request_key),
 UNIQUE KEY uq_es_adjustment(cash_adjustment_id),
 INDEX idx_es_exp(company_id,expense_id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 FOREIGN KEY(account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 FOREIGN KEY(cash_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 FOREIGN KEY(cash_adjustment_id) REFERENCES cash_shift_adjustments(id),
 CHECK(amount>0),
 CHECK((method='cash' AND cash_shift_id IS NOT NULL AND account_id IS NULL) OR (method='bank' AND account_id IS NOT NULL AND cash_shift_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_reversals (
 payment_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 cash_adjustment_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_er_adjustment(cash_adjustment_id),
 FOREIGN KEY(payment_id) REFERENCES expense_payments(id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 FOREIGN KEY(cash_adjustment_id) REFERENCES cash_shift_adjustments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
