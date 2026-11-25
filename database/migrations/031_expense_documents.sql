-- 031: additive expense documents; apply after 030, verified backup and isolated restore.
-- Re-runnable. Legacy expense import links existing cash entries, never posts money again.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND INDEX_NAME='uq_cash_adjustment_identity')=0,'ALTER TABLE cash_shift_adjustments ADD UNIQUE KEY uq_cash_adjustment_identity(id,company_id)','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;

CREATE TABLE IF NOT EXISTS expense_categories (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(160) NOT NULL,
 accounting_type ENUM('expense','asset','prepayment','inventory') NOT NULL DEFAULT 'expense',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_expense_category_identity(id,company_id),
 UNIQUE KEY uq_expense_category_name(company_id,name),
 CONSTRAINT fk_expense_category_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_policies (
 company_id BIGINT UNSIGNED PRIMARY KEY,
 approval_mode ENUM('none','all','threshold') NOT NULL DEFAULT 'all',
 approval_threshold DECIMAL(20,0) NOT NULL DEFAULT 0,
 CONSTRAINT chk_expense_threshold CHECK(approval_threshold>=0),
 CONSTRAINT fk_expense_policy_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 document_number VARCHAR(64) NOT NULL,
 expense_date DATE NOT NULL,
 category_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL,
 description TEXT NULL,
 notes TEXT NULL,
 subtotal DECIMAL(20,0) NOT NULL DEFAULT 0,
 extra_amount DECIMAL(20,0) NOT NULL DEFAULT 0,
 discount_amount DECIMAL(20,0) NOT NULL DEFAULT 0,
 total_amount DECIMAL(20,0) NOT NULL DEFAULT 0,
 approval_status ENUM('draft','pending','approved','rejected','voided') NOT NULL DEFAULT 'draft',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 request_key CHAR(64) NOT NULL,
 request_digest CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL,
 voided_at DATETIME NULL,
 UNIQUE KEY uq_expense_identity(id,company_id),
 UNIQUE KEY uq_expense_document(company_id,document_number),
 UNIQUE KEY uq_expense_request(company_id,request_key),
 INDEX idx_expense_branch(company_id,branch_id,expense_date,id),
 INDEX idx_expense_owner(company_id,created_by,expense_date,id),
 INDEX idx_expense_status(company_id,approval_status,expense_date),
 CONSTRAINT fk_expense_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_expense_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id),
 CONSTRAINT fk_expense_category FOREIGN KEY(category_id,company_id) REFERENCES expense_categories(id,company_id),
 CONSTRAINT fk_expense_creator FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT fk_expense_approver FOREIGN KEY(approved_by) REFERENCES users(id),
 CONSTRAINT chk_expense_amounts CHECK(subtotal>=0 AND extra_amount>=0 AND discount_amount>=0 AND total_amount>=0 AND total_amount=subtotal+extra_amount-discount_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL,
 description TEXT NULL,
 quantity_milli BIGINT UNSIGNED NOT NULL,
 unit VARCHAR(40) NOT NULL,
 unit_price DECIMAL(20,0) NOT NULL,
 line_total DECIMAL(20,0) NOT NULL,
 INDEX idx_expense_item_parent(company_id,expense_id),
 CONSTRAINT fk_expense_item_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CONSTRAINT chk_expense_item_money CHECK(quantity_milli>0 AND unit_price>=0 AND line_total>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 method ENUM('cash','bank','personal') NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 cash_shift_id BIGINT UNSIGNED NULL,
 bank_account_id BIGINT UNSIGNED NULL,
 paid_date DATE NOT NULL,
 tracking_number VARCHAR(120) NULL,
 posted_at DATETIME NULL,
 cash_adjustment_id BIGINT UNSIGNED NULL,
 UNIQUE KEY uq_expense_payment_identity(id,company_id),
 UNIQUE KEY uq_expense_payment_document(id,expense_id,company_id),
 UNIQUE KEY uq_expense_payment_cash(company_id,cash_adjustment_id),
 INDEX idx_expense_payment_parent(company_id,expense_id),
 CONSTRAINT fk_expense_payment_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CONSTRAINT fk_expense_payment_shift FOREIGN KEY(cash_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 CONSTRAINT fk_expense_payment_bank FOREIGN KEY(bank_account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 CONSTRAINT fk_expense_payment_cash FOREIGN KEY(cash_adjustment_id,company_id) REFERENCES cash_shift_adjustments(id,company_id),
 CONSTRAINT chk_expense_payment_amount CHECK(amount>0),
 CONSTRAINT chk_expense_payment_source CHECK((method='cash' AND cash_shift_id IS NOT NULL AND bank_account_id IS NULL) OR (method='bank' AND bank_account_id IS NOT NULL AND cash_shift_id IS NULL) OR (method='personal' AND cash_shift_id IS NULL AND bank_account_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_reimbursements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 method ENUM('cash','bank') NOT NULL,
 cash_shift_id BIGINT UNSIGNED NULL,
 bank_account_id BIGINT UNSIGNED NULL,
 cash_adjustment_id BIGINT UNSIGNED NULL,
 paid_date DATE NOT NULL,
 tracking_number VARCHAR(120) NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_employee_reimbursement_identity(id,company_id),
 UNIQUE KEY uq_employee_reimbursement_document(id,expense_id,company_id),
 UNIQUE KEY uq_employee_reimbursement_request(company_id,request_key),
 UNIQUE KEY uq_employee_reimbursement_cash(company_id,cash_adjustment_id),
 CONSTRAINT fk_employee_reimbursement_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CONSTRAINT fk_employee_reimbursement_payment FOREIGN KEY(payment_id,expense_id,company_id) REFERENCES expense_payments(id,expense_id,company_id),
 CONSTRAINT fk_employee_reimbursement_shift FOREIGN KEY(cash_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 CONSTRAINT fk_employee_reimbursement_bank FOREIGN KEY(bank_account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 CONSTRAINT fk_employee_reimbursement_cash FOREIGN KEY(cash_adjustment_id,company_id) REFERENCES cash_shift_adjustments(id,company_id),
 CONSTRAINT fk_employee_reimbursement_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_employee_reimbursement_amount CHECK(amount>0),
 CONSTRAINT chk_employee_reimbursement_source CHECK((method='cash' AND cash_shift_id IS NOT NULL AND bank_account_id IS NULL) OR (method='bank' AND bank_account_id IS NOT NULL AND cash_shift_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_bank_movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 payment_id BIGINT UNSIGNED NULL,
 reimbursement_id BIGINT UNSIGNED NULL,
 bank_account_id BIGINT UNSIGNED NOT NULL,
 direction ENUM('in','out') NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 paid_date DATE NOT NULL,
 reference VARCHAR(120) NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_expense_bank_payment(company_id,payment_id,direction),
 UNIQUE KEY uq_expense_bank_reimbursement(company_id,reimbursement_id,direction),
 INDEX idx_expense_bank_date(company_id,bank_account_id,paid_date),
 CONSTRAINT fk_expense_bank_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CONSTRAINT fk_expense_bank_payment FOREIGN KEY(payment_id,expense_id,company_id) REFERENCES expense_payments(id,expense_id,company_id),
 CONSTRAINT fk_expense_bank_reimbursement FOREIGN KEY(reimbursement_id,expense_id,company_id) REFERENCES employee_reimbursements(id,expense_id,company_id),
 CONSTRAINT fk_expense_bank_account FOREIGN KEY(bank_account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 CONSTRAINT fk_expense_bank_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_expense_bank_amount CHECK(amount>0),
 CONSTRAINT chk_expense_bank_origin CHECK((payment_id IS NULL)<>(reimbursement_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(64) NOT NULL,
 reason TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_expense_event_document(company_id,expense_id,id),
 CONSTRAINT fk_expense_event_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CONSTRAINT fk_expense_event_actor FOREIGN KEY(actor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expense_attachments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(255) NOT NULL,
 stored_name VARCHAR(128) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 size_bytes BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_expense_attachment_storage(stored_name),
 INDEX idx_expense_attachment_document(company_id,expense_id),
 CONSTRAINT fk_expense_attachment_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 CONSTRAINT fk_expense_attachment_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_expense_attachment_size CHECK(size_bytes>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'اجاره و شارژ','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='اجاره و شارژ');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'آب، برق، گاز و ارتباطات','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='آب، برق، گاز و ارتباطات');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'ملزومات اداری','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='ملزومات اداری');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'حمل و نقل','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='حمل و نقل');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'تعمیر و نگهداری','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='تعمیر و نگهداری');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'پذیرایی','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='پذیرایی');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'حقوق و دستمزد','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='حقوق و دستمزد');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'عوارض و مالیات','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='عوارض و مالیات');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'هزینه نقدی انتقال‌یافته','expense' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='هزینه نقدی انتقال‌یافته');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'خرید دارایی','asset' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='خرید دارایی');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'پیش‌پرداخت','prepayment' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='پیش‌پرداخت');

INSERT INTO expense_categories(company_id,name,accounting_type) SELECT c.id,'خرید موجودی','inventory' FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.company_id=c.id AND e.name='خرید موجودی');

INSERT INTO expense_policies(company_id,approval_mode,approval_threshold)
SELECT c.id,'all',0 FROM companies c WHERE NOT EXISTS (SELECT 1 FROM expense_policies p WHERE p.company_id=c.id);

-- Import only explicitly tagged legacy costs. Leave generic cash withdrawals unchanged.
INSERT INTO expenses(company_id,branch_id,created_by,document_number,expense_date,category_id,title,description,subtotal,total_amount,approval_status,request_key,request_digest,created_at,approved_at)
SELECT a.company_id,a.expense_branch_id,a.created_by,CONCAT('LEGACY-CASH-',a.id),DATE(a.created_at),c.id,'هزینه نقدی انتقال‌یافته',a.reason,a.amount,a.amount,'approved',SHA2(CONCAT('legacy-cash-expense:',a.id),256),SHA2(CONCAT(a.company_id,':',a.id,':',a.amount),256),a.created_at,a.created_at
FROM cash_shift_adjustments a
JOIN expense_categories c ON c.company_id=a.company_id AND c.name='هزینه نقدی انتقال‌یافته'
JOIN branches b ON b.id=a.expense_branch_id AND b.company_id=a.company_id
WHERE a.expense_branch_id IS NOT NULL AND a.direction='out'
AND NOT EXISTS (SELECT 1 FROM expenses e WHERE e.company_id=a.company_id AND e.request_key=SHA2(CONCAT('legacy-cash-expense:',a.id),256));

INSERT INTO expense_items(company_id,expense_id,title,description,quantity_milli,unit,unit_price,line_total)
SELECT e.company_id,e.id,e.title,e.description,1000,'مورد',e.total_amount,e.total_amount
FROM expenses e WHERE e.document_number LIKE 'LEGACY-CASH-%'
AND e.request_key=SHA2(CONCAT('legacy-cash-expense:',SUBSTRING(e.document_number,13)),256)
AND NOT EXISTS (SELECT 1 FROM expense_items i WHERE i.company_id=e.company_id AND i.expense_id=e.id);

INSERT INTO expense_payments(company_id,expense_id,method,amount,cash_shift_id,paid_date,posted_at,cash_adjustment_id)
SELECT a.company_id,e.id,'cash',a.amount,a.shift_id,DATE(a.created_at),a.created_at,a.id
FROM cash_shift_adjustments a JOIN expenses e ON e.company_id=a.company_id AND e.request_key=SHA2(CONCAT('legacy-cash-expense:',a.id),256)
WHERE a.expense_branch_id IS NOT NULL AND a.direction='out'
AND NOT EXISTS (SELECT 1 FROM expense_payments p WHERE p.company_id=a.company_id AND p.cash_adjustment_id=a.id);

-- Durable request history survives draft edits replacing the document's current request key.
CREATE TABLE IF NOT EXISTS expense_requests (
 company_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_digest CHAR(64) NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,request_key),
 INDEX idx_expense_request_document(company_id,expense_id),
 CONSTRAINT fk_expense_request_document FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO expense_requests(company_id,request_key,request_digest,expense_id,created_at)
SELECT e.company_id,e.request_key,e.request_digest,e.id,e.created_at FROM expenses e
WHERE NOT EXISTS (SELECT 1 FROM expense_requests r WHERE r.company_id=e.company_id AND r.request_key=e.request_key);

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND COLUMN_NAME='expense_document_id')=0,'ALTER TABLE cash_shift_adjustments ADD COLUMN expense_document_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND CONSTRAINT_NAME='fk_cash_adjust_expense_document')=0,'ALTER TABLE cash_shift_adjustments ADD CONSTRAINT fk_cash_adjust_expense_document FOREIGN KEY(expense_document_id,company_id) REFERENCES expenses(id,company_id)','SELECT 1');
PREPARE expense_stmt FROM @ddl; EXECUTE expense_stmt; DEALLOCATE PREPARE expense_stmt;
UPDATE cash_shift_adjustments a JOIN expense_payments p ON p.cash_adjustment_id=a.id AND p.company_id=a.company_id SET a.expense_document_id=p.expense_id WHERE a.expense_document_id IS NULL;
