<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=borrow.php');
    exit;
}

$userId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$roleName = (string)($_SESSION['role_name'] ?? '');

// 節次設定：可依附件節次代號與時間調整
$periodSlots = [
    'D0' => ['label' => '日間第0節', 'start' => '07:10:00', 'end' => '08:00:00'],
    'D1' => ['label' => '日間第1節', 'start' => '08:10:00', 'end' => '09:00:00'],
    'D2' => ['label' => '日間第2節', 'start' => '09:10:00', 'end' => '10:00:00'],
    'D3' => ['label' => '日間第3節', 'start' => '10:10:00', 'end' => '11:00:00'],
    'D4' => ['label' => '日間第4節', 'start' => '11:10:00', 'end' => '12:00:00'],
    'DN' => ['label' => '日間第5節', 'start' => '12:40:00', 'end' => '13:30:00'],
    'D5' => ['label' => '日間第6節', 'start' => '13:40:00', 'end' => '14:30:00'],
    'D6' => ['label' => '日間第7節', 'start' => '14:40:00', 'end' => '15:30:00'],
    'D7' => ['label' => '日間第8節', 'start' => '15:40:00', 'end' => '16:30:00'],
    'D8' => ['label' => '夜間第1節', 'start' => '16:40:00', 'end' => '17:30:00'],
    'E0' => ['label' => '夜間第2節', 'start' => '17:40:00', 'end' => '18:30:00'],
    'E1' => ['label' => '夜間第3節', 'start' => '18:40:00', 'end' => '19:30:00'],
    'E2' => ['label' => '夜間第4節', 'start' => '19:35:00', 'end' => '20:20:00'],
    'E3' => ['label' => '夜間第5節', 'start' => '20:30:00', 'end' => '21:20:00'],
    'E4' => ['label' => '夜間第6節', 'start' => '21:25:00', 'end' => '22:10:00'],
];
$periodOrder = array_keys($periodSlots);

$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system');
$dbError = '';
if (!$link) {
    $dbError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');
}

$equipmentMap = [];
$spaceMap = [];
if ($dbError === '') {
        $equipmentSql = "
        SELECT
            ec.equipment_code,
            ec.equipment_name,
            ec.borrow_limit_quantity,
            COALESCE(e_stats.total_count, 0) - COALESCE(eri_stats.reserved_count, 0) AS available_quantity
        FROM equipment_categories ec
        LEFT JOIN (
            SELECT equipment_code, COUNT(*) AS total_count
            FROM equipments
            WHERE operation_status = 1
            GROUP BY equipment_code
        ) e_stats ON e_stats.equipment_code = ec.equipment_code
        LEFT JOIN (
            SELECT e.equipment_code, SUM(eri.borrow_quantity) AS reserved_count
            FROM equipment_reservation_items eri
            JOIN equipments e ON e.equipment_id = eri.equipment_id
            JOIN reservations r ON eri.reservation_id = r.reservation_id
            WHERE r.borrow_start_at <= NOW()
              AND r.borrow_end_at > NOW()
              AND r.approval_status IN ('pending', 'approved')
            GROUP BY e.equipment_code
        ) eri_stats ON eri_stats.equipment_code = ec.equipment_code
        ORDER BY ec.equipment_code ASC
    ";

    $equipmentResult = mysqli_query($link, $equipmentSql);
    if ($equipmentResult) {
        while ($row = mysqli_fetch_assoc($equipmentResult)) {
            $code = (string)$row['equipment_code'];
            $limit = $row['borrow_limit_quantity'] !== null ? (int)$row['borrow_limit_quantity'] : null;
            $equipmentMap[$code] = [
                'equipment_code' => $code,
                'equipment_name' => (string)$row['equipment_name'],
                'borrow_limit_quantity' => $limit,
                'available_quantity' => (int)$row['available_quantity'],
            ];
        }
    } else {
        $dbError = '讀取器材資料失敗：' . mysqli_error($link);
    }

    if ($dbError === '') {
        $spaceSql = "
            SELECT
                s.space_id,
                s.space_name,
                s.capacity,
                s.space_status
            FROM spaces s
            WHERE s.space_status IN ('available', '1')
            ORDER BY s.space_id ASC
        ";

        $spaceResult = mysqli_query($link, $spaceSql);
        if ($spaceResult) {
            while ($row = mysqli_fetch_assoc($spaceResult)) {
                $spaceId = (string)$row['space_id'];
                $spaceMap[$spaceId] = [
                    'space_id' => $spaceId,
                    'space_name' => (string)$row['space_name'],
                    'capacity' => (int)$row['capacity'],
                    'space_status' => (string)$row['space_status'],
                ];
            }
        } else {
            $dbError = '讀取空間資料失敗：' . mysqli_error($link);
        }
    }
}

$reservationApplicantColumn = 'user_id';

$borrowError = '';
$borrowSuccess = '';
$formData = [
    'resource_type' => 'equipment',
    'equipment_code' => '',
    'space_id' => '',
    'borrow_quantity' => '1',
    'borrow_date' => '',
    'start_period_code' => '',
    'end_period_code' => '',
    'purpose' => '',
    'phone' => '',
    'cart_items' => '[]',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['resource_type'] = trim((string)($_POST['resource_type'] ?? 'equipment'));
    $formData['equipment_code'] = trim((string)($_POST['equipment_code'] ?? ''));
    $formData['space_id'] = trim((string)($_POST['space_id'] ?? ''));
    $formData['borrow_quantity'] = trim((string)($_POST['borrow_quantity'] ?? '1'));
    $formData['borrow_date'] = trim((string)($_POST['borrow_date'] ?? ''));
    $formData['start_period_code'] = trim((string)($_POST['start_period_code'] ?? ''));
    $formData['end_period_code'] = trim((string)($_POST['end_period_code'] ?? ''));
    $formData['purpose'] = trim((string)($_POST['purpose'] ?? ''));
    $formData['phone'] = trim((string)($_POST['phone'] ?? ''));
    $formData['cart_items'] = trim((string)($_POST['cart_items'] ?? '[]'));

    if ($dbError !== '') {
        $borrowError = $dbError;
    } elseif (!in_array($formData['resource_type'], ['equipment', 'space'], true)) {
        $borrowError = '請選擇有效的借用類型。';
    } else {
        // 先計算借用時間（用於後續驗證）
        $borrowStartAtSql = '';
        $borrowEndAtSql = '';
        
        if (
            $formData['borrow_date'] !== '' &&
            $formData['start_period_code'] !== '' &&
            $formData['end_period_code'] !== ''
        ) {
            if (isset($periodSlots[$formData['start_period_code']]) && isset($periodSlots[$formData['end_period_code']])) {
                $startIndex = array_search($formData['start_period_code'], $periodOrder, true);
                $endIndex = array_search($formData['end_period_code'], $periodOrder, true);
                if ($startIndex !== false && $endIndex !== false && $endIndex >= $startIndex) {
                    $borrowStartAtSql = $formData['borrow_date'] . ' ' . $periodSlots[$formData['start_period_code']]['start'];
                    $borrowEndAtSql = $formData['borrow_date'] . ' ' . $periodSlots[$formData['end_period_code']]['end'];
                }
            }
        }

        $selectedEquipment = null;
        $selectedSpace = null;
        $borrowQuantity = 1;
        $equipmentCart = [];
        $cartSummary = [];

        if ($formData['resource_type'] === 'equipment') {
            $equipmentCart = json_decode($formData['cart_items'], true);
            if (!is_array($equipmentCart) || count($equipmentCart) === 0) {
                $borrowError = '請先將器材加入借用清單。';
            } else {
                foreach ($equipmentCart as $item) {
                    $code = (string)($item['code'] ?? '');
                    $qty = (int)($item['quantity'] ?? 0);
                    if ($qty <= 0) continue;
                    if (!isset($cartSummary[$code])) {
                        $cartSummary[$code] = 0;
                    }
                    $cartSummary[$code] += $qty;
                }
                
                if (count($cartSummary) === 0) {
                    $borrowError = '借用清單無效。';
                } else {
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
                        $borrowError = '您沒有有效的器材證照，無法借用器材。';
                    } else {
                        foreach ($cartSummary as $code => $qty) {
                            if (!isset($equipmentMap[$code])) {
                                $borrowError = '清單包含無效的器材項目。';
                                break;
                            }
                            $selEq = $equipmentMap[$code];
                            if ($selEq['borrow_limit_quantity'] !== null && $qty > (int)$selEq['borrow_limit_quantity']) {
                                $borrowError = "{$selEq['equipment_name']} 借用數量超過限借數量。";
                                break;
                            }
                            if ($qty > (int)$selEq['available_quantity']) {
                                $borrowError = "{$selEq['equipment_name']} 借用數量超過目前可借用數量。";
                                break;
                            }
                            // 檢查該使用者對此器材類別所有未拒絕預約的總借用數量
                            if ($selEq['borrow_limit_quantity'] !== null) {
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
                                    mysqli_stmt_bind_param($totalQuantityStmt, 'ss', $userId, $code);
                                    mysqli_stmt_execute($totalQuantityStmt);
                                    $totalQuantityResult = mysqli_stmt_get_result($totalQuantityStmt);
                                    $totalQuantityRow = $totalQuantityResult ? mysqli_fetch_assoc($totalQuantityResult) : null;
                                    mysqli_stmt_close($totalQuantityStmt);
        
                                    $currTotal = $totalQuantityRow ? (int)$totalQuantityRow['total_quantity'] : 0;
                                    $nextTotal = $currTotal + $qty;
        
                                    if ($nextTotal > (int)$selEq['borrow_limit_quantity']) {
                                        $borrowError = sprintf(
                                            '您對 %s 的所有未完成預約共 %d 個，加上本次申請 %d 個共 %d 個，已超過限借數量 %d 個，無法申請。',
                                            $selEq['equipment_name'],
                                            $currTotal,
                                            $qty,
                                            $nextTotal,
                                            (int)$selEq['borrow_limit_quantity']
                                        );
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        } else {
                if (!isset($spaceMap[$formData['space_id']])) {
                $borrowError = '請選擇有效的空間項目。';
            } else {
                $selectedSpace = $spaceMap[$formData['space_id']];
                $spaceStatusVal = (string)$selectedSpace['space_status'];
                // Allow borrow when status is textual 'available' or numeric '1'.
                // Treat other values (eg 'maintenance','disabled','2') as not borrowable.
                if (!in_array($spaceStatusVal, ['available', '1'], true)) {
                    $borrowError = '所選空間目前不可借用。';
                }
            }
        }

        if ($borrowError !== '') {
            // Keep the first validation error.
        } elseif (
            $formData['borrow_date'] === '' ||
            $formData['start_period_code'] === '' ||
            $formData['end_period_code'] === ''
        ) {
            $borrowError = '請完整填寫借用日期與起訖節次。';
        } elseif (!isset($periodSlots[$formData['start_period_code']]) || !isset($periodSlots[$formData['end_period_code']])) {
            $borrowError = '節次代號無效，請重新選擇。';
        } elseif ($formData['purpose'] === '') {
            $borrowError = '請填寫用途說明。';
        } else {
            $startIndex = array_search($formData['start_period_code'], $periodOrder, true);
            $endIndex = array_search($formData['end_period_code'], $periodOrder, true);

            if ($startIndex === false || $endIndex === false || $endIndex < $startIndex) {
                $borrowError = '結束節次不可早於開始節次。';
            } else {
                $submittedResourceType = $formData['resource_type'];
            }
        }

        if ($borrowError === '') {

            mysqli_begin_transaction($link);

            try {
                $uploadedProposalPath = null;

                // 確保 space_reservation_items 表有 proposal_file 和 proposal_uploaded_at 欄位
                $proposalFileColumnResult = mysqli_query($link, "SHOW COLUMNS FROM space_reservation_items LIKE 'proposal_file'");
                if (!($proposalFileColumnResult && mysqli_num_rows($proposalFileColumnResult) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE space_reservation_items ADD COLUMN proposal_file VARCHAR(255) NULL COMMENT '上傳之活動企劃書檔案路徑' AFTER space_id")) {
                        throw new RuntimeException('無法建立 space_reservation_items.proposal_file 欄位：' . mysqli_error($link));
                    }
                }
                $proposalUploadedAtColumnResult = mysqli_query($link, "SHOW COLUMNS FROM space_reservation_items LIKE 'proposal_uploaded_at'");
                if (!($proposalUploadedAtColumnResult && mysqli_num_rows($proposalUploadedAtColumnResult) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE space_reservation_items ADD COLUMN proposal_uploaded_at DATETIME NULL COMMENT '活動企劃書上傳時間' AFTER proposal_file")) {
                        throw new RuntimeException('無法建立 space_reservation_items.proposal_uploaded_at 欄位：' . mysqli_error($link));
                    }
                }

                if ($formData['phone'] !== '') {
                    $updatePhoneStmt = mysqli_prepare($link, 'UPDATE users SET phone = ? WHERE user_id = ?');
                    if (!$updatePhoneStmt) {
                        throw new RuntimeException('更新聯絡電話失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($updatePhoneStmt, 'ss', $formData['phone'], $userId);
                    mysqli_stmt_execute($updatePhoneStmt);
                    mysqli_stmt_close($updatePhoneStmt);
                }
                $applicantColumn = $reservationApplicantColumn;

                // 檢查 reservations 表是否有 purpose 與 certificate_id 欄位，視情況決定 INSERT 欄位
                $reservationCols = [];
                $colRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
                if ($colRes) {
                    while ($crow = mysqli_fetch_assoc($colRes)) {
                        $reservationCols[] = (string)$crow['Field'];
                    }
                }
                $hasPurposeCol = in_array('purpose', $reservationCols, true);
                $hasCertificateIdCol = in_array('certificate_id', $reservationCols, true);

                $insertCols = [$applicantColumn, 'borrow_start_at', 'borrow_end_at'];
                $bindValues = [$userId, $borrowStartAtSql, $borrowEndAtSql];
                $bindTypes = 'sss';

                if ($hasPurposeCol) {
                    $insertCols[] = 'purpose';
                    $bindValues[] = $formData['purpose'];
                    $bindTypes .= 's';
                }

                $colsSql = implode(", ", $insertCols) . ", approval_status, created_at";
                if ($hasCertificateIdCol) {
                    $colsSql .= ", certificate_id";
                }

                $placeholders = implode(', ', array_fill(0, count($insertCols), '?')) . ', "pending", NOW()' . ($hasCertificateIdCol ? ', NULL' : '');

                $insertReservationSql = sprintf("INSERT INTO reservations ( %s ) VALUES (%s)", $colsSql, $placeholders);

                $reservationStmt = mysqli_prepare($link, $insertReservationSql);
                if (!$reservationStmt) {
                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_error($link));
                }

                mysqli_stmt_bind_param($reservationStmt, $bindTypes, ...$bindValues);
                mysqli_stmt_execute($reservationStmt);
                $reservationId = (int)mysqli_insert_id($link);
                mysqli_stmt_close($reservationStmt);

                // 企劃書相關變數
                $proposalFileForSpace = null;
                $proposalUploadedAtForSpace = null;

                // 若為申請空間且有上傳企劃書，處理上傳並更新 reservations
                if ($formData['resource_type'] === 'space') {
                    if (!isset($_FILES['proposal_file']) || $_FILES['proposal_file']['error'] === UPLOAD_ERR_NO_FILE) {
                        throw new RuntimeException('申請場地需上傳活動企劃書。');
                    }

                    $file = $_FILES['proposal_file'];
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('企劃書上傳失敗（錯誤碼：' . (int)$file['error'] . '）。');
                    }

                    $maxBytes = 5 * 1024 * 1024; // 5MB
                    if ($file['size'] > $maxBytes) {
                        throw new RuntimeException('企劃書大小超過 5MB 限制。');
                    }

                    if (class_exists('finfo')) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string)$finfo->file($file['tmp_name']);
                    } elseif (function_exists('mime_content_type')) {
                        $mime = (string)mime_content_type($file['tmp_name']);
                    } else {
                        // 臨時備援：若 server 真的無法使用 fileinfo 或 mime_content_type，
                        // 以檔案副檔名當作最後判斷 (僅接受 .pdf)。注意：此作法有安全風險，
                        // 建議盡快重啟 Web 伺服器以啟用 fileinfo。
                        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                        if ($ext === 'pdf') {
                            $mime = 'application/pdf';
                        } else {
                            throw new RuntimeException('伺服器未啟用 fileinfo 擴充套件，且無法使用 mime_content_type 判別檔案，請上傳副檔名為 .pdf 的檔案，或聯絡系統管理員以啟用 php_fileinfo。');
                        }
                    }
                    // 僅允許 PDF
                    $allowed = [
                        'application/pdf' => 'pdf',
                    ];
                    if (!array_key_exists($mime, $allowed)) {
                        throw new RuntimeException('企劃書格式不支援，僅接受 PDF。');
                    }

                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'proposals';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        throw new RuntimeException('建立上傳目錄失敗。');
                    }

                    $ext = $allowed[$mime];
                    $safeBasename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo((string)$file['name'], PATHINFO_FILENAME));
                    $targetName = sprintf('%d_%s.%s', $reservationId, $safeBasename, $ext);
                    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;

                    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                        throw new RuntimeException('企劃書儲存失敗。');
                    }

                    // 儲存相對路徑到資料庫
                    $proposalFileForSpace = 'uploads/proposals/' . $targetName;
                    $proposalUploadedAtForSpace = date('Y-m-d H:i:s');
                    $uploadedProposalPath = $targetPath;
                }

                if ($formData['resource_type'] === 'equipment') {
                    $allEquipmentIds = [];
                    foreach ($cartSummary as $equipmentCode => $qty) {
                        $stockCheckStmt = mysqli_prepare(
                            $link,
                            'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                        );
                        if (!$stockCheckStmt) {
                            throw new RuntimeException('檢查器材庫存失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($stockCheckStmt, 's', $equipmentCode);
                        mysqli_stmt_execute($stockCheckStmt);
                        $stockCheckResult = mysqli_stmt_get_result($stockCheckStmt);
                        $stockRow = $stockCheckResult ? mysqli_fetch_assoc($stockCheckResult) : null;
                        mysqli_stmt_close($stockCheckStmt);
    
                        $availableCountInTransaction = $stockRow ? (int)$stockRow['available_count'] : 0;
                        if ($availableCountInTransaction < $qty) {
                            throw new RuntimeException("器材 {$equipmentCode} 目前可借用數量不足，請調整清單內數量。");
                        }
    
                        $selectEquipmentStmt = mysqli_prepare(
                            $link,
                            'SELECT equipment_id FROM equipments WHERE equipment_code = ? AND operation_status = 1 ORDER BY equipment_id ASC LIMIT ?'
                        );
                        if (!$selectEquipmentStmt) {
                            throw new RuntimeException('讀取可借器材失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($selectEquipmentStmt, 'si', $equipmentCode, $qty);
                        mysqli_stmt_execute($selectEquipmentStmt);
                        $availableEquipmentResult = mysqli_stmt_get_result($selectEquipmentStmt);
    
                        $equipmentIds = [];
                        while ($equipmentRow = mysqli_fetch_assoc($availableEquipmentResult)) {
                            $equipmentIds[] = (int)$equipmentRow['equipment_id'];
                        }
                        mysqli_stmt_close($selectEquipmentStmt);
    
                        if (count($equipmentIds) < $qty) {
                            throw new RuntimeException("目前可借器材 {$equipmentCode} 數量不足，請調整借用數量。");
                        }
                        $allEquipmentIds = array_merge($allEquipmentIds, $equipmentIds);
                    }

                    $reservationItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id, borrow_quantity) VALUES (?, ?, 1)'
                    );
                    if (!$reservationItemStmt) {
                        throw new RuntimeException('建立器材預約明細失敗：' . mysqli_error($link));
                    }

                    foreach ($allEquipmentIds as $equipmentId) {
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

                    foreach ($allEquipmentIds as $equipmentId) {
                        mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'is', $equipmentId, $borrowStartAtSql);
                        mysqli_stmt_execute($updateEquipmentStatusStmt);
                    }
                    mysqli_stmt_close($updateEquipmentStatusStmt);
                    // 嘗試建立器材核簽紀錄（若申請人有有效證照）
                    $certificateId = null;
                    $certSelectStmt = mysqli_prepare(
                        $link,
                        'SELECT certificate_id FROM equipment_certificates WHERE holder_id = ? AND validity_status = "valid" ORDER BY issue_date DESC LIMIT 1'
                    );
                    if ($certSelectStmt) {
                        mysqli_stmt_bind_param($certSelectStmt, 's', $userId);
                        mysqli_stmt_execute($certSelectStmt);
                        $certSelectResult = mysqli_stmt_get_result($certSelectStmt);
                        $certRow = $certSelectResult ? mysqli_fetch_assoc($certSelectResult) : null;
                        mysqli_stmt_close($certSelectStmt);
                        if ($certRow && isset($certRow['certificate_id'])) {
                            $certificateId = (int)$certRow['certificate_id'];
                        }
                    }

                    // 建立器材核簽紀錄（若無證照則以 NULL 儲存 certificate_id）
                    if ($certificateId !== null) {
                        $insertSignoffStmt = mysqli_prepare(
                            $link,
                            'INSERT INTO equipment_signoffs (reservation_id, certificate_id, reviewer_id, signoff_status) VALUES (?, ?, ?, "pending")'
                        );
                        if (!$insertSignoffStmt) {
                            throw new RuntimeException('建立器材核簽紀錄失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($insertSignoffStmt, 'iis', $reservationId, $certificateId, $userId);
                        mysqli_stmt_execute($insertSignoffStmt);
                        mysqli_stmt_close($insertSignoffStmt);
                    } else {
                        $insertSignoffStmt = mysqli_prepare(
                            $link,
                            'INSERT INTO equipment_signoffs (reservation_id, certificate_id, reviewer_id, signoff_status) VALUES (?, NULL, ?, "pending")'
                        );
                        if (!$insertSignoffStmt) {
                            throw new RuntimeException('建立器材核簽紀錄失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($insertSignoffStmt, 'is', $reservationId, $userId);
                        mysqli_stmt_execute($insertSignoffStmt);
                        mysqli_stmt_close($insertSignoffStmt);
                    }
                } else {
                    $spaceConflictStmt = mysqli_prepare(
                            $link,
                            'SELECT COUNT(*) AS conflict_count
                             FROM space_reservation_items sri
                             JOIN reservations r ON r.reservation_id = sri.reservation_id
                             WHERE sri.space_id = ?
                               AND r.approval_status IN ("pending", "approved")
                               AND NOT (r.borrow_end_at <= ? OR r.borrow_start_at >= ?)'
                        );
                        if (!$spaceConflictStmt) {
                            throw new RuntimeException('檢查空間時段衝突失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($spaceConflictStmt, 'sss', $formData['space_id'], $borrowStartAtSql, $borrowEndAtSql);
                        mysqli_stmt_execute($spaceConflictStmt);
                        $spaceConflictResult = mysqli_stmt_get_result($spaceConflictStmt);
                        $spaceConflictRow = $spaceConflictResult ? mysqli_fetch_assoc($spaceConflictResult) : null;
                        mysqli_stmt_close($spaceConflictStmt);

                        if ($spaceConflictRow && (int)$spaceConflictRow['conflict_count'] > 0) {
                            throw new RuntimeException('該時段空間已被預約，請改選其他時段或空間。');
                        }

                    $spaceItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO space_reservation_items (reservation_id, space_id, proposal_file, proposal_uploaded_at) VALUES (?, ?, ?, ?)'
                    );
                    if (!$spaceItemStmt) {
                        throw new RuntimeException('建立空間預約明細失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($spaceItemStmt, 'isss', $reservationId, $formData['space_id'], $proposalFileForSpace, $proposalUploadedAtForSpace);
                    mysqli_stmt_execute($spaceItemStmt);
                    mysqli_stmt_close($spaceItemStmt);
                    // 不再更新 spaces 表的營運狀態，因為我們通過查詢衝突來檢查可用性
                }

                mysqli_commit($link);
                $borrowSuccess = '申請已送出，申請編號：' . $reservationId . '。';
                // ----- 寄送預約成功通知信 -----
                $userEmailStmt = mysqli_prepare($link, 'SELECT email FROM users WHERE user_id = ?');
                if ($userEmailStmt) {
                    mysqli_stmt_bind_param($userEmailStmt, 's', $userId);
                    mysqli_stmt_execute($userEmailStmt);
                    $resObj = mysqli_stmt_get_result($userEmailStmt);
                    if ($rowObj = mysqli_fetch_assoc($resObj)) {
                        $userEmail = $rowObj['email'];
                        if (!empty($userEmail)) {
                            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                                require_once __DIR__ . '/lib/PHPMailer/Exception.php';
                                require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
                                require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
                            }
                            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                            try {
                                $mail->isSMTP();
                                $mail->Host       = 'smtp.gmail.com'; 
                                $mail->SMTPAuth   = true;
                                $mail->Username   = 'sasass041919@gmail.com';
                                $mail->Password   = 'xogusuplsoapxayc';      
                                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                                $mail->Port       = 465;
                                $mail->CharSet    = 'UTF-8';
                                $mail->setFrom('sasass041919@gmail.com', '借用系統通知');
                                $mail->addAddress($userEmail, $displayName);
                                $mail->isHTML(true);
                                $mail->Subject = '【系統通知】預約申請已成功送出';
                                $mail->Body    = "您好，{$displayName}：<br><br>您的預約申請（單號：{$reservationId}）已經成功送出，目前狀態為<b>「審核中」</b>。<br><br>系統管理員將會儘速處理您的申請，審核結果出爐後會再次以 Email 通知您。<br><br>感謝您的使用！";
                                $mail->AltBody = "您好，{$displayName}：\n\n您的預約申請（單號：{$reservationId}）已經成功送出，目前狀態為「審核中」。\n\n系統管理員將會儘速處理您的申請，審核結果出爐後會再次以 Email 通知您。\n\n感謝您的使用！";
                                $mail->send();
                            } catch (Exception $e) {
                                error_log("預約成功信件寄送失敗: " . $mail->ErrorInfo);
                            }
                        }
                    }
                    mysqli_stmt_close($userEmailStmt);
                }
                // ------------------------------
                $formData = [
                    'resource_type' => 'equipment',
                    'equipment_code' => '',
                    'space_id' => '',
                    'borrow_quantity' => '1',
                    'borrow_date' => '',
                    'start_period_code' => '',
                    'end_period_code' => '',
                    'purpose' => '',
                    'phone' => '',
                    'cart_items' => '[]',
                ];

                if ($submittedResourceType === 'equipment') {
                    foreach ($cartSummary as $equipmentCode => $qty) {
                        if (isset($equipmentMap[$equipmentCode])) {
                            $equipmentMap[$equipmentCode]['available_quantity'] -= $qty;
                            if ($equipmentMap[$equipmentCode]['available_quantity'] < 0) {
                                $equipmentMap[$equipmentCode]['available_quantity'] = 0;
                            }
                        }
                    }
                }
            } catch (Throwable $exception) {
                mysqli_rollback($link);
                if ($uploadedProposalPath !== null && is_file($uploadedProposalPath)) {
                    @unlink($uploadedProposalPath);
                }
                $borrowError = $exception->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>借用申請｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        /* 避免快取問題，在此處再次宣告必要的樣式 */
        .equipment-selector-container {
            display: flex; gap: 20px; border: 1px solid #ddd;
            border-radius: 8px; padding: 20px; background: #f2f4f6;
            align-items: flex-start; margin-bottom: 20px;
        }
        @media (max-width: 900px) {
            .equipment-selector-container { flex-direction: column; }
        }
        .es-left, .es-right {
            flex: 1; background: transparent; border: none;
            min-height: 480px; display: flex; flex-direction: column; width: 100%;
        }
        .es-title { padding: 15px; font-weight: bold; border-bottom: 1px solid #eee; color: #333; display: flex; align-items: center; gap: 10px; }
        .es-search { padding: 10px 15px; border-bottom: 1px solid #eee; }
        .es-search input { width: 100%; padding: 10px 15px; border: 1px solid #ccc; border-radius: 20px; outline: none; font-size: 14px; }
        .es-list { flex: 1; overflow-y: auto; max-height: 380px; margin: 0; padding: 0; list-style: none; }
        .es-item { border-bottom: 1px solid #f5f5f5; }
        .es-item-header { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; transition: background 0.2s; }
        .es-item-header:hover { background: #fafafa; }
        .es-item-name { font-size: 15px; color: #444; }
        .es-btn-invite { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.2s; }
        .es-btn-invite:hover:not(:disabled) { background: #cbd5e1; }
        .es-btn-invite:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; border-color: #e2e8f0; }
        .es-item-body { display: none; padding: 15px; background: #f8f9fa; border-top: 1px dashed #eee; font-size: 14px; }
        .es-item-body.active { display: block; animation: fadeIn 0.2s ease-in-out; }
        .es-item-details { display: flex; justify-content: space-between; margin-bottom: 15px; color: #666; font-weight: bold; }
        .es-item-action { display: flex; gap: 10px; align-items: center; }
        .es-item-action input[type="number"] { width: 70px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        .es-btn-add { background: #10b981; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: normal; font-size: 13px; transition: background 0.2s; }
        .es-btn-add:hover { background: #059669; }
        .es-right-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #eee; }
        .es-right-item-text { font-size: 15px; color: #333; }
        .es-btn-remove { color: #e74c3c; background: none; border: none; cursor: pointer; font-size: 14px; padding: 5px 10px; }
        .es-btn-remove:hover { text-decoration: underline; }
        
        .full-width-layout {
            grid-template-columns: 1fr !important;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>📚 校園資源租借系統</h1>
            </div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="borrow-page">
                <h2>器材借用申請</h2>
                <p class="borrow-subtitle">角色：<?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?>。填寫申請後將送出審核。</p>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($borrowError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($borrowError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($borrowSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($borrowSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <div class="borrow-layout" id="mainBorrowLayout">
                    <section class="card borrow-form-card">
                        <h3>申請資料</h3>
                        <form method="post" enctype="multipart/form-data" class="borrow-form" action="borrow.php">
                            <div class="form-group">
                                <label for="resource_type">借用類型</label>
                                <select id="resource_type" name="resource_type">
                                    <option value="equipment" <?php echo $formData['resource_type'] === 'equipment' ? 'selected' : ''; ?>>器材</option>
                                    <option value="space" <?php echo $formData['resource_type'] === 'space' ? 'selected' : ''; ?>>空間</option>
                                </select>
                                <div id="proposalGroup" style="margin-top:0.6rem;">
                                    <label for="proposal_file">活動企劃書（申請場地時必填，僅接受 PDF，限 5MB）</label>
                                    <input type="file" id="proposal_file" name="proposal_file" accept="application/pdf">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="applicant_user_id">申請人帳號</label>
                                <input type="text" id="applicant_user_id" value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                            </div>

                            <div id="equipmentSelectorContainer" class="equipment-selector-container">
                                <div class="es-left">
                                    <div class="es-title">
                                        <span style="color: #3b82f6; margin-right: 8px;">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                                            </svg>
                                        </span>
                                        選擇器材
                                    </div>
                                    <div class="es-search">
                                        <input type="text" id="esSearchInput" placeholder="搜尋器材名稱...">
                                    </div>
                                    <ul class="es-list" id="esEquipmentList" style="background:#fff; border-radius:8px; border:1px solid #e0e0e0;">
                                        <?php foreach ($equipmentMap as $equipment) { 
                                            $avail = (int)$equipment['available_quantity'];
                                            $limitRaw = $equipment['borrow_limit_quantity'];
                                            $limit = $limitRaw === null ? '不限' : (int)$limitRaw;
                                            $maxInput = $limitRaw !== null ? min($avail, (int)$limitRaw) : $avail;
                                            $isAvail = $avail > 0;
                                        ?>
                                            <li class="es-item" data-name="<?php echo htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?>" data-code="<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>" style="<?php echo $isAvail ? '' : 'opacity: 0.6;'; ?>">
                                                <div class="es-item-header">
                                                    <span class="es-item-name"><?php echo htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                                    <button type="button" class="es-btn-invite" <?php echo $isAvail ? '' : 'disabled'; ?>><?php echo $isAvail ? '選擇' : '已借完'; ?></button>
                                                </div>
                                                <div class="es-item-body">
                                                    <div class="es-item-details">
                                                        <span>目前可借用數量：<?php echo $avail; ?></span>
                                                        <span>限借數量：<?php echo $limit; ?></span>
                                                    </div>
                                                    <div class="es-item-action">
                                                        <label>選擇借幾個：</label>
                                                        <input type="number" class="es-qty-input" min="1" max="<?php echo $maxInput; ?>" value="1">
                                                        <button type="button" class="es-btn-add">加入清單</button>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                </div>
                                <div class="es-right">
                                    <div class="es-title" style="color: #333;">
                                        <span style="color: #f59e0b; margin-right: 8px;">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                                                <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                                            </svg>
                                        </span>
                                        已選取器材
                                    </div>
                                    <ul class="es-list" id="esSelectedList" style="background:#fff; border-radius:8px; padding: 10px; border:1px solid #e0e0e0;">
                                    </ul>
                                </div>
                            </div>

                            <div class="form-group" id="spaceGroup">
                                <label for="space_id">空間項目</label>
                                <select id="space_id" name="space_id">
                                    <option value="">請選擇</option>
                                    <?php foreach ($spaceMap as $space) { 
                                        $spaceStatusVal = (string)$space['space_status'];
                                        $isSelectable = in_array($spaceStatusVal, ['1', 'available'], true);
                                    ?>
                                        <option value="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['space_id'] === $space['space_id'] ? 'selected' : ''; ?> <?php echo $isSelectable ? '' : 'disabled'; ?>>
                                            <?php echo htmlspecialchars($space['space_id'] . ' - ' . $space['space_name'] . ' (' . $space['space_status'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            
                            <input type="hidden" id="cart_items" name="cart_items" value="<?php echo htmlspecialchars($formData['cart_items'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="form-group">
                                <label for="borrow_date">借用日期</label>
                                <input type="date" id="borrow_date" name="borrow_date" value="<?php echo htmlspecialchars($formData['borrow_date'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="start_period_code">開始節次代號</label>
                                <select id="start_period_code" name="start_period_code" required>
                                    <option value="">請選擇</option>
                                    <?php foreach ($periodSlots as $periodCode => $periodConfig) { ?>
                                        <option value="<?php echo htmlspecialchars($periodCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['start_period_code'] === $periodCode ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($periodCode . ' (' . substr($periodConfig['start'], 0, 5) . '-' . substr($periodConfig['end'], 0, 5) . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="end_period_code">結束節次代號</label>
                                <select id="end_period_code" name="end_period_code" required>
                                    <option value="">請選擇</option>
                                    <?php foreach ($periodSlots as $periodCode => $periodConfig) { ?>
                                        <option value="<?php echo htmlspecialchars($periodCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['end_period_code'] === $periodCode ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($periodCode . ' (' . substr($periodConfig['start'], 0, 5) . '-' . substr($periodConfig['end'], 0, 5) . ')', ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="phone">聯絡電話</label>
                                <input type="text" id="phone" name="phone" placeholder="例：09XXXXXXXX" value="<?php echo htmlspecialchars($formData['phone'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label for="purpose">用途說明</label>
                                <textarea id="purpose" name="purpose" rows="4" required><?php echo htmlspecialchars($formData['purpose'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="form-buttons">
                                <button type="submit" class="btn-primary" id="borrowSubmitBtn">送出借用申請</button>
                                <button type="button" class="btn-secondary" onclick="location.href='index.php'">取消</button>
                            </div>
                        </form>
                    </section>

                    <section class="card borrow-stock-card" id="spaceStockSection" style="margin-top: 2rem;">
                        <h3>空間可借狀態</h3>
                        <div class="borrow-table-wrapper">
                            <div id="spaceStockWrapper">
                            <table class="management-table borrow-table">
                                <thead>
                                    <tr>
                                        <th>空間 ID</th>
                                        <th>空間名稱</th>
                                        <th>容納人數</th>
                                        <th>狀態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($spaceMap as $space) { ?>
                                        <tr data-space-id="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>" data-space-status="<?php echo htmlspecialchars((string)$space['space_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <td><?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($space['space_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo (int)$space['capacity']; ?></td>
                                            <td class="space-status-cell"><?php echo htmlspecialchars($space['space_status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            const spaceSelect = document.getElementById('space_id');
            const resourceTypeSelect = document.getElementById('resource_type');
            const submitButton = document.getElementById('borrowSubmitBtn');
            const spaceGroup = document.getElementById('spaceGroup');
            const equipmentSelectorContainer = document.getElementById('equipmentSelectorContainer');
            const proposalFileInput = document.getElementById('proposal_file');
            const proposalGroup = document.getElementById('proposalGroup');
            const cartItemsInput = document.getElementById('cart_items');
            
            const esSearchInput = document.getElementById('esSearchInput');
            const esEquipmentList = document.getElementById('esEquipmentList');
            const esSelectedList = document.getElementById('esSelectedList');

            let cartItems = [];
            try {
                const parsed = JSON.parse(cartItemsInput.value);
                if (Array.isArray(parsed)) cartItems = parsed;
            } catch(e) {}
            
            function renderCart() {
                esSelectedList.innerHTML = '';
                cartItems.forEach((item, index) => {
                    const li = document.createElement('li');
                    li.className = 'es-right-item';
                    const displayName = item.name ? `${item.name} (${item.code})` : item.code;
                    li.innerHTML = `
                        <span class="es-right-item-text">${displayName} <span style="color:#666; font-size:13px; margin-left:8px;">x ${item.quantity}</span></span>
                        <button type="button" class="es-btn-remove" data-index="${index}">取消</button>
                    `;
                    esSelectedList.appendChild(li);
                });
                document.querySelectorAll('.es-btn-remove').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const idx = parseInt(this.dataset.index);
                        cartItems.splice(idx, 1);
                        cartItemsInput.value = JSON.stringify(cartItems);
                        renderCart();
                    });
                });
            }

            // Init Equipment UI events
            if(esEquipmentList) {
                const items = esEquipmentList.querySelectorAll('.es-item');
                
                // Search
                if(esSearchInput) {
                    esSearchInput.addEventListener('input', function() {
                        const q = this.value.trim().toLowerCase();
                        items.forEach(li => {
                            const name = li.dataset.name.toLowerCase();
                            const code = li.dataset.code.toLowerCase();
                            if (name.includes(q) || code.includes(q)) {
                                li.style.display = '';
                            } else {
                                li.style.display = 'none';
                            }
                        });
                    });
                }

                // Toggle & Add
                items.forEach(li => {
                    const inviteBtn = li.querySelector('.es-btn-invite');
                    const body = li.querySelector('.es-item-body');
                    const addBtn = li.querySelector('.es-btn-add');
                    const qtyInput = li.querySelector('.es-qty-input');
                    const name = li.dataset.name;
                    const code = li.dataset.code;

                    if(inviteBtn) {
                        inviteBtn.addEventListener('click', function(e) {
                            e.stopPropagation();
                            const isActive = body.classList.contains('active');
                            // Close everyone else first to make it clean
                            items.forEach(o => {
                                const ob = o.querySelector('.es-item-body');
                                if (ob) ob.classList.remove('active');
                            });
                            
                            if(!isActive) {
                                body.classList.add('active');
                            }
                        });
                    }

                    if(addBtn) {
                        addBtn.addEventListener('click', function() {
                            const qty = parseInt(qtyInput.value, 10);
                            if (isNaN(qty) || qty <= 0) {
                                alert('請輸入大於0的借用數量。');
                                return;
                            }
                            const existing = cartItems.find(i => i.code === code);
                            if (existing) {
                                existing.quantity += qty;
                            } else {
                                cartItems.push({ code: code, name: name, quantity: qty });
                            }
                            cartItemsInput.value = JSON.stringify(cartItems);
                            renderCart();
                            body.classList.remove('active');
                        });
                    }
                });
            }

            function refreshModeUI() {
                const mode = resourceTypeSelect.value;
                const isEquipment = mode === 'equipment';
                
                const mainLayout = document.getElementById('mainBorrowLayout');

                if (equipmentSelectorContainer) equipmentSelectorContainer.style.display = isEquipment ? 'flex' : 'none';
                if (spaceGroup) spaceGroup.style.display = isEquipment ? 'none' : '';
                
                const spaceStockWrapper = document.getElementById('spaceStockWrapper');
                const spaceStockSection = document.getElementById('spaceStockSection');
                
                if (spaceStockWrapper) spaceStockWrapper.style.display = isEquipment ? 'none' : '';
                if (spaceStockSection) {
                    spaceStockSection.style.display = isEquipment ? 'none' : '';
                }
                if (mainLayout) {
                    if (isEquipment) {
                        mainLayout.classList.add('full-width-layout');
                    } else {
                        mainLayout.classList.remove('full-width-layout');
                    }
                }

                if (spaceSelect) spaceSelect.required = !isEquipment;

                if (proposalFileInput) {
                    proposalFileInput.required = !isEquipment;
                }
                if (proposalGroup) {
                    proposalGroup.style.display = isEquipment ? 'none' : '';
                }
            }

            function mapSpaceStatusToLabel(raw) {
                switch (String(raw)) {
                    case '1':
                    case 'available':
                        return '可借';
                    case '2':
                    case 'borrowed':
                    case '已借出':
                        return '已借出';
                    case '3':
                        return '維修中';
                    case '4':
                        return '停用中';
                    case '5':
                        return '已淘汰';
                    default:
                        return raw || '';
                }
            }

            function refreshSpaceTableFilter() {
                const rows = document.querySelectorAll('table.borrow-table tbody tr[data-space-id]');
                const mode = resourceTypeSelect.value;
                const selected = spaceSelect ? spaceSelect.value : '';
                rows.forEach(row => {
                    const raw = row.dataset.spaceStatus || '';
                    const statusCell = row.querySelector('.space-status-cell');
                    if (mode === 'space') {
                        if (statusCell) statusCell.textContent = mapSpaceStatusToLabel(raw);
                        if (selected === '') {
                            row.style.display = '';
                        } else {
                            row.style.display = row.dataset.spaceId === selected ? '' : 'none';
                        }
                    } else {
                        if (statusCell) statusCell.textContent = raw;
                        row.style.display = '';
                    }
                });
            }

            if (resourceTypeSelect) {
                resourceTypeSelect.addEventListener('change', function () {
                    refreshModeUI();
                    refreshSpaceTableFilter();
                });
            }

            if (spaceSelect) {
                spaceSelect.addEventListener('change', refreshSpaceTableFilter);
            }
            
            const borrowForm = document.querySelector('.borrow-form');
            if (borrowForm) {
                borrowForm.addEventListener('submit', function(e) {
                    const mode = resourceTypeSelect.value;
                    if (mode === 'equipment' && cartItems.length === 0) {
                        e.preventDefault();
                        alert('請將器材加入借用清單。');
                        return;
                    }
                });
            }

            refreshModeUI();
            refreshSpaceTableFilter();
            renderCart();
        })();
    </script>
</body>
</html>
