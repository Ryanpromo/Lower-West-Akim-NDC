ALTER TABLE users
  DROP INDEX uq_users_single_chairman,
  DROP COLUMN chairman_slot,
  MODIFY COLUMN role ENUM('chairman', 'secretary', 'deputy_secretary', 'treasurer', 'executive') NOT NULL,
  ADD COLUMN official_position VARCHAR(120) NULL AFTER role,
  ADD COLUMN chairman_slot TINYINT GENERATED ALWAYS AS (CASE WHEN role = 'chairman' THEN 1 ELSE NULL END) STORED,
  ADD UNIQUE KEY uq_users_single_chairman (chairman_slot);

UPDATE users
SET official_position = CASE role
  WHEN 'chairman' THEN 'Constituency Chairman'
  WHEN 'secretary' THEN 'Constituency Secretary'
  WHEN 'deputy_secretary' THEN 'Deputy Secretary'
  ELSE official_position
END
WHERE official_position IS NULL;

CREATE TABLE executive_reports (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  author_id BIGINT UNSIGNED NOT NULL,
  report_type ENUM('activity', 'project') NOT NULL,
  category VARCHAR(60) NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NOT NULL,
  activity_date DATE NOT NULL,
  location VARCHAR(180) NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  reviewer_id BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  rejection_reason VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_status_date (status, activity_date, created_at),
  KEY idx_reports_author_status (author_id, status, created_at),
  CONSTRAINT fk_reports_author FOREIGN KEY (author_id) REFERENCES users (id),
  CONSTRAINT fk_reports_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE report_media (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  media_type ENUM('photo', 'document') NOT NULL,
  storage_name CHAR(44) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_media_storage_name (storage_name),
  KEY idx_report_media_report_type (report_id, media_type),
  CONSTRAINT fk_report_media_report FOREIGN KEY (report_id) REFERENCES executive_reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_report_media_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE report_approvals (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  reviewer_id BIGINT UNSIGNED NOT NULL,
  decision ENUM('approved', 'rejected') NOT NULL,
  reason VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_approvals_report (report_id, created_at),
  CONSTRAINT fk_report_approvals_report FOREIGN KEY (report_id) REFERENCES executive_reports (id) ON DELETE CASCADE,
  CONSTRAINT fk_report_approvals_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_id BIGINT UNSIGNED NOT NULL,
  report_id BIGINT UNSIGNED NULL,
  message VARCHAR(255) NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_recipient_read (recipient_id, read_at, created_at),
  CONSTRAINT fk_notifications_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_report FOREIGN KEY (report_id) REFERENCES executive_reports (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
