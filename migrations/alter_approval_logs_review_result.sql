USE borrowing_system;

ALTER TABLE approval_logs
  MODIFY COLUMN review_result ENUM('approved','need_revision','rejected') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '審核結果';