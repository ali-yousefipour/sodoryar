-- LAN PC-POS bridge foundation. Apply manually after backup; requires migration 024.
-- No existing payment or invoice is changed. Live PSP support requires verified SDK/protocol.
CREATE TABLE IF NOT EXISTS pos_bridges (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(150) NOT NULL,
 token_hash CHAR(64) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 last_seen_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_bridge_token_hash(token_hash),
 UNIQUE KEY uq_bridge_scoped(id,company_id,branch_id),
 CONSTRAINT fk_pos_bridge_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_terminals (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 bridge_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(150) NOT NULL,
 psp ENUM('behpardakht','fanava','parsian','other','simulator') NOT NULL,
 model VARCHAR(150) NULL,
 psp_terminal_number VARCHAR(80) NULL,
 connection_mode ENUM('lan','official_sdk','simulator') NOT NULL DEFAULT 'lan',
 lan_ip VARCHAR(45) NULL,
 lan_port SMALLINT UNSIGNED NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pos_terminal_scope(id,company_id,branch_id),
 INDEX idx_pos_terminals_bridge(bridge_id,company_id,branch_id),
 CONSTRAINT fk_pos_terminals_bridge FOREIGN KEY(bridge_id,company_id,branch_id) REFERENCES pos_bridges(id,company_id,branch_id),
 CONSTRAINT fk_pos_terminals_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id),
 CONSTRAINT chk_pos_port CHECK(lan_port IS NULL OR lan_port > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_payment_requests (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 bridge_id BIGINT UNSIGNED NOT NULL,
 terminal_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,
 request_key CHAR(64) NOT NULL,
 amount_irr DECIMAL(20,0) NOT NULL,
 status ENUM('queued','processing','approved','declined','cancelled','unknown','reconciled') NOT NULL DEFAULT 'queued',
 lease_expires_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pos_request_idempotency(company_id,request_key),
 UNIQUE KEY uq_pos_request_scope(id,company_id,branch_id),
 INDEX idx_pos_bridge_queue(bridge_id,status,created_at),
 CONSTRAINT fk_pos_request_terminal FOREIGN KEY(terminal_id,company_id,branch_id) REFERENCES pos_terminals(id,company_id,branch_id),
 CONSTRAINT fk_pos_request_bridge FOREIGN KEY(bridge_id,company_id,branch_id) REFERENCES pos_bridges(id,company_id,branch_id),
 CONSTRAINT fk_pos_request_invoice FOREIGN KEY(invoice_id,company_id) REFERENCES invoices(id,company_id),
 CONSTRAINT fk_pos_request_actor FOREIGN KEY(created_by) REFERENCES users(id),
 CONSTRAINT chk_pos_amount_positive CHECK(amount_irr>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_transactions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NOT NULL,
 psp_status VARCHAR(50) NULL,
 reference_number VARCHAR(120) NULL,
 tracking_number VARCHAR(120) NULL,
 psp_terminal_number VARCHAR(80) NULL,
 returned_amount_irr DECIMAL(20,0) NULL,
 payment_id BIGINT UNSIGNED NULL,
 result_received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pos_transaction_request(request_id),
 UNIQUE KEY uq_pos_transaction_receipt(company_id,psp_terminal_number,reference_number),
 UNIQUE KEY uq_pos_transaction_payment(payment_id),
 CONSTRAINT fk_pos_transaction_request FOREIGN KEY(request_id,company_id,branch_id) REFERENCES pos_payment_requests(id,company_id,branch_id),
 CONSTRAINT fk_pos_transaction_payment FOREIGN KEY(payment_id) REFERENCES invoice_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_transaction_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 event_type VARCHAR(50) NOT NULL,
 safe_metadata_json JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_pos_events_request(request_id,created_at),
 CONSTRAINT fk_pos_event_request FOREIGN KEY(request_id) REFERENCES pos_payment_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_reconciliation_records (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 request_id BIGINT UNSIGNED NOT NULL,
 resolved_by BIGINT UNSIGNED NULL,
 resolution ENUM('pending','verified_paid','verified_unpaid') NOT NULL DEFAULT 'pending',
 evidence_reference VARCHAR(160) NULL,
 note VARCHAR(500) NULL,
 resolved_at DATETIME NULL,
 UNIQUE KEY uq_pos_reconcile_request(request_id),
 CONSTRAINT fk_pos_reconcile_request FOREIGN KEY(request_id) REFERENCES pos_payment_requests(id),
 CONSTRAINT fk_pos_reconcile_user FOREIGN KEY(resolved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
