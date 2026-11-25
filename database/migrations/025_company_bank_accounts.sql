-- Company bank-account registry; no inferred balances or financial postings.
CREATE TABLE IF NOT EXISTS company_bank_accounts (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 company_id BIGINT UNSIGNED NOT NULL,
 branch_id BIGINT UNSIGNED NULL,
 bank_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL,
 account_number VARCHAR(40) NULL,
 iban CHAR(26) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(id),
 UNIQUE KEY uq_cba_id_company(id,company_id),
 UNIQUE KEY uq_cba_iban(company_id,iban),
 INDEX idx_cba_branch(company_id,branch_id,is_active),
 CONSTRAINT fk_cba_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_cba_branch FOREIGN KEY(branch_id,company_id) REFERENCES branches(id,company_id),
 CONSTRAINT fk_cba_bank FOREIGN KEY(bank_id) REFERENCES banks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_account_registry (
 company_id BIGINT UNSIGNED NOT NULL,
 terminal_id BIGINT UNSIGNED NOT NULL,
 account_id BIGINT UNSIGNED NOT NULL,
 terminal_number VARCHAR(64) NOT NULL,
 PRIMARY KEY(company_id,terminal_id),
 UNIQUE KEY uq_pos_registry_number(company_id,terminal_number),
 CONSTRAINT fk_par_terminal FOREIGN KEY(terminal_id) REFERENCES pos_settings(id),
 CONSTRAINT fk_par_account FOREIGN KEY(account_id,company_id) REFERENCES company_bank_accounts(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
