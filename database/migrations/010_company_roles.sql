-- Sodoryar 010: optional company-scoped reusable operator roles.
-- Apply after 009, after a verified backup. Existing users and permissions remain unchanged.
CREATE TABLE IF NOT EXISTS company_roles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(120) NOT NULL,
 description VARCHAR(500) NOT NULL DEFAULT '',
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_company_role_name(company_id,name),
 UNIQUE KEY uq_company_role_id(id,company_id),
 CONSTRAINT fk_company_roles_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_role_permissions (
 role_id BIGINT UNSIGNED NOT NULL,
 company_id BIGINT UNSIGNED NOT NULL,
 permission_key VARCHAR(80) NOT NULL,
 PRIMARY KEY(role_id,permission_key),
 CONSTRAINT fk_role_permission_tenant FOREIGN KEY(role_id,company_id) REFERENCES company_roles(id,company_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_role_assignments (
 user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 role_id BIGINT UNSIGNED NOT NULL,
 assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_assignment_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_assignment_tenant FOREIGN KEY(role_id,company_id) REFERENCES company_roles(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
