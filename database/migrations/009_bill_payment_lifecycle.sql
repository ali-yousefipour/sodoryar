-- Sodoryar 009: reversible lifecycle for manually entered bill/payment records.
-- Apply after database/migrations/008_invoice_date.sql. Back up the database first.
-- Existing records stay active; neither payments nor invoices are deleted by migration.
ALTER TABLE bill_payments
  ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN deleted_by BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD INDEX idx_bill_trash (company_id, deleted_at, id),
  ADD CONSTRAINT fk_bill_deleted_by FOREIGN KEY (deleted_by) REFERENCES users(id);
-- Re-running this ALTER on an already migrated database will fail safely.
-- Check SHOW COLUMNS FROM bill_payments LIKE 'deleted_at' before applying.
