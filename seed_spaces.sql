-- 匯入場地資訊（可重複執行）
-- 使用方式：在 borrowing_system 資料庫執行本檔案

USE borrowing_system;

-- 註：戶外擺攤區 capacity 先以「可容納攤位數」預設 20，可依實際調整
INSERT INTO spaces (space_id, space_name, capacity, space_status) VALUES
('OUT001', '戶外空間 真善美聖廣場', 120, 'available'),
('OUT002', '戶外空間 校門口左側擺攤區 AB', 20, 'available'),
('OUT003', '戶外空間 校門口左側擺攤區 CD', 20, 'available'),

('ES001', '進修部 地下室演講聽 ES001', 200, 'available'),
('ES002', '進修部 地下室教室 ES002', 40, 'available'),
('ES003', '進修部 地下室教室 ES003', 40, 'available'),
('ES004', '進修部 地下室教室 ES004', 40, 'available'),
('ES005', '進修部 地下室教室 ES005', 40, 'available'),
('ES006', '進修部 地下室教室 ES006', 40, 'available'),

('LE-B1-L', '文開樓 地下室舞蹈空間 左側', 50, 'available'),
('LE-B1-M', '文開樓 地下室舞蹈空間 中間', 50, 'available'),
('LE-B1-R', '文開樓 地下室舞蹈空間 右側軟墊區', 50, 'available'),

('EZ003', '焯炤館 地下室演講廳 EZ003', 160, 'available'),
('EZ012', '焯炤館 地下室旋律廣場 EZ012', 100, 'available'),
('EZ015', '焯炤館 地下室視聽會議室 EZ015', 40, 'available'),
('EZ008', '焯炤館 地下室中型會議室 EZ008', 20, 'available'),
('EZ016', '焯炤館 地下室鏡鏡屋 EZ016', 40, 'available'),
('EZ004', '焯炤館 地下室夢幻電影城 EZ004', 50, 'available'),
('EZ408', '焯炤館 四樓康樂教室 EZ408', 100, 'available'),

('RA1H', '仁愛學苑 公共空間 一樓半', 30, 'available'),
('RA2H', '仁愛學苑 公共空間 二樓半', 30, 'available'),
('RA3H', '仁愛學苑 公共空間 三樓半', 30, 'available'),

('204', '課指組二樓 204 會議室', 15, 'available')
ON DUPLICATE KEY UPDATE
    space_name = VALUES(space_name),
    capacity = VALUES(capacity),
    space_status = VALUES(space_status),
    updated_at = CURRENT_TIMESTAMP;
