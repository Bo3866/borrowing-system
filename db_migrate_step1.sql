
USE borrowing_system;

ALTER TABLE reservations 
ADD COLUMN organization_name VARCHAR(100) NULL COMMENT '單位名稱 / 主辦社團',
ADD COLUMN activity_name VARCHAR(100) NULL COMMENT '活動名稱',
ADD COLUMN participant_count VARCHAR(20) NULL COMMENT '活動對象人數',
ADD COLUMN staff_count INT NULL COMMENT '工作人員人數',
ADD COLUMN club_president VARCHAR(50) NULL COMMENT '社 / 會長';

