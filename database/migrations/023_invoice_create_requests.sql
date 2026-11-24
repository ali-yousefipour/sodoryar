-- Durable request-to-invoice mapping, scoped to the issuing user and company.
CREATE TABLE IF NOT EXISTS invoice_create_requests (
 company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 invoice_id BIGINT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(company_id,user_id,request_key),
 UNIQUE KEY uq_invoice_create_result(company_id,invoice_id),
 CONSTRAINT fk_invoice_create_result FOREIGN KEY(invoice_id,company_id) REFERENCES invoices(id,company_id),
 CONSTRAINT fk_invoice_create_actor FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
