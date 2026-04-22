<?php
$file = 'c:\AppServ\www\borrowing-system\borrow.php';
$content = file_get_contents($file);

$old1 = <<<'EOD'
    $formData['resource_type'] = trim((string)($_POST['resource_type'] ?? 'equipment'));
    $formData['equipment_code'] = trim((string)($_POST['equipment_code'] ?? ''));
    $formData['space_id'] = trim((string)($_POST['space_id'] ?? ''));
    $formData['borrow_quantity'] = trim((string)($_POST['borrow_quantity'] ?? '1'));
EOD;

$new1 = <<<'EOD'
    $formData['resource_type'] = trim((string)($_POST['resource_type'] ?? 'equipment'));
    $cartItemsRaw = trim((string)($_POST['cart_items'] ?? '[]'));
    $cartItems = json_decode($cartItemsRaw, true);
    if (!is_array($cartItems)) {
        $cartItems = [];
    }
    $formData['equipment_code'] = trim((string)($_POST['equipment_code'] ?? ''));
    $formData['space_id'] = trim((string)($_POST['space_id'] ?? ''));
    $formData['borrow_quantity'] = trim((string)($_POST['borrow_quantity'] ?? '1'));
EOD;
$content = str_replace($old1, $new1, $content);


$old2 = <<<'EOD'
        $selectedEquipment = null;
        $selectedSpace = null;
        $borrowQuantity = 1;

        if ($formData['resource_type'] === 'equipment') {
            if (!isset($equipmentMap[$formData['equipment_code']])) {
                $borrowError = '請選擇有效的器材項目。';
            } else {
                $selectedEquipment = $equipmentMap[$formData['equipment_code']];
                $borrowQuantity = (int)$formData['borrow_quantity'];

                // 檢查證照
                $certificateCheckSql = "
                    SELECT 1
                    FROM equipment_certificates
                    WHERE holder_id = ?
                      AND validity_status = 'valid'
                      AND CURDATE() <= valid_until
                ";
                $certStmt = mysqli_prepare($link, $certificateCheckSql);
                mysqli_stmt_bind_param($certStmt, 's', $userId);
                mysqli_stmt_execute($certStmt);
                $certResult = mysqli_stmt_get_result($certStmt);
                $hasValidCertificate = mysqli_num_rows($certResult) > 0;
                mysqli_stmt_close($certStmt);

                if (!$hasValidCertificate) {
                    $borrowError = '您沒有有效的器材證照，無法借用此器材。';
                }
                // 原有的數量檢查
                elseif ($borrowQuantity <= 0) {
                    $borrowError = '借用數量必須大於 0。';
                } elseif ($selectedEquipment['borrow_limit_quantity'] !== null && $borrowQuantity > (int)$selectedEquipment['borrow_limit_quantity']) {
                    $borrowError = '借用數量超過限借數量。';
                } elseif ($borrowQuantity > (int)$selectedEquipment['available_quantity']) {
                    $borrowError = '借用數量超過目前可借用數量。';
                } else {
                    // 檢查該使用者對此器材類別所有未拒絕預約的總借用數量
                    if ($selectedEquipment['borrow_limit_quantity'] !== null && $borrowError === '') {
                        $reservApplicantCol = $reservationApplicantColumn;
                        $totalQuantitySql = sprintf(
                            'SELECT COALESCE(SUM(eri.borrow_quantity), 0) AS total_quantity
                             FROM reservations r
                             JOIN equipment_reservation_items eri ON r.reservation_id = eri.reservation_id
                             JOIN equipments e ON eri.equipment_id = e.equipment_id
                             WHERE r.%s = ?
                               AND r.approval_status IN ("pending", "approved")
                               AND e.equipment_code = ?'
                            , $reservApplicantCol
                        );
                        $totalQuantityStmt = mysqli_prepare($link, $totalQuantitySql);
                        if ($totalQuantityStmt) {
                            mysqli_stmt_bind_param($totalQuantityStmt, 'ss', $userId, $formData['equipment_code']);
                            mysqli_stmt_execute($totalQuantityStmt);
                            $totalQuantityResult = mysqli_stmt_get_result($totalQuantityStmt);
                            $totalQuantityRow = $totalQuantityResult ? mysqli_fetch_assoc($totalQuantityResult) : null;
                            mysqli_stmt_close($totalQuantityStmt);

                            $currentTotalQuantity = $totalQuantityRow ? (int)$totalQuantityRow['total_quantity'] : 0;
                            $nextTotalQuantity = $currentTotalQuantity + $borrowQuantity;

                            if ($nextTotalQuantity > (int)$selectedEquipment['borrow_limit_quantity']) {
                                $borrowError = sprintf(
                                    '您對該器材的所有未完成預約共 %d 個，加上本 次申請 %d 個共 %d 個，已超過限借數量 %d 個，無法申請。',
                                    $currentTotalQuantity,
                                    $borrowQuantity,
                                    $nextTotalQuantity,
                                    (int)$selectedEquipment['borrow_limit_quantity']
                                );
                            }
                        }
                    }
                }
            }
        } else {
EOD;

$new2 = <<<'EOD'
        $selectedEquipment = null; // Deprecated for multi-item cart, kept for fallback
        $selectedSpace = null;
        $borrowQuantity = 1;

        if ($formData['resource_type'] === 'equipment') {
            if (empty($cartItems)) {
                $borrowError = '請選擇至少一項器材且填寫數量。';
            } else {
                // 檢查證照 (只需檢查一次)
                $certificateCheckSql = "
                    SELECT 1
                    FROM equipment_certificates
                    WHERE holder_id = ?
                      AND validity_status = 'valid'
                      AND CURDATE() <= valid_until
                ";
                $certStmt = mysqli_prepare($link, $certificateCheckSql);
                mysqli_stmt_bind_param($certStmt, 's', $userId);
                mysqli_stmt_execute($certStmt);
                $certResult = mysqli_stmt_get_result($certStmt);
                $hasValidCertificate = mysqli_num_rows($certResult) > 0;
                mysqli_stmt_close($certStmt);

                if (!$hasValidCertificate) {
                    $borrowError = '您沒有有效的器材證照，無法借用器材。';
                } else {
                    foreach ($cartItems as $item) {
                        $cCode = $item['code'] ?? '';
                        $cQty = (int)($item['quantity'] ?? 0);
                        if (!isset($equipmentMap[$cCode])) {
                            $borrowError = "請選擇有效的器材項目。";
                            break;
                        }
                        $selectedE = $equipmentMap[$cCode];
                        if ($cQty <= 0) {
                            $borrowError = "{$selectedE['equipment_name']} 借用數量必須大於 0。";
                            break;
                        }
                        if ($selectedE['borrow_limit_quantity'] !== null && $cQty > (int)$selectedE['borrow_limit_quantity']) {
                            $borrowError = "{$selectedE['equipment_name']} 借用數量超過限借數量。";
                            break;
                        }
                        if ($cQty > (int)$selectedE['available_quantity']) {
                            $borrowError = "{$selectedE['equipment_name']} 借用數量超過目前可借用數量。";
                            break;
                        }
                        
                        if ($selectedE['borrow_limit_quantity'] !== null) {
                            $reservApplicantCol = $reservationApplicantColumn;
                            $tqSql = sprintf(
                                'SELECT COALESCE(SUM(eri.borrow_quantity), 0) AS total_quantity
                                 FROM reservations r
                                 JOIN equipment_reservation_items eri ON r.reservation_id = eri.reservation_id
                                 JOIN equipments e ON eri.equipment_id = e.equipment_id
                                 WHERE r.%s = ?
                                   AND r.approval_status IN ("pending", "approved")
                                   AND e.equipment_code = ?',
                                $reservApplicantCol
                            );
                            $tqStmt = mysqli_prepare($link, $tqSql);
                            if ($tqStmt) {
                                mysqli_stmt_bind_param($tqStmt, 'ss', $userId, $cCode);
                                mysqli_stmt_execute($tqStmt);
                                $tqRes = mysqli_stmt_get_result($tqStmt);
                                $tqRow = $tqRes ? mysqli_fetch_assoc($tqRes) : null;
                                mysqli_stmt_close($tqStmt);
                                $cTotal = $tqRow ? (int)$tqRow['total_quantity'] : 0;
                                $nTotal = $cTotal + $cQty;
                                if ($nTotal > (int)$selectedE['borrow_limit_quantity']) {
                                    $borrowError = sprintf(
                                        '對 %s 加上本次申請共 %d 個，已超過限借 %d 個，無法申請。',
                                        $selectedE['equipment_name'], $nTotal, (int)$selectedE['borrow_limit_quantity']
                                    );
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        } else {
EOD;

$content = str_replace($old2, $new2, $content);

$old3 = <<<'EOD'
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
                }
EOD;

$new3 = <<<'EOD'
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
                    if (!$stockCheckStmt || !$selectEquipmentStmt || !$reservationItemStmt) {
                        throw new RuntimeException('建立器材預約明細指令備製失敗：' . mysqli_error($link));
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
                            throw new RuntimeException('目前可借用數量不足，無法送出申請。');
                        }

                        mysqli_stmt_bind_param($selectEquipmentStmt, 'si', $cCode, $cQty);
                        mysqli_stmt_execute($selectEquipmentStmt);
                        $availableEquipmentResult = mysqli_stmt_get_result($selectEquipmentStmt);

                        $equipmentIds = [];
                        while ($equipmentRow = mysqli_fetch_assoc($availableEquipmentResult)) {
                            $equipmentIds[] = (int)$equipmentRow['equipment_id'];
                        }

                        if (count($equipmentIds) < $cQty) {
                            throw new RuntimeException('目前可借器材不足，請調整借用數量。');
                        }

                        foreach ($equipmentIds as $equipmentId) {
                            mysqli_stmt_bind_param($reservationItemStmt, 'ii', $reservationId, $equipmentId);
                            mysqli_stmt_execute($reservationItemStmt);
                        }
                    }
                    mysqli_stmt_close($stockCheckStmt);
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($reservationItemStmt);
                }
EOD;

$content = str_replace($old3, $new3, $content);

file_put_contents($file, $content);
echo "PHP replacement done.";
?>
