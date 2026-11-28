-- 031: Opt-in subscriptions. No existing tenant is blocked by this migration.
CREATE TABLE IF NOT EXISTS company_subscriptions (
 company_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 monthly_fee BIGINT UNSIGNED NOT NULL DEFAULT 0,
 months SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 total_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
 paid_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_subscription_company FOREIGN KEY(company_id) REFERENCES companies(id),
 CONSTRAINT chk_subscription_months CHECK(months BETWEEN 1 AND 120),
 CONSTRAINT chk_subscription_dates CHECK(end_date>=start_date),
 CONSTRAINT chk_subscription_paid CHECK(paid_amount<=total_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS company_subscription_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 old_snapshot JSON NULL,
 new_snapshot JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_subscription_events_company(company_id,created_at),
 FOREIGN KEY(company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
