-- 030: reconcile existing POS receipts against a documented bank deposit; no new receipts.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_payments' AND INDEX_NAME='uq_payment_tenant')=0,
 'ALTER TABLE invoice_payments ADD UNIQUE KEY uq_payment_tenant(id,company_id)','SELECT 1');
PREPARE settle_stmt FROM @ddl; EXECUTE settle_stmt; DEALLOCATE PREPARE settle_stmt;
CREATE TABLE IF NOT EXISTS pos_bank_settlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 terminal_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 bank_reference VARCHAR(120) NOT NULL,
 settled_date DATE NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_digest CHAR(64) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pos_settlement_tenant(id,company_id),
 UNIQUE KEY uq_pos_settlement_request(company_id,request_key),
 UNIQUE KEY uq_pos_settlement_bank_ref(company_id,account_id,bank_reference),
 INDEX idx_pos_settlement_date(company_id,settled_date,terminal_id),
 CONSTRAINT fk_pos_settle_terminal FOREIGN KEY(terminal_id) REFERENCES pos_settings(id),
 CONSTRAINT fk_pos_settle_account FOREIGN KEY(account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 CONSTRAINT fk_pos_settle_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_pos_settle_positive CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pos_bank_settlement_items (
 company_id BIGINT UNSIGNED NOT NULL,
 settlement_id BIGINT UNSIGNED NOT NULL,
 payment_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 PRIMARY KEY(company_id,payment_id),
 CONSTRAINT fk_pos_settle_item_header FOREIGN KEY(settlement_id,company_id) REFERENCES pos_bank_settlements(id,company_id),
 CONSTRAINT fk_pos_settle_item_payment FOREIGN KEY(payment_id,company_id) REFERENCES invoice_payments(id,company_id),
 CONSTRAINT chk_pos_settle_item_positive CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
