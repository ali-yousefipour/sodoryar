-- Run ONCE in phpMyAdmin against the Sodoryar database before enabling optional features.
-- Existing invoices, customers and users remain intact.

CREATE TABLE IF NOT EXISTS user_profiles (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 national_code CHAR(10) NULL,
 mobile VARCHAR(20) NULL,
 address TEXT NULL,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_profile_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_voids (
 invoice_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 voided_by BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 voided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_void_invoice FOREIGN KEY(invoice_id,company_id) REFERENCES invoices(id,company_id),
 CONSTRAINT fk_void_user FOREIGN KEY(voided_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom invoice items have no predefined service, so their FK field must be nullable.
ALTER TABLE invoice_items MODIFY service_id BIGINT UNSIGNED NULL;
