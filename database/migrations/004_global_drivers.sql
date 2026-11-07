-- Shared driver directory for all companies; financial records remain tenant-scoped.
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
