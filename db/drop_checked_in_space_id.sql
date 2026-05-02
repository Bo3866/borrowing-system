-- drop_checked_in_space_id.sql
-- 使用說明：
-- 1) 建議在 CLI (mysql) 或具有充分權限的管理工具執行。
-- 2) 執行前請確認你已在資料庫 `borrowing_system` 下，並先備份。
-- 3) 若外鍵名稱不同，請先用 SHOW CREATE TABLE checkin_logs\G 查詢正確名稱並替換下列 DROP FOREIGN KEY 的名稱。

-- 1) 備份表（結構 + 資料）
CREATE TABLE IF NOT EXISTS checkin_logs_backup LIKE checkin_logs;
INSERT INTO checkin_logs_backup SELECT * FROM checkin_logs;

-- 2) 顯示目前表結構以確認外鍵名稱（建議先手動檢查）
SHOW CREATE TABLE checkin_logs;

-- 如果你已知外鍵名稱為 fk_checkin_logs_space，則執行下面兩行；
-- 否則把 fk_checkin_logs_space 換成 SHOW CREATE TABLE 結果中的外鍵名稱。

-- 3) 刪除外鍵約束
ALTER TABLE checkin_logs DROP FOREIGN KEY fk_checkin_logs_space;

-- 4) 若需要，同時刪除相關索引（若索引名稱與外鍵不同，請以 SHOW INDEX 結果為準）
-- 下方範例試圖刪除假設名稱為 idx_checked_in_space_id 的索引；如不存在可註解或移除這行
-- ALTER TABLE checkin_logs DROP INDEX idx_checked_in_space_id;

-- 5) 刪除欄位
ALTER TABLE checkin_logs DROP COLUMN checked_in_space_id;

-- 6) 驗證
SHOW COLUMNS FROM checkin_logs;

-- 完成。
