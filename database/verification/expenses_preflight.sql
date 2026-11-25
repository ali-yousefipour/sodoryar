-- READ ONLY: run on the intended existing database before selecting migrations.
-- Do not substitute install.sql or the incompatible 031_expense_documents.sql schema.
SELECT DATABASE() AS selected_database, VERSION() AS database_version;
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
 ('branches','user_branch_assignments','company_bank_accounts','expenses','expense_categories',
  'expense_items','expense_payments','expense_events','expense_settlements','expense_reversals','expense_returns');
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME IN ('expenses','expense_items','expense_categories','expense_payments','expense_events',
 'expense_settlements','expense_reversals','expense_returns') ORDER BY TABLE_NAME,ORDINAL_POSITION;
SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME
 FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE()
 AND TABLE_NAME IN ('expenses','expense_items','expense_categories','expense_payments','expense_events',
 'expense_settlements','expense_reversals','expense_returns') ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION;
SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME IN ('expenses','expense_items','expense_categories','expense_payments','expense_events',
 'expense_settlements','expense_reversals','expense_returns') GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE;
