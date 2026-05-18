USE borrowing_system;

ALTER TABLE reservations 
ADD COLUMN  organization_name VARCHAR(100) NULL COMMENT '單位名稱 / 主辦社團',
ADD COLUMN  activity_name VARCHAR(100) NULL COMMENT '活動名稱',
ADD COLUMN  participant_count VARCHAR(20) NULL COMMENT '活動對象人數',
ADD COLUMN  staff_count INT NULL COMMENT '工作人員人數',
ADD COLUMN  club_president VARCHAR(50) NULL COMMENT '社 / 會長',
ADD COLUMN  activity_coordinator VARCHAR(50) NULL COMMENT '活動聯絡人',
ADD COLUMN  coordinator_department VARCHAR(50) NULL COMMENT '聯絡人系級',
ADD COLUMN  coordinator_phone VARCHAR(50) NULL COMMENT '聯絡人手機',
ADD COLUMN  coordinator_other_contact VARCHAR(255) NULL COMMENT '其他聯絡方式',
ADD COLUMN  vehicle_entry VARCHAR(10) NULL COMMENT '汽車進入校園',
ADD COLUMN  setup_flags VARCHAR(10) NULL COMMENT '是否插旗',
ADD COLUMN  flag_details VARCHAR(255) NULL COMMENT '插旗地點及數量',
ADD COLUMN  purpose VARCHAR(255) NULL COMMENT '借用用途';

-- Add missing boolean flags for application (has_alcohol, has_fire, has_sales)
ALTER TABLE reservations
	ADD COLUMN  has_alcohol VARCHAR(1) NULL COMMENT '是否含酒精',
	ADD COLUMN  has_fire VARCHAR(1) NULL COMMENT '是否有明火',
	ADD COLUMN  has_sales VARCHAR(1) NULL COMMENT '是否販售活動';
