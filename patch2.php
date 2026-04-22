<?php
$file = 'c:\AppServ\www\borrowing-system\borrow.php';
$content = file_get_contents($file);

$content = preg_replace('/if \(\$formData\[\'resource_type\'\] === \'equipment\'\) \{.+?\}\s+\} else \{/s', <<<'EOD'
if ($formData['resource_type'] === 'equipment') {
            if (empty($cartItems)) {
                $borrowError = '請選擇至少一項器材且填寫數量。';
            } else {
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
                            $borrowError = "找不到器材：{$cCode}。";
                            break;
                        }
                        $selectedE = $equipmentMap[$cCode];
                        $selectedEquipment = $selectedE;
                        
                        if ($cQty <= 0) {
                            $borrowError = "{$selectedE['equipment_name']} 借用數量須大於 0。";
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
                                   AND e.equipment_code = ?'
                                , $reservApplicantCol
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
                                        '%s 未完成預約共 %d 個，加上本次申請 %d 個共 %d 個，超過限借數量 %d 個。',
                                        $selectedE['equipment_name'],
                                        $cTotal,
                                        $cQty,
                                        $nTotal,
                                        (int)$selectedE['borrow_limit_quantity']
                                    );
                                    break;
                                }
                            }
                        }
                    }
                }
            }
        } else {
EOD
, $content, 1);

// DB Insertion replacement
$content = preg_replace('/if \(\$formData\[\'resource_type\'\] === \'equipment\'\) \{.+?\}\s+\$reservApplicantCol = \$reservationApplicantColumn;/s', <<<'EOD'
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
                        throw new RuntimeException('建立器材預約明細指令失敗：' . mysqli_error($link));
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
                            throw new RuntimeException('目前可借器材不足，請調整數量。');
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

                $reservApplicantCol = $reservationApplicantColumn;
EOD
, $content, 1);

file_put_contents($file, $content);
echo "Regex patching complete!";
?>
