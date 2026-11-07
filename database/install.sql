-- Sodoryar MySQL installation / migrations 001 + 002
-- Select database h301194_sodor in phpMyAdmin before importing this file.
-- No credentials or operational data are included.

-- Sodoryar migration 001: initial multi-tenant schema
-- Import into the selected h301194_sodor database using phpMyAdmin.
-- Safe for repeated execution: CREATE IF NOT EXISTS does not modify existing tables.

CREATE TABLE IF NOT EXISTS companies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(180) NOT NULL, slug VARCHAR(80) NOT NULL UNIQUE,
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NULL, username VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(180) NOT NULL,
 role ENUM('superadmin','company_admin','operator') NOT NULL DEFAULT 'operator',
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_user_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS customers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 national_code VARCHAR(12) NOT NULL, first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(100) NOT NULL, mobile VARCHAR(20), address TEXT,
 line_area VARCHAR(180), line_code VARCHAR(40), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_customer_tenant(company_id,national_code),
 UNIQUE KEY uq_customer_id_company(id,company_id),
 CONSTRAINT fk_customer_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(200) NOT NULL, price BIGINT UNSIGNED NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_service_id_company(id,company_id),
 CONSTRAINT fk_service_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 invoice_number VARCHAR(60) NOT NULL, customer_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL, total_amount BIGINT UNSIGNED NOT NULL,
 payment_type ENUM('cash','pos') NOT NULL, pos_ref VARCHAR(120),
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_invoice_number(company_id,invoice_number),
 UNIQUE KEY uq_invoice_id_company(id,company_id), INDEX idx_invoice_date(company_id,created_at),
 CONSTRAINT fk_invoice_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_invoice_customer FOREIGN KEY(customer_id,company_id) REFERENCES customers(id,company_id),
 CONSTRAINT fk_invoice_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS invoice_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL, service_id BIGINT UNSIGNED NOT NULL,
 service_title VARCHAR(200) NOT NULL, price BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_item_invoice FOREIGN KEY(invoice_id,company_id) REFERENCES invoices(id,company_id) ON DELETE CASCADE,
 CONSTRAINT fk_item_service FOREIGN KEY(service_id,company_id) REFERENCES services(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL,
 details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_log_tenant(company_id,created_at),
 CONSTRAINT fk_log_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_log_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS pos_settings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 brand VARCHAR(100) NOT NULL, ip_address VARCHAR(45), port SMALLINT UNSIGNED,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_pos_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS print_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL, paper_type ENUM('thermal80','a5') NOT NULL,
 header_text TEXT, footer_text TEXT, logo_path VARCHAR(255), layout_json JSON,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_template_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bill_payments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL, bill_type VARCHAR(40) NOT NULL,
 bill_id VARCHAR(100) NOT NULL, payment_id VARCHAR(100) NOT NULL,
 amount BIGINT UNSIGNED NOT NULL, ref_code VARCHAR(120), response_code VARCHAR(30),
 national_code VARCHAR(12), driver_name VARCHAR(200),
 paid_method ENUM('manual','pos') NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_bill_date(company_id,created_at),
 CONSTRAINT fk_bill_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_bill_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS app_settings (
 company_id BIGINT UNSIGNED NOT NULL, setting_key VARCHAR(120) NOT NULL,
 setting_value TEXT, PRIMARY KEY(company_id,setting_key),
 CONSTRAINT fk_settings_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS commissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL, period_type ENUM('daily','monthly') NOT NULL,
 period_key VARCHAR(10) NOT NULL, sales_total BIGINT UNSIGNED NOT NULL,
 rate DECIMAL(6,3) NOT NULL, amount BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_comm_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_comm_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Import after 001_initial.sql. Re-running is safe.
CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 attempt_key CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_login_attempt_window (attempt_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Operator-specific access rights. New installation includes this table.
CREATE TABLE IF NOT EXISTS user_permissions (
 user_id BIGINT UNSIGNED NOT NULL,
 permission_key VARCHAR(80) NOT NULL,
 PRIMARY KEY(user_id,permission_key),
 CONSTRAINT fk_permission_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Central driver lookup directory (migration 004).
CREATE TABLE IF NOT EXISTS global_drivers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 national_code CHAR(10) NOT NULL UNIQUE,
 first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(100) NOT NULL,
 mobile VARCHAR(30) NULL,
 line_area VARCHAR(180) NULL,
 line_code VARCHAR(60) NULL,
 vehicle_plate VARCHAR(80) NULL,
 national_code_valid TINYINT(1) NOT NULL DEFAULT 1,
 details_json JSON NULL,
 source_name VARCHAR(160) NULL,
 imported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_driver_name (last_name,first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
