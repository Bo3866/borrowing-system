-- 為 reservations 增加「其他場地」欄位
USE borrowing_system;

ALTER TABLE reservations
ADD COLUMN IF NOT EXISTS other_space_name VARCHAR(120) NULL COMMENT '申請者自填其他場地名稱';
