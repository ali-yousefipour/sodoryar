CREATE TABLE companies (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(180) NOT NULL, slug VARCHAR(80) NOT NULL UNIQUE,
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NULL, username VARCHAR(100) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL, full_name VARCHAR(180) NOT NULL,
 role ENUM('superadmin','company_admin','operator') NOT NULL DEFAULT 'operator',
 is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_user_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE customers (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 national_code VARCHAR(12) NOT NULL, first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(100) NOT NULL, mobile VARCHAR(20), address TEXT,
 line_area VARCHAR(180), line_code VARCHAR(40), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_customer_tenant(company_id,national_code),
 UNIQUE KEY uq_customer_id_company(id,company_id),
 CONSTRAINT fk_customer_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(200) NOT NULL, price BIGINT UNSIGNED NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_service_id_company(id,company_id),
 CONSTRAINT fk_service_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE invoices (
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
CREATE TABLE invoice_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL, service_id BIGINT UNSIGNED NOT NULL,
 service_title VARCHAR(200) NOT NULL, price BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_item_invoice FOREIGN KEY(invoice_id,company_id) REFERENCES invoices(id,company_id) ON DELETE CASCADE,
 CONSTRAINT fk_item_service FOREIGN KEY(service_id,company_id) REFERENCES services(id,company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NULL,
 user_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL,
 details TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_log_tenant(company_id,created_at),
 CONSTRAINT fk_log_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_log_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE pos_settings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 brand VARCHAR(100) NOT NULL, ip_address VARCHAR(45), port SMALLINT UNSIGNED,
 is_active TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_pos_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE print_templates (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 title VARCHAR(160) NOT NULL, paper_type ENUM('thermal80','a5') NOT NULL,
 header_text TEXT, footer_text TEXT, logo_path VARCHAR(255), layout_json JSON,
 is_default TINYINT(1) NOT NULL DEFAULT 0,
 CONSTRAINT fk_template_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE bill_payments (
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
CREATE TABLE app_settings (
 company_id BIGINT UNSIGNED NOT NULL, setting_key VARCHAR(120) NOT NULL,
 setting_value TEXT, PRIMARY KEY(company_id,setting_key),
 CONSTRAINT fk_settings_company FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE commissions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, company_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL, period_type ENUM('daily','monthly') NOT NULL,
 period_key VARCHAR(10) NOT NULL, sales_total BIGINT UNSIGNED NOT NULL,
 rate DECIMAL(6,3) NOT NULL, amount BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_comm_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT fk_comm_user FOREIGN KEY(user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
