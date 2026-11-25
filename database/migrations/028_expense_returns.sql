-- Additive, rerunnable; requires 026_expense_documents and 027_expense_reversals_reimbursements.
-- Never infer a refund from administrative cancellation; never rewrite historical money.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expense_payments' AND INDEX_NAME='uq_ep_return_identity')=0,'ALTER TABLE expense_payments ADD UNIQUE KEY uq_ep_return_identity(id,company_id,expense_id)','SELECT 1');
PREPARE return_stmt FROM @ddl; EXECUTE return_stmt; DEALLOCATE PREPARE return_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expense_settlements' AND INDEX_NAME='uq_es_return_identity')=0,'ALTER TABLE expense_settlements ADD UNIQUE KEY uq_es_return_identity(id,company_id,expense_id)','SELECT 1');
PREPARE return_stmt FROM @ddl; EXECUTE return_stmt; DEALLOCATE PREPARE return_stmt;
CREATE TABLE IF NOT EXISTS expense_returns (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 expense_id BIGINT UNSIGNED NOT NULL,
 payment_id BIGINT UNSIGNED NULL,
 settlement_id BIGINT UNSIGNED NULL,
 amount DECIMAL(20,0) NOT NULL,
 method ENUM('cash','bank') NOT NULL,
 account_id BIGINT UNSIGNED NULL,
 cash_shift_id BIGINT UNSIGNED NULL,
 cash_adjustment_id BIGINT UNSIGNED NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 return_date DATE NOT NULL,
 reference VARCHAR(120) NOT NULL,
 reason VARCHAR(500) NOT NULL,
 request_key CHAR(64) NOT NULL,
 request_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_expense_return_request(company_id,request_key),
 UNIQUE KEY uq_expense_return_cash(cash_adjustment_id),
 UNIQUE KEY uq_expense_return_payment_receipt(company_id,payment_id,reference),
 UNIQUE KEY uq_expense_return_settlement_receipt(company_id,settlement_id,reference),
 UNIQUE KEY uq_expense_return_bank_receipt(company_id,account_id,reference),
 INDEX idx_expense_return_document(company_id,expense_id),
 INDEX idx_expense_return_payment(company_id,payment_id),
 INDEX idx_expense_return_settlement(company_id,settlement_id),
 FOREIGN KEY(expense_id,company_id) REFERENCES expenses(id,company_id),
 FOREIGN KEY(payment_id,company_id,expense_id) REFERENCES expense_payments(id,company_id,expense_id),
 FOREIGN KEY(settlement_id,company_id,expense_id) REFERENCES expense_settlements(id,company_id,expense_id),
 FOREIGN KEY(account_id,company_id) REFERENCES company_bank_accounts(id,company_id),
 FOREIGN KEY(cash_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 FOREIGN KEY(cash_adjustment_id) REFERENCES cash_shift_adjustments(id),
 FOREIGN KEY(actor_id,company_id) REFERENCES users(id,company_id),
 CHECK(amount>0),
 CHECK((payment_id IS NULL) <> (settlement_id IS NULL)),
 CHECK((method='cash' AND cash_shift_id IS NOT NULL AND account_id IS NULL AND cash_adjustment_id IS NOT NULL)
    OR (method='bank' AND account_id IS NOT NULL AND cash_shift_id IS NULL AND cash_adjustment_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
