-- 017: one-time authorization keys for subsequent receipt collection.
-- Before applying: take and verify a backup, inspect 016 and existing table columns.
-- The application rejects follow-up collections until this migration is installed.
-- Do not run twice: first inspect INFORMATION_SCHEMA for request_key and index.
ALTER TABLE invoice_payments
    ADD COLUMN request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE KEY uq_invoice_payment_request (company_id, request_key);
