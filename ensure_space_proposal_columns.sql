-- 確保 space_reservation_items 表有 proposal_file 和 proposal_uploaded_at 列
ALTER TABLE space_reservation_items ADD COLUMN IF NOT EXISTS proposal_file VARCHAR(255) NULL COMMENT '上傳之活動企劃書檔案路徑' AFTER space_id;
ALTER TABLE space_reservation_items ADD COLUMN IF NOT EXISTS proposal_uploaded_at DATETIME NULL COMMENT '活動企劃書上傳時間' AFTER proposal_file;

-- 驗證列是否存在
DESCRIBE space_reservation_items;
