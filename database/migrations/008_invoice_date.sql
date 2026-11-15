-- Run once on existing databases. Older invoices inherit their original creation date.
ALTER TABLE invoices
  ADD COLUMN invoice_date DATE NULL DEFAULT NULL AFTER pos_ref,
  ADD INDEX idx_invoice_service_date (company_id, invoice_date);
UPDATE invoices SET invoice_date = DATE(created_at) WHERE invoice_date IS NULL;
