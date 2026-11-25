-- 028: additive terminal metadata; existing payment identities remain unchanged.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_account_registry' AND COLUMN_NAME='merchant_number')=0,'ALTER TABLE pos_account_registry ADD COLUMN merchant_number VARCHAR(64) NULL','SELECT 1');
PREPARE pos_stmt FROM @ddl; EXECUTE pos_stmt; DEALLOCATE PREPARE pos_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_account_registry' AND COLUMN_NAME='serial_number')=0,'ALTER TABLE pos_account_registry ADD COLUMN serial_number VARCHAR(120) NULL','SELECT 1');
PREPARE pos_stmt FROM @ddl; EXECUTE pos_stmt; DEALLOCATE PREPARE pos_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_account_registry' AND COLUMN_NAME='psp_name')=0,'ALTER TABLE pos_account_registry ADD COLUMN psp_name VARCHAR(120) NULL','SELECT 1');
PREPARE pos_stmt FROM @ddl; EXECUTE pos_stmt; DEALLOCATE PREPARE pos_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_account_registry' AND COLUMN_NAME='owner_name')=0,'ALTER TABLE pos_account_registry ADD COLUMN owner_name VARCHAR(180) NULL','SELECT 1');
PREPARE pos_stmt FROM @ddl; EXECUTE pos_stmt; DEALLOCATE PREPARE pos_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_account_registry' AND COLUMN_NAME='internal_code')=0,'ALTER TABLE pos_account_registry ADD COLUMN internal_code VARCHAR(40) NULL','SELECT 1');
PREPARE pos_stmt FROM @ddl; EXECUTE pos_stmt; DEALLOCATE PREPARE pos_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pos_account_registry' AND COLUMN_NAME='notes')=0,'ALTER TABLE pos_account_registry ADD COLUMN notes VARCHAR(500) NULL','SELECT 1');
PREPARE pos_stmt FROM @ddl; EXECUTE pos_stmt; DEALLOCATE PREPARE pos_stmt;
