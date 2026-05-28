USE borrowing_system;

CREATE TABLE IF NOT EXISTS handover_schedules (
  handover_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reservation_id BIGINT UNSIGNED NOT NULL,
  handover_at DATETIME NOT NULL COMMENT '預計交接時間',
  returned_at DATETIME NULL COMMENT '歸還完成時間',
  note VARCHAR(500) NULL COMMENT '交接備註',
  created_by VARCHAR(10) NOT NULL COMMENT '建立人(工讀生 user_id)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (handover_id),
  KEY idx_handover_reservation (reservation_id),
  KEY idx_handover_at (handover_at),
  KEY idx_handover_returned_at (returned_at),
  KEY idx_handover_created_by (created_by),
  CONSTRAINT fk_handover_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_handover_created_by
    FOREIGN KEY (created_by) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
