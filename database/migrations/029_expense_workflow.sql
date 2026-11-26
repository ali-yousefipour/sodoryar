-- Additive expense workflow; requires the existing 026/027 schema. No financial reposting.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expenses' AND COLUMN_NAME='version')=0,'ALTER TABLE expenses ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1','SELECT 1');
PREPARE workflow_stmt FROM @ddl; EXECUTE workflow_stmt; DEALLOCATE PREPARE workflow_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expenses' AND COLUMN_NAME='subtotal')=0,'ALTER TABLE expenses ADD COLUMN subtotal DECIMAL(20,0) NOT NULL DEFAULT 0, ADD COLUMN extra_amount DECIMAL(20,0) NOT NULL DEFAULT 0, ADD COLUMN discount_amount DECIMAL(20,0) NOT NULL DEFAULT 0','SELECT 1');
PREPARE workflow_stmt FROM @ddl; EXECUTE workflow_stmt; DEALLOCATE PREPARE workflow_stmt;
UPDATE expenses SET subtotal=total_amount WHERE subtotal=0 AND extra_amount=0 AND discount_amount=0;
CREATE TABLE IF NOT EXISTS expense_policies (
 company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 approval_mode ENUM('none','all','threshold','branch') NOT NULL DEFAULT 'all',
 approval_threshold DECIMAL(20,0) NOT NULL DEFAULT 0,
 FOREIGN KEY(company_id) REFERENCES companies(id), CHECK(approval_threshold>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_branch_approvers (
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 approval_limit DECIMAL(20,0) NOT NULL,
 PRIMARY KEY(company_id,branch_id,user_id),
 FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id),
 FOREIGN KEY(user_id,company_id) REFERENCES users(id,company_id), CHECK(approval_limit>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_mutation_requests (
 company_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(company_id,request_key),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS expense_attachments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 original_name VARCHAR(180) NOT NULL,
 stored_name VARCHAR(64) NOT NULL,
 content_hash CHAR(64) NOT NULL,
 mime_type VARCHAR(100) NOT NULL,
 size_bytes INT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_expense_attachment_file(stored_name),
 UNIQUE KEY uq_expense_attachment_content(company_id,expense_id,content_hash),
 INDEX idx_expense_attachment_document(company_id,expense_id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 FOREIGN KEY(created_by,company_id) REFERENCES users(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
