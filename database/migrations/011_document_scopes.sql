-- Sodoryar 011: optional invoice/bill document-scope policy for company operators.
-- Apply only after database backup. The existing effective scope remains company by default.
CREATE TABLE IF NOT EXISTS user_document_scopes (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 scope ENUM('own','company') NOT NULL DEFAULT 'company',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_doc_scope_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_doc_scope_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
