<?php
$file = 'c:\AppServ\www\borrowing-system\borrow.php';
$content = file_get_contents($file);

$old = <<<'EOD'
                if ($formData['resource_type'] === 'equipment') {
                    $stockCheckStmt = mysqli_prepare(
                        $link,
                        'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                    );
                    if (!$stockCheckStmt) {
                        throw new RuntimeException('檢查器材庫存失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($stockCheckStmt, 's', $formData['equipment_code']);
                    mysqli_stmt_execute($stockCheckStmt);
                    $stockCheckResult = mysqli_stmt_get_result($stockCheckStmt);
                    $stockRow = $stockCheckResult ? mysqli_fetch_assoc($stockCheckResult) : null;
                    mysqli_stmt_close($stockCheckStmt);

                    $availableCountInTransaction = $stockRow ? (int)$stockRow['available_count'] : 0;
                    if ($availableCountInTransaction <= 0) {
                        throw new RuntimeException('目前可借用數量為 0，無法送出申請。');
                    }
                    if ($availableCountInTransaction < $borrowQuantity) {
                        throw new RuntimeException('目前可借用數量不足，請調整借用數量。');
                    }

                    $selectEquipmentStmt = mysqli_prepare(
                        $link,
                        'SELECT equipment_id FROM equipments WHERE equipment_code = ? AND operation_status = 1 ORDER BY equipment_id ASC LIMIT ?'
                    );
                    if (!$selectEquipmentStmt) {
                        throw new RuntimeException('讀取可借器材失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($selectEquipmentStmt, 'si', $formData['equipment_code'], $borrowQuantity);
                    mysqli_stmt_execute($selectEquipmentStmt);
                    $availableEquipmentResult = mysqli_stmt_get_result($selectEquipmentStmt);

                    $equipmentIds = [];
                    while ($equipmentRow = mysqli_fetch_assoc($availableEquipmentResult)) {
                        $equipmentIds[] = (int)$equipmentRow['equipment_id'];
                    }
                    mysqli_stmt_close($selectEquipmentStmt);

                    if (count($equipmentIds) < $borrowQuantity) {
                        throw new RuntimeException('目前可借器材不足，請調整借用數量。');
                    }

                    $reservationItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id, borrow_quantity) VALUES (?, ?, 1)'
                    );
                    if (!$reservationItemStmt) {
                        throw new RuntimeException('建立器材預約明細失敗：' . mysqli_error($link));
                    }

                    foreach ($equipmentIds as $equipmentId) {
                        mysqli_stmt_bind_param($reservationItemStmt, 'ii', $reservationId, $equipmentId);
                        mysqli_stmt_execute($reservationItemStmt);
                    }
                    mysqli_stmt_close($reservationItemStmt);

                    $updateEquipmentStatusStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1 AND ? <= NOW()'
                    );
                    if (!$updateEquipmentStatusStmt) {
                        throw new RuntimeException('更新器材可借狀態失敗：' . mysqli_error($link));
                    }

                    foreach ($equipmentIds as $equipmentId) {
                        mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'is', $equipmentId, $borrowStartAtSql);
                        mysqli_stmt_execute($updateEquipmentStatusStmt);

                        // 如果是未來預約，不會更新，所以affected_rows可能為0， 但不拋錯
                        // if (mysqli_stmt_affected_rows($updateEquipmentStatusStmt) !== 1) {
                        //     throw new RuntimeException('器材狀態更新異常，請 重新送出申請。');
                        // }
                    }
                    mysqli_stmt_close($updateEquipmentStatusStmt);
EOD;

$new = <<<'EOD'
                if ($formData['resource_type'] === 'equipment') {
                    $stockCheckStmt = mysqli_prepare(
                        $link,
                        'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                    );
                    $selectEquipmentStmt = mysqli_prepare(
                        $link,
                        'SELECT equipment_id FROM equipments WHERE equipment_code = ? AND operation_status = 1 ORDER BY equipment_id ASC LIMIT ?'
                    );
                    $reservationItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id, borrow_quantity) VALUES (?, ?, 1)'
                    );
                    $updateEquipmentStatusStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1 AND ? <= NOW()'
                    );
                    if (!$stockCheckStmt || !$selectEquipmentStmt || !$reservationItemStmt || !$updateEquipmentStatusStmt) {
                        throw new RuntimeException('建立器材預約指令失敗：' . mysqli_error($link));
                    }

                    foreach ($cartItems as $item) {
                        $cCode = $item['code'];
                        $cQty = (int)$item['quantity'];

                        mysqli_stmt_bind_param($stockCheckStmt, 's', $cCode);
                        mysqli_stmt_execute($stockCheckStmt);
                        $stockCheckResult = mysqli_stmt_get_result($stockCheckStmt);
                        $stockRow = $stockCheckResult ? mysqli_fetch_assoc($stockCheckResult) : null;

                        $availableCountInTransaction = $stockRow ? (int)$stockRow['available_count'] : 0;
                        if ($availableCountInTransaction < $cQty) {
                            throw new RuntimeException("器材 {$cCode} 目前可借用數量不足，無法送出申請。");
                        }

                        mysqli_stmt_bind_param($selectEquipmentStmt, 'si', $cCode, $cQty);
                        mysqli_stmt_execute($selectEquipmentStmt);
                        $availableEquipmentResult = mysqli_stmt_get_result($selectEquipmentStmt);

                        $equipmentIds = [];
                        while ($equipmentRow = mysqli_fetch_assoc($availableEquipmentResult)) {
                            $equipmentIds[] = (int)$equipmentRow['equipment_id'];
                        }

                        if (count($equipmentIds) < $cQty) {
                            throw new RuntimeException("器材 {$cCode} 實際可取得數量不足。");
                        }

                        foreach ($equipmentIds as $equipmentId) {
                            mysqli_stmt_bind_param($reservationItemStmt, 'ii', $reservationId, $equipmentId);
                            mysqli_stmt_execute($reservationItemStmt);
                            
                            mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'is', $equipmentId, $borrowStartAtSql);
                            mysqli_stmt_execute($updateEquipmentStatusStmt);
                        }
                    }
                    mysqli_stmt_close($stockCheckStmt);
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($reservationItemStmt);
                    mysqli_stmt_close($updateEquipmentStatusStmt);
EOD;

$content2 = str_replace($old, $new, $content);
if ($content === $content2) {
    echo "REPLACE FAILED!";
} else {
    file_put_contents($file, $content2);
    echo "REPLACE SUCCESS!";
}
?>
