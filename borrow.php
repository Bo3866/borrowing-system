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

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system',3307);
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
            COALESCE(SUM(CASE WHEN e.operation_status = 1 THEN 1 ELSE 0 END), 0) - COALESCE(SUM(eri.borrow_quantity), 0) AS available_quantity
        FROM equipment_categories ec
        LEFT JOIN equipments e ON e.equipment_code = ec.equipment_code
        LEFT JOIN equipment_reservation_items eri ON e.equipment_id = eri.equipment_id
        LEFT JOIN reservations r ON eri.reservation_id = r.reservation_id
            AND r.borrow_start_at <= NOW()
            AND r.borrow_end_at > NOW()
            AND r.approval_status IN ('pending', 'approved')
        GROUP BY ec.equipment_code, ec.equipment_name, ec.borrow_limit_quantity
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
                                    '您對該器材的所有未完成預約共 %d 個，加上本次申請 %d 個共 %d 個，已超過限借數量 %d 個，無法申請。',
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

                        // 如果是未來預約，不會更新，所以affected_rows可能為0，但不拋錯
                        // if (mysqli_stmt_affected_rows($updateEquipmentStatusStmt) !== 1) {
                        //     throw new RuntimeException('器材狀態更新異常，請重新送出申請。');
                        // }
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
                ];

                if ($submittedResourceType === 'equipment' && $selectedEquipment !== null) {
                    $selectedCode = (string)$selectedEquipment['equipment_code'];
                    if (isset($equipmentMap[$selectedCode])) {
                        $equipmentMap[$selectedCode]['available_quantity'] -= $borrowQuantity;
                        if ($equipmentMap[$selectedCode]['available_quantity'] < 0) {
                            $equipmentMap[$selectedCode]['available_quantity'] = 0;
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
    <link rel="stylesheet" href="styles.css">
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

                <div class="borrow-layout">
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

                            <div class="form-group" id="equipmentGroup">
                                <label for="equipment_code">器材項目</label>
                                <select id="equipment_code" name="equipment_code">
                                    <option value="">請選擇</option>
                                    <?php foreach ($equipmentMap as $equipment) { ?>
                                        <option
                                            value="<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                            data-available="<?php echo (int)$equipment['available_quantity']; ?>"
                                            data-limit="<?php echo $equipment['borrow_limit_quantity'] === null ? '' : (int)$equipment['borrow_limit_quantity']; ?>"
                                            <?php echo (int)$equipment['available_quantity'] <= 0 ? 'disabled' : ''; ?>
                                            <?php echo $formData['equipment_code'] === $equipment['equipment_code'] ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($equipment['equipment_code'] . ' - ' . $equipment['equipment_name'] . ((int)$equipment['available_quantity'] <= 0 ? '（已借完）' : ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php } ?>
                                </select>
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

                            <div class="borrow-hint-box" id="equipmentHintBox">
                                <div>目前可借用數量：<strong id="availableQuantityHint">-</strong></div>
                                <div>限借數量：<strong id="borrowLimitHint">-</strong></div>
                            </div>

                            <div class="form-group" id="borrowQuantityGroup">
                                <label for="borrow_quantity">借用數量</label>
                                <input type="number" id="borrow_quantity" name="borrow_quantity" min="1" value="<?php echo htmlspecialchars($formData['borrow_quantity'], ENT_QUOTES, 'UTF-8'); ?>" required>
                            </div>

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

                    <section class="card borrow-stock-card">
                        <div id="equipmentStockSection">
                        <h3>器材可借狀態</h3>
                        <p class="borrow-table-note">以下為即時可借用數量與限借數量。</p>
                        <div class="borrow-table-wrapper">
                            <div id="equipmentStockWrapper">
                            <table class="management-table borrow-table">
                                <thead>
                                    <tr>
                                        <th>代碼</th>
                                        <th>器材名稱</th>
                                        <th>目前可借</th>
                                        <th>限借</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($equipmentMap as $equipment) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo (int)$equipment['available_quantity']; ?></td>
                                            <td><?php echo $equipment['borrow_limit_quantity'] === null ? '不限' : (int)$equipment['borrow_limit_quantity']; ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            </div>
                        </div>

                        </div>
                        <div id="spaceStockSection">
                        <h3 style="margin-top: 1.2rem;">空間可借狀態</h3>
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
                        </div>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            const equipmentSelect = document.getElementById('equipment_code');
            const spaceSelect = document.getElementById('space_id');
            const resourceTypeSelect = document.getElementById('resource_type');
            const quantityInput = document.getElementById('borrow_quantity');
            const submitButton = document.getElementById('borrowSubmitBtn');
            const availableQuantityHint = document.getElementById('availableQuantityHint');
            const borrowLimitHint = document.getElementById('borrowLimitHint');
            const equipmentGroup = document.getElementById('equipmentGroup');
            const spaceGroup = document.getElementById('spaceGroup');
            const equipmentHintBox = document.getElementById('equipmentHintBox');
            const borrowQuantityGroup = document.getElementById('borrowQuantityGroup');
            const proposalFileInput = document.getElementById('proposal_file');
            const proposalGroup = document.getElementById('proposalGroup');

            function ensureSelectableEquipment() {
                const selectedOption = equipmentSelect.options[equipmentSelect.selectedIndex];
                if (selectedOption && selectedOption.value && !selectedOption.disabled) {
                    return;
                }

                for (const option of equipmentSelect.options) {
                    if (option.value && !option.disabled) {
                        equipmentSelect.value = option.value;
                        return;
                    }
                }

                equipmentSelect.value = '';
            }

            function refreshModeUI() {
                const mode = resourceTypeSelect.value;
                const isEquipment = mode === 'equipment';

                equipmentGroup.style.display = isEquipment ? '' : 'none';
                equipmentHintBox.style.display = isEquipment ? '' : 'none';
                borrowQuantityGroup.style.display = isEquipment ? '' : 'none';
                spaceGroup.style.display = isEquipment ? 'none' : '';
                const equipmentStockWrapper = document.getElementById('equipmentStockWrapper');
                const spaceStockWrapper = document.getElementById('spaceStockWrapper');
                const equipmentStockSection = document.getElementById('equipmentStockSection');
                const spaceStockSection = document.getElementById('spaceStockSection');
                if (equipmentStockWrapper) equipmentStockWrapper.style.display = isEquipment ? '' : 'none';
                if (spaceStockWrapper) spaceStockWrapper.style.display = isEquipment ? 'none' : '';
                if (equipmentStockSection) equipmentStockSection.style.display = isEquipment ? '' : 'none';
                if (spaceStockSection) spaceStockSection.style.display = isEquipment ? 'none' : '';

                equipmentSelect.required = isEquipment;
                quantityInput.required = isEquipment;
                spaceSelect.required = !isEquipment;

                if (proposalFileInput) {
                    proposalFileInput.required = !isEquipment;
                }
                if (proposalGroup) {
                    proposalGroup.style.display = isEquipment ? 'none' : '';
                }

                if (!isEquipment) {
                    quantityInput.value = '1';
                    submitButton.disabled = false;
                    quantityInput.removeAttribute('max');
                    availableQuantityHint.textContent = '-';
                    borrowLimitHint.textContent = '-';
                } else {
                    ensureSelectableEquipment();
                    refreshBorrowHints();
                }
            }

            function refreshBorrowHints() {
                const option = equipmentSelect.options[equipmentSelect.selectedIndex];
                if (!option || !option.value) {
                    availableQuantityHint.textContent = '-';
                    borrowLimitHint.textContent = '-';
                    quantityInput.removeAttribute('max');
                    submitButton.disabled = true;
                    return;
                }

                if (option.disabled) {
                    availableQuantityHint.textContent = '0';
                    borrowLimitHint.textContent = option.dataset.limit !== '' ? String(option.dataset.limit) : '不限';
                    quantityInput.max = '0';
                    quantityInput.value = '0';
                    submitButton.disabled = true;
                    return;
                }

                const available = Number(option.dataset.available || 0);
                const limitRaw = option.dataset.limit;
                const hasLimit = limitRaw !== '';
                const limit = hasLimit ? Number(limitRaw) : null;

                availableQuantityHint.textContent = String(available);
                borrowLimitHint.textContent = hasLimit ? String(limit) : '不限';

                const maxValue = hasLimit ? Math.min(available, limit) : available;
                if (maxValue > 0) {
                    quantityInput.max = String(maxValue);
                    submitButton.disabled = false;
                    if (Number(quantityInput.value) > maxValue) {
                        quantityInput.value = String(maxValue);
                    }
                } else {
                    quantityInput.max = '0';
                    quantityInput.value = '0';
                    submitButton.disabled = true;
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
                const selected = spaceSelect.value;
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

            resourceTypeSelect.addEventListener('change', function () { refreshModeUI(); refreshSpaceTableFilter(); });
            equipmentSelect.addEventListener('change', refreshBorrowHints);
            spaceSelect.addEventListener('change', function () { refreshModeUI(); refreshSpaceTableFilter(); });
            refreshModeUI();
            refreshSpaceTableFilter();
            if (resourceTypeSelect.value === 'equipment') {
                refreshBorrowHints();
            }
        })();
    </script>
</body>
</html>
