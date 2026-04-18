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
  user_id VARCHAR(30) NOT NULL COMMENT '學號/教職員編號',
  full_name VARCHAR(100) NOT NULL COMMENT '姓名',
  role_name VARCHAR(30) NOT NULL COMMENT '角色',
  email VARCHAR(255) NOT NULL COMMENT '電子郵件',
  phone VARCHAR(30) NULL COMMENT '電話',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- 2) 器材分類表
CREATE TABLE IF NOT EXISTS equipment_categories (
  category_code VARCHAR(20) NOT NULL COMMENT '分類代碼，例: CAM',
  category_name VARCHAR(100) NOT NULL COMMENT '分類名稱',
  code_prefix VARCHAR(20) NOT NULL COMMENT '編號前綴，例: CAM',
  category_description TEXT NULL COMMENT '類別描述',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (category_code),
  UNIQUE KEY uq_equipment_categories_prefix (code_prefix)
) ENGINE=InnoDB;

-- 3) 器材表
CREATE TABLE IF NOT EXISTS equipments (
  equipment_id VARCHAR(40) NOT NULL COMMENT '器材編號(類別-年份-流水號)',
  category_code VARCHAR(20) NOT NULL COMMENT '所屬分類(分類代碼)',
  equipment_name VARCHAR(150) NOT NULL COMMENT '器材名稱',
  total_quantity INT NOT NULL DEFAULT 1 COMMENT '總數量',
  available_quantity INT NOT NULL DEFAULT 1 COMMENT '目前可用數量',
  operation_status ENUM('normal', 'maintenance', 'disabled') NOT NULL DEFAULT 'normal' COMMENT '營運狀態: 正常/維修中/停用中',
  is_retired TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否汰除(0:否,1:是)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (equipment_id),
  KEY idx_equipments_category (category_code),
  KEY idx_equipments_name (equipment_name),
  CONSTRAINT chk_equipments_quantity CHECK (total_quantity >= 0 AND available_quantity >= 0 AND available_quantity <= total_quantity),
  CONSTRAINT fk_equipments_category
    FOREIGN KEY (category_code) REFERENCES equipment_categories (category_code)
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
  applicant_id VARCHAR(30) NOT NULL COMMENT '申請人編號(學號/教職員編號)',
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '送出時間(先送先審)',
  borrow_start_at DATETIME NOT NULL COMMENT '借用開始時間',
  borrow_end_at DATETIME NOT NULL COMMENT '借用結束時間',
  approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT '預約審核狀態',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (reservation_id),
  KEY idx_reservations_applicant (applicant_id),
  KEY idx_reservations_time (borrow_start_at, borrow_end_at),
  CONSTRAINT chk_reservations_time CHECK (borrow_end_at > borrow_start_at),
  CONSTRAINT fk_reservations_applicant
    FOREIGN KEY (applicant_id) REFERENCES users (user_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 6) 器材證照
CREATE TABLE IF NOT EXISTS equipment_certificates (
  certificate_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '證照編號',
  holder_id VARCHAR(30) NOT NULL COMMENT '持有者編號(學號/教職員編號)',
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
  equipment_id VARCHAR(40) NOT NULL COMMENT '關聯器材(器材編號)',
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
  reviewer_id VARCHAR(30) NOT NULL COMMENT '審核人員ID(學號/教職員編號)',
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
  equipment_id VARCHAR(40) NOT NULL COMMENT '關聯器材(器材編號)',
  reporter_id VARCHAR(30) NOT NULL COMMENT '報修人編號(學號/教職員編號)',
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
  reviewer_id VARCHAR(30) NOT NULL COMMENT '審核人員ID(學號/教職員編號)',
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

-- 12) 器材一覽表：每種類別只保留前 10 個器材
-- MySQL 8.0+ 視窗函數版本
CREATE OR REPLACE VIEW equipment_overview_top10 AS
SELECT
  ranked.category_code,
  ranked.category_name,
  ranked.equipment_id,
  ranked.equipment_name,
  ranked.total_quantity,
  ranked.available_quantity,
  ranked.operation_status,
  ranked.is_retired
FROM (
  SELECT
    c.category_code,
    c.category_name,
    e.equipment_id,
    e.equipment_name,
    e.total_quantity,
    e.available_quantity,
    e.operation_status,
    e.is_retired,
    ROW_NUMBER() OVER (
      PARTITION BY c.category_code
      ORDER BY e.equipment_id ASC
    ) AS rn
  FROM equipment_categories c
  INNER JOIN equipments e
    ON e.category_code = c.category_code
) AS ranked
WHERE ranked.rn <= 10;

-- 若要即時查詢（不透過 view），可使用：
-- SELECT * FROM equipment_overview_top10 ORDER BY category_code, equipment_id;
