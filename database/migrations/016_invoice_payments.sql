-- 016: invoice payment ledger. Apply after a verified backup, prior to enabling multi-payments.
-- Safe to re-run: CREATE IF NOT EXISTS and legacy backfill guarded by unique invoice identity.
CREATE TABLE IF NOT EXISTS invoice_payments (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL,
 payment_method ENUM('cash','pos') NOT NULL,
 amount DECIMAL(20,0) NOT NULL,
 terminal_id BIGINT UNSIGNED NULL,
 tracking_number VARCHAR(120) NULL,
 reference_number VARCHAR(120) NULL,
 paid_at DATETIME NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 voided_at DATETIME NULL,
 voided_by BIGINT UNSIGNED NULL,
 void_reason VARCHAR(500) NULL,
 legacy_invoice_id BIGINT UNSIGNED NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_invoice_payments_legacy (legacy_invoice_id),
 INDEX idx_invoice_payments_invoice (company_id,invoice_id,voided_at),
 INDEX idx_invoice_payments_day (company_id,paid_at,payment_method),
 CONSTRAINT fk_invoice_payments_invoice FOREIGN KEY (invoice_id,company_id) REFERENCES invoices(id,company_id) ON DELETE RESTRICT,
 CONSTRAINT fk_invoice_payments_terminal FOREIGN KEY (terminal_id) REFERENCES pos_settings(id) ON DELETE RESTRICT,
 CONSTRAINT fk_invoice_payments_creator FOREIGN KEY (created_by) REFERENCES users(id),
 CONSTRAINT fk_invoice_payments_voider FOREIGN KEY (voided_by) REFERENCES users(id),
 CONSTRAINT chk_invoice_payments_positive CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historical invoices: one payment representing the previous single-method payment record.
-- Execute only after confirming legacy invoices were considered fully paid.
INSERT INTO invoice_payments
(company_id,invoice_id,payment_method,amount,tracking_number,paid_at,created_by,legacy_invoice_id)
SELECT i.company_id,i.id,i.payment_type,i.total_amount,i.pos_ref,i.created_at,i.user_id,i.id
FROM invoices i
LEFT JOIN invoice_payments p ON p.invoice_id=i.id AND p.company_id=i.company_id
WHERE p.id IS NULL AND i.total_amount>0;
