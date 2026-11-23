-- 020: immutable service rate history, effective from a local Gregorian date.
-- Apply only after verified backup, in MariaDB/MySQL with strict mode.
-- Existing invoices already store immutable service_title and price snapshots; no invoice rewrite.
CREATE TABLE IF NOT EXISTS service_rate_versions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 company_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NOT NULL,
 effective_from DATE NOT NULL,
 service_title VARCHAR(200) NOT NULL,
 rate BIGINT UNSIGNED NOT NULL,
 changed_by BIGINT UNSIGNED NULL,
 reason VARCHAR(500) NOT NULL,
 recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_service_rate_lookup (company_id,service_id,effective_from,id),
 CONSTRAINT fk_service_rate_service FOREIGN KEY(service_id,company_id)
    REFERENCES services(id,company_id) ON DELETE RESTRICT,
 CONSTRAINT fk_service_rate_actor FOREIGN KEY(changed_by)
    REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single baseline per existing service; subsequent runs never rewrite or duplicate it.
INSERT INTO service_rate_versions(company_id,service_id,effective_from,service_title,rate,changed_by,reason)
SELECT s.company_id,s.id,'1000-01-01',s.title,s.price,NULL,'مبنای تعرفه هنگام نصب تاریخچه تغییرات'
FROM services s
WHERE NOT EXISTS (
  SELECT 1 FROM service_rate_versions r
  WHERE r.company_id=s.company_id AND r.service_id=s.id
);
