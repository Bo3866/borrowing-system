<?php
$f = "c:/AppServ/www/borrowing-system/borrow.php";
$c = file_get_contents($f);
$parts = explode("if (\$formData['resource_type'] === 'equipment') {\r\n                    \$stockCheckStmt", $c);

if (count($parts) > 1) {
    $before = $parts[0];
    
    $parts2 = explode("                    // 嘗試建立器材核簽紀錄", $parts[1]);
    $after = "                    // 嘗試建立器材核簽紀錄" . $parts2[1];
    
    $mid = "if (\$formData['resource_type'] === 'equipment') {
                    \$stockCheckStmt = mysqli_prepare(
                        \$link,
                        'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                    );
                    \$selectEquipmentStmt = mysqli_prepare(
                        \$link,
                        'SELECT equipment_id FROM equipments WHERE equipment_code = ? AND operation_status = 1 ORDER BY equipment_id ASC LIMIT ?'
                    );
                    \$reservationItemStmt = mysqli_prepare(
                        \$link,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id, borrow_quantity) VALUES (?, ?, 1)'
                    );
                    \$updateEquipmentStatusStmt = mysqli_prepare(
                        \$link,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1 AND ? <= NOW()'
                    );
                    if (!\$stockCheckStmt || !\$selectEquipmentStmt || !\$reservationItemStmt || !\$updateEquipmentStatusStmt) {
                        throw new RuntimeException('建立器材預約明細指令失敗：' . mysqli_error(\$link));
                    }

                    foreach (\$cartItems as \$item) {
                        \$cCode = \$item['code'];
                        \$cQty = (int)\$item['quantity'];

                        mysqli_stmt_bind_param(\$stockCheckStmt, 's', \$cCode);
                        mysqli_stmt_execute(\$stockCheckStmt);
                        \$stockCheckResult = mysqli_stmt_get_result(\$stockCheckStmt);
                        \$stockRow = \$stockCheckResult ? mysqli_fetch_assoc(\$stockCheckResult) : null;

                        \$availableCountInTransaction = \$stockRow ? (int)\$stockRow['available_count'] : 0;
                        if (\$availableCountInTransaction < \$cQty) {
                            throw new RuntimeException(\"器材 {\$cCode} 目前可借用數量不足，無法送出申請。\");
                        }

                        mysqli_stmt_bind_param(\$selectEquipmentStmt, 'si', \$cCode, \$cQty);
                        mysqli_stmt_execute(\$selectEquipmentStmt);
                        \$availableEquipmentResult = mysqli_stmt_get_result(\$selectEquipmentStmt);

                        \$equipmentIds = [];
                        while (\$equipmentRow = mysqli_fetch_assoc(\$availableEquipmentResult)) {
                            \$equipmentIds[] = (int)\$equipmentRow['equipment_id'];
                        }

                        if (count(\$equipmentIds) < \$cQty) {
                            throw new RuntimeException(\"器材 {\$cCode} 實際可取得數量不足。\");
                        }

                        foreach (\$equipmentIds as \$equipmentId) {
                            mysqli_stmt_bind_param(\$reservationItemStmt, 'ii', \$reservationId, \$equipmentId);
                            mysqli_stmt_execute(\$reservationItemStmt);
                            
                            mysqli_stmt_bind_param(\$updateEquipmentStatusStmt, 'is', \$equipmentId, \$borrowStartAtSql);
                            mysqli_stmt_execute(\$updateEquipmentStatusStmt);
                        }
                    }
                    mysqli_stmt_close(\$stockCheckStmt);
                    mysqli_stmt_close(\$selectEquipmentStmt);
                    mysqli_stmt_close(\$reservationItemStmt);
                    mysqli_stmt_close(\$updateEquipmentStatusStmt);
                }\r\n";
    file_put_contents($f, $before . $mid . $after);
    echo "SUCCESS\n";
} else {
    echo "FAILED SPLIT\n";
}
?>
