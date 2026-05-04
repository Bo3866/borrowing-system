<?php
session_start();

function getEquipmentIcon($name) {
    if (mb_strpos($name, '麥克風') !== false) return '🎤';
    if (mb_strpos($name, '擴音') !== false || mb_strpos($name, '音響') !== false || mb_strpos($name, '喊話器') !== false) return '🔊';
    if (mb_strpos($name, '相機') !== false || mb_strpos($name, '攝影機') !== false) return '📷';
    if (mb_strpos($name, '腳架') !== false) return '🔭';
    if (mb_strpos($name, '布幕') !== false || mb_strpos($name, '投影') !== false) return '📽️';
    if (mb_strpos($name, '鋼琴') !== false) return '🎹';
    if (mb_strpos($name, '看板') !== false) return '📋';
    if (mb_strpos($name, '桌') !== false) return '🪚';
    if (mb_strpos($name, '椅') !== false) return '🪑';
    if (mb_strpos($name, '帳') !== false) return '⛺';
    if (mb_strpos($name, '警示') !== false || mb_strpos($name, '交通') !== false) return '🚧';
    if (mb_strpos($name, '旗') !== false) return '🚩';
    if (mb_strpos($name, '燈') !== false) return '💡';
    if (mb_strpos($name, '對講機') !== false) return '📻';
    if (mb_strpos($name, '電') !== false || mb_strpos($name, '線') !== false) return '🔌';
    if (mb_strpos($name, '睡袋') !== false) return '🛌';
    if (mb_strpos($name, '茶桶') !== false) return '🫖';
    return '📦';
}

function getSpaceIcon($name) {
    return '📍';
}

require_once __DIR__ . '/config/database.php';

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

$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system',3306);
$dbError = '';
$link = getMysqliConnection($dbError);

$userPhone = '';
if ($dbError === '') {
    $phoneStmt = mysqli_prepare($link, 'SELECT phone FROM users WHERE user_id = ? LIMIT 1');
    if ($phoneStmt) {
        mysqli_stmt_bind_param($phoneStmt, 's', $userId);
        mysqli_stmt_execute($phoneStmt);
        $phoneResult = mysqli_stmt_get_result($phoneStmt);
        if ($phoneResult) {
            $phoneRow = mysqli_fetch_assoc($phoneResult);
            if ($phoneRow && isset($phoneRow['phone'])) {
                $userPhone = trim((string)$phoneRow['phone']);
            }
        }
        mysqli_stmt_close($phoneStmt);
    }
}

$equipmentMap = [];
$spaceMap = [];
$existingSpaceReservations = [];
$existingEquipmentReservations = [];
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

        // 讀取既有空間預約，提供前端節次衝突提示使用
        // 若資料表尚未建立，維持空陣列即可，不中斷頁面。
        $spaceItemsTableRes = mysqli_query($link, "SHOW TABLES LIKE 'space_reservation_items'");
        if ($spaceItemsTableRes && mysqli_num_rows($spaceItemsTableRes) > 0) {
            $existingReservationsSql = "
                SELECT
                    sri.space_id,
                    DATE(r.borrow_start_at) AS reserve_date,
                    TIME(r.borrow_start_at) AS reserve_start,
                    TIME(r.borrow_end_at) AS reserve_end
                FROM space_reservation_items sri
                JOIN reservations r ON r.reservation_id = sri.reservation_id
                WHERE r.approval_status IN ('pending', 'approved')
            ";
            $existingReservationsResult = mysqli_query($link, $existingReservationsSql);
            if ($existingReservationsResult) {
                while ($existingRow = mysqli_fetch_assoc($existingReservationsResult)) {
                    $existingSpaceReservations[] = [
                        'space_id' => (string)$existingRow['space_id'],
                        'date' => (string)$existingRow['reserve_date'],
                        'start' => (string)$existingRow['reserve_start'],
                        'end' => (string)$existingRow['reserve_end'],
                    ];
                }
            }
        }
        // 讀取既有器材預約（按日），提供前端當日是否已借出判定
        $equipItemsTableRes = mysqli_query($link, "SHOW TABLES LIKE 'equipment_reservation_items'");
        if ($equipItemsTableRes && mysqli_num_rows($equipItemsTableRes) > 0) {
            $existingEquipSql = "
                SELECT
                    e.equipment_code,
                    DATE(r.borrow_start_at) AS reserve_date
                FROM equipment_reservation_items eri
                JOIN reservations r ON r.reservation_id = eri.reservation_id
                JOIN equipments e ON eri.equipment_id = e.equipment_id
                WHERE r.approval_status IN ('pending', 'approved')
            ";
            $existingEquipResult = mysqli_query($link, $existingEquipSql);
            if ($existingEquipResult) {
                while ($erow = mysqli_fetch_assoc($existingEquipResult)) {
                    $existingEquipmentReservations[] = [
                        'equipment_code' => (string)$erow['equipment_code'],
                        'date' => (string)$erow['reserve_date'],
                    ];
                }
            }
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
    'phone' => $userPhone,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " POST handler invoked\nPOST:" . json_encode($_POST) . "\nFILES:" . json_encode(array_map(function($f){ return is_array($f)?array_merge($f,['tmp_name'=>'...']):$f; }, $_FILES)) . "\n\n", FILE_APPEND | LOCK_EX);
    $formData['resource_type'] = trim((string)($_POST['resource_type'] ?? 'equipment'));
    $cartItemsRaw = trim((string)($_POST['cart_items'] ?? '[]'));
    $cartItems = json_decode($cartItemsRaw, true);
    if (!is_array($cartItems)) {
        $cartItems = [];
    }
    // We override equipment_code and borrow_quantity since cart_items processes multiple
    $formData['equipment_code'] = 'unused';
    $formData['space_id'] = trim((string)($_POST['space_id'] ?? ''));
    $formData['borrow_quantity'] = '1';
    $formData['borrow_date'] = trim((string)($_POST['borrow_date'] ?? ''));
    $formData['start_period_code'] = trim((string)($_POST['start_period_code'] ?? ''));
    $formData['end_period_code'] = trim((string)($_POST['end_period_code'] ?? ''));
    $formData['purpose'] = trim((string)($_POST['purpose'] ?? ''));
    $formData['phone'] = trim((string)($_POST['phone'] ?? ''));

    $cartEquipments = [];
    $cartSpaceId = null;

    foreach ($cartItems as $item) {
        if (isset($item['type']) && $item['type'] === 'space') {
            $cartSpaceId = trim((string)$item['code']);
        } else {
            $cartEquipments[] = $item;
        }
    }
    $formData['cartEquipments'] = $cartEquipments;
    $formData['space_id'] = $cartSpaceId ?? '';

    if ($dbError !== '') {
        $borrowError = $dbError;
    } elseif (empty($cartEquipments) && empty($formData['space_id'])) {
        $borrowError = '請選擇至少一項器材或一個場地。';
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
                    // 若選到的借用開始時間已落在過去，視為無效
                    if ($borrowStartAtSql !== '' && strtotime($borrowStartAtSql) < time()) {
                        $borrowError = '借用開始時間不可為過去時間。';
                    }
        }

        $selectedEquipment = null;
        $selectedSpace = null;
        $borrowQuantity = 1;

        if (!empty($cartEquipments) && $borrowError === '') {
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
                foreach ($cartEquipments as $item) {
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
        }
        
        if (!empty($formData['space_id']) && $borrowError === '') {
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
            @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " VALIDATION ERROR: " . $borrowError . "\nPOST:" . json_encode($_POST) . "\nFILES:" . json_encode(array_map(function($f){ return is_array($f)?array_merge($f,['tmp_name'=>'...']):$f; }, $_FILES)) . "\n\n", FILE_APPEND | LOCK_EX);
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

                        // Ensure essential reservation-related tables exist so inserts won't fail
                        // (uses safe CREATE TABLE IF NOT EXISTS statements copied from schema)
                        $createReservationsSql = <<<SQL
CREATE TABLE IF NOT EXISTS reservations (
    reservation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(10) NOT NULL,
    proposal_file VARCHAR(255) NULL,
    proposal_uploaded_at DATETIME NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    borrow_start_at DATETIME NOT NULL,
    borrow_end_at DATETIME NOT NULL,
    approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    returned_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reservation_id)
) ENGINE=InnoDB;
SQL;

                        $createEquipItemsSql = <<<SQL
CREATE TABLE IF NOT EXISTS equipment_reservation_items (
    equipment_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    equipment_id BIGINT UNSIGNED NOT NULL,
    borrow_quantity INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (equipment_item_id)
) ENGINE=InnoDB;
SQL;

                        $createSpaceItemsSql = <<<SQL
CREATE TABLE IF NOT EXISTS space_reservation_items (
    space_item_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    space_id VARCHAR(30) NOT NULL,
    proposal_file VARCHAR(255) NULL,
    proposal_uploaded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (space_item_id)
) ENGINE=InnoDB;
SQL;

                        $createSignoffsSql = <<<SQL
CREATE TABLE IF NOT EXISTS equipment_signoffs (
    signoff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id BIGINT UNSIGNED NOT NULL,
    certificate_id BIGINT UNSIGNED NULL,
    reviewer_id VARCHAR(10) NOT NULL,
    signoff_status ENUM('approved','rejected','pending') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (signoff_id)
) ENGINE=InnoDB;
SQL;

                        // Run creates but do not fail the whole flow on DDL error; log instead
                        if ($link) {
                                if (!mysqli_query($link, $createReservationsSql)) {
                                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " CREATE reservations failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                                }
                                if (!mysqli_query($link, $createEquipItemsSql)) {
                                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " CREATE equipment_reservation_items failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                                }
                                if (!mysqli_query($link, $createSpaceItemsSql)) {
                                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " CREATE space_reservation_items failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                                }
                                if (!mysqli_query($link, $createSignoffsSql)) {
                                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " CREATE equipment_signoffs failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                                }
                        }

                        mysqli_begin_transaction($link);

            try {
                $uploadedProposalPath = null;

                // 確保 space_reservation_items 表有 proposal_file 和 proposal_uploaded_at 欄位
                // NOTE: 如果整個表不存在，跳過 ALTER TABLE 以避免造成所有申請皆失敗。
                $tableExistsRes = mysqli_query($link, "SHOW TABLES LIKE 'space_reservation_items'");
                if ($tableExistsRes && mysqli_num_rows($tableExistsRes) > 0) {
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
                } else {
                    @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " SKIP ALTER: space_reservation_items table not found, skipping ALTER TABLE checks.\n", FILE_APPEND | LOCK_EX);
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

                $hasSubmittedAtCol = in_array('submitted_at', $reservationCols, true);
                
                $submittedAtVal = date('Y-m-d H:i:s'); // 保證同一批次提交時間一致
                $insertCols = [$applicantColumn, 'borrow_start_at', 'borrow_end_at'];
                $bindValuesTemplate = [$userId, $borrowStartAtSql, $borrowEndAtSql];
                $bindTypesTemplate = 'sss';

                if ($hasPurposeCol) {
                    $insertCols[] = 'purpose';
                    $bindValuesTemplate[] = $formData['purpose'];
                    $bindTypesTemplate .= 's';
                }
                
                if ($hasSubmittedAtCol) {
                    $insertCols[] = 'submitted_at';
                    $bindValuesTemplate[] = $submittedAtVal;
                    $bindTypesTemplate .= 's';
                }

                $colsSql = implode(", ", $insertCols) . ", approval_status, created_at";
                if ($hasCertificateIdCol) {
                    $colsSql .= ", certificate_id";
                }

                $placeholders = implode(', ', array_fill(0, count($insertCols), '?')) . ', "pending", NOW()' . ($hasCertificateIdCol ? ', NULL' : '');
                $insertReservationSql = sprintf("INSERT INTO reservations ( %s ) VALUES (%s)", $colsSql, $placeholders);

                $createdReservationIds = [];

                // 企劃書相關變數
                $proposalFileForSpace = null;
                $proposalUploadedAtForSpace = null;

                if (!empty($cartEquipments)) {
                    $stockCheckStmt = mysqli_prepare(
                        $link,
                        'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                    );
                    $selectEquipmentStmt = mysqli_prepare(
                        $link,
                        'SELECT e.equipment_id 
                         FROM equipments e 
                         WHERE e.equipment_code = ? 
                           AND e.operation_status = 1 
                           AND e.equipment_id NOT IN (
                               SELECT eri.equipment_id
                               FROM equipment_reservation_items eri
                               JOIN reservations r ON r.reservation_id = eri.reservation_id
                               WHERE r.approval_status IN ("pending", "approved")
                                 AND r.borrow_start_at < ?
                                 AND r.borrow_end_at > ?
                           )
                         ORDER BY e.equipment_id ASC LIMIT ?'
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
                        throw new RuntimeException('建立器材預約明細指令失敗：' . mysqli_error($link));
                    }

                    // 嘗試取得有效證照（供器材核簽用）
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

                    // 針對購物車內【每一個器材項目】建立各自獨立的預約單 (reservation)
                    foreach ($cartEquipments as $item) {
                        $cCode = $item['code'];
                        $cQty = (int)$item['quantity'];

                                                // 檢查該天是否已有任何預約（器材按天單位）
                                                $overlapCheckSql = "
                                                        SELECT COALESCE(SUM(eri.borrow_quantity), 0) AS used_qty
                                                        FROM equipment_reservation_items eri
                                                        JOIN reservations r ON r.reservation_id = eri.reservation_id
                                                        JOIN equipments e ON e.equipment_id = eri.equipment_id
                                                        WHERE e.equipment_code = ?
                                                            AND r.approval_status IN ('pending', 'approved')
                                                            AND DATE(r.borrow_start_at) = DATE(?)
                                                ";
                        $overlapStmt = mysqli_prepare($link, $overlapCheckSql);
                        mysqli_stmt_bind_param($overlapStmt, 'ss', $cCode, $borrowStartAtSql);
                        mysqli_stmt_execute($overlapStmt);
                        $overlapRes = mysqli_stmt_get_result($overlapStmt);
                        $overlapRow = $overlapRes ? mysqli_fetch_assoc($overlapRes) : null;
                        mysqli_stmt_close($overlapStmt);
                        
                        $usedQty = $overlapRow ? (int)$overlapRow['used_qty'] : 0;
                        $totalQty = isset($equipmentMap[$cCode]) ? (int)$equipmentMap[$cCode]['available_quantity'] : 0;
                        if (($usedQty + $cQty) > $totalQty) {
                            throw new RuntimeException("器材 {$cCode} 在該時段的可借用數量不足，請選擇其他時段。");
                        }

                        mysqli_stmt_bind_param($stockCheckStmt, 's', $cCode);
                        mysqli_stmt_execute($stockCheckStmt);
                        $stockCheckResult = mysqli_stmt_get_result($stockCheckStmt);
                        $stockRow = $stockCheckResult ? mysqli_fetch_assoc($stockCheckResult) : null;

                        $availableCountInTransaction = $stockRow ? (int)$stockRow['available_count'] : 0;
                        if ($availableCountInTransaction < $cQty) {
                            throw new RuntimeException("器材 {$cCode} 目前整體狀態異常或數量不足，無法送出申請。");
                        }

                        mysqli_stmt_bind_param($selectEquipmentStmt, 'sssi', $cCode, $borrowEndAtSql, $borrowStartAtSql, $cQty);
                        mysqli_stmt_execute($selectEquipmentStmt);
                        $availableEquipmentResult = mysqli_stmt_get_result($selectEquipmentStmt);

                        $equipmentIds = [];
                        while ($equipmentRow = mysqli_fetch_assoc($availableEquipmentResult)) {
                            $equipmentIds[] = (int)$equipmentRow['equipment_id'];
                        }

                        if (count($equipmentIds) < $cQty) {
                            throw new RuntimeException("器材 {$cCode} 實際可取得數量不足。");
                        }

                        // 新增 Reservations (每一款器材一張預約單，但 submitted_at 完全一樣)
                        $reservationStmt = mysqli_prepare($link, $insertReservationSql);
                        if (!$reservationStmt) {
                            throw new RuntimeException('建立預約主檔失敗：' . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($reservationStmt, $bindTypesTemplate, ...$bindValuesTemplate);
                        mysqli_stmt_execute($reservationStmt);
                        $itemReservationId = (int)mysqli_insert_id($link);
                        mysqli_stmt_close($reservationStmt);

                        $createdReservationIds[] = $itemReservationId;

                        // 將實體器材加入該預約單
                        foreach ($equipmentIds as $equipmentId) {
                            mysqli_stmt_bind_param($reservationItemStmt, 'ii', $itemReservationId, $equipmentId);
                            mysqli_stmt_execute($reservationItemStmt);
                            
                            mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'is', $equipmentId, $borrowStartAtSql);
                            mysqli_stmt_execute($updateEquipmentStatusStmt);
                        }

                        // 建立器材核簽紀錄
                        if ($certificateId !== null) {
                            $insertSignoffStmt = mysqli_prepare(
                                $link,
                                'INSERT INTO equipment_signoffs (reservation_id, certificate_id, reviewer_id, signoff_status) VALUES (?, ?, ?, "pending")'
                            );
                            if (!$insertSignoffStmt) {
                                throw new RuntimeException('建立器材核簽紀錄失敗：' . mysqli_error($link));
                            }
                            mysqli_stmt_bind_param($insertSignoffStmt, 'iis', $itemReservationId, $certificateId, $userId);
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
                            mysqli_stmt_bind_param($insertSignoffStmt, 'is', $itemReservationId, $userId);
                            mysqli_stmt_execute($insertSignoffStmt);
                            mysqli_stmt_close($insertSignoffStmt);
                        }
                    }

                    mysqli_stmt_close($stockCheckStmt);
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($reservationItemStmt);
                    mysqli_stmt_close($updateEquipmentStatusStmt);
                }

                if (!empty($formData['space_id'])) {
                    // 若為申請空間且有上傳企劃書，處理上傳並更新 reservations
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
                        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                        if ($ext === 'pdf') {
                            $mime = 'application/pdf';
                        } else {
                            throw new RuntimeException('伺服器未啟用 fileinfo 擴充套件，請上傳副檔名為 .pdf 的檔案。');
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
                    $timestampLabel = time();
                    $targetName = sprintf('%s_%s.%s', $timestampLabel, $safeBasename, $ext);
                    $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;

                    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                        throw new RuntimeException('企劃書儲存失敗。');
                    }

                    $proposalFileForSpace = 'uploads/proposals/' . $targetName;
                    $proposalUploadedAtForSpace = date('Y-m-d H:i:s');
                    $uploadedProposalPath = $targetPath;

                    // Space 共用 1 張預約單
                    $reservationStmt = mysqli_prepare($link, $insertReservationSql);
                    if (!$reservationStmt) {
                        throw new RuntimeException('建立預約主檔失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($reservationStmt, $bindTypesTemplate, ...$bindValuesTemplate);
                    mysqli_stmt_execute($reservationStmt);
                    $reservationId = (int)mysqli_insert_id($link);
                    mysqli_stmt_close($reservationStmt);

                    $createdReservationIds[] = $reservationId;

                                                $spaceConflictStmt = mysqli_prepare(
                                                                $link,
                                                                'SELECT COUNT(*) AS conflict_count
                                                         FROM space_reservation_items sri
                                                         JOIN reservations r ON r.reservation_id = sri.reservation_id
                                                         WHERE sri.space_id = ?
                                                             AND r.approval_status IN ("pending", "approved")
                                                             AND NOT (r.borrow_end_at < ? OR r.borrow_start_at > ?)'
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
                    mysqli_stmt_bind_param($spaceItemStmt, 'isss', $createdReservationIds[0], $formData['space_id'], $proposalFileForSpace, $proposalUploadedAtForSpace);
                    mysqli_stmt_execute($spaceItemStmt);
                    mysqli_stmt_close($spaceItemStmt);
                    // 不再更新 spaces 表的營運狀態，因為我們通過查詢衝突來檢查可用性
                }

                mysqli_commit($link);
                $idsStr = implode(', ', $createdReservationIds);
                $borrowSuccess = '申請已送出，申請編號：' . $idsStr . '。';
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
                                $mail->setFrom('sasass041919@gmail.com', '器材借用系統');
                                $mail->addAddress($userEmail, $displayName);
                                $mail->isHTML(true);
                                $mail->Subject = '【系統通知】預約申請已成功送出';
                                $mail->Body    = "您好，{$displayName}：<br><br>您的預約申請（單號：{$idsStr}）已經成功送出，目前狀態為<b>「審核中」</b>。<br><br>系統管理員將會儘速處理您的申請，審核結果出爐後會再次以 Email 通知您。<br><br>感謝您的使用！";
                                $mail->AltBody = "您好，{$displayName}：\n\n您的預約申請（單號：{$idsStr}）已經成功送出，目前狀態為「審核中」。\n\n系統管理員將會儘速處理您的申請，審核結果出爐後會再次以 Email 通知您。\n\n感謝您的使用！";
                                $mail->send();
                            } catch (Exception $e) {
                                error_log("預約成功信件寄送失敗: " . $mail->ErrorInfo);
                            }
                        }
                    }
                    mysqli_stmt_close($userEmailStmt);
                }
                // ------------------------------
                $formData['phone'] = $userPhone;

                foreach ($cartEquipments as $item) {
                    $selectedCode = (string)$item['code'];
                    $borrowQuantity = (int)$item['quantity'];
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
                @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " EXCEPTION: " . $exception->getMessage() . "\nTRACE:" . $exception->getTraceAsString() . "\nPOST:" . json_encode($_POST) . "\nFILES:" . json_encode(array_map(function($f){ return is_array($f)?array_merge($f,['tmp_name'=>'...']):$f; }, $_FILES)) . "\n\n", FILE_APPEND | LOCK_EX);
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* 避免快取問題，在此處再次宣告必要的樣式 */
        
        /* 增強互動性與動態效果 */
        *:not(i):not(svg) { transition: color 0.15s, background-color 0.15s, border-color 0.15s, box-shadow 0.15s !important; }
        .card.borrow-form-card {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); 
            transition: transform 0.3s ease, box-shadow 0.3s ease !important;
        }
        .card.borrow-form-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        /* 表單元素焦點效果 */
        input[type="text"], input[type="date"], input[type="number"], select, textarea {
            transition: all 0.2s ease !important;
        }
        input[type="text"]:focus, input[type="date"]:focus, input[type="number"]:focus, select:focus, textarea:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
            border-color: #3b82f6 !important;
            transform: translateY(-1px);
        }
        
        /* 按鈕互動 */
        .btn-primary, .btn-secondary {
            transition: all 0.2s ease !important;
            position: relative;
            overflow: hidden;
        }
        .btn-primary:hover, .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-primary:active, .btn-secondary:active {
            transform: translateY(0);
        }

        .nav-btn {
            transition: all 0.2s ease !important;
        }
        .nav-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            color: #3b82f6;
        }

        .equipment-selector-container {
            display: flex; gap: 20px; border: 1px solid #ddd;
            border-radius: 8px; padding: 20px; background: #f8fafc;
            align-items: stretch; margin-bottom: 20px;
        }
        @media (max-width: 900px) {
            .equipment-selector-container { flex-direction: column; }
            .es-left, .es-right { height: auto !important; min-height: 400px; }
        }
        .es-left {
            flex: 1.6; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            height: 600px; display: flex; flex-direction: column; width: 100%; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease !important;
        }
        .es-left:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        /* flatpickr disabled day custom style for unavailable equipment dates */
        .flatpickr-day.borrow-disabled {
            background: #f8d7da !important;
            color: #721c24 !important;
            border-color: #f5c6cb !important;
        }
        
        .es-right {
            flex: 1; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
            height: 600px; display: flex; flex-direction: column; width: 100%; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            transition: box-shadow 0.3s ease !important;
        }
        .es-right:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
        
        .es-title { padding: 15px; font-weight: bold; border-bottom: 1px solid #e2e8f0; background: #fff; color: #333; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .es-search { padding: 10px 15px; border-bottom: 1px solid #e2e8f0; background: #fff; flex-shrink: 0; }
        .es-search input { width: 100%; padding: 10px 15px; border: 1px solid #ccc; border-radius: 20px; outline: none; font-size: 14px; transition: box-shadow 0.2s, border-color 0.2s !important; }
        .es-search input:focus { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2) !important; border-color: #3b82f6; }
        
        .es-list { flex: 1; overflow-y: auto; margin: 0; padding: 15px; list-style: none; background: #f8fafc !important; scroll-behavior: smooth; }
        
        .es-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.2s ease, box-shadow 0.2s ease !important; }
        .es-item:hover { transform: translateY(-3px); box-shadow: 0 8px 12px -3px rgba(0,0,0,0.1); border-color: #cbd5e1; }
        
        .es-item-header { display: flex; align-items: center; justify-content: space-between; padding: 15px; cursor: pointer; transition: background 0.2s; min-height: 70px; border-radius: 10px; }
        .es-item-header:hover { background: #f1f5f9; }
        .es-item-info { display: flex; align-items: flex-start; gap: 15px; flex: 1; min-width: 0; }
        .es-item-icon { width: 40px; height: 40px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #64748b; font-size: 20px; flex-shrink: 0; }
        .es-item-name-block { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; }
        .es-item-title { font-weight: bold; font-size: 16px; color: #1e293b; line-height: 1.3;}
        .es-item-subtitle { font-size: 13px; color: #64748b; display: flex; flex-direction: column; gap: 4px; line-height: 1.3; }
        button.es-btn-invite { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; padding: 6px 0; width: 75px !important; text-align: center; border-radius: 6px; font-weight: 500; font-size: 14px; transition: all 0.2s; white-space: nowrap; flex-shrink: 0; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; margin-left: 15px; }
        .es-btn-invite:hover:not(:disabled) { background: #dbeafe; }
        .es-btn-invite:disabled { background: #f8fafc; color: #94a3b8; cursor: not-allowed; border-color: #e2e8f0; }
        .es-item-body { display: none; padding: 15px; background: #f8f9fa; border-top: 1px dashed #eee; font-size: 14px; }
        .es-item-body.active { display: block; animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1); border-radius: 0 0 10px 10px; }
        
        @keyframes slideDown {
            0% { opacity: 0; transform: translateY(-10px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }
        .es-item-details { display: flex; justify-content: space-between; margin-bottom: 15px; color: #666; font-weight: bold; }
        .es-item-action { display: flex; gap: 10px; align-items: center; }
        .es-item-action input[type="number"] { width: 70px; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 13px; }
        button.es-btn-add { background: #3b82f6; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 500; font-size: 14px; transition: background 0.2s; width: 100% !important; margin-left: 0; }
        button.es-btn-add:hover { background: #2563eb; }
        .es-right-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid #eee; transition: all 0.2s ease !important; border-radius: 8px; margin-bottom: 5px; }
        .es-right-item:hover { background-color: #f1f5f9; transform: translateX(5px); }
        button.es-btn-remove { color: #ef4444; background: none; border: none; cursor: pointer; font-size: 14px; padding: 5px 10px; width: auto !important; transition: all 0.2s ease; border-radius: 6px; }
        button.es-btn-remove:hover { background-color: rgba(239, 68, 68, 0.1); color: #b91c1c; }
        .cart-header { display: flex; justify-content: space-between; padding: 10px 12px; font-weight: bold; color: #64748b; border-bottom: 2px solid #e2e8f0; margin-bottom: 10px; }
        .cart-row { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 10px; }
        .cart-col-name { flex: 2; font-weight: 500; color: #333; font-size: 14px; }
        .cart-col-qty { flex: 1; text-align: center; }
        .cart-col-action { flex: 1; text-align: right; }
        
        .full-width-layout {
            grid-template-columns: 1fr !important;
        }

        /* 月曆自訂樣式 */
        .cal-grid-header { text-align:center; font-weight:bold; color:#64748b; padding:12px; background:#f1f5f9; border-radius:6px; font-size:14px; }
        .cal-day-cell { min-height:85px; border:1px solid #e2e8f0; border-radius:8px; padding:8px; cursor:pointer; transition:all 0.2s; display:flex; flex-direction:column; background:#ffffff; box-shadow:0 1px 2px rgba(0,0,0,0.02); }
        .cal-day-cell:hover { border-color:#3b82f6; box-shadow:0 0 0 2px rgba(59,130,246,0.15); transform:translateY(-1px); }
        .cal-day-cell.empty { background:transparent; border:none; cursor:default; box-shadow:none; }
        .cal-day-cell.empty:hover { transform:none; box-shadow:none; }
        .cal-day-date { font-weight:bold; color:#334155; margin-bottom:5px; font-size:15px; }
        
        .cal-day-status { font-size:13px; flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; border-radius:6px; font-weight:bold; padding:4px; }
        .status-full { background:#dcfce7; color:#166534; border:1px solid #bbf7d0;} /* 全天可借 */
        .status-partial { background:#fef9c3; color:#854d0e; border:1px solid #fef08a;} /* 數量變少/部分可借 */
        .status-none { background:#fee2e2; color:#991b1b; border:1px solid #fecaca;} /* 全天無法借 */
        .status-unknown { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;} /* 過去日期或無效 */
        
        .period-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(130px, 1fr)); gap:10px; }
        .period-item { padding:12px; border-radius:8px; font-size:13px; text-align:center; box-shadow:0 1px 3px rgba(0,0,0,0.05); transition:transform 0.2s; }
        .period-item:hover { transform:translateY(-2px); }
        .calendar-card { width:100%; border-top:4px solid #3b82f6; }
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

                <!-- ================== 即時可借狀態月曆 (On-the-fly Calculation) ================== -->
                <section class="card calendar-card" style="margin-bottom: 20px;">
                    <div class="cal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; flex-wrap:wrap; gap:10px;">
                        <div style="flex:1; min-width:250px;">
                            <h3 style="margin:0 0 10px 0; color:var(--primary); display:flex; align-items:center; gap:8px;">
                                📅 可用狀態查詢 (即時計算)
                            </h3>
                            <select id="calItemSelect" style="max-width:300px;">
                                <option value="">-- 請選擇要查詢的項目 --</option>
                                <optgroup label="器材">
                                    <?php foreach($equipmentMap as $e) { echo '<option value="equipment|'.htmlspecialchars($e['equipment_code']).'">'.htmlspecialchars($e['equipment_name']).'</option>'; } ?>
                                </optgroup>
                                <optgroup label="場地">
                                    <?php foreach($spaceMap as $s) { echo '<option value="space|'.htmlspecialchars($s['space_id']).'">'.htmlspecialchars($s['space_name']).'</option>'; } ?>
                                </optgroup>
                            </select>
                        </div>
                        <div style="display:flex; align-items:center; gap:15px;">
                            <button type="button" id="calPrevBtn" class="btn-secondary" style="padding:6px 12px; font-weight:bold;">&lt;</button>
                            <h4 id="calMonthLabel" style="margin:0; min-width:110px; text-align:center; color:#333;">---- 年 - 月</h4>
                            <button type="button" id="calNextBtn" class="btn-secondary" style="padding:6px 12px; font-weight:bold;">&gt;</button>
                        </div>
                    </div>
                    
                    <div id="calLoading" style="display:none; text-align:center; padding:20px; color:#64748b; font-weight:bold;">資料讀取與計算中...</div>
                    <div id="calEmptyMsg" style="text-align:center; padding:20px; color:#64748b;">請從左上方下拉選單選擇想要查詢的項目</div>
                    
                    <div id="calContainer" style="display:none;">
                        <!-- 星期標題 -->
                        <div style="display:grid; grid-template-columns:repeat(7, 1fr); gap:5px; margin-bottom:5px;">
                            <div class="cal-grid-header">日</div><div class="cal-grid-header">一</div><div class="cal-grid-header">二</div>
                            <div class="cal-grid-header">三</div><div class="cal-grid-header">四</div><div class="cal-grid-header">五</div><div class="cal-grid-header">六</div>
                        </div>
                        <!-- 月曆格子 -->
                        <div id="calGrid" style="display:grid; grid-template-columns:repeat(7, 1fr); gap:5px; margin-bottom:15px;">
                        </div>
                        <!-- 詳細資訊 -->
                        <div id="calDetails" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:15px;">
                            <h4 id="calDetailsTitle" style="margin-top:0; color:#1e293b; border-bottom:1px dashed #cbd5e1; padding-bottom:8px;"></h4>
                            <div id="calDetailsGrid" class="period-list">
                            </div>
                        </div>
                    </div>
                </section>
                <!-- ========================================================================= -->

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($borrowError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($borrowError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($borrowSuccess !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($borrowSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <div class="borrow-layout full-width-layout" id="mainBorrowLayout">
                    <section class="card borrow-form-card">
                        <h3>申請資料</h3>
                        <form method="post" enctype="multipart/form-data" class="borrow-form" action="borrow.php" novalidate>
                            <div class="form-group" style="display:none;">
                                <label for="resource_type">借用類型 (已合併)</label>
                                <select id="resource_type" name="resource_type">
                                    <option value="both" selected>兩者</option>
                                </select>
                            </div>

                            <div class="form-group" id="proposalGroup" style="display: none; margin-bottom: 20px;">
                                <label for="proposal_file">活動企劃書（申請場地時必填，僅接受 PDF，限 5MB）</label>
                                <input type="file" id="proposal_file" name="proposal_file" accept="application/pdf">
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
                                        選擇借用項目
                                    </div>
                                    <div class="es-tabs" style="display:flex; background:#fff; border-bottom:1px solid #e2e8f0; flex-shrink: 0;">
                                        <button type="button" class="es-tab-btn active" data-target="equipment" style="flex:1; padding:10px; border:none; background:none; cursor:pointer; font-weight:bold; color:#3b82f6; border-bottom:2px solid #3b82f6; transition:all 0.2s;">器材</button>
                                        <button type="button" class="es-tab-btn" data-target="space" style="flex:1; padding:10px; border:none; background:none; cursor:pointer; font-weight:bold; color:#64748b; border-bottom:2px solid transparent; transition:all 0.2s;">空間場地</button>
                                    </div>
                                    <div class="es-search" style="display: flex; align-items: center; justify-content: space-between; gap: 15px;">
                                        <div style="flex: 1;">
                                            <input type="text" id="esSearchInput" placeholder="搜尋名稱...">
                                        </div>
                                        <div id="esItemCount" style="font-size: 13px; color: #64748b; white-space: nowrap;">
                                            顯示所有項目
                                        </div>
                                    </div>
                                    <ul class="es-list" id="esEquipmentList">
                                        <?php foreach ($equipmentMap as $equipment) { 
                                            $avail = (int)$equipment['available_quantity'];
                                            $limitRaw = $equipment['borrow_limit_quantity'];
                                            $limit = $limitRaw === null ? '不限' : (int)$limitRaw;
                                            $maxInput = $limitRaw !== null ? min($avail, (int)$limitRaw) : $avail;
                                        ?>
                                            <li class="es-item" data-type="equipment" data-name="<?php echo htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?>" data-code="<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>" data-original-disabled="0">
                                                <div class="es-item-header">
                                                    <div class="es-item-info">
                                                        <div class="es-item-icon"><?php echo getEquipmentIcon($equipment['equipment_name']); ?></div>
                                                        <div class="es-item-name-block">
                                                            <div class="es-item-title"><?php echo htmlspecialchars($equipment['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <div class="es-item-subtitle">
                                                                <span>型號: <?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                                <span>可借數量: <span style="color:#2563eb; font-weight:bold;"><?php echo $avail; ?></span></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="es-btn-invite">選擇</button>
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
                                        <?php foreach ($spaceMap as $space) { 
                                            $spaceStatusVal = (string)$space['space_status'];
                                        ?>
                                            <li class="es-item" data-type="space" data-name="<?php echo htmlspecialchars($space['space_name'], ENT_QUOTES, 'UTF-8'); ?>" data-code="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>" data-original-disabled="0">
                                                <div class="es-item-header">
                                                    <div class="es-item-info">
                                                        <div class="es-item-icon"><?php echo getSpaceIcon($space['space_name']); ?></div>
                                                        <div class="es-item-name-block">
                                                            <div class="es-item-title"><?php echo htmlspecialchars($space['space_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                            <div class="es-item-subtitle">
                                                                <span>編號: <?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                                <span>容納人數: <?php echo (int)$space['capacity']; ?></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="es-btn-invite">選擇</button>
                                                </div>
                                                <div class="es-item-body">
                                                    <div class="es-item-details">
                                                        <span>容納人數：<?php echo (int)$space['capacity']; ?></span>
                                                    </div>
                                                    <div class="es-item-action">
                                                        <label>場地僅能選擇一個</label>
                                                        <input type="number" class="es-qty-input" min="1" max="1" value="1" style="display:none;">
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
                                        已選取項目
                                    </div>
                                    <div style="flex: 1; display: flex; flex-direction: column; min-height: 0; background:#f8fafc; padding: 15px;">
                                        <div class="cart-header" style="flex-shrink: 0; background: #f8fafc;">
                                            <div class="cart-col-name">項目名稱</div>
                                            <div class="cart-col-qty">數量</div>
                                            <div class="cart-col-action">操作</div>
                                        </div>
                                        <ul class="es-list" id="esSelectedList" style="padding: 0; background: #f8fafc !important;">
                                        </ul>
                                    </div>
                                </div>
                            </div>


                            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="borrow_date">借用日期</label>
                                    <input type="text" id="borrow_date" name="borrow_date" value="<?php echo htmlspecialchars($formData['borrow_date'], ENT_QUOTES, 'UTF-8'); ?>" data-mindate="<?php echo date('Y-m-d'); ?>" placeholder="選擇借用日期" required readonly>
                                </div>

                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="start_period_code">開始節次</label>
                                    <select id="start_period_code" name="start_period_code" required>
                                        <option value="">請選擇</option>
                                        <?php foreach ($periodSlots as $periodCode => $periodConfig) { ?>
                                            <option value="<?php echo htmlspecialchars($periodCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['start_period_code'] === $periodCode ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($periodCode . ' (' . substr($periodConfig['start'], 0, 5) . '-' . substr($periodConfig['end'], 0, 5) . ')', ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>

                                <div class="form-group" style="flex: 1; min-width: 150px;">
                                    <label for="end_period_code">結束節次</label>
                                    <select id="end_period_code" name="end_period_code" required>
                                        <option value="">請選擇</option>
                                        <?php foreach ($periodSlots as $periodCode => $periodConfig) { ?>
                                            <option value="<?php echo htmlspecialchars($periodCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formData['end_period_code'] === $periodCode ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($periodCode . ' (' . substr($periodConfig['start'], 0, 5) . '-' . substr($periodConfig['end'], 0, 5) . ')', ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
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
                                <button type="submit" class="btn-primary" id="borrowSubmitBtn">確認借用</button>
                                <button type="button" class="btn-secondary" onclick="location.href='index.php'">取消</button>
                            </div>
                            <div id="submitDebugMsg" style="margin-top:8px; font-size:13px; color:#64748b;"></div>
                        </form>
                    </section>
                </div>
            </section>
        </main>
    </div>

    <script>
        (function () {
            const borrowForm = document.querySelector('form.borrow-form');
            const spaceSelect = document.getElementById('space_id');
            const resourceTypeSelect = document.getElementById('resource_type');
            const submitButton = document.getElementById('borrowSubmitBtn');
            const spaceGroup = document.getElementById('spaceGroup');
            const equipmentSelectorContainer = document.getElementById('equipmentSelectorContainer');
            const proposalFileInput = document.getElementById('proposal_file');
            const proposalGroup = document.getElementById('proposalGroup');
            const submitDebugMsg = document.getElementById('submitDebugMsg');

            // --- Missing cart logic re-added ---
            const esEquipmentList = document.getElementById('esEquipmentList');
            const esSelectedList = document.getElementById('esSelectedList');
            const esSearchInput = document.getElementById('esSearchInput');
            const esItemCount = document.getElementById('esItemCount');
            
            // cartItemsInput needs to be dynamically added if missing
            let cartItemsInput = document.querySelector('input[name="cart_items"]');
            if(!cartItemsInput) {
                const f = document.querySelector('form.borrow-form');
                if(f) {
                    cartItemsInput = document.createElement('input');
                    cartItemsInput.type = 'hidden';
                    cartItemsInput.name = 'cart_items';
                    f.appendChild(cartItemsInput);
                }
            }
            
            const items = esEquipmentList ? esEquipmentList.querySelectorAll('.es-item') : [];
            let cartItems = [];
            let currentTab = 'equipment';
            const tabBtns = document.querySelectorAll('.es-tab-btn');

            function updateItemCount() {
                if(!esItemCount) return;
                let visible = 0;
                let total = 0;
                items.forEach(el => {
                    if(el.dataset.type === currentTab) {
                        total++;
                        if(el.style.display !== 'none') visible++;
                    }
                });
                esItemCount.innerHTML = `顯示 ${visible > 0 ? '1' : '0'}-${visible} / 共 ${total} 項`;
            }

            function renderCart() {
                if(!esSelectedList) return;
                esSelectedList.innerHTML = '';
                cartItems.forEach((c, index) => {
                    const li = document.createElement('li');
                    li.className = 'es-right-item';
                    li.innerHTML = `
                        <div class="cart-row">
                            <div class="cart-col-name">${c.name} (${c.code})</div>
                            <div class="cart-col-qty">
                                <input type="number" 
                                    class="cart-qty-update" 
                                    data-index="${index}" 
                                    value="${c.quantity}" 
                                    min="1" 
                                    style="width: 50px; text-align: center; border: 1px solid #ccc; border-radius:4px; padding:2px;"
                                    ${c.type === 'space' ? 'disabled title="場地僅能申請一項"' : ''}>
                            </div>
                            <div class="cart-col-action">
                                <button type="button" class="es-btn-remove" data-index="${index}">移除</button>
                            </div>
                        </div>
                    `;
                    esSelectedList.appendChild(li);
                });
                
                // Binding updates
                const qtys = esSelectedList.querySelectorAll('.cart-qty-update');
                qtys.forEach(input => {
                    input.addEventListener('change', function() {
                        const idx = parseInt(this.dataset.index, 10);
                        const newVal = parseInt(this.value, 10);
                        if(newVal > 0) {
                            cartItems[idx].quantity = newVal;
                            cartItemsInput.value = JSON.stringify(cartItems);
                        } else {
                            this.value = cartItems[idx].quantity;
                        }
                    });
                });

                const removes = esSelectedList.querySelectorAll('.es-btn-remove');
                removes.forEach(btn => {
                    btn.addEventListener('click', function() {
                        const idx = parseInt(this.dataset.index, 10);
                        cartItems.splice(idx, 1);
                        cartItemsInput.value = JSON.stringify(cartItems);
                        renderCart();
                        refreshModeUI();
                    });
                });
            }

            if(esSearchInput) {
                esSearchInput.addEventListener('input', function() {
                    const q = this.value.toLowerCase().trim();
                    items.forEach(li => {
                        const txt = li.textContent.toLowerCase();
                        const type = li.dataset.type;
                        const matchType = (type === currentTab);
                        const matchQuery = txt.includes(q);
                        li.style.display = (matchType && matchQuery) ? '' : 'none';
                    });
                    updateItemCount();
                });
            }

            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    tabBtns.forEach(b => {
                        b.classList.remove('active');
                        b.style.color = '#64748b';
                        b.style.borderBottomColor = 'transparent';
                    });
                    this.classList.add('active');
                    this.style.color = '#3b82f6';
                    this.style.borderBottomColor = '#3b82f6';
                    currentTab = this.dataset.target;
                    if(esSearchInput) {
                        esSearchInput.dispatchEvent(new Event('input'));
                    }
                });
            });

            // Initialize display
            if(esSearchInput) {
                esSearchInput.dispatchEvent(new Event('input'));
            }

            // -----------------------------------

            // Toggle & Add
            items.forEach(li => {
                const header = li.querySelector('.es-item-header');
                const inviteBtn = li.querySelector('.es-btn-invite');
                const body = li.querySelector('.es-item-body');
                const addBtn = li.querySelector('.es-btn-add');
                const qtyInput = li.querySelector('.es-qty-input');
                const name = li.dataset.name;
                const code = li.dataset.code;
                const type = li.dataset.type;

                if(header) {
                    header.addEventListener('click', function(e) {
                        if (inviteBtn && inviteBtn.disabled) return;
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
                
                if(inviteBtn) {
                    // Stop propagation so clicking btn doesn't double-trigger if we bind header
                    inviteBtn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        if(header) header.click();
                    });
                }

                if(addBtn) {
                    addBtn.addEventListener('click', function() {
                        const qty = parseInt(qtyInput.value, 10);
                        if (isNaN(qty) || qty <= 0) {
                            alert('請輸入大於 0 的借用數量。');
                            return;
                        }
                        
                        if (type === 'space') {
                            const existingSpace = cartItems.find(i => i.type === 'space');
                            if (existingSpace && existingSpace.code !== code) {
                                alert('同時只能申請借用一個場地，您已選取「' + existingSpace.name + '」。如欲更換，請先移除原有的場地。');
                                return;
                            }
                        }

                        const existing = cartItems.find(i => i.code === code);
                        if (existing) {
                            if (type === 'equipment') {
                                existing.quantity += qty;
                            }
                        } else {
                            cartItems.push({ code: code, name: name, quantity: qty, type: type });
                            
                            // 如果是器材，預先獲取當月的可用性數據
                            if (type === 'equipment') {
                                const today = new Date();
                                const year = today.getFullYear();
                                const month = String(today.getMonth() + 1).padStart(2, '0');
                                
                                fetch(`api_get_availability.php?type=equipment&id=${encodeURIComponent(code)}&year=${year}&month=${month}`)
                                    .then(res => res.json())
                                    .then(data => {
                                        if (data.total_capacity !== undefined) {
                                            // 緩存整個月的數據
                                                const monthKey = `${code}_${year}_${month}`;
                                                availabilityCache[monthKey] = data;
                                            const daysInMonth = new Date(year, month, 0).getDate();
                                            for (let d = 1; d <= daysInMonth; d++) {
                                                const dateStr = `${year}-${month}-${String(d).padStart(2, '0')}`;
                                                const dateKey = `${code}_${dateStr}`;
                                                availabilityCache[dateKey] = {
                                                    totalCapacity: data.total_capacity,
                                                    reservations: data.reservations || []
                                                };
                                            }
                                                // If flatpickr is active, refresh disabled dates for this equipment/month
                                                try {
                                                    if (typeof refreshDisabledDatesForCurrentMonth === 'function' && window.fpBorrowDate) {
                                                        refreshDisabledDatesForCurrentMonth(window.fpBorrowDate, { code: code, quantity: qty });
                                                    }
                                                } catch (e) { console.error('refresh after preload error', e); }
                                        }
                                    })
                                    .catch(err => console.error('Preload availability error:', err));
                            }
                        }
                        if(cartItemsInput) {
                            cartItemsInput.value = JSON.stringify(cartItems);
                        }
                        renderCart();
                        refreshModeUI();
                        body.classList.remove('active');
                    });
                }
            });

            function refreshModeUI() {
                const hasSpace = cartItems.some(i => i.type === 'space');
                
                if (proposalGroup) {
                    proposalGroup.style.display = hasSpace ? 'block' : 'none';
                    if (proposalFileInput) {
                        proposalFileInput.required = hasSpace;
                    }
                }
                
                if (typeof window.updatePeriodOptions === 'function') {
                    window.updatePeriodOptions();
                }
            }

            function handleFormSubmit() {
                if (submitDebugMsg) {
                    submitDebugMsg.textContent = '已觸發送出，正在提交資料...';
                }
                if (cartItemsInput) {
                    cartItemsInput.value = JSON.stringify(cartItems);
                }
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.textContent = '送出中...';
                }
            }

            if (submitButton) {
                submitButton.addEventListener('click', function () {
                    if (submitDebugMsg) {
                        submitDebugMsg.textContent = '已按下送出按鈕，準備驗證欄位...';
                    }
                });
            }

            if (borrowForm) borrowForm.addEventListener('submit', handleFormSubmit);
            refreshModeUI();
            renderCart();
        })();
        
        // ================== 月曆即時計算與渲染邏輯 ==================
        
        const calData = {
            currentDate: new Date(), // 當前決定顯示哪一年的哪一個月
            selectedItemType: null,
            selectedItemId: null,
            selectedItemName: null,
            totalCapacity: 0,
            reservations: [],
            periodSlots: <?php echo json_encode($periodSlots); ?>, // 節次設定從後端帶入
            periodOrder: <?php echo json_encode($periodOrder); ?>  // D0, D1... 等等順序
        };
        
        const calUI = {
            select: document.getElementById('calItemSelect'),
            prevBtn: document.getElementById('calPrevBtn'),
            nextBtn: document.getElementById('calNextBtn'),
            monthLabel: document.getElementById('calMonthLabel'),
            loading: document.getElementById('calLoading'),
            emptyMsg: document.getElementById('calEmptyMsg'),
            container: document.getElementById('calContainer'),
            grid: document.getElementById('calGrid'),
            details: document.getElementById('calDetails'),
            detailsTitle: document.getElementById('calDetailsTitle'),
            detailsGrid: document.getElementById('calDetailsGrid')
        };
        
        // 基礎設置: 設定預設月份(可以為今天)
        calData.currentDate.setDate(1); // 固定為1號
        
        function updateCalendarHeader() {
            const y = calData.currentDate.getFullYear();
            const m = calData.currentDate.getMonth() + 1; // 0-based
            if(calUI.monthLabel) calUI.monthLabel.textContent = `${y} 年 ${m} 月`;
        }
        
        // 解析選擇的項目
        if(calUI.select) {
            calUI.select.addEventListener('change', function(e) {
                const val = e.target.value;
                if (!val) {
                    calData.selectedItemType = null;
                    calData.selectedItemId = null;
                    calData.selectedItemName = null;
                    calUI.emptyMsg.style.display = 'block';
                    calUI.container.style.display = 'none';
                    calUI.details.style.display = 'none';
                    return;
                }
                
                const parts = val.split('|');
                calData.selectedItemType = parts[0];
                calData.selectedItemId = parts[1];
                calData.selectedItemName = e.target.options[e.target.selectedIndex].text;
                
                calUI.emptyMsg.style.display = 'none';
                calUI.container.style.display = 'block';
                fetchAvailability();
            });
        }
        
        // 月份切換
        if(calUI.prevBtn) {
            calUI.prevBtn.addEventListener('click', () => {
                calData.currentDate.setMonth(calData.currentDate.getMonth() - 1);
                updateCalendarHeader();
                if(calData.selectedItemId) fetchAvailability();
            });
        }
        
        if(calUI.nextBtn) {
            calUI.nextBtn.addEventListener('click', () => {
                calData.currentDate.setMonth(calData.currentDate.getMonth() + 1);
                updateCalendarHeader();
                if(calData.selectedItemId) fetchAvailability();
            });
        }
        
        // 抓取線上預約紀錄
        function fetchAvailability() {
            calUI.loading.style.display = 'block';
            calUI.grid.style.visibility = 'hidden';
            calUI.details.style.display = 'none';
            
            const year = calData.currentDate.getFullYear();
            const month = calData.currentDate.getMonth() + 1;
            
            fetch(`api_get_availability.php?type=${calData.selectedItemType}&id=${calData.selectedItemId}&year=${year}&month=${month}`)
                .then(res => res.json())
                .then(data => {
                    calUI.loading.style.display = 'none';
                    calUI.grid.style.visibility = 'visible';
                    if (data.error) {
                        alert('載入失敗：' + data.error);
                        return;
                    }
                    calData.totalCapacity = data.total_capacity || 0;
                    calData.reservations = data.reservations || [];
                    renderCalendar();
                })
                .catch(err => {
                    console.error('Calendar Fetch Error:', err);
                    calUI.loading.style.display = 'none';
                    alert('無法讀取即時狀態，請重試。');
                });
        }
        
        // 畫出月曆
        function renderCalendar() {
            calUI.grid.innerHTML = '';
            
            const y = calData.currentDate.getFullYear();
            const m = calData.currentDate.getMonth(); // 0-based
            const firstDayObj = new Date(y, m, 1);
            const lastDayObj = new Date(y, m + 1, 0); // 這個月的最後一天
            
            let currentDayOfWeek = firstDayObj.getDay(); // 0=Sun, 1=Mon...
            const daysInMonth = lastDayObj.getDate();
            
            // 補空前導格子
            for (let i = 0; i < currentDayOfWeek; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'cal-day-cell empty';
                calUI.grid.appendChild(emptyCell);
            }
            
            // 印出每天的格子
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = `${y}-${String(m+1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const cell = document.createElement('div');
                cell.className = 'cal-day-cell';
                
                // 計算這一天「整天」的大致狀態 (找出一天中最糟的時段狀態)
                const dayStatus = calculateDayStatus(dateStr);
                
                const dateHeader = document.createElement('div');
                dateHeader.className = 'cal-day-date';
                dateHeader.textContent = String(d);
                
                const statusBox = document.createElement('div');
                statusBox.className = `cal-day-status status-${dayStatus.color}`;
                statusBox.innerHTML = `<span>${dayStatus.text}</span>`;
                
                cell.appendChild(dateHeader);
                cell.appendChild(statusBox);
                
                // 點擊查看詳細（器材以天為單位，不需要看詳細）
                if (calData.selectedItemType === 'space') {
                    cell.onclick = () => showDayDetails(dateStr, dayStatus);
                }
                
                calUI.grid.appendChild(cell);
            }
        }
        
        // 計算一日的大致狀態（用來標示月曆上的顏色）
        function calculateDayStatus(dateStr) {
            // 這個日期的 00:00 秒數，若比今天還早就顯示未知/過去
            const targetDateObj = new Date(dateStr + 'T00:00:00');
            const today = new Date();
            today.setHours(0,0,0,0);
            if (targetDateObj < today) {
                return { text: '已過去', color: 'unknown' };
            }
            
            let minAvail = calData.totalCapacity;
            let maxAvail = 0;
            
            // 迴圈算該天所有時段
            calData.periodOrder.forEach(pCode => {
                const pData = calData.periodSlots[pCode];
                let avail = calculateAvailableForPeriod(dateStr, pData.start, pData.end);
                if (avail < minAvail) minAvail = avail;
                if (avail > maxAvail) maxAvail = avail;
            });
            
            if (calData.totalCapacity === 0) {
                return { text: '無法出借', color: 'none' };
            }
            
            // 對於器材，以天為單位，直接顯示剩餘數量
            if (calData.selectedItemType === 'equipment') {
                if (minAvail === 0) {
                    return { text: '已借出', color: 'none' };
                } else {
                    return { text: `還可借 ${minAvail} 件`, color: 'full' };
                }
            }
            
            // 對於場地，保持現有邏輯
            if (minAvail === calData.totalCapacity) {
                return { text: '全天可借', color: 'full' };
            } else if (minAvail === 0 && maxAvail === 0) {
                return { text: '已借滿', color: 'none' };
            } else {
                return { text: '部分時段', color: 'partial' };
            }
        }
        
        // 核心數學：計算一個指定日期的特定時區，有多少物品剩餘
        function calculateAvailableForPeriod(dateStr, startPeriodTime, endPeriodTime) {
            let used = 0;
            
            // 對於器材，只要該天有任何預約就算已用（按天單位，不按時段）
            if (calData.selectedItemType === 'equipment') {
                calData.reservations.forEach(r => {
                    const rDate = r.start.substring(0, 10);
                    if (rDate === dateStr) {
                        used += r.qty;
                    }
                });
            } else {
                // 對於空間，保持原有時段重疊邏輯
                let pStart = new Date(`${dateStr}T${startPeriodTime}`).getTime();
                let pEnd = new Date(`${dateStr}T${endPeriodTime}`).getTime();
                
                calData.reservations.forEach(r => {
                    let rStart = new Date(r.start.replace(' ', 'T')).getTime();
                    let rEnd = new Date(r.end.replace(' ', 'T')).getTime();
                    
                    if (!(pEnd <= rStart || pStart >= rEnd)) {
                        used += r.qty;
                    }
                });
            }
            
            let finalAvail = calData.totalCapacity - used;
            return Math.max(0, finalAvail);
        }
        
        // 點開詳細節次清單
        function showDayDetails(dateStr, dayStatus) {
            calUI.details.style.display = 'block';
            calUI.detailsTitle.textContent = `【${calData.selectedItemName}】 ${dateStr} 詳細時段狀態`;
            calUI.detailsGrid.innerHTML = '';
            
            calData.periodOrder.forEach(pCode => {
                const pData = calData.periodSlots[pCode];
                let avail = calculateAvailableForPeriod(dateStr, pData.start, pData.end);
                
                const itemDiv = document.createElement('div');
                itemDiv.className = 'period-item';
                
                let bgColor = '#dcfce7'; // green
                let textColor = '#166534';
                let text = `剩餘 ${avail}`;
                
                if (avail === 0) {
                    bgColor = '#fee2e2'; // red
                    textColor = '#991b1b';
                    text = '已滿';
                } else if (avail < calData.totalCapacity) {
                    bgColor = '#fef9c3'; // yellow
                    textColor = '#854d0e';
                }
                
                // 如果是空間，顯示「可借用」、「已被約走」而不是剩餘1
                if (calData.selectedItemType === 'space') {
                    if (avail === 0) {
                        text = '被約走';
                    } else {
                        text = '可借用';
                    }
                }

                itemDiv.style.backgroundColor = bgColor;
                itemDiv.style.color = textColor;
                
                itemDiv.innerHTML = `
                    <div style="font-weight:bold; margin-bottom:3px;">${pData.label}</div>
                    <div style="font-size:11px; color:#666; margin-bottom:5px;">${pData.start.substring(0,5)} ~ ${pData.end.substring(0,5)}</div>
                    <div style="font-weight:bold; font-size:14px; border-top:1px solid rgba(0,0,0,0.1); padding-top:3px;">${text}</div>
                `;
                calUI.detailsGrid.appendChild(itemDiv);
            });
            
            // Scroll to details
            calUI.details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        // 初始化月曆 header
        updateCalendarHeader();
    </script>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
// 存儲當前選中的器材/空間的可用性數據（按日期緩存）
window.availabilityCache = window.availabilityCache || {};
const availabilityCache = window.availabilityCache;
const existingSpaceReservations = <?= json_encode($existingSpaceReservations ?? []); ?>;
const existingEquipmentReservations = <?= json_encode($existingEquipmentReservations ?? []); ?>;
const periodSlotsMap = <?= json_encode($periodSlots); ?>;

document.addEventListener('DOMContentLoaded', () => {
    const spaceIdEl = document.getElementById('space_id');
    const borrowDateEl = document.getElementById('borrow_date');
    const startPeriodEl = document.getElementById('start_period_code');
    const endPeriodEl = document.getElementById('end_period_code');
    const resTypeEl = document.getElementById('resource_type');
    
    // 驗證日期是否可選
    function isDateSelectable(dateStr, itemCode, itemType, reqQty) {
        if (!itemCode || !dateStr) return true;
        reqQty = reqQty || 1;
        
        if (itemType === 'equipment') {
            // 器材按天單位判斷：該天有任何預約就不可選
            const availability = availabilityCache[`${itemCode}_${dateStr}`];
            if (!availability) return true;
            
            let dayHasReservation = false;
            availability.reservations.forEach(r => {
                const rDate = r.start.substring(0, 10);
                if (rDate === dateStr) dayHasReservation = true;
            });
            return !dayHasReservation;
        }
        
        if (itemType === 'space') {
             const conflicts = existingSpaceReservations.filter(r => r.space_id === itemCode && r.date === dateStr);
             const periodOrder = Object.keys(periodSlotsMap);
             // 如果在所有時段中都有衝突，則全天不可選
             for (const code of periodOrder) {
                  const times = periodSlotsMap[code];
                  if (!times) continue;
                  const pStart = times.start;
                  const pEnd = times.end;
                  // 檢查此時段是否能借
                  let canBorrow = true;
                  for (const c of conflicts) {
                       if (pStart < c.end && pEnd > c.start) {
                            canBorrow = false;
                            break;
                       }
                  }
                  if (canBorrow) return true; // 只要有一個時段可以借，當天就可選
             }
             return false; // 全天衝突
        }
        
        const dateKey = `${itemCode}_${dateStr}`;
        if (!availabilityCache[dateKey]) return true; // 無數據時允許選擇
        
        const availability = availabilityCache[dateKey];
        const periodOrder = Object.keys(periodSlotsMap);
        
        // 檢查是否至少有一個時段可用
        for (const code of periodOrder) {
            const times = periodSlotsMap[code];
            if (!times) continue;
            
            let used = 0;
            const pStart = new Date(`${dateStr}T${times.start}`).getTime();
            const pEnd = new Date(`${dateStr}T${times.end}`).getTime();
            
            availability.reservations.forEach(r => {
                const rStart = new Date(r.start.replace(' ', 'T')).getTime();
                const rEnd = new Date(r.end.replace(' ', 'T')).getTime();
                
                if (!(pEnd <= rStart || pStart >= rEnd)) {
                    used += r.qty;
                }
            });
            
            const finalAvail = Math.max(0, availability.totalCapacity - used);
            if (finalAvail >= reqQty) {
                return true; // 至少有一個時段滿足所需數量
            }
        }
        
        return false; // 全天都已借滿或不足
    }

    function updatePeriodOptions() {
        const cartItemsInput = document.querySelector('input[name="cart_items"]');
        const cartItemsStr = cartItemsInput ? cartItemsInput.value : '[]';
        let cartItems = [];
        try { cartItems = JSON.parse(cartItemsStr); } catch (e) {}

        const equipmentObj = cartItems.find(c => c.type === 'equipment');
        const spaceObj = cartItems.find(c => c.type === 'space');
        const selEquipment = equipmentObj ? equipmentObj.code : '';
        const selSpace = spaceObj ? spaceObj.code : '';
        const selDate = borrowDateEl ? borrowDateEl.value : '';
        const now = new Date();
        console.log('updatePeriodOptions called', { selSpace, selDate, now: now.toString() });
        
        // Reset all options
        if (startPeriodEl) Array.from(startPeriodEl.options).forEach(opt => { 
            if(opt.value) {
                opt.disabled = false; 
                opt.innerHTML = opt.innerHTML.replace(' (���i�ɥ�)', '')
                                           .replace(' (過去時段)', '')
                                           .replace(' (已被預約)', '')
                                           .replace(' (緊鄰保留)', '')
                                           .replace(' (不可選)', '');
            }
        });
        if (endPeriodEl) Array.from(endPeriodEl.options).forEach(opt => { 
            if(opt.value) {
                opt.disabled = false;
                opt.innerHTML = opt.innerHTML.replace(' (���i�ɥ�)', '')
                                           .replace(' (過去時段)', '')
                                           .replace(' (已被預約)', '')
                                           .replace(' (緊鄰保留)', '')
                                           .replace(' (不可選)', '');
            }
        });

        if (!selDate) return;

        // 對於器材，基於實時API數據來禁用已借滿的時段
        if (selEquipment && selDate) {
            const year = selDate.substring(0, 4);
            const month = selDate.substring(5, 7);
            const dateKey = `${selEquipment}_${selDate}`;
            const availability = availabilityCache[dateKey];
            
            if (availability) {
                // 器材按天判：該天有任何預約就禁用所有時段
                let dayHasReservation = false;
                availability.reservations.forEach(r => {
                    const rDate = r.start.substring(0, 10);
                    if (rDate === selDate) dayHasReservation = true;
                });
                
                if (dayHasReservation) {
                    // 禁用所有時段
                    const periodOrder = Object.keys(periodSlotsMap);
                    for (const code of periodOrder) {
                        if (startPeriodEl) {
                            const opt1 = startPeriodEl.querySelector(`option[value="${code}"]`);
                            if (opt1) {
                                opt1.disabled = true;
                                if (!opt1.innerHTML.includes('該天已被預約')) opt1.innerHTML += ' (該天已被預約)';
                            }
                        }
                        if (endPeriodEl) {
                            const opt2 = endPeriodEl.querySelector(`option[value="${code}"]`);
                            if (opt2) {
                                opt2.disabled = true;
                                if (!opt2.innerHTML.includes('該天已被預約')) opt2.innerHTML += ' (該天已被預約)';
                            }
                        }
                    }
                    return;
                }
            } else {
                // 無快取，需要先取得
                const monthKey = `${selEquipment}_${year}_${month}`;
                if (!availabilityCache[monthKey]) {
                    fetch(`api_get_availability.php?type=equipment&id=${encodeURIComponent(selEquipment)}&year=${year}&month=${month}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.total_capacity !== undefined) {
                             availabilityCache[monthKey] = data;
                            const daysInMonth = new Date(parseInt(year,10), parseInt(month,10), 0).getDate();
                            for (let d = 1; d <= daysInMonth; d++) {
                                const dateStr = `${year}-${month}-${String(d).padStart(2,'0')}`;
                                const dateKeyInner = `${selEquipment}_${dateStr}`;
                                 availabilityCache[dateKeyInner] = {
                                    totalCapacity: data.total_capacity,
                                    reservations: data.reservations || []
                                };
                            }
                            // 再次執行以套用禁用
                            updatePeriodOptions();
                        }
                    })
                    .catch(err => console.error('Fetch availability error:', err));
                    return;
                } else {
                    if (!availabilityCache[dateKey]) {
                        const data = availabilityCache[monthKey];
                        availabilityCache[dateKey] = {
                            totalCapacity: data.total_capacity,
                            reservations: data.reservations || []
                        };
                    }
                }
            }

        }

        // Find conflicts (only relevant for space mode)
        const conflicts = (selSpace && selDate) ? existingSpaceReservations.filter(r => r.space_id === selSpace && r.date === selDate) : [];

        conflicts.forEach(c => {
            for (const [code, times] of Object.entries(periodSlotsMap)) {
                // overlapping periods (existing behavior)
                if (times.start < c.end && times.end > c.start) {
                    if (startPeriodEl) {
                        const opt1 = startPeriodEl.querySelector(`option[value="${code}"]`);
                        if (opt1) {
                            opt1.disabled = true;
                            if(!opt1.innerHTML.includes('已被預約')) opt1.innerHTML += ' (已被預約)';
                        }
                    }
                    if (endPeriodEl) {
                        const opt2 = endPeriodEl.querySelector(`option[value="${code}"]`);
                        if (opt2) {
                            opt2.disabled = true;
                            if(!opt2.innerHTML.includes('已被預約')) opt2.innerHTML += ' (已被預約)';
                        }
                    }
                }

                // 處理緊鄰保留：找到結束時間小於等於 c.end 的最後一個節次，並停用其下一節
                try {
                    function timeToSec(t) {
                        const p = (t||'00:00:00').split(':').map(x=>parseInt(x,10)||0);
                        return p[0]*3600 + p[1]*60 + p[2];
                    }
                    const cEndSec = timeToSec(c.end);
                    // 建立 periodEndSeconds 陣列
                    const periodCodes = Object.keys(periodSlotsMap);
                    const periodEndSecs = periodCodes.map(cd => timeToSec(periodSlotsMap[cd].end));
                    // 找最後一個 endSec <= cEndSec (或最接近)
                    let lastIdx = -1;
                    for (let idx = 0; idx < periodEndSecs.length; idx++) {
                        if (periodEndSecs[idx] <= cEndSec + 1) {
                            lastIdx = idx;
                        } else {
                            break;
                        }
                    }
                    const nextIdx = lastIdx + 1;
                    if (nextIdx >= 0 && nextIdx < periodCodes.length) {
                        const nextCode = periodCodes[nextIdx];
                        if (startPeriodEl) {
                            const opt1n = startPeriodEl.querySelector(`option[value="${nextCode}"]`);
                            if (opt1n) {
                                opt1n.disabled = true;
                                if(!opt1n.innerHTML.includes(' (緊鄰保留)')) opt1n.innerHTML += ' (緊鄰保留)';
                                console.log('disable adjacent next start', nextCode, 'for reservation ending at', c.end);
                            }
                        }
                        if (endPeriodEl) {
                            const opt2n = endPeriodEl.querySelector(`option[value="${nextCode}"]`);
                            if (opt2n) {
                                opt2n.disabled = true;
                                if(!opt2n.innerHTML.includes(' (緊鄰保留)')) opt2n.innerHTML += ' (緊鄰保留)';
                                console.log('disable adjacent next end', nextCode, 'for reservation ending at', c.end);
                            }
                        }
                    }
                } catch (e) {
                    console.error('adjacent disable error', e);
                }
            }
        });

        // 若選擇的是今天，停用已過的節次（包含已開始或已結束的節次）
        try {
            const now = new Date();
            const todayStr = now.toISOString().slice(0,10);
            const currentTimeStr = now.toTimeString().split(' ')[0]; // HH:MM:SS
            const periodOrder = Object.keys(periodSlotsMap);

            if (selDate === todayStr) {
                // helper to build Date from YYYY-MM-DD and HH:MM:SS parts (local time)
                function makeDateFromYMDTime(ymd, timeStr) {
                    const [y, m, d] = (ymd || '').split('-').map(s => parseInt(s, 10));
                    const parts = (timeStr || '00:00:00').split(':').map(s => parseInt(s, 10));
                    const hh = parts[0] || 0;
                    const mm = parts[1] || 0;
                    const ss = parts[2] || 0;
                    return new Date(y, (m || 1) - 1, d || 1, hh, mm, ss);
                }

                for (const [code, times] of Object.entries(periodSlotsMap)) {
                    const startDt = makeDateFromYMDTime(selDate, times.start);
                    const endDt = makeDateFromYMDTime(selDate, times.end);
                    if (startDt <= now) {
                        if (startPeriodEl) {
                            const opt1 = startPeriodEl.querySelector(`option[value="${code}"]`);
                            if (opt1) {
                                opt1.disabled = true;
                                if (!opt1.innerHTML.includes('過去時段')) opt1.innerHTML += ' (過去時段)';
                                console.log('disable start option due to past', code, startDt.toString(), now.toString());
                            }
                        }
                        if (endPeriodEl) {
                            const opt2 = endPeriodEl.querySelector(`option[value="${code}"]`);
                            if (opt2) {
                                opt2.disabled = true;
                                if (!opt2.innerHTML.includes('過去時段')) opt2.innerHTML += ' (過去時段)';
                                console.log('disable end option due to past', code, endDt.toString(), now.toString());
                            }
                        }
                    }
                }
            }

            // 如果已選開始節次，限制結束節次不得早於開始節次，且若為今天也不可選已過的結束節次
            if (startPeriodEl && endPeriodEl && startPeriodEl.value) {
                const startVal = startPeriodEl.value;
                const startIndex = periodOrder.indexOf(startVal);
                if (startIndex !== -1) {
                    Array.from(endPeriodEl.options).forEach(opt => {
                        if (!opt.value) return;
                        const optIndex = periodOrder.indexOf(opt.value);
                        let shouldDisable = optIndex < startIndex;
                        // 當選擇的是今天，若該節次的結束時間已在過去也不可選
                        if (selDate === todayStr) {
                            const optTimes = periodSlotsMap[opt.value];
                            if (optTimes) {
                                const endDt = makeDateFromYMDTime(selDate, optTimes.end);
                                if (endDt <= now) shouldDisable = true;
                            }
                        }
                        opt.disabled = shouldDisable;
                        // 加上提示文字
                        if (shouldDisable) {
                            if (!opt.innerHTML.includes(' (不可選)') && !opt.innerHTML.includes('過去時段')) {
                                opt.innerHTML += ' (不可選)';
                            }
                        } else {
                            opt.innerHTML = opt.innerHTML.replace(' (不可選)', '').replace(' (過去時段)', '');
                        }
                    });
                }
            }
        } catch (e) {
            // ignore any unexpected errors in client-side time-checking
            console.error(e);
        }
    }

    if (borrowDateEl) {
        // Initialize flatpickr on the borrow date input and manage per-day disabling for equipment
        let disabledDatesSet = new Set();
        let fp = null;

        function fallbackToNativeDate() {
            try {
                borrowDateEl.removeAttribute('readonly');
                borrowDateEl.type = 'date';
            } catch (e) { console.error('fallbackToNativeDate error', e); }
        }

        if (typeof flatpickr !== 'function') {
            console.warn('flatpickr not available; falling back to native date input');
            fallbackToNativeDate();
        } else {
            try {
                fp = flatpickr(borrowDateEl, {
                    dateFormat: 'Y-m-d',
                    minDate: borrowDateEl.getAttribute('data-mindate') || 'today',
                    disable: [],
                    onChange: function(selectedDates, dateStr) {
                        const selDate = dateStr;
                        const cartItemsInput = document.querySelector('input[name="cart_items"]');
                        const cartItemsStr = cartItemsInput ? cartItemsInput.value : '[]';
                        let cartItems = [];
                        try { cartItems = JSON.parse(cartItemsStr); } catch (e) {}

                        const equipmentObj = cartItems.find(c => c.type === 'equipment');
                        const spaceObj = cartItems.find(c => c.type === 'space');

                        function proceedUpdate() {
                            if (equipmentObj) {
                                if (!isDateSelectable(selDate, equipmentObj.code, 'equipment', parseInt(equipmentObj.quantity, 10))) {
                                    alert('器材在該日期全天已借滿，請選擇其他日期。');
                                    fp.clear();
                                    updatePeriodOptions();
                                    return;
                                }
                            }
                            if (spaceObj) {
                                if (!isDateSelectable(selDate, spaceObj.code, 'space', 1)) {
                                    alert('空間在該日期全天已被預約，請選擇其他日期。');
                                    fp.clear();
                                    updatePeriodOptions();
                                    return;
                                }
                            }
                            updatePeriodOptions();
                        }

                        if (equipmentObj && selDate) {
                            const year = selDate.substring(0, 4);
                            const month = selDate.substring(5, 7);
                            const monthKey = `${equipmentObj.code}_${year}_${month}`;
                            if (!availabilityCache[monthKey]) {
                                fetch(`api_get_availability.php?type=equipment&id=${encodeURIComponent(equipmentObj.code)}&year=${year}&month=${month}`)
                                    .then(res => res.json())
                                    .then(data => {
                                        availabilityCache[monthKey] = data;
                                        availabilityCache[`${equipmentObj.code}_${selDate}`] = { totalCapacity: data.total_capacity, reservations: data.reservations || [] };
                                        proceedUpdate();
                                        refreshDisabledDatesForCurrentMonth(fp, equipmentObj);
                                    })
                                    .catch(err => { console.error('Date check error:', err); proceedUpdate(); });
                            } else {
                                if (!availabilityCache[`${equipmentObj.code}_${selDate}`]) {
                                    const data = availabilityCache[monthKey];
                                    availabilityCache[`${equipmentObj.code}_${selDate}`] = { totalCapacity: data.total_capacity, reservations: data.reservations || [] };
                                }
                                proceedUpdate();
                            }
                        } else if (spaceObj && selDate) {
                            if (!isDateSelectable(selDate, spaceObj.code, 'space', 1)) {
                                alert('空間在該日期全天已被預約，請選擇其他日期。');
                                fp && fp.clear();
                            }
                            updatePeriodOptions();
                        } else {
                            updatePeriodOptions();
                        }
                    },
                    onMonthChange: function() { refreshDisabledDatesForCurrentMonth(fp); },
                    onYearChange: function() { refreshDisabledDatesForCurrentMonth(fp); },
                    onDayCreate: function(dObj, dStr, fpObj, dayElem) {
                        try {
                            const d = dayElem.dateObj.toISOString().slice(0,10);
                            if (disabledDatesSet && disabledDatesSet.has(d)) dayElem.classList.add('borrow-disabled');
                        } catch(e) {}
                    }
                });
            } catch (e) {
                console.error('flatpickr init error', e);
                fallbackToNativeDate();
            }
        }

        window.fpBorrowDate = fp;

        function refreshDisabledDatesForCurrentMonth(fpInstance, equipmentObjParam) {
            const cartItemsInput = document.querySelector('input[name="cart_items"]');
            const cartItemsStr = cartItemsInput ? cartItemsInput.value : '[]';
            let cartItems = [];
            try { cartItems = JSON.parse(cartItemsStr); } catch (e) {}
            const equipmentObj = equipmentObjParam || cartItems.find(c => c.type === 'equipment');
            if (!equipmentObj) {
                disabledDatesSet = new Set();
                fpInstance.set('disable', []);
                fpInstance.redraw && fpInstance.redraw();
                return;
            }
            const year = String(fpInstance.currentYear);
            const month = String(fpInstance.currentMonth + 1).padStart(2, '0');
            fetch(`api_get_availability.php?type=equipment&id=${encodeURIComponent(equipmentObj.code)}&year=${year}&month=${month}`)
                .then(res => res.json())
                .then(data => {
                    const total = data.total_capacity || 0;
                    const reservations = data.reservations || [];
                    const yNum = parseInt(year, 10);
                    const mNum = parseInt(month, 10);
                    const lastDay = new Date(yNum, mNum, 0).getDate();
                    const set = new Set();
                    for (let d = 1; d <= lastDay; d++) {
                        const day = `${year}-${String(mNum).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
                        let dayHasReservation = false;
                        reservations.forEach(r => {
                            const rDate = r.start.substring(0, 10);
                            if (rDate === day) dayHasReservation = true;
                        });
                        if (dayHasReservation) set.add(day);
                    }
                    disabledDatesSet = set;
                    fpInstance.set('disable', [function(date) { const ds = date.toISOString().slice(0,10); return disabledDatesSet.has(ds); }]);
                    fpInstance.redraw && fpInstance.redraw();
                })
                .catch(err => { console.error('fetch month avail error', err); });
        }

        // wrap existing updatePeriodOptions to also refresh disabled dates
        if (window.updatePeriodOptions) {
            const _orig = window.updatePeriodOptions;
            window.updatePeriodOptions = function() { _orig(); try { refreshDisabledDatesForCurrentMonth(window.fpBorrowDate); } catch(e) {} };
        }
        if (startPeriodEl) startPeriodEl.addEventListener('change', updatePeriodOptions);
        if (borrowDateEl) {
            borrowDateEl.addEventListener('change', updatePeriodOptions);
            borrowDateEl.addEventListener('input', updatePeriodOptions);
        }

        // Expose function globally so cart changes can trigger this
        window.updatePeriodOptions = updatePeriodOptions;

        // Prevent user from selecting a disabled option (some browsers/clients may bypass)
        if (startPeriodEl) {
            startPeriodEl.addEventListener('change', function () {
                const opt = startPeriodEl.options[startPeriodEl.selectedIndex];
                if (opt && opt.disabled) {
                    alert('所選開始節次不可選，請選擇其他節次。');
                    startPeriodEl.value = '';
                    updatePeriodOptions();
                }
            });
        }
        if (endPeriodEl) {
            endPeriodEl.addEventListener('change', function () {
                const opt = endPeriodEl.options[endPeriodEl.selectedIndex];
                if (opt && opt.disabled) {
                    alert('所選結束節次不可選，請選擇其他節次。');
                    endPeriodEl.value = '';
                    updatePeriodOptions();
                }
            });
        }

        updatePeriodOptions();
    }
});
</script>
</body>
</html>


