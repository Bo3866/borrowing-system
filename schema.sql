-- 校園空間與器材租借系統 ERD 對應 SQL
-- Target: MySQL 8.0+

SET NAMES utf8mb4;
SET time_zone = '+08:00';

CREATE DATABASE IF NOT EXISTS borrowing_system
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE borrowing_system;

-- 1) 使用者
CREATE TABLE IF NOT EXISTS users (
  user_id VARCHAR(10) NOT NULL COMMENT '學號/教職員編號',
  full_name VARCHAR(100) NOT NULL COMMENT '姓名',
  role_name VARCHAR(15) NOT NULL COMMENT '角色',
  email VARCHAR(255) NOT NULL COMMENT '電子郵件',
  phone VARCHAR(15) NULL COMMENT '電話',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- 2) 器材分類表
CREATE TABLE IF NOT EXISTS equipment_categories (
  equipment_code VARCHAR(5) NOT NULL COMMENT '器材代碼(例: A1, B2)',
  equipment_name VARCHAR(150) NOT NULL COMMENT '器材名稱',
  borrow_limit_quantity INT NOT NULL DEFAULT 1 COMMENT '限借數量',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (equipment_code)
) ENGINE=InnoDB;

-- 器材分類初始化數據
INSERT IGNORE INTO equipment_categories (equipment_code, equipment_name, borrow_limit_quantity) VALUES
('A1', '喊話器(充電電池*1)', 1),
('A2', '有線麥克風', 2),
('A3', '短麥克風架', 1),
('A4', '長麥克風架', 2),
('A5', 'MIPRO 擴音器 MA-100SB(無線麥克風*1)', 1),
('A6', 'MIPRO 擴音器 MA-708(無線麥克風*2)', 1),
('A7', 'YAMAHA 擴音器 600BT(喇叭*2、無線麥克風*2)', 1),
('A8', '金嗓 卡拉 ok(無線麥克風*2)', 1),
('A9', '戶外高級音響 MA-808(喇叭*2、無線麥克風*4)', 1),
('A10', '電鋼琴', 1),
('B1', '投影布幕(長150*寬210*高240cm)', 1),
('B2', '單槍投影機', 1),
('B3', '數位相機', 1),
('B4', 'DV 攝影機', 1),
('B5', 'DV 腳架', 1),
('C1A', 'A 字看板 木(長110*寬80cm)', 2),
('C1B', 'A 字看板 鋁(長89*寬64cm)', 2),
('C2', '珍珠椅', 40),
('C3', '折疊鐵椅', 10),
('C4', '折疊長桌(長180*寬70*高75cm)', 4),
('C5', '司令帳(沙袋*2)(開-長300*寬300*高345cm)', 4),
('C6', 'TRUSS 帆布立架組(300*200cm 長方形)', 1),
('C7', '交通警示錐', 20),
('C8', '交通警示橫桿(長200cm)', 15),
('C9', '插旗組(旗桿、旗帽)', 20),
('C10', '旗座', 10),
('D1A', '地燈 黃光', 2),
('D1B', '地燈 白光', 2),
('D2', '地燈架', 2),
('D3', '七彩旋轉燈', 1),
('D4', '追蹤燈組', 1),
('E1', '延長線捲', 2),
('E2', '無線電對講機', 4),
('E3', '茶桶40L', 1),
('E4', '睡袋', NULL);

-- 3) 器材表
CREATE TABLE IF NOT EXISTS equipments (
  equipment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '器材編號(自動流水號)',
  equipment_code VARCHAR(5) NOT NULL COMMENT '器材代碼(例: A1, B2)',
  operation_status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '營運狀態: 1=可借用、2=已借出、3=維修中、4=停用中、5=已淘汰',
  operation_remark VARCHAR(255) NULL COMMENT '營運備註',
  added_date DATE NULL COMMENT '入庫日期',
  maintenance_count INT NOT NULL DEFAULT 0 COMMENT '維修次數',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (equipment_id),
  KEY idx_equipments_code (equipment_code),
  CONSTRAINT fk_equipments_code
    FOREIGN KEY (equipment_code) REFERENCES equipment_categories (equipment_code)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 4) 空間表
CREATE TABLE IF NOT EXISTS spaces (
  space_id VARCHAR(30) NOT NULL COMMENT '場地編號(教室編號)',
  space_name VARCHAR(120) NOT NULL COMMENT '空間名稱',
  capacity INT NOT NULL DEFAULT 1 COMMENT '大小/容納人數',
  space_status ENUM('available', 'maintenance', 'disabled') NOT NULL DEFAULT 'available' COMMENT '空間狀態',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (space_id),
  CONSTRAINT chk_spaces_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

-- 5) 預約總表
CREATE TABLE IF NOT EXISTS reservations (
  reservation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '預約編號',
  user_id VARCHAR(10) NOT NULL COMMENT '申請人編號(學號/教職員編號)',
  -- 活動企劃書檔案路徑（申請場地時可上傳）
  proposal_file VARCHAR(255) NULL COMMENT '上傳之活動企劃書檔案路徑',
  proposal_uploaded_at DATETIME NULL COMMENT '活動企劃書上傳時間',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '送出時間(先送先審)',
  borrow_start_at DATETIME NOT NULL COMMENT '借用開始時間',
  borrow_end_at DATETIME NOT NULL COMMENT '借用結束時間',
  approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT '預約審核狀態',
  returned_at DATETIME NULL COMMENT '歸還完成時間',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (reservation_id),
  KEY idx_reservations_applicant (user_id),
  KEY idx_reservations_time (borrow_start_at, borrow_end_at),
  CONSTRAINT chk_reservations_time CHECK (borrow_end_at > borrow_start_at),
  CONSTRAINT fk_reservations_applicant
    FOREIGN KEY (user_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6) 器材證照
CREATE TABLE IF NOT EXISTS equipment_certificates (
  certificate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '證照編號',
  holder_id VARCHAR(10) NOT NULL COMMENT '持有者編號(學號/教職員編號)',
  issue_date DATE NOT NULL COMMENT '發照日期',
  valid_until DATE NOT NULL COMMENT '有效日期',
  validity_status ENUM('valid', 'expired') NOT NULL DEFAULT 'valid' COMMENT '證照有效狀態',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (certificate_id),
  KEY idx_equipment_certificates_holder (holder_id),
  CONSTRAINT chk_equipment_certificates_dates CHECK (valid_until >= issue_date),
  CONSTRAINT fk_equipment_certificates_holder
    FOREIGN KEY (holder_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7) 器材預約明細
CREATE TABLE IF NOT EXISTS equipment_reservation_items (
  equipment_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '器材預約明細編號',
  reservation_id BIGINT UNSIGNED NOT NULL COMMENT '關聯預約總表(預約編號)',
  equipment_id BIGINT UNSIGNED NOT NULL COMMENT '關聯器材(器材編號)',
  borrow_quantity INT NOT NULL DEFAULT 1 COMMENT '借用數量',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (equipment_item_id),
  UNIQUE KEY uq_equipment_item_reservation_equipment (reservation_id, equipment_id),
  KEY idx_equipment_item_equipment (equipment_id),
  CONSTRAINT chk_equipment_item_qty CHECK (borrow_quantity > 0),
  CONSTRAINT fk_equipment_item_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_equipment_item_equipment
    FOREIGN KEY (equipment_id) REFERENCES equipments (equipment_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 8) 空間預約明細
CREATE TABLE IF NOT EXISTS space_reservation_items (
  space_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '空間預約明細編號',
  reservation_id BIGINT UNSIGNED NOT NULL COMMENT '關聯預約總表(預約編號)',
  space_id VARCHAR(30) NOT NULL COMMENT '關聯空間編號(場地/教室編號)',
  proposal_file VARCHAR(255) NULL COMMENT '上傳之活動企劃書檔案路徑',
  proposal_uploaded_at DATETIME NULL COMMENT '活動企劃書上傳時間',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (space_item_id),
  UNIQUE KEY uq_space_item_reservation_space (reservation_id, space_id),
  KEY idx_space_item_space (space_id),
  CONSTRAINT fk_space_item_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_space_item_space
    FOREIGN KEY (space_id) REFERENCES spaces (space_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 9) 審核紀錄表(審核人員用)
CREATE TABLE IF NOT EXISTS approval_logs (
  approval_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '審核紀錄編號',
  reservation_id BIGINT UNSIGNED NOT NULL COMMENT '關聯預約總表(預約編號)',
  reviewer_id VARCHAR(10) NOT NULL COMMENT '審核人員ID(學號/教職員編號)',
  reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '審核時間',
  review_result ENUM('approved', 'rejected') NOT NULL COMMENT '審核結果',
  review_comment TEXT NULL COMMENT '詳細評論/理由',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (approval_log_id),
  KEY idx_approval_logs_reservation (reservation_id),
  KEY idx_approval_logs_reviewer (reviewer_id),
  CONSTRAINT fk_approval_logs_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_approval_logs_reviewer
    FOREIGN KEY (reviewer_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 10) 器材報修
CREATE TABLE IF NOT EXISTS equipment_maintenance (
  maintenance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '維修編號',
  equipment_id BIGINT UNSIGNED NOT NULL COMMENT '關聯器材(器材編號)',
  reporter_id VARCHAR(10) NOT NULL COMMENT '報修人編號(學號/教職員編號)',
  reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '報修時間',
  fault_description TEXT NOT NULL COMMENT '故障描述',
  maintenance_status ENUM('pending', 'in_progress', 'completed') NOT NULL DEFAULT 'pending' COMMENT '報修狀態',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (maintenance_id),
  KEY idx_equipment_maintenance_equipment (equipment_id),
  KEY idx_equipment_maintenance_reporter (reporter_id),
  CONSTRAINT fk_equipment_maintenance_equipment
    FOREIGN KEY (equipment_id) REFERENCES equipments (equipment_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_equipment_maintenance_reporter
    FOREIGN KEY (reporter_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 11) 器材核簽
CREATE TABLE IF NOT EXISTS equipment_signoffs (
  signoff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '簽核編號',
  reservation_id BIGINT UNSIGNED NOT NULL COMMENT '關聯預約單(預約編號)',
  certificate_id BIGINT UNSIGNED NOT NULL COMMENT '關聯證照(證照編號)',
  reviewer_id VARCHAR(10) NOT NULL COMMENT '審核人員ID(學號/教職員編號)',
  signoff_status ENUM('approved', 'rejected', 'pending') NOT NULL DEFAULT 'pending' COMMENT '狀態(通過/不通過/待審)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (signoff_id),
  KEY idx_equipment_signoffs_reservation (reservation_id),
  KEY idx_equipment_signoffs_certificate (certificate_id),
  KEY idx_equipment_signoffs_reviewer (reviewer_id),
  CONSTRAINT fk_equipment_signoffs_reservation
    FOREIGN KEY (reservation_id) REFERENCES reservations (reservation_id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_equipment_signoffs_certificate
    FOREIGN KEY (certificate_id) REFERENCES equipment_certificates (certificate_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_equipment_signoffs_reviewer
    FOREIGN KEY (reviewer_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 12) 器材初始化數據
INSERT IGNORE INTO equipments (equipment_code, operation_status, added_date) VALUES
('A1', 1, '2025-02-01'),
('A2', 1, '2025-02-01'), ('A2', 1, '2025-02-01'), ('A2', 1, '2025-02-01'), ('A2', 1, '2025-02-01'),
('A3', 1, '2025-02-01'), ('A3', 1, '2025-02-01'), ('A3', 1, '2025-02-01'), ('A3', 1, '2025-02-01'),
('A4', 1, '2025-02-01'), ('A4', 1, '2025-02-01'), ('A4', 1, '2025-02-01'), ('A4', 1, '2025-02-01'),
('A5', 1, '2025-02-01'),
('A6', 1, '2025-02-01'),
('A7', 1, '2025-02-01'),
('A8', 1, '2025-02-01'),
('A9', 1, '2025-02-01'),
('A10', 1, '2025-02-01'),
('B1', 1, '2025-02-01'), ('B1', 1, '2025-02-01'),
('B2', 1, '2025-02-01'), ('B2', 1, '2025-02-01'),
('B3', 1, '2025-02-01'), ('B3', 1, '2025-02-01'),
('B4', 1, '2025-02-01'), ('B4', 1, '2025-02-01'),
('B5', 1, '2025-02-01'), ('B5', 1, '2025-02-01'),
('C1A', 1, '2025-02-01'), ('C1A', 1, '2025-02-01'),
('C1B', 1, '2025-02-01'), ('C1B', 1, '2025-02-01'),
('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'), ('C2', 1, '2025-02-01'),
('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'), ('C3', 1, '2025-02-01'),
('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'), ('C4', 1, '2025-02-01'),
('C5', 1, '2025-02-01'), ('C5', 1, '2025-02-01'),
('C6', 1, '2025-02-01'), ('C6', 1, '2025-02-01'),
('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'), ('C7', 1, '2025-02-01'),
('C8', 1, '2025-02-01'), ('C8', 1, '2025-02-01'), ('C8', 1, '2025-02-01'), ('C8', 1, '2025-02-01'), ('C8', 1, '2025-02-01'), ('C8', 1, '2025-02-01'),
('C9', 1, '2025-02-01'), ('C9', 1, '2025-02-01'), ('C9', 1, '2025-02-01'), ('C9', 1, '2025-02-01'),
('C10', 1, '2025-02-01'), ('C10', 1, '2025-02-01'), ('C10', 1, '2025-02-01'), ('C10', 1, '2025-02-01'),
('D1A', 1, '2025-02-01'), ('D1A', 1, '2025-02-01'), ('D1A', 1, '2025-02-01'), ('D1A', 1, '2025-02-01'),
('D1B', 1, '2025-02-01'), ('D1B', 1, '2025-02-01'), ('D1B', 1, '2025-02-01'), ('D1B', 1, '2025-02-01'),
('D2', 1, '2025-02-01'), ('D2', 1, '2025-02-01'), ('D2', 1, '2025-02-01'), ('D2', 1, '2025-02-01'),
('D3', 1, '2025-02-01'), ('D3', 1, '2025-02-01'),
('D4', 1, '2025-02-01'), ('D4', 1, '2025-02-01'),
('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'), ('E1', 1, '2025-02-01'),
('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'), ('E2', 1, '2025-02-01'),
('E3', 1, '2025-02-01'), ('E3', 1, '2025-02-01'), ('E3', 1, '2025-02-01'),
('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01'), ('E4', 1, '2025-02-01');
