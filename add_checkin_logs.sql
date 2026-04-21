-- 報到紀錄表（供 QR 報到使用）
USE borrowing_system;

CREATE TABLE IF NOT EXISTS checkin_logs (
  checkin_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT UNSIGNED NOT NULL,
  user_id VARCHAR(10) NOT NULL,
  checked_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checkin_source VARCHAR(20) NOT NULL DEFAULT 'qr',
  PRIMARY KEY (checkin_id),
  UNIQUE KEY uq_checkin_once (reservation_id, user_id),
  KEY idx_checkin_user (user_id),
  CONSTRAINT fk_checkin_logs_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_checkin_logs_user
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
