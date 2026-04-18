-- 駁回或刪除預約時，自動還原器材可借狀態
-- 使用方式：在 borrowing_system 資料庫執行本檔案

USE borrowing_system;

DROP TRIGGER IF EXISTS trg_reservations_restore_equipment_on_reject;
DROP TRIGGER IF EXISTS trg_reservations_restore_equipment_on_delete;

DELIMITER $$

CREATE TRIGGER trg_reservations_restore_equipment_on_reject
BEFORE UPDATE ON reservations
FOR EACH ROW
BEGIN
    IF OLD.approval_status <> 'rejected' AND NEW.approval_status = 'rejected' THEN
        UPDATE equipments e
        INNER JOIN equipment_reservation_items eri ON eri.equipment_id = e.equipment_id
        SET e.operation_status = 1
        WHERE eri.reservation_id = OLD.reservation_id
          AND e.operation_status = 2;
    END IF;
END$$

CREATE TRIGGER trg_reservations_restore_equipment_on_delete
BEFORE DELETE ON reservations
FOR EACH ROW
BEGIN
    UPDATE equipments e
    INNER JOIN equipment_reservation_items eri ON eri.equipment_id = e.equipment_id
    SET e.operation_status = 1
    WHERE eri.reservation_id = OLD.reservation_id
      AND e.operation_status = 2;
END$$

DELIMITER ;
