-- 027: internal transfers are paired cash adjustments, never invoice receipts.
CREATE TABLE IF NOT EXISTS cash_transfers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 source_shift_id BIGINT UNSIGNED NOT NULL,
 destination_shift_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 reason VARCHAR(400) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_token CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_cash_transfer_request(company_id,request_token),
 UNIQUE KEY uq_cash_transfer_tenant(id,company_id),
 CONSTRAINT fk_transfer_source FOREIGN KEY(source_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 CONSTRAINT fk_transfer_destination FOREIGN KEY(destination_shift_id,company_id) REFERENCES cash_shifts(id,company_id),
 CONSTRAINT fk_transfer_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_transfer_amount CHECK(amount>0),
 CONSTRAINT chk_transfer_distinct CHECK(source_shift_id<>destination_shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND COLUMN_NAME='transfer_id')=0,
 'ALTER TABLE cash_shift_adjustments ADD COLUMN transfer_id BIGINT UNSIGNED NULL','SELECT 1');
PREPARE transfer_stmt FROM @ddl; EXECUTE transfer_stmt; DEALLOCATE PREPARE transfer_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND CONSTRAINT_NAME='fk_cash_adjust_transfer')=0,
 'ALTER TABLE cash_shift_adjustments ADD CONSTRAINT fk_cash_adjust_transfer FOREIGN KEY(transfer_id,company_id) REFERENCES cash_transfers(id,company_id)','SELECT 1');
PREPARE transfer_stmt FROM @ddl; EXECUTE transfer_stmt; DEALLOCATE PREPARE transfer_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cash_shift_adjustments' AND INDEX_NAME='uq_cash_transfer_leg')=0,
 'ALTER TABLE cash_shift_adjustments ADD UNIQUE KEY uq_cash_transfer_leg(company_id,transfer_id,direction)','SELECT 1');
PREPARE transfer_stmt FROM @ddl; EXECUTE transfer_stmt; DEALLOCATE PREPARE transfer_stmt;
