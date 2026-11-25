-- 026: expense documents, line items and payment allocations.
-- No historical postings, opening balances or invoice mutations.
CREATE TABLE IF NOT EXISTS expense_categories (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 classification ENUM('expense','asset','inventory','prepayment') NOT NULL DEFAULT 'expense',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY(id), UNIQUE KEY uq_ec_company_name(company_id,name),
 UNIQUE KEY uq_ec_id_company(id,company_id),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expenses (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 category_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 expense_date DATE NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 title VARCHAR(180) NOT NULL,
 notes TEXT NULL,
 classification ENUM('expense','asset','inventory','prepayment') NOT NULL,
 total_amount DECIMAL(20,0) NOT NULL,
 approval_status ENUM('draft','pending','approved','rejected','void') NOT NULL DEFAULT 'pending',
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 review_note VARCHAR(500) NULL,
 PRIMARY KEY(id), UNIQUE KEY uq_exp_id_company(id,company_id),
 UNIQUE KEY uq_exp_request(company_id,created_by,request_key),
 INDEX idx_exp_period(company_id,branch_id,expense_date,approval_status),
 INDEX idx_exp_owner(company_id,created_by,expense_date),
 FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id),
 FOREIGN KEY(category_id,company_id) REFERENCES expense_categories(id,company_id),
 FOREIGN KEY(created_by,company_id) REFERENCES users(id,company_id),
 CHECK(total_amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_items (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL,
 description VARCHAR(500) NULL,
 quantity INT UNSIGNED NOT NULL,
 unit VARCHAR(40) NOT NULL,
 unit_price DECIMAL(20,0) NOT NULL,
 line_total DECIMAL(20,0) NOT NULL,
 INDEX idx_ei_exp(company_id,expense_id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CHECK(quantity>0 AND unit_price>0 AND line_total=quantity*unit_price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 method ENUM('cash','bank','personal') NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 account_id BIGINT UNSIGNED NULL,
 cash_shift_id BIGINT UNSIGNED NULL,
 cash_adjustment_id BIGINT UNSIGNED NULL,
 tracking VARCHAR(120) NULL,
 posted_at DATETIME NULL,
 INDEX idx_ep_exp(company_id,expense_id),
 UNIQUE KEY uq_ep_cash_adjustment(cash_adjustment_id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 FOREIGN KEY(account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 FOREIGN KEY(cash_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 FOREIGN KEY(cash_adjustment_id) REFERENCES cash_shift_adjustments(id),
 CHECK(amount>0),
 CHECK((method='cash' AND cash_shift_id IS NOT NULL AND account_id IS NULL) OR
 (method='bank' AND account_id IS NOT NULL AND cash_shift_id IS NULL) OR
 (method='personal' AND account_id IS NULL AND cash_shift_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 reason VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ee_exp(company_id,expense_id,id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Default classifications exclude asset purchases from operating-expense totals.
INSERT INTO expense_categories(company_id,name,classification)
SELECT c.id,v.name,v.classification FROM companies c CROSS JOIN (
 SELECT 'خرید ملزومات مصرفی' name,'expense' classification
 UNION ALL SELECT 'پذیرایی و مواد غذایی','expense'
 UNION ALL SELECT 'لوازم اداری','expense'
 UNION ALL SELECT 'تعمیرات و نگهداری','expense'
 UNION ALL SELECT 'تجهیزات سرمایه‌ای','asset'
 UNION ALL SELECT 'خدمات','expense'
 UNION ALL SELECT 'حمل‌ونقل','expense'
 UNION ALL SELECT 'آب، برق، گاز و تلفن','expense'
 UNION ALL SELECT 'اینترنت و ارتباطات','expense'
 UNION ALL SELECT 'اجاره','expense'
 UNION ALL SELECT 'سایر هزینه‌ها','expense'
 UNION ALL SELECT 'موجودی کالا','inventory'
 UNION ALL SELECT 'پیش‌پرداخت','prepayment'
) v WHERE NOT EXISTS(SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name=v.name);
