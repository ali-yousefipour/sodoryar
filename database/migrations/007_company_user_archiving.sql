-- Run ONCE in phpMyAdmin for existing installations.
-- Soft-archive only: historical invoices, financial reports and activity logs remain intact.
ALTER TABLE companies ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE users ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL;

