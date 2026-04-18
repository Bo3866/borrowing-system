-- users 測試帳號資料
-- 匯入前請先確認 schema.sql 已建立 borrowing_system 與 users 資料表

USE borrowing_system;

-- 角色對應建議
-- role_name = '1'：學生（只能使用借用功能）
-- role_name = '2'：課指組老師（可審核與修改器材資訊）

INSERT INTO users (user_id, full_name, role_name, email, phone)
VALUES
  ('S000000001', '學生', '1', 'student@example.com', '0912345678'),
  ('T000000001', '課指組老師', '2', 'teacher@example.com', '0922333444')
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  role_name = VALUES(role_name),
  email = VALUES(email),
  phone = VALUES(phone);
