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
            COALESCE(SUM(CASE WHEN e.operation_status = 1 THEN 1 ELSE 0 END), 0) - COALESCE(COUNT(eri.equipment_id), 0) AS available_quantity
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
    'organization_name' => '',
    'activity_name' => '',
    'participant_count' => '',
    'staff_count' => '',
    'club_president' => '',
    'activity_coordinator' => '',
    'coordinator_department' => '',
    'coordinator_phone' => '',
    'coordinator_other_contact' => '',
    'vehicle_entry' => 'no',
    'has_alcohol' => '',
    'has_fire' => '',
    'has_sales' => '',
    'setup_flags' => 'no',
    'flag_count' => 1,
    'flag_agreement' => '',
    'resource_type' => 'equipment',
    'equipment_code' => '',
    'space_id' => '',
    'borrow_start_date' => '',
    'borrow_start_time' => '',
    'borrow_end_date' => '',
    'borrow_end_time' => '',
    'purpose' => '',
    'phone' => $userPhone,
    'has_alcohol' => '',
    'has_fire' => '',
    'has_sales' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " POST handler invoked\nPOST:" . json_encode($_POST) . "\nFILES:" . json_encode(array_map(function($f){ return is_array($f)?array_merge($f,['tmp_name'=>'...']):$f; }, $_FILES)) . "\n\n", FILE_APPEND | LOCK_EX);
    $formData['resource_type'] = trim((string)($_POST['resource_type'] ?? 'equipment'));
    $cartItemsRaw = trim((string)($_POST['cart_items'] ?? '[]'));
    $cartItems = json_decode($cartItemsRaw, true);
    if (!is_array($cartItems)) {
        $cartItems = [];
    }
    // We override equipment_code since cart_items processes multiple
    $formData['equipment_code'] = 'unused';
    $formData['organization_name'] = trim((string)($_POST['organization_name'] ?? ''));
    $formData['activity_name'] = trim((string)($_POST['activity_name'] ?? ''));
    $formData['participant_count'] = trim((string)($_POST['participant_count'] ?? ''));
    $formData['staff_count'] = trim((string)($_POST['staff_count'] ?? ''));
    $formData['club_president'] = trim((string)($_POST['club_president'] ?? ''));
    $formData['activity_coordinator'] = trim((string)($_POST['activity_coordinator'] ?? ''));
    $formData['coordinator_department'] = trim((string)($_POST['coordinator_department'] ?? ''));
    $formData['coordinator_phone'] = trim((string)($_POST['coordinator_phone'] ?? ''));
    $formData['coordinator_other_contact'] = trim((string)($_POST['coordinator_other_contact'] ?? ''));
    $formData['vehicle_entry'] = trim((string)($_POST['vehicle_entry'] ?? 'no'));
    $formData['has_alcohol'] = isset($_POST['has_alcohol']) ? '1' : '';
    $formData['has_fire'] = isset($_POST['has_fire']) ? '1' : '';
    $formData['has_sales'] = isset($_POST['has_sales']) ? '1' : '';
    $formData['setup_flags'] = trim((string)($_POST['setup_flags'] ?? 'no'));
    $formData['flag_count'] = (int)($_POST['flag_count'] ?? 1);
<<<<<<< HEAD
    $formData['has_alcohol'] = isset($_POST['has_alcohol']) ? '1' : '';
    $formData['has_fire'] = isset($_POST['has_fire']) ? '1' : '';
    $formData['has_sales'] = isset($_POST['has_sales']) ? '1' : '';
=======
    $formData['flag_agreement'] = isset($_POST['flag_agreement']) ? '1' : '';
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3
    $formData['space_id'] = trim((string)($_POST['space_id'] ?? ''));
    $formData['borrow_start_date'] = trim((string)($_POST['borrow_start_date'] ?? ''));
    
    $bsh = $_POST['borrow_start_time_h'] ?? '';
    $bsm = $_POST['borrow_start_time_m'] ?? '';
    $formData['borrow_start_time'] = ($bsh !== '' && $bsm !== '') ? sprintf('%02d:%02d:00', $bsh, $bsm) : '';

    $formData['borrow_end_date'] = trim((string)($_POST['borrow_end_date'] ?? ''));
    
    $beh = $_POST['borrow_end_time_h'] ?? '';
    $bem = $_POST['borrow_end_time_m'] ?? '';
    $formData['borrow_end_time'] = ($beh !== '' && $bem !== '') ? sprintf('%02d:%02d:00', $beh, $bem) : '';

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
            $formData['borrow_start_date'] !== '' &&
            $formData['borrow_start_time'] !== '' &&
            $formData['borrow_end_date'] !== '' &&
            $formData['borrow_end_time'] !== ''
        ) {
            $borrowStartAtSql = $formData['borrow_start_date'] . ' ' . $formData['borrow_start_time'];
            $borrowEndAtSql = $formData['borrow_end_date'] . ' ' . $formData['borrow_end_time'];
            
            if (strtotime($borrowStartAtSql) < time()) {
                $borrowError = '借用開始時間不可為過去時間。';
            } elseif (strtotime($borrowEndAtSql) <= strtotime($borrowStartAtSql)) {
                $borrowError = '結束時間不可早於或等於開始時間。';
            } else {
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
                                'SELECT COALESCE(COUNT(eri.equipment_id), 0) AS total_quantity
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
            $formData['borrow_start_date'] === '' ||
            $formData['borrow_start_time'] === '' ||
            $formData['borrow_end_date'] === '' ||
            $formData['borrow_end_time'] === ''
        ) {
            $borrowError = '請完整填寫借用起訖日期與時間。';
        } elseif ($formData['purpose'] === '') {
            $borrowError = '請填寫用途說明。';
        } elseif ($formData['setup_flags'] === 'yes' && $formData['flag_count'] > 20) {
            $borrowError = '宣傳旗幟最多只能選 20 支。';
<<<<<<< HEAD
        } elseif ($formData['setup_flags'] === 'yes' && $formData['flag_count'] < 1) {
            $borrowError = '宣傳旗幟數量至少為 1 支。';
        } elseif ($formData['setup_flags'] === 'yes' && !isset($_POST['flag_agree'])) {
            $borrowError = '請勾選：已閱讀並同意旗幟插立注意事項。';
        } elseif ($formData['setup_flags'] === 'yes' && $formData['borrow_start_date'] !== '' && strtotime($formData['borrow_start_date']) < strtotime('+7 weekdays', strtotime(date('Y-m-d')))) {
            $borrowError = '插立旗幟使用日期只能選 7 個工作天之後的日期。';
=======
        } elseif ($formData['setup_flags'] === 'yes' && empty($formData['flag_agreement'])) {
            $borrowError = '您必須勾選同意旗幟插立各項注意事項及無條件承擔賠償責任聲明。';
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3
        } else {
            $requires30Days = false;
            if (
                $formData['has_alcohol'] === '1' || 
                $formData['has_fire'] === '1' || 
                $formData['has_sales'] === '1' || 
                $formData['participant_count'] === '100~200人' || 
                $formData['participant_count'] === '200人以上'
            ) {
                $requires30Days = true;
            }

            if ($requires30Days && $formData['borrow_start_date'] !== '' && strtotime($formData['borrow_start_date']) < strtotime('+30 days', strtotime(date('Y-m-d')))) {
                $borrowError = '包含特殊性質（酒精、明火、攤販或超過100人）的活動，必須在 30 天之前申請。';
            } elseif ($formData['setup_flags'] === 'yes' && $formData['borrow_start_date'] !== '' && strtotime($formData['borrow_start_date']) < strtotime('+7 weekdays', strtotime(date('Y-m-d')))) {
                $borrowError = '插立旗幟使用日期只能選 7 個工作天之後的日期。';
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
    certificate_id BIGINT UNSIGNED NULL,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (space_item_id)
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
                        }

                        mysqli_begin_transaction($link);

            try {
                $uploadedProposalPath = null;

                // Ensure `reservations` has `proposal_file` and `proposal_uploaded_at` columns.
                $reservationColsRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'proposal_file\'');
                if (!($reservationColsRes && mysqli_num_rows($reservationColsRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN proposal_file VARCHAR(255) NULL")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add proposal_file failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
                $reservationUploadedAtRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'proposal_uploaded_at\'');
                if (!($reservationUploadedAtRes && mysqli_num_rows($reservationUploadedAtRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN proposal_uploaded_at DATETIME NULL")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add proposal_uploaded_at failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
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

                $hasSubmittedAtCol = in_array('submitted_at', $reservationCols, true);
                $hasApprovalStageCol = in_array('approval_stage', $reservationCols, true);

                // 自動將使用者的證照編號寫入 reservations.certificate_id
                $certificateId = null;
                $certSelectStmt = mysqli_prepare(
                    $link,
                    'SELECT certificate_id FROM equipment_certificates WHERE holder_id = ? AND validity_status = "valid" ORDER BY issue_date DESC LIMIT 1'
                );
                if ($certSelectStmt) {
                    mysqli_stmt_bind_param($certSelectStmt, 's', $userId);
                    mysqli_stmt_execute($certSelectStmt);
                    $certSelectResult = mysqli_stmt_get_result($certSelectStmt);
                    if ($certSelectResult && $certRow = mysqli_fetch_assoc($certSelectResult)) {
                        $certificateId = (int)$certRow['certificate_id'];
                    }
                    mysqli_stmt_close($certSelectStmt);
                }
                
                $submittedAtVal = date('Y-m-d H:i:s'); // 保證同一批次提交時間一致
<<<<<<< HEAD
                $insertCols = [$applicantColumn, 'borrow_start_at', 'borrow_end_at', 'organization_name', 'activity_name', 'participant_count', 'staff_count', 'club_president', 'activity_coordinator', 'coordinator_department', 'coordinator_phone', 'coordinator_other_contact', 'vehicle_entry', 'setup_flags', 'flag_count', 'has_alcohol', 'has_fire', 'has_sales'];
                $bindValuesTemplate = [$userId, $borrowStartAtSql, $borrowEndAtSql, $formData['organization_name'], $formData['activity_name'], $formData['participant_count'], (int)$formData['staff_count'], $formData['club_president'], $formData['activity_coordinator'], $formData['coordinator_department'], $formData['coordinator_phone'], $formData['coordinator_other_contact'], $formData['vehicle_entry'], $formData['setup_flags'], (int)$formData['flag_count'], $formData['has_alcohol'], $formData['has_fire'], $formData['has_sales']];
                $bindTypesTemplate = 'ssssssisssssssisss';
=======
                $insertCols = [$applicantColumn, 'borrow_start_at', 'borrow_end_at', 'organization_name', 'activity_name', 'participant_count', 'staff_count', 'club_president', 'activity_coordinator', 'coordinator_department', 'coordinator_phone', 'coordinator_other_contact', 'vehicle_entry', 'has_alcohol', 'has_fire', 'has_sales', 'setup_flags', 'flag_count'];
                $bindValuesTemplate = [$userId, $borrowStartAtSql, $borrowEndAtSql, $formData['organization_name'], $formData['activity_name'], $formData['participant_count'], (int)$formData['staff_count'], $formData['club_president'], $formData['activity_coordinator'], $formData['coordinator_department'], $formData['coordinator_phone'], $formData['coordinator_other_contact'], $formData['vehicle_entry'], $formData['has_alcohol'], $formData['has_fire'], $formData['has_sales'], $formData['setup_flags'], (int)$formData['flag_count']];
                $bindTypesTemplate = 'ssssssissssssssssi';
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3

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

                if ($hasApprovalStageCol) {
                    // new submissions start at stage 'a'
                    $insertCols[] = 'approval_stage';
                    $bindValuesTemplate[] = 'a';
                    $bindTypesTemplate .= 's';
                }

                if ($hasCertificateIdCol) {
                    $insertCols[] = 'certificate_id';
                    $bindValuesTemplate[] = $certificateId;
                    $bindTypesTemplate .= 'i';
                }

                $colsSql = implode(", ", $insertCols) . ", approval_status, created_at";
                $placeholders = implode(', ', array_fill(0, count($insertCols), '?')) . ', "pending", NOW()';
                $insertReservationSql = sprintf("INSERT INTO reservations ( %s ) VALUES (%s)", $colsSql, $placeholders);

                $createdReservationIds = [];

                // 企劃書相關變數
                $proposalFileForReservation = null;
                $proposalUploadedAtForReservation = null;

                // 只建立一張預約單 (不論是借器材還是場地)
                $reservationStmt = mysqli_prepare($link, $insertReservationSql);
                if (!$reservationStmt) {
                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_error($link));
                }
                mysqli_stmt_bind_param($reservationStmt, $bindTypesTemplate, ...$bindValuesTemplate);
                mysqli_stmt_execute($reservationStmt);
                $commonReservationId = (int)mysqli_insert_id($link);
                mysqli_stmt_close($reservationStmt);

                $createdReservationIds = [$commonReservationId];
                $reservationId = $commonReservationId;

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
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id) VALUES (?, ?)'
                    );
                    $updateEquipmentStatusStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1 AND ? <= NOW()'
                    );
                    if (!$stockCheckStmt || !$selectEquipmentStmt || !$reservationItemStmt || !$updateEquipmentStatusStmt) {
                        throw new RuntimeException('建立器材預約明細指令失敗：' . mysqli_error($link));
                    }

                    // 針對購物車內【每一個器材項目】建立各自獨立的預約單 (reservation)
                    foreach ($cartEquipments as $item) {
                        $cCode = $item['code'];
                        $cQty = (int)$item['quantity'];

                                                // 檢查該天是否已有任何預約（器材按天單位）
                                                $overlapCheckSql = "
                                                        SELECT COALESCE(COUNT(eri.equipment_id), 0) AS used_qty
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

                        // 使用共用的預約單 ID（同一筆申請共用一個 reservation）
                        $itemReservationId = $commonReservationId;

                        // 將實體器材加入該預約單
                        foreach ($equipmentIds as $equipmentId) {
                            mysqli_stmt_bind_param($reservationItemStmt, 'ii', $itemReservationId, $equipmentId);
                            mysqli_stmt_execute($reservationItemStmt);
                            
                            mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'is', $equipmentId, $borrowStartAtSql);
                            mysqli_stmt_execute($updateEquipmentStatusStmt);
                        }
                    }

                    mysqli_stmt_close($stockCheckStmt);
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($reservationItemStmt);
                    mysqli_stmt_close($updateEquipmentStatusStmt);
                }

                $proposalFileForReservation = null;
                $proposalUploadedAtForReservation = null;

                if (isset($_FILES['proposal_file']) && $_FILES['proposal_file']['error'] !== UPLOAD_ERR_NO_FILE) {
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

                    $proposalFileForReservation = 'uploads/proposals/' . $targetName;
                    $proposalUploadedAtForReservation = date('Y-m-d H:i:s');
                    $uploadedProposalPath = $targetPath;

                    $updateProposalStmt = mysqli_prepare(
                        $link,
                        'UPDATE reservations SET proposal_file = ?, proposal_uploaded_at = ? WHERE reservation_id = ?'
                    );
                    if (!$updateProposalStmt) {
                        throw new RuntimeException('更新企劃書資訊失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($updateProposalStmt, 'ssi', $proposalFileForReservation, $proposalUploadedAtForReservation, $commonReservationId);
                    mysqli_stmt_execute($updateProposalStmt);
                    mysqli_stmt_close($updateProposalStmt);
                }

                if (!empty($formData['space_id'])) {
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
                        'INSERT INTO space_reservation_items (reservation_id, space_id) VALUES (?, ?)'
                    );
                    if (!$spaceItemStmt) {
                        throw new RuntimeException('建立空間預約明細失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($spaceItemStmt, 'is', $reservationId, $formData['space_id']);
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
                                $mail->Username   = 'right.jing0104@gmail.com';
                                $mail->Password   = 'hwarm0625.0603';      
                                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                                $mail->Port       = 465;
                                $mail->CharSet    = 'UTF-8';
                                $mail->setFrom('right.jing0104@gmail.com', '器材借用系統');
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


                /* 草稿功能區 */
        .draft-action-row{
            display:flex;
            gap:15px;
            margin-top:15px;
            width:100%;
        }

        .draft-btn{
            flex:1;
            border:none;
            border-radius:10px;
            padding:14px 20px;
            font-size:15px;
            font-weight:600;
            cursor:pointer;
            transition:all .25s ease;
        }

        .save-btn{
            background:#f59e0b;
            color:#fff;
        }

        .save-btn:hover{
            background:#d97706;
            transform:translateY(-2px);
        }

        .draft-box-btn{
            background:#6366f1;
            color:#fff;
        }

        .draft-box-btn:hover{
            background:#4338ca;
            transform:translateY(-2px);
        }

        .draft-message{
            margin-top:10px;
            font-size:14px;
            color:#64748b;
        }



    </style>
    <!-- 引入 Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh-tw.js"></script>
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
                <h2>申請</h2>
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

                <div class="borrow-layout full-width-layout" id="mainBorrowLayout">
                    <section class="card borrow-form-card">
                        <!-- 進度條 Stepper -->
                        <div class="stepper-container">
                            <div class="stepper-item active" id="stepper-1" onclick="goToStep(1)" style="cursor: pointer;">
                                <div class="step-circle">1</div>
                                <div class="step-label">活動申請(1/2)</div>
                            </div>
                            <div class="stepper-line"></div>
                            <div class="stepper-item" id="stepper-2" onclick="goToStep(2)" style="cursor: pointer;">
                                <div class="step-circle">2</div>
                                <div class="step-label">活動申請(2/2)</div>
                            </div>
                            <div class="stepper-line"></div>
                            <div class="stepper-item" id="stepper-3" onclick="goToStep(3)" style="cursor: pointer;">
                                <div class="step-circle">3</div>
                                <div class="step-label">器材與場地 (確認送出)</div>
                            </div>
                        </div>

                        <form method="post" enctype="multipart/form-data" class="borrow-form" action="borrow.php" novalidate id="multistep_form">
                            <input type="hidden" name="current_step" id="current_step" value="<?php echo htmlspecialchars($_POST['current_step'] ?? '1', ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="current_draft_id" id="current_draft_id" value="">
                            <input type="file" id="proposal_file" name="proposal_file" accept=".pdf,application/pdf" style="opacity: 0; position: absolute; z-index: -1; width: 0; height: 0;" onchange="document.getElementById('proposal_file_name_display').innerText = this.files.length > 0 ? this.files[0].name : '';">
                            <!-- ========== 步驟 1 內容區 ========== -->
                            <div class="step-content active" id="step-content-1">
                                <h3 class="step-title" style="margin-bottom: 10px;">第一步：活動基本資料</h3>
                                <p class="step-desc" style="color: #7f8c8d; margin-bottom: 20px;">請填寫活動相關資訊與申請日期。</p>

                                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 20px; background: #eef2ff; padding: 15px; border-radius: 8px; border: 1px solid #c7d2fe;">
                                    <h4 style="margin: 0; color: #1e40af; font-size: 16px;">企劃書</h4>
                                    <label for="proposal_file" style="margin: 0; background-color: #1554b9; color: white; padding: 6px 15px; border-radius: 5px; cursor: pointer; font-size: 16px; font-weight: normal; transition: background 0.2s;">
                                        📤 按此上傳活動企劃書 (僅接受PDF檔)
                                    </label>
                                    <span id="proposal_file_name_display" style="font-size: 14px; color: #1554b9; font-weight: 500;"></span>
                                </div>

                                <div class="form-group" style="margin-top: 10px;">
                                    <label for="organization_name">單位名稱 / 主辦社團 <span style="color:red">*</span></label>
                                    <input type="text" id="organization_name" name="organization_name" class="" placeholder="請輸入主辦單位名稱" value="<?php echo htmlspecialchars($formData['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label for="activity_name">活動名稱 <span style="color:red">*</span></label>
                                    <input type="text" id="activity_name" name="activity_name" class="form-control" placeholder="請輸入活動名稱" value="<?php echo htmlspecialchars($formData['activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <div class="form-group" style="flex: 1; min-width: 150px;">
                                        <label for="participant_count">活動對象人數 (100人以上的活動只能選擇30天後的日期) <span style="color:red">*</span></label>
                                        <select id="participant_count" name="participant_count" class="" required style="padding: 8px;">
                                            <option value="" <?php echo (($formData['participant_count'] ?? '') === '') ? 'selected' : ''; ?>>請選擇</option>
                                            <option value="50人以下" <?php echo (($formData['participant_count'] ?? '') === '50人以下') ? 'selected' : ''; ?>>50人以下</option>
                                            <option value="50~100人" <?php echo (($formData['participant_count'] ?? '') === '50~100人') ? 'selected' : ''; ?>>50~100人</option>
                                            <option value="100~200人" <?php echo (($formData['participant_count'] ?? '') === '100~200人') ? 'selected' : ''; ?>>100~200人</option>
                                            <option value="200人以上" <?php echo (($formData['participant_count'] ?? '') === '200人以上') ? 'selected' : ''; ?>>200人以上</option>
                                        </select>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 150px;">
                                        <label for="staff_count">工作人員人數 <span style="color:red">*</span></label>
                                        <input type="number" id="staff_count" name="staff_count" class="form-control" placeholder="請輸入人數" min="1" required>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label for="club_president">社 / 會長 <span style="color:red">*</span></label>
                                        <input type="text" id="club_president" name="club_president" class="" placeholder="請輸入姓名" value="<?php echo htmlspecialchars($formData['club_president'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label for="activity_coordinator">活動負責人<span style="color:red">*</span></label>
                                        <input type="text" id="activity_coordinator" name="activity_coordinator" class="form-control" placeholder="請輸入活動負責人姓名" value="<?php echo htmlspecialchars($formData['activity_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label for="coordinator_department">系級<span style="color:red">*</span></label>
                                        <input type="text" id="coordinator_department" name="coordinator_department" class="form-control" placeholder="請輸入系級" value="<?php echo htmlspecialchars($formData['coordinator_department'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group" style="flex: 1; min-width: 150px; margin-bottom: 0;">
                                        <label for="coordinator_phone">聯絡電話<span style="color:red">*</span></label>
                                        <input type="text" id="coordinator_phone" name="coordinator_phone" class="form-control" placeholder="請輸入聯絡電話" value="<?php echo htmlspecialchars($formData['coordinator_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="coordinator_other_contact">其他聯絡方式</label>
                                    <input type="text" id="coordinator_other_contact" name="coordinator_other_contact" class="form-control" placeholder="請輸入其他聯絡方式（如 Email）" value="<?php echo htmlspecialchars($formData['coordinator_other_contact'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>

<<<<<<< HEAD
                                <div class="form-group" style="margin-top: 12px;">
                                    <label>特殊項目（請勾選適用項目）</label>
                                    <div style="display:flex; gap:20px; margin-top:8px; align-items:center;">
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_fire" value="1" <?php echo ($formData['has_fire'] === '1') ? 'checked' : ''; ?>>
                                            <span>明火</span>
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_alcohol" value="1" <?php echo ($formData['has_alcohol'] === '1') ? 'checked' : ''; ?>>
                                            <span>含酒精</span>
                                        </label>
                                        <label style="display:flex; align-items:center; gap:8px; margin:0;">
                                            <input type="checkbox" name="has_sales" value="1" <?php echo ($formData['has_sales'] === '1') ? 'checked' : ''; ?>>
                                            <span>販售活動</span>
=======
                                <div class="form-group" style="margin-top: 10px;">
                                    <label>活動特殊性質（可複選）</label>
                                    <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 8px;">
                                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; white-space: nowrap;">
                                            <input type="checkbox" name="has_alcohol" value="1" <?php echo ($formData['has_alcohol'] === '1') ? 'checked' : ''; ?>>
                                            <span>有酒精</span>
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; white-space: nowrap;">
                                            <input type="checkbox" name="has_fire" value="1" <?php echo ($formData['has_fire'] === '1') ? 'checked' : ''; ?>>
                                            <span>有明火</span>
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; white-space: nowrap;">
                                            <input type="checkbox" name="has_sales" value="1" <?php echo ($formData['has_sales'] === '1') ? 'checked' : ''; ?>>
                                            <span>需擺攤販售</span>
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3
                                        </label>
                                    </div>
                                </div>

                                <div class="form-group" style="margin-top: 20px; border-top: 1px solid #ccc; padding-top: 15px;">
                                    <label>活動開始時間 <span style="color:red">*</span></label>
                                    <div style="display: flex; gap: 10px; margin-bottom: 15px; align-items: center;">
                                        <input type="date" id="borrow_start_date" name="borrow_start_date" class="form-control" value="<?php echo htmlspecialchars($formData['borrow_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <?php
                                        $curBsh = ''; $curBsm = '';
                                        if (!empty($formData['borrow_start_time'])) {
                                            $parts = explode(':', $formData['borrow_start_time']);
                                            if (count($parts) >= 2) {
                                                $curBsh = (int)$parts[0];
                                                $curBsm = (int)$parts[1];
                                            }
                                        }
                                        ?>
                                        <select name="borrow_start_time_h" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <?php for($h=0; $h<=23; $h++) { 
                                                $selected = ($curBsh !== '' && $curBsh === $h) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $h; ?>" <?php echo $selected; ?>><?php echo $h; ?></option>
                                            <?php } ?>
                                        </select>
                                        <span>時</span>
                                        
                                        <select name="borrow_start_time_m" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <option value="00" <?php echo ($curBsm !== '' && $curBsm === 00) ? 'selected' : ''; ?>>00</option>
                                            <option value="30" <?php echo ($curBsm !== '' && $curBsm === 30) ? 'selected' : ''; ?>>30</option>
                                        </select>
                                        <span>分</span>
                                    </div>
                                    
                                    <label>活動結束時間 <span style="color:red">*</span></label>
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="date" id="borrow_end_date" name="borrow_end_date" class="form-control" value="<?php echo htmlspecialchars($formData['borrow_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <?php
                                        $curBeh = ''; $curBem = '';
                                        if (!empty($formData['borrow_end_time'])) {
                                            $parts = explode(':', $formData['borrow_end_time']);
                                            if (count($parts) >= 2) {
                                                $curBeh = (int)$parts[0];
                                                $curBem = (int)$parts[1];
                                            }
                                        }
                                        ?>
                                        <select name="borrow_end_time_h" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <?php for($h=0; $h<=23; $h++) { 
                                                $selected = ($curBeh !== '' && $curBeh === $h) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $h; ?>" <?php echo $selected; ?>><?php echo $h; ?></option>
                                            <?php } ?>
                                        </select>
                                        <span>時</span>
                                        
                                        <select name="borrow_end_time_m" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <option value="00" <?php echo ($curBem !== '' && $curBem === 00) ? 'selected' : ''; ?>>00</option>
                                            <option value="30" <?php echo ($curBem !== '' && $curBem === 30) ? 'selected' : ''; ?>>30</option>
                                        </select>
                                        <span>分</span>
                                    </div>
                                </div>

                                <div class="step-actions">
                                    <button type="button" class="btn btn-primary btn-next" onclick="goToStep(2)">下一步 ➔ 場地需求 </button>
                                </div>


                                <div class="draft-action-row">
                                    <button type="button" id="saveDraftBtn" class="draft-btn save-btn">
                                        暫存申請
                                    </button>
                                    <button type="button" id="openDraftBoxBtn" class="draft-btn draft-box-btn">
                                        草稿箱
                                    </button>
                                </div>
                                <div id="submitDebugMsg" class="draft-message"></div>


                            </div>
                            
                            <!-- ========== 步驟 2 內容區 ========== -->
                            <div class="step-content" id="step-content-2">
                                <h3 class="step-title" style="margin-bottom: 10px;">第二步：場地需求</h3>
                                
                                <div class="form-group" style="margin-top: 20px;">
                                    <label>車輛進出 <span style="color:red">*</span></label>
                                    <div style="margin-top: 8px; display: flex; align-items: center; gap: 20px;">
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="vehicle_entry" value="no" id="vehicleNo" style="margin: 0;" <?php echo ($formData['vehicle_entry'] === 'no' || empty($formData['vehicle_entry'])) ? 'checked' : ''; ?>> 否
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="vehicle_entry" value="yes" id="vehicleYes" style="margin: 0;" <?php echo ($formData['vehicle_entry'] === 'yes') ? 'checked' : ''; ?>> 是
                                        </label>
                                    </div>
                                </div>
                                <!-- 特殊項目已移至第一步：不在此處顯示 -->
                                <div class="form-group" style="margin-top: 20px;">
                                    <label>插立旗幟(選擇"是"將填寫旗幟插立表單) <span style="color:red">*</span></label>
                                    <div style="margin-top: 8px; display: flex; align-items: center; gap: 20px;">
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="setup_flags" value="no" id="flagOptionNo" style="margin: 0;" <?php echo ($formData['setup_flags'] === 'no' || empty($formData['setup_flags'])) ? 'checked' : ''; ?> onchange="toggleFlagDetails()"> 否
                                        </label>
                                        <label style="display: flex; align-items: center; gap: 5px; font-weight: normal; cursor: pointer; margin: 0;">
                                            <input type="radio" name="setup_flags" value="yes" id="flagOptionYes" style="margin: 0;" <?php echo ($formData['setup_flags'] === 'yes') ? 'checked' : ''; ?> onchange="toggleFlagDetails()"> 是
                                        </label>
                                    </div>
                                </div>
                                
<div id="flagDetailsSection" style="display:none; margin-top:20px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">
                                <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
                                    旗幟插立申請表
                                </div>

<<<<<<< HEAD
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start; margin-bottom:12px;">
                                    <div>
                                        <label>申請單位 <span style="color:red">*</span></label>
                                        <input type="text" id="flag_organization_name" name="flag_organization_name" class="form-control" value="<?php echo htmlspecialchars($formData['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div>
                                        <label>活動名稱 <span style="color:red">*</span></label>
                                        <input type="text" id="flag_activity_name" name="flag_activity_name" class="form-control" value="<?php echo htmlspecialchars($formData['activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div>
                                        <label>負責人 <span style="color:red">*</span></label>
                                        <input type="text" id="flag_responsible_person" name="flag_responsible_person" class="form-control" value="<?php echo htmlspecialchars($formData['activity_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div>
                                        <label>連絡電話 <span style="color:red">*</span></label>
                                        <input type="text" id="flag_contact_phone" name="flag_contact_phone" class="form-control" value="<?php echo htmlspecialchars($formData['coordinator_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>

                                <div style="display:flex; gap:15px; align-items:center; margin-bottom:15px;">
                                    <div>
                                        <label>使用日期 <span style="color:red">*</span></label>
                                        <div style="display:flex; gap:8px; align-items:center;">
                                            <input type="date" id="flag_use_start" name="flag_use_start" class="form-control" readonly style="background:#fff;" value="<?php echo htmlspecialchars($formData['borrow_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            <span>至</span>
                                            <input type="date" id="flag_use_end" name="flag_use_end" class="form-control" readonly style="background:#fff;" value="<?php echo htmlspecialchars($formData['borrow_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div style="font-size:12px;color:#64748b;margin-top:6px;">說明：使用日期已自動帶入活動起訖時間，無法修改。</div>
                                    </div>
                                </div>

                                <div style="display:flex; gap:15px; align-items:center; margin-bottom:15px;">
                                    <div style="flex:1;">
                                        <label>宣傳旗幟 <span style="color:red">*</span></label>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <span>共</span>
                                            <input type="number"
                                                name="flag_count"
                                                id="flag_count"
                                                class="form-control"
                                                min="1"
                                                max="20"
                                                step="1"
                                                style="width:100px;height:38px;"
                                                placeholder="最多20"
                                                value="<?php echo htmlspecialchars((string)($formData['flag_count'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>">
                                            <span>支</span>
=======
                                <div style="padding: 20px;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                        <div>
                                            <label style="font-weight:600; display:block; margin-bottom:6px;">申請單位 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_org" class="form-control" value="<?php echo htmlspecialchars($formData['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled style="background:#eef2ff; border:1px solid #cbd5e1; padding:10px; border-radius:6px; width:100%; color:#475569;">
                                        </div>
                                        <div>
                                            <label style="font-weight:600; display:block; margin-bottom:6px;">負責人 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_responsible" class="form-control" value="<?php echo htmlspecialchars($formData['activity_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled style="background:#eef2ff; border:1px solid #cbd5e1; padding:10px; border-radius:6px; width:100%; color:#475569;">
                                        </div>
                                    </div>

                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                                        <div>
                                            <label style="font-weight:600; display:block; margin-bottom:6px;">連絡電話 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_phone" class="form-control" value="<?php echo htmlspecialchars($formData['coordinator_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled style="background:#eef2ff; border:1px solid #cbd5e1; padding:10px; border-radius:6px; width:100%; color:#475569;">
                                        </div>
                                        <div>
                                            <label style="font-weight:600; display:block; margin-bottom:6px;">活動名稱 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_activity" class="form-control" value="<?php echo htmlspecialchars($formData['activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled style="background:#eef2ff; border:1px solid #cbd5e1; padding:10px; border-radius:6px; width:100%; color:#475569;">
                                        </div>
                                    </div>

                                    <div style="margin-bottom:20px;">
                                        <label style="font-weight:600; display:block; margin-bottom:6px;">使用日期 <span style="font-weight:normal; font-size:13px; color:#64748b;">(系統已限制需於7個工作天前申請)</span> <span style="color:red">*</span></label>
                                        <div style="display:flex; gap:8px; align-items:center;">
                                            <div style="position:relative;">
                                                <input type="text" id="flag_start_date" class="form-control" value="<?php echo htmlspecialchars($formData['borrow_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled style="background:#fff; border:1px solid #cbd5e1; padding:8px 36px 8px 10px; border-radius:6px; width:160px; color:#475569;">
                                                <span style="position:absolute; right:8px; top:50%; transform:translateY(-50%); color:#0f172a; font-weight:bold;">📅</span>
                                            </div>
                                            <span style="color:#475569; padding: 0 5px;">至</span>
                                            <div style="position:relative;">
                                                <input type="text" id="flag_end_date" class="form-control" value="<?php echo htmlspecialchars($formData['borrow_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled style="background:#fff; border:1px solid #cbd5e1; padding:8px 36px 8px 10px; border-radius:6px; width:160px; color:#475569;">
                                                <span style="position:absolute; right:8px; top:50%; transform:translateY(-50%); color:#0f172a; font-weight:bold;">📅</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div style="display:flex; gap:30px; align-items:center; margin-bottom:5px;">
                                        <div>
                                            <label style="font-weight:600; display:block; margin-bottom:6px;">宣傳旗幟 (至多20支) <span style="color:red">*</span></label>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span style="color:#475569;">共</span>
                                                <input type="number"
                                                    name="flag_count"
                                                    id="flag_count"
                                                    class="form-control"
                                                    min="1"
                                                    max="20"
                                                    step="1"
                                                    style="width:80px;height:38px; background:#fff; border:1px solid #cbd5e1; padding:8px; border-radius:6px;"
                                                    placeholder="最多20"
                                                    value="<?php echo htmlspecialchars((string)($formData['flag_count'] ?? '1'), ENT_QUOTES, 'UTF-8'); ?>"
                                                    oninput="if(this.value>20) {this.value=20; alert('宣傳旗幟最多只能選 20 支');}" required>
                                                <span style="color:#475569;">支</span>
                                            </div>
                                        </div>
                                        <div>
                                            <label style="font-weight:600; display:block; margin-bottom:6px;">懸掛位置-中央走道 <span style="color:red">*</span></label>
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3
                                        </div>
                                    </div>

                                    <div style="flex:1;">
                                        <label>懸掛位置</label>
                                        <div style="padding:8px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:4px;">中央走道</div>
                                        <input type="hidden" id="flag_location" name="flag_location" value="中央走道">
                                    </div>
                                </div>

                                <div style="margin-top:8px;">
                                    <label style="display:block; font-weight:normal;">
                                        <input type="checkbox" id="flag_agree" name="flag_agree" value="1" style="margin-right:8px;"> 我為旗幟插立總負責人，已詳細閱讀並遵守以下各項注意事項，為維護校園安全與景觀，願無條件承擔所插旗幟所致之一切賠償責任，特此聲明。
                                    </label>
                                </div>
                                
                                <label style="display: flex; align-items: flex-start; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; background: #eff6ff; padding: 15px 20px; border-top: 1px solid #cbd5e1; border-radius: 0 0 8px 8px;">
                                    <input type="checkbox" name="flag_agreement" id="flag_agreement" value="1" <?php echo (isset($formData['flag_agreement']) && $formData['flag_agreement'] == '1') ? 'checked' : ''; ?> style="margin-top: 2px;" required>
                                    <span style="color: #1e3a8a; line-height: 1.5; font-size: 14px;">本人為旗幟插立總負責人，已詳細閱讀並遵守以下各項注意事項，為維護校園安全與景觀，願無條件承擔所插旗幟所致之一切賠償責任，特此聲明。 <span style="color:red">*</span></span>
                                </label>
                            </div>

                                <script>
                                function isFlagEnabled() {
                                    const checkedFlag = document.querySelector('input[name="setup_flags"]:checked');
                                    return checkedFlag && checkedFlag.value === 'yes';
                                }

                                function addWorkDays(startDate, days) {
                                    const date = new Date(startDate.getFullYear(), startDate.getMonth(), startDate.getDate());
                                    let count = 0;

                                    while (count < days) {
                                        date.setDate(date.getDate() + 1);
                                        const weekDay = date.getDay();
                                        if (weekDay !== 0 && weekDay !== 6) {
                                            count++;
                                        }
                                    }

                                    return date;
                                }

                                function formatDate(date) {
                                    const y = date.getFullYear();
                                    const m = String(date.getMonth() + 1).padStart(2, '0');
                                    const d = String(date.getDate()).padStart(2, '0');
                                    return `${y}-${m}-${d}`;
                                }

                                function getMinFlagDate() {
                                    return formatDate(addWorkDays(new Date(), 7));
                                }

                                function toggleFlagDetails() {
                                    const detailsSection = document.getElementById('flagDetailsSection');
                                    if (!detailsSection) return;

                                    const show = isFlagEnabled();
                                    detailsSection.style.display = show ? 'block' : 'none';

                                    // Only enable the editable controls (flag_count and agreement). Keep display fields readonly/disabled.
                                    detailsSection.querySelectorAll('input, select, textarea').forEach(function (el) {
                                        const editable = (el.id === 'flag_count' || el.id === 'flag_agreement' || el.name === 'flag_agreement');
                                        if (show && editable) {
                                            el.removeAttribute('disabled');
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });

                                    if (show) {
                                        syncFlagForm();
                                        validateStartDate();
                                    }
                                }

                                function is30DaysRequired() {
                                    const participantCount = document.getElementById('participant_count')?.value;
                                    const hasAlcohol = document.querySelector('input[name="has_alcohol"]')?.checked;
                                    const hasFire = document.querySelector('input[name="has_fire"]')?.checked;
                                    const hasSales = document.querySelector('input[name="has_sales"]')?.checked;
                                    
                                    return (participantCount === '100~200人' || participantCount === '200人以上') ||
                                           hasAlcohol || hasFire || hasSales;
                                }

                                function validateStartDate() {
                                    const startDateInput = document.getElementById('borrow_start_date');
                                    if (!startDateInput || !startDateInput.value) return;

                                    const selectedDate = new Date(startDateInput.value);
                                    selectedDate.setHours(0,0,0,0);

                                    const req30 = is30DaysRequired();
                                    const reqFlag = isFlagEnabled();

                                    let errorMsg = '';
                                    
                                    if (req30) {
                                        const min30Date = new Date();
                                        min30Date.setDate(min30Date.getDate() + 30);
                                        min30Date.setHours(0,0,0,0);
                                        
                                        if (selectedDate < min30Date) {
                                            errorMsg = '注意：由於您的活動包含特殊性質（酒精、明火、攤販或超過100人），必須在 30 天之前申請！\n系統已清空不合規的日期，請重新選擇至少為 ' + formatDate(min30Date) + ' 的日期。';
                                        }
                                    }

                                    if (!errorMsg && reqFlag) {
                                        const minFlagDateStr = getMinFlagDate();
                                        const minFlagDate = new Date(minFlagDateStr);
                                        minFlagDate.setHours(0,0,0,0);
                                        
                                        if (selectedDate < minFlagDate) {
                                            errorMsg = '注意：插立旗幟的使用日期只能選擇 7 個工作天之後的日期（至少為 ' + minFlagDateStr + '）！\n系統已為您清空不合規的活動開始日期，請重新選擇。';
                                        }
                                    }

                                    if (errorMsg) {
                                        alert(errorMsg);
                                        startDateInput.value = '';
                                        const sEl = document.getElementById('flag_start_date');
                                        const eEl = document.getElementById('flag_end_date');
                                        if (sEl) sEl.value = '';
                                        if (eEl) eEl.value = '';
                                    }
                                }

                                function syncFlagForm() {
                                    if (!isFlagEnabled()) return;
                                    const flagCount = document.getElementById('flag_count');
                                    if (flagCount && flagCount.value !== '' && Number(flagCount.value) > 20) {
                                        flagCount.value = 20;
                                    }

<<<<<<< HEAD
                                    // Sync usage dates from main activity dates and lock them
                                    const bs = document.getElementById('borrow_start_date');
                                    const be = document.getElementById('borrow_end_date');
                                    const fus = document.getElementById('flag_use_start');
                                    const fue = document.getElementById('flag_use_end');
                                    if (fus && fue && bs && be) {
                                        fus.value = bs.value || '';
                                        fue.value = be.value || '';
                                        // set min for visual cue (even though fields are read-only)
                                        try {
                                            const min = getMinFlagDate();
                                            fus.setAttribute('min', min);
                                            fue.setAttribute('min', min);
                                        } catch (e) {}

                                        // If the activity start is earlier than allowed minimum, warn user
                                        if (bs.value) {
                                            const minDate = new Date(getMinFlagDate());
                                            const startDate = new Date(bs.value);
                                            if (startDate < minDate) {
                                                // show gentle alert and focus the activity start date for correction
                                                alert('插立旗幟使用日期必須為 7 個工作天之後，請將活動開始日期調整至 ' + getMinFlagDate() + '（或更晚）。');
                                                bs.focus();
                                            }
                                        }
                                    }
=======
                                    // Update display inputs
                                    const org = document.getElementById('organization_name')?.value || '';
                                    const act = document.getElementById('activity_name')?.value || '';
                                    const coord = document.getElementById('activity_coordinator')?.value || '';
                                    const phone = document.getElementById('coordinator_phone')?.value || '';
                                    const sDate = document.getElementById('borrow_start_date')?.value || '';
                                    const eDate = document.getElementById('borrow_end_date')?.value || '';

                                    const orgEl = document.getElementById('flag_org');
                                    const actEl = document.getElementById('flag_activity');
                                    const coordEl = document.getElementById('flag_responsible');
                                    const phoneEl = document.getElementById('flag_phone');
                                    const sEl = document.getElementById('flag_start_date');
                                    const eEl = document.getElementById('flag_end_date');

                                    if (orgEl) orgEl.value = org || '(未填寫)';
                                    if (actEl) actEl.value = act || '(未填寫)';
                                    if (coordEl) coordEl.value = coord || '(未填寫)';
                                    if (phoneEl) phoneEl.value = phone || '(未填寫)';
                                    if (sEl) sEl.value = sDate || '';
                                    if (eEl) eEl.value = eDate || '';
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3
                                }

                                document.addEventListener('DOMContentLoaded', function () {
                                    const flagRadios = document.querySelectorAll('input[name="setup_flags"]');
                                    const flagCount = document.getElementById('flag_count');

                                    flagRadios.forEach(function (radio) {
                                        radio.addEventListener('change', function () {
                                            toggleFlagDetails();
                                            syncFlagForm();
                                        });
                                    });

                                    ['borrow_start_date', 'borrow_end_date', 'organization_name', 'activity_name', 'coordinator_phone', 'activity_coordinator', 'participant_count'].forEach(function (id) {
                                        const el = document.getElementById(id);
                                        if (el) {
                                            if (id === 'borrow_start_date' || id === 'participant_count') {
                                                el.addEventListener('change', function() {
                                                    validateStartDate();
                                                    syncFlagForm();
                                                });
                                            } else {
                                                el.addEventListener('change', syncFlagForm);
                                            }
                                            el.addEventListener('input', syncFlagForm);
                                        }
                                    });

<<<<<<< HEAD
                                    // Auto-sync specific step-1 fields into the flag application fields
                                    (function(){
                                        const pairs = [
                                            ['organization_name', 'flag_organization_name'],
                                            ['activity_name', 'flag_activity_name'],
                                            ['activity_coordinator', 'flag_responsible_person'],
                                            ['coordinator_phone', 'flag_contact_phone']
                                        ];
                                        pairs.forEach(function(pair){
                                            const src = document.getElementById(pair[0]);
                                            const dst = document.getElementById(pair[1]);
                                            if (!src || !dst) return;
                                            // initial copy
                                            dst.value = src.value || dst.value || '';
                                            // update on input/change
                                            src.addEventListener('input', function(){ dst.value = src.value; });
                                            src.addEventListener('change', function(){ dst.value = src.value; });
                                        });
                                    })();
=======
                                    ['has_alcohol', 'has_fire', 'has_sales'].forEach(function(name) {
                                        const el = document.querySelector('input[name="' + name + '"]');
                                        if (el) {
                                            el.addEventListener('change', function() {
                                                validateStartDate();
                                            });
                                        }
                                    });
>>>>>>> 08406ea6bf3daedf111ec6eb25373837712993f3

                                    if (flagCount) {
                                        flagCount.addEventListener('input', function () {
                                            if (this.value !== '' && Number(this.value) > 20) {
                                                this.value = 20;
                                                alert('宣傳旗幟最多只能選 20 支');
                                            }

                                            if (this.value !== '' && Number(this.value) < 1) {
                                                this.value = 1;
                                            }
                                        });
                                    }

                                    toggleFlagDetails();
                                    syncFlagForm();

                                    // 註解掉避免重複綁定 saveDraft
                                    // const saveBtns = document.querySelectorAll('.saveDraftBtn');
                                    // saveBtns.forEach(function (btn) {
                                    //    ...
                                    // });

                                    // 草稿箱
                                    const draftBtns = document.querySelectorAll('.openDraftBoxBtn');

                                    draftBtns.forEach(function (btn) {
                                        btn.addEventListener('click', function () {
                                            window.location.href = 'draft_box.php';
                                        });
                                    });
                                });
                                </script>

                                <div class="step-actions">
                                    <button type="button" class="btn btn-secondary" onclick="goToStep(1)"> ⬅ 回上一步</button>
                                    <button type="button" class="btn btn-primary btn-next" onclick="goToStep(3)">下一步 ➔ 挑選器材與場地</button>
                                </div>

                                <div class="draft-action-row">
                                    <button type="button" class="draft-btn save-btn saveDraftBtn">
                                        暫存申請
                                    </button>
                                    <button type="button" class="draft-btn draft-box-btn openDraftBoxBtn">
                                        草稿箱
                                    </button>
                                </div>
                                <div id="submitDebugMsg" class="draft-message"></div>
                            </div>
                            <!-- ========== 步驟 3 內容區 ========== -->
                            <div class="step-content" id="step-content-3">
                                <h3 class="step-title" style="margin-bottom: 10px;">第三步：器材與場地</h3>
                                
                                <!-- 隱藏或不需要重新顯示的部分 -->
                                <div class="form-group" style="display:none;">
                                    <label for="resource_type">借用類型 (已合併)</label>
                                    <select id="resource_type" name="resource_type">
                                        <option value="both" selected>兩者</option>
                                    </select>
                                </div>

                            <!-- Old proposalGroup removed -->

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
                                                                <span>可借數量: <span class="es-available-value" data-code="<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>" data-type="equipment">請先選日期</span></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="es-btn-invite">選擇</button>
                                                </div>
                                                <div class="es-item-body">
                                                    <div class="es-item-details">
                                                        <span>目前可借用數量：<strong class="es-availability-detail" data-code="<?php echo htmlspecialchars($equipment['equipment_code'], ENT_QUOTES, 'UTF-8'); ?>" data-type="equipment">請先選日期</strong></span>
                                                        <span>限借數量：<?php echo $limit; ?></span>
                                                    </div>
                                                    <div class="es-item-action">
                                                        <label>選擇借幾個：</label>
                                                        <input type="number" class="es-qty-input" min="1" max="1" value="1" disabled>
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
                                                                <span>可借數量: <span class="es-available-value" data-code="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>" data-type="space">請先選日期與節次</span></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="es-btn-invite">選擇</button>
                                                </div>
                                                <div class="es-item-body">
                                                    <div class="es-item-details">
                                                        <span>容納人數：<?php echo (int)$space['capacity']; ?></span>
                                                        <span>目前可借用數量：<strong class="es-availability-detail" data-code="<?php echo htmlspecialchars($space['space_id'], ENT_QUOTES, 'UTF-8'); ?>" data-type="space">請先選日期與節次</strong></span>
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


                            <div class="form-group">
                                <label for="purpose">用途說明 <span style="color:red">*</span></label>
                                <textarea id="purpose" name="purpose" rows="4" required><?php echo htmlspecialchars($formData['purpose'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>

                            <div class="step-actions">
                                <button type="button" class="btn btn-secondary" onclick="goToStep(2)"> ⬅ 回上一步</button>
                                <button type="submit" class="btn btn-primary btn-next" id="borrowSubmitBtn">確認借用</button>
                            </div>

                            <div class="draft-action-row">
                                <button type="button" class="draft-btn save-btn saveDraftBtn">
                                    暫存申請
                                </button>
                                <button type="button" class="draft-btn draft-box-btn openDraftBoxBtn">
                                    草稿箱
                                </button>
                            </div>
                            <div id="submitDebugMsg" class="draft-message"></div>
                        </div> <!-- end of step-content-3 -->

                        <!-- 草稿功能保留可放至其他位置, 或暫時隱藏, 為了簡化, 先放著 -->
                        <!-- <div class="form-buttons">
                                <div class="draft-buttons">
                                    <button type="button" class="btn-draft btn-draft-save" id="saveDraftBtn">暫存申請</button>
                                    <button type="button" class="btn-draft btn-draft-manage" id="manageDraftBtn">草稿箱</button>
                                </div>
                                <button type="button" class="btn-secondary" onclick="location.href='index.php'">取消</button>
                            </div> -->
                        <div id="submitDebugMsg" style="margin-top:8px; font-size:13px; color:#64748b;"></div>
                        </form>
                    </section>

                    <!-- 草稿管理中心模態框 -->
                    <div id="draftModalOverlay" class="draft-modal-overlay">
                        <div class="draft-modal">
                            <div class="draft-modal-header">
                                <h2>📋 草稿管理中心</h2>
                                <button type="button" class="draft-modal-close" id="draftModalCloseBtn">&times;</button>
                            </div>
                            <div class="draft-modal-content">
                                <div id="draftMessage" class="draft-message"></div>
                                <div id="draftTableContainer">
                                    <p class="draft-empty-message">
                                        <div class="draft-empty-icon">📭</div>
                                        暫無已儲存的草稿
                                    </p>
                                </div>
                            </div>
                            <div class="draft-modal-footer">
                                <div class="draft-footer-buttons">
                                    <button type="button" class="draft-btn-new" id="draftBtnNew">✨ 新增申請</button>
                                    <button type="button" class="draft-btn-close" id="draftBtnClose">關閉</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <!-- 草稿管理 JavaScript 模組 - 必須在 borrow.php 邏輯之前加載 -->
    <script src="DraftManager.js?v=<?php echo time(); ?>"></script>
    <script>
        window.draftManager = new DraftManager();
    </script>

    <script>
        // ===== 草稿第三步「已選取項目」同步橋接 =====
        // 用途：暫存前把右側已選項目寫進 cart_items；草稿載入後再把 cart_items 畫回右側清單。
        window.borrowCartDraftBridge = {
            getOrCreateCartInput: function () {
                let cartInput = document.querySelector('input[name="cart_items"]');
                const form = document.getElementById('multistep_form') || document.querySelector('form.borrow-form');

                if (!cartInput && form) {
                    cartInput = document.createElement('input');
                    cartInput.type = 'hidden';
                    cartInput.name = 'cart_items';
                    form.appendChild(cartInput);
                }

                return cartInput;
            },

            normalizeItems: function (items) {
                return (Array.isArray(items) ? items : []).map(function (item) {
                    return {
                        code: String(item.code || item.equipment_code || item.space_id || '').trim(),
                        name: String(item.name || item.equipment_name || item.space_name || item.code || item.space_id || '').trim(),
                        quantity: parseInt(item.quantity || item.qty || 1, 10) || 1,
                        type: String(item.type || (item.space_id ? 'space' : 'equipment')).trim()
                    };
                }).filter(function (item) {
                    return item.code !== '';
                });
            },

            // 從右側 DOM 備援抓資料：避免 cartItems 區域變數還沒同步到 hidden input
            readItemsFromRightPanelDom: function () {
                const rows = document.querySelectorAll('#esSelectedList .es-right-item');
                const items = [];

                rows.forEach(function (row) {
                    const nameCol = row.querySelector('.cart-col-name');
                    const qtyInput = row.querySelector('.cart-qty-update');
                    if (!nameCol) return;

                    const rawName = nameCol.textContent.trim();
                    const match = rawName.match(/^(.*)\s*\(([^()]+)\)\s*$/);
                    const name = match ? match[1].trim() : rawName;
                    const code = match ? match[2].trim() : '';
                    const quantity = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
                    const type = qtyInput && qtyInput.disabled ? 'space' : 'equipment';

                    if (code) {
                        items.push({ code: code, name: name || code, quantity: quantity, type: type });
                    }
                });

                return items;
            },

            syncHiddenBeforeSave: function () {
                const cartInput = this.getOrCreateCartInput();
                if (!cartInput) return [];

                let items = [];

                if (typeof window.getBorrowCartItems === 'function') {
                    items = window.getBorrowCartItems();
                }

                if (!Array.isArray(items) || items.length === 0) {
                    items = this.readItemsFromRightPanelDom();
                }

                // 若右側也抓不到，就保留 hidden input 原本資料
                if ((!Array.isArray(items) || items.length === 0) && cartInput.value) {
                    try {
                        const oldItems = JSON.parse(cartInput.value || '[]');
                        items = Array.isArray(oldItems) ? oldItems : [];
                    } catch (e) {
                        items = [];
                    }
                }

                items = this.normalizeItems(items);
                cartInput.value = JSON.stringify(items);
                return items;
            },

            getDraftCartItems: function (draft) {
                if (!draft) return [];
                const data = draft.formData || draft.data || draft.form_data || {};
                let raw = data.cart_items || data.cartItems || draft.cart_items || draft.cartItems || '[]';

                if (Array.isArray(raw)) return this.normalizeItems(raw);

                try {
                    const parsed = JSON.parse(raw || '[]');
                    return this.normalizeItems(Array.isArray(parsed) ? parsed : []);
                } catch (e) {
                    console.error('草稿 cart_items 解析失敗', e, raw);
                    return [];
                }
            },

            restoreRightPanel: function (draft) {
                const items = this.getDraftCartItems(draft);
                const cartInput = this.getOrCreateCartInput();

                if (cartInput) {
                    cartInput.value = JSON.stringify(items);
                }

                if (typeof window.setBorrowCartItems === 'function') {
                    window.setBorrowCartItems(items);
                    return;
                }

                if (typeof window.restoreBorrowCartFromHidden === 'function') {
                    window.restoreBorrowCartFromHidden();
                    return;
                }

                // 最後備援：直接手動畫到右側，避免畫面空白
                const list = document.getElementById('esSelectedList');
                if (!list) return;
                list.innerHTML = '';

                items.forEach(function (item, index) {
                    const li = document.createElement('li');
                    li.className = 'es-right-item';
                    li.innerHTML = `
                        <div class="cart-row">
                            <div class="cart-col-name">${item.name} (${item.code})</div>
                            <div class="cart-col-qty">
                                <input type="number"
                                       class="cart-qty-update"
                                       data-index="${index}"
                                       value="${item.quantity}"
                                       min="1"
                                       style="width:50px;text-align:center;border:1px solid #ccc;border-radius:4px;padding:2px;"
                                       ${item.type === 'space' ? 'disabled title="場地僅能申請一項"' : ''}>
                            </div>
                            <div class="cart-col-action">
                                <button type="button" class="es-btn-remove" data-index="${index}">移除</button>
                            </div>
                        </div>
                    `;
                    list.appendChild(li);
                });
            }
        };
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('multistep_form');
            const saveBtn = document.getElementById('saveDraftBtn');
            const draftBoxBtn = document.getElementById('openDraftBoxBtn');
            const msg = document.getElementById('submitDebugMsg');

            const STORAGE_KEY = 'borrow_drafts';

            function getDrafts() {
                return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
            }

            function saveDrafts(drafts) {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts));
            }

            function collectFormData() {
                const data = {};

                form.querySelectorAll('input, select, textarea').forEach(el => {
                    if (!el.name) return;

                    if (el.type === 'file') return;

                    if (el.type === 'checkbox') {
                        data[el.name] = el.checked ? '1' : '';
                    } else if (el.type === 'radio') {
                        if (el.checked) data[el.name] = el.value;
                    } else {
                        data[el.name] = el.value;
                    }
                });

                return data;
            }

            function clearFormAfterSave() {
                // Intentionally left minimal to avoid unexpected navigation/reset during save.
                console.log('clearFormAfterSave called — no-op to preserve form state after draft save');
            }

            if (saveBtn) {
                saveBtn.addEventListener('click', function () {
                    const drafts = getDrafts();

                    if (window.borrowCartDraftBridge) {
                        window.borrowCartDraftBridge.syncHiddenBeforeSave();
                    }

                    const formData = collectFormData();

                    const draft = {
                        draftId: 'draft_' + Date.now(),
                        timestamp: new Date().toLocaleString('zh-TW'),
                        activityName: formData.activity_name || '未填寫活動名稱',
                        purpose: formData.purpose || '',
                        currentStep: document.getElementById('current_step')?.value || '1',
                        formData: formData
                    };

                    drafts.unshift(draft);
                    saveDrafts(drafts);

                    if (msg) msg.textContent = '✅ 草稿已暫存，表單內容已保留';

                    // 保留表單與目前步驟，使用者可繼續編輯
                });
            }

            if (draftBoxBtn) {
                draftBoxBtn.addEventListener('click', function () {
                    window.location.href = 'draft_box.php';
                });
            }

            // 從草稿箱載入草稿
            const params = new URLSearchParams(window.location.search);
            const loadId = params.get('draft_id');

            if (loadId) {
                // 使用 DraftManager 取得草稿（若 DraftManager 尚未啟動，退回到 localStorage）
                let draft = null;
                if (window.draftManager && typeof window.draftManager.getDraftById === 'function') {
                    draft = window.draftManager.getDraftById(loadId);
                }

                if (!draft) {
                    const drafts = JSON.parse(localStorage.getItem('borrow_drafts') || '[]');
                    draft = drafts.find(d => d.draftId === loadId);
                }

                if (draft) {
                    if (window.draftManager && typeof window.draftManager.loadDraftToForm === 'function') {
                        window.draftManager.loadDraftToForm(draft);
                    } else if (draft.formData) {
                        // Fallback for very old drafts that still have formData
                        Object.keys(draft.formData).forEach(function (name) {
                            const els = document.querySelectorAll(`[name="${name}"]`);

                            els.forEach(function (el) {
                                if (el.type === 'checkbox') {
                                    el.checked = draft.formData[name] === '1';
                                } else if (el.type === 'radio') {
                                    el.checked = el.value === draft.formData[name];
                                } else if (el.type !== 'file') {
                                    el.value = draft.formData[name];
                                }
                            });
                        });
                    }

                    // 將目前編輯的草稿 id 寫入 hidden input，供暫存時覆寫判斷
                    const currentDraftIdEl = document.getElementById('current_draft_id');
                    if (currentDraftIdEl) currentDraftIdEl.value = draft.draftId || '';

                    // 草稿如果有第三步已選取項目，先填回 hidden input，並同步畫回右側「已選取項目」。
                    if (window.borrowCartDraftBridge) {
                        window.borrowCartDraftBridge.restoreRightPanel(draft);
                        setTimeout(function () {
                            window.borrowCartDraftBridge.restoreRightPanel(draft);
                        }, 200);
                    }

                    // 草稿資料填回表單後，重新判斷「插立旗幟」是否為是。
                    // 若 setup_flags = yes，會自動顯示旗幟插立申請表。
                    if (typeof toggleFlagDetails === 'function') {
                        toggleFlagDetails();
                    }

                    // 重新同步旗幟申請表資料與活動日期。
                    if (typeof syncFlagForm === 'function') {
                        syncFlagForm();
                    }

                    const step = draft.currentStep || '1';

                    if (typeof showStep === 'function') {
                        showStep(step);
                    } else {

                        document.querySelectorAll('.step-content')
                            .forEach(el => el.classList.remove('active'));

                        document.querySelectorAll('.stepper-item')
                            .forEach(el => el.classList.remove('active'));

                        document.getElementById('step-content-' + step)
                            ?.classList.add('active');

                        document.getElementById('stepper-' + step)
                            ?.classList.add('active');

                        const currentStepInput =
                            document.getElementById('current_step');

                        if (currentStepInput) {
                            currentStepInput.value = step;
                        }
                    }
                }
            }
        });
    </script>


    <script>
        // 確保在 DOM 完全加載後執行
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', startApplication);
        } else {
            startApplication();
        }

function startApplication() {
    console.log('[Borrow Page] Initializing...');
    
    // 👇👇👇 把這整段用 /* 和 */ 包起來，或是整段刪掉 👇👇👇
    /*
    if (!window.draftManager) {
        console.error('[Draft] DraftManager not found! Retrying...');
        setTimeout(startApplication, 100);
        return;
    }
    */
    // 👆👆👆 註解到這邊 👆👆👆
    
    console.log('[Draft] DraftManager bypassed or initialized');
    
    // 記得我們上一回合說要補上的變數宣告（在 initializeBorrowForm 裡面）也要加喔！
    initializeBorrowForm();
    
    // 啟用草稿管理模組
    initializeDraftManagement();
}

        // ================== 借用表單邏輯 ==================
        function initializeBorrowForm() {
            (function () {
            const spaceGroup = document.getElementById('spaceGroup');
            const equipmentSelectorContainer = document.getElementById('equipmentSelectorContainer');
            const proposalFileInput = document.getElementById('proposal_file');
            const proposalFileNameDisplay = document.getElementById('proposal_file_name_display');
            const submitDebugMsg = document.getElementById('submitDebugMsg');
        // 👇 新增這兩行 👇
        const submitButton = document.getElementById('borrowSubmitBtn');
        const borrowForm = document.getElementById('multistep_form');
        // 👆 新增這兩行 👆
            if (proposalFileInput) {
                proposalFileInput.addEventListener('change', function(e) {
                    if (proposalFileNameDisplay) {
                        if (e.target.files && e.target.files.length > 0) {
                            proposalFileNameDisplay.textContent = e.target.files[0].name;
                        } else {
                            proposalFileNameDisplay.textContent = '';
                        }
                    }
                });
            }

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
            if (cartItemsInput && cartItemsInput.value) {
                try {
                    const savedCartItems = JSON.parse(cartItemsInput.value);
                    cartItems = Array.isArray(savedCartItems) ? savedCartItems : [];
                } catch (e) {
                    console.error('草稿已選取項目解析失敗', e);
                    cartItems = [];
                }
            }
            // 讓草稿功能可以讀取/還原第三步右側「已選取項目」的實際購物車資料
            window.getBorrowCartItems = function () {
                return Array.isArray(cartItems) ? JSON.parse(JSON.stringify(cartItems)) : [];
            };

            window.setBorrowCartItems = function (itemsToRestore) {
                cartItems = Array.isArray(itemsToRestore) ? itemsToRestore.map(function (item) {
                    return {
                        code: String(item.code || item.equipment_code || item.space_id || ''),
                        name: String(item.name || item.equipment_name || item.space_name || item.code || item.space_id || ''),
                        quantity: parseInt(item.quantity || item.qty || 1, 10) || 1,
                        type: String(item.type || (item.space_id ? 'space' : 'equipment'))
                    };
                }).filter(function (item) {
                    return item.code !== '';
                }) : [];

                if (cartItemsInput) {
                    cartItemsInput.value = JSON.stringify(cartItems);
                }

                renderCart();
                refreshModeUI();
            };

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
                

            const availabilityCache = window.availabilityCache || (window.availabilityCache = {});
            const pendingAvailabilityLoads = window.pendingAvailabilityLoads || (window.pendingAvailabilityLoads = {});

            function setItemAvailabilityState(item, availabilityText, detailText, maxQty, isEnabled) {
                const qtyInput = item.querySelector('.es-qty-input');
                const addBtn = item.querySelector('.es-btn-add');
                const inviteBtn = item.querySelector('.es-btn-invite');
                const valueEl = item.querySelector('.es-available-value');
                const detailEl = item.querySelector('.es-availability-detail');

                if (valueEl) {
                    valueEl.textContent = availabilityText;
                }
                if (detailEl) {
                    detailEl.textContent = detailText;
                }

                if (qtyInput) {
                    qtyInput.max = String(Math.max(1, maxQty || 1));
                    qtyInput.disabled = !isEnabled;
                    if (!isEnabled) {
                        qtyInput.value = 1;
                    } else if (parseInt(qtyInput.value, 10) > maxQty) {
                        qtyInput.value = Math.max(1, maxQty);
                    }
                }

                if (addBtn) {
                    addBtn.disabled = !isEnabled;
                }
                
                if (inviteBtn) {
                    inviteBtn.disabled = !isEnabled;
                }
            }

            function refreshResourceAvailability() {
                const borrowStartDateElLocal = document.getElementById('borrow_start_date');
                const borrowEndDateElLocal = document.getElementById('borrow_end_date');
                const selectedDate = borrowStartDateElLocal ? borrowStartDateElLocal.value : '';
                const selectedEndDate = borrowEndDateElLocal ? borrowEndDateElLocal.value : '';
                const startPeriodCode = 'always';
                const endPeriodCode = 'always';
                const periodSlotsMap = window.periodSlotsMap || {};
                const spaceReservations = window.existingSpaceReservations || [];

                items.forEach(item => {
                    const type = item.dataset.type;
                    const code = item.dataset.code;

                    if (type === 'equipment') {
                        if (!selectedDate) {
                            setItemAvailabilityState(item, '請先選日期', '請先選日期', 1, true); // 改為 true 讓他可以加入再說
                            return;
                        }

                        const year = selectedDate.substring(0, 4);
                        const month = selectedDate.substring(5, 7);
                        const monthKey = `${code}_${year}_${month}`;
                        const applyAvailability = (data) => {
                            const reservations = Array.isArray(data.reservations) ? data.reservations : [];
                            let used = 0;
                            reservations.forEach(r => {
                                if ((r.start || '').substring(0, 10) === selectedDate) {
                                    used += parseInt(r.qty || 0, 10) || 0;
                                }
                            });
                            const availableQty = Math.max(0, (parseInt(data.total_capacity || 0, 10) || 0) - used);
                            const label = String(availableQty);
                            setItemAvailabilityState(item, label, label, availableQty, availableQty > 0);
                        };

                        if (availabilityCache[monthKey]) {
                            applyAvailability(availabilityCache[monthKey]);
                        } else if (!pendingAvailabilityLoads[monthKey]) {
                            pendingAvailabilityLoads[monthKey] = true;
                            fetch(`api_get_availability.php?type=equipment&id=${encodeURIComponent(code)}&year=${year}&month=${month}`)
                                .then(res => res.json())
                                .then(data => {
                                    if (data && data.total_capacity !== undefined) {
                                        availabilityCache[monthKey] = data;
                                    }
                                })
                                .catch(err => console.error('Resource availability load error:', err))
                                .finally(() => {
                                    delete pendingAvailabilityLoads[monthKey];
                                    if (typeof window.refreshResourceAvailability === 'function') {
                                        window.refreshResourceAvailability();
                                    }
                                });
                            setItemAvailabilityState(item, '讀取中...', '讀取中...', 1, true); // 讓它能點
                        } else {
                            setItemAvailabilityState(item, '讀取中...', '讀取中...', 1, true); // 讓它能點
                        }
                        return;
                    }

                    if (type === 'space') {
                        const startH = document.querySelector('select[name="borrow_start_time_h"]')?.value;
                        const startM = document.querySelector('select[name="borrow_start_time_m"]')?.value;
                        const endH = document.querySelector('select[name="borrow_end_time_h"]')?.value;
                        const endM = document.querySelector('select[name="borrow_end_time_m"]')?.value;
                        
                        if (!selectedDate || !selectedEndDate || !startH || !startM || !endH || !endM) {
                            setItemAvailabilityState(item, '請先選日期與時間', '請先選完整借用起訖時間', 1, true); // 改為 true 讓他可以加入再說
                            return;
                        }

                        const selectedStart = `${startH.padStart(2, '0')}:${startM.padStart(2, '0')}:00`;
                        const selectedEndString = `${endH.padStart(2, '0')}:${endM.padStart(2, '0')}:00`;
                        
                        // 我們暫時用前端粗略判斷（同一天）是否衝突
                        const availableQty = 1;
                        const label = String(availableQty);
                        setItemAvailabilityState(item, label, label, availableQty, availableQty > 0);
                    }
                });
            }

            window.refreshResourceAvailability = refreshResourceAvailability;

            // 綁定事件使得選擇時間時也能重新驗證
            document.querySelectorAll('#borrow_start_date, #borrow_end_date, select[name="borrow_start_time_h"], select[name="borrow_start_time_m"], select[name="borrow_end_time_h"], select[name="borrow_end_time_m"]').forEach(el => {
                el.addEventListener('change', refreshResourceAvailability);
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

                // Sync the "選擇/已加入" button state for all items
                document.querySelectorAll('.es-item').forEach(item => {
                    const code = item.dataset.code;
                    const inviteBtn = item.querySelector('.es-btn-invite');
                    if (inviteBtn) {
                        const inCart = cartItems.some(c => c.code === code);
                        if (inCart) {
                            inviteBtn.innerText = '已加入';
                            inviteBtn.style.backgroundColor = '#dcfce7';
                            inviteBtn.style.color = '#166534';
                        } else {
                            inviteBtn.innerText = '選擇';
                            inviteBtn.style.backgroundColor = '';
                            inviteBtn.style.color = '';
                        }
                    }
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
                        // Instead of expanding, directly add 1 to cart
                        if(addBtn) addBtn.click();
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
                
                if (proposalFileInput) {
                    proposalFileInput.required = hasSpace;
                }
                
                if (typeof window.updatePeriodOptions === 'function') {
                    window.updatePeriodOptions();
                }
            }

            function restoreCartFromHiddenInput() {
                if (!cartItemsInput) return;

                let restoredItems = [];
                if (cartItemsInput.value) {
                    try {
                        const parsed = JSON.parse(cartItemsInput.value);
                        restoredItems = Array.isArray(parsed) ? parsed : [];
                    } catch (e) {
                        console.error('草稿已選取項目載入失敗', e);
                        restoredItems = [];
                    }
                }

                if (typeof window.setBorrowCartItems === 'function') {
                    window.setBorrowCartItems(restoredItems);
                } else {
                    cartItems = restoredItems;
                    cartItemsInput.value = JSON.stringify(cartItems);
                    renderCart();
                    refreshModeUI();
                }
            }

            window.restoreBorrowCartFromHidden = restoreCartFromHiddenInput;

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
            restoreCartFromHiddenInput();
            })();
        }

        // ================== 草稿管理 (Draft Management) ==================
        function initializeDraftManagement() {
            (function() {
            // DOM 元素參考
            const saveDraftBtn = document.getElementById('saveDraftBtn');
            const manageDraftBtn = document.getElementById('manageDraftBtn');
            const draftModalOverlay = document.getElementById('draftModalOverlay');
            const draftModalCloseBtn = document.getElementById('draftModalCloseBtn');
            const draftBtnClose = document.getElementById('draftBtnClose');
            const draftBtnNew = document.getElementById('draftBtnNew');
            const draftTableContainer = document.getElementById('draftTableContainer');
            const draftMessage = document.getElementById('draftMessage');

            /**
             * 顯示暫時訊息
             */
            function showMessage(text, type = 'success', duration = 3000) {
                draftMessage.textContent = text;
                draftMessage.className = `draft-message show ${type}`;
                
                if (duration > 0) {
                    setTimeout(() => {
                        draftMessage.classList.remove('show');
                    }, duration);
                }
            }

            /**
             * 渲染草稿列表表格
             */
            function renderDraftTable() {
                const stats = window.draftManager.getDraftStats();
                
                if (stats.totalCount === 0) {
                    draftTableContainer.innerHTML = `
                        <p class="draft-empty-message">
                            <div class="draft-empty-icon">📭</div>
                            暫無已儲存的草稿
                        </p>
                    `;
                    return;
                }

                let tableHtml = `
                    <table class="draft-table">
                        <thead>
                            <tr>
                                <th>暫存時間</th>
                                <th>用途摘要</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                stats.drafts.forEach(draft => {
                    const timestamp = draft.timestamp || '未知';
                    const purposeSummary = window.draftManager.generatePurposeSummary(draft.purpose);
                    const draftId = draft.draftId;
                    
                    tableHtml += `
                        <tr>
                            <td>${timestamp}</td>
                            <td>${purposeSummary}</td>
                            <td>
                                <div class="draft-actions">
                                    <button type="button" class="draft-btn-load" onclick="loadDraft('${draftId}')">載入</button>
                                    <button type="button" class="draft-btn-delete" onclick="deleteDraft('${draftId}')">刪除</button>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                tableHtml += `
                        </tbody>
                    </table>
                `;

                draftTableContainer.innerHTML = tableHtml;
            }

            /**
             * 全域函數：載入草稿
             */
            window.loadDraft = function(draftId) {
                const draft = window.draftManager.getDraftById(draftId);
                if (!draft) {
                    showMessage('找不到此草稿', 'error');
                    return;
                }

                const success = window.draftManager.loadDraftToForm(draft);
                if (success) {
                    // 標記目前正在編輯的草稿 id，供儲存時判斷覆寫或另存
                    const currentDraftIdEl = document.getElementById('current_draft_id');
                    if (currentDraftIdEl) currentDraftIdEl.value = draft.draftId || '';

                    closeDraftModal();
                    showMessage(`✓ 已載入草稿 (${draft.timestamp})`, 'success', 3000);
                } else {
                    showMessage('載入草稿失敗，請重新嘗試', 'error');
                }
            };

            /**
             * 全域函數：刪除草稿
             */
            window.deleteDraft = function(draftId) {
                if (!confirm('確定要刪除此草稿嗎？')) {
                    return;
                }

                const deleted = window.draftManager.deleteDraft(draftId);
                if (deleted) {
                    renderDraftTable();
                    showMessage('✓ 草稿已刪除', 'success', 2000);
                } else {
                    showMessage('刪除草稿失敗', 'error');
                }
            };

            /**
             * 開啟草稿管理中心模態框
             */
            function openDraftModal() {
                renderDraftTable();
                draftModalOverlay.classList.add('active');
            }

            /**
             * 關閉草稿管理中心模態框
             */
            function closeDraftModal() {
                draftModalOverlay.classList.remove('active');
            }

            window.closeDraftModal = closeDraftModal;

            /**
             * 保存草稿
             */
            /**
             * 顯示覆寫/另存 modal，回傳使用者選擇：'overwrite'|'save_new'|'cancel'
             * @returns {Promise<string>}
             */
            function showOverwriteModal() {
                return new Promise(function (resolve) {
                    let modal = document.getElementById('overwriteModal');
                    if (!modal) {
                        modal = document.createElement('div');
                        modal.id = 'overwriteModal';
                        modal.innerHTML = `
                            <div class="overlay"></div>
                            <div class="overwrite-modal-content">
                                <div class="modal-body">偵測到您正在編輯已載入的草稿。請選擇要覆寫原有草稿，或另存為新草稿。</div>
                                <div class="modal-actions">
                                    <button type="button" class="btn btn-secondary" data-action="cancel">取消</button>
                                    <button type="button" class="btn btn-primary" data-action="save_new">另存為新草稿</button>
                                    <button type="button" class="btn btn-danger" data-action="overwrite">覆寫草稿</button>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(modal);

                        const style = document.createElement('style');
                        style.textContent = `
                            #overwriteModal { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 9999; }
                            #overwriteModal .overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); }
                            #overwriteModal .overwrite-modal-content { position: relative; background: #fff; padding: 18px; border-radius: 8px; width: 420px; max-width: 90%; box-shadow: 0 8px 24px rgba(0,0,0,0.15); z-index: 10000; }
                            #overwriteModal .modal-body { margin-bottom: 12px; color: #0f172a; }
                            #overwriteModal .modal-actions { display:flex; gap:8px; justify-content: flex-end; }
                            #overwriteModal .btn { padding:6px 10px; border-radius:6px; border:1px solid #cbd5e1; cursor:pointer; }
                            #overwriteModal .btn-primary { background:#3b82f6; color:#fff; border-color:#3b82f6; }
                            #overwriteModal .btn-danger { background:#ef4444; color:#fff; border-color:#ef4444; }
                            #overwriteModal .btn-secondary { background:#f1f5f9; color:#0f172a; }
                        `;
                        document.head.appendChild(style);

                        modal.addEventListener('click', function (e) {
                            const action = e.target.getAttribute('data-action');
                            if (action) {
                                modal.style.display = 'none';
                                resolve(action);
                            }
                        });
                    }

                    modal.style.display = 'flex';
                });
            }
            async function saveDraft(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    console.log('[Draft] button clicked!');
    try {
        if (window.borrowCartDraftBridge) {
            window.borrowCartDraftBridge.syncHiddenBeforeSave();
        }

        const currentDraftIdEl = document.getElementById('current_draft_id');
        let currentDraftId = currentDraftIdEl ? currentDraftIdEl.value : '';

        console.log('[Draft] start, currentDraftId =', currentDraftId);

        // 取得表單資料
        const draftData = window.draftManager?.extractFormData?.();
        if (!draftData) throw new Error('無法提取表單資料');

        // ⭐ 記錄「送出前」是否已有 draftId（這才是關鍵）
        const hadDraftBeforeSave = !!currentDraftId;

        // ⭐ 如果已有 draft → 先問要怎麼處理
        if (hadDraftBeforeSave) {

            const userChoice = await showOverwriteModal();
            console.log('[Draft] userChoice =', userChoice);

            if (userChoice === 'overwrite') {

                // 覆寫
                draftData.draftId = currentDraftId;

            } else if (userChoice === 'save_new') {

                // ⭐ 強制新草稿（關鍵修正）
                draftData.draftId = null;
                draftData.forceNew = true;

                currentDraftId = '';

                if (currentDraftIdEl) {
                    currentDraftIdEl.value = '';
                }

            } else {
                showMessage('已取消暫存', 'error', 2000);
                return;
            }
        }

        // ⭐ save
        const saved = await window.draftManager.saveDraft(draftData);

        console.log('[Draft] saved =', saved);

        if (!saved || !saved.draftId) {
            throw new Error('草稿儲存失敗：沒有 draftId');
        }

        // ⭐ 更新 UI state
        const isNew = !hadDraftBeforeSave || draftData.forceNew === true;

        if (currentDraftIdEl) {
            currentDraftIdEl.value = saved.draftId;
        }

        // ⭐ 新增與覆寫都跳出明確的 alert 提示
        if (isNew) {
            alert(
                '新增草稿成功！\n\n草稿代碼：' +
                saved.draftId +
                '\n\n您可以在草稿箱中查看。'
            );
        } else {
            alert(
                '覆寫草稿成功！\n\n草稿代碼：' +
                saved.draftId +
                '\n\n原本的草稿內容已更新。'
            );
        }

        showMessage(
            `✓ 草稿已暫存 (${saved.draftId.substring(0, 20)}...)`,
            'success',
            3000
        );

    } catch (error) {
        console.error('[Draft Error]', error);

        showMessage(
            `✗ 暫存失敗：${error.message}`,
            'error',
            3000
        );
    }
}

            /**
             * 新增申請（清空表單）
             */
            function createNewApplication() {
                if (!confirm('確定要清空當前表單並建立新申請嗎？\n（已暫存的資料不會遺失）')) {
                    return;
                }
                window.draftManager.clearForm();
                closeDraftModal();
                showMessage('✓ 表單已清空，可開始新申請', 'success', 2000);
            }

            // 事件綁定
            document.querySelectorAll('.saveDraftBtn, #saveDraftBtn').forEach(btn => {
                btn.addEventListener('click', saveDraft);
            });

            if (manageDraftBtn) {
                manageDraftBtn.addEventListener('click', openDraftModal);
            }

            if (draftModalCloseBtn) {
                draftModalCloseBtn.addEventListener('click', closeDraftModal);
            }

            if (draftBtnClose) {
                draftBtnClose.addEventListener('click', closeDraftModal);
            }

            if (draftBtnNew) {
                draftBtnNew.addEventListener('click', createNewApplication);
            }

            // 點擊模態框背景關閉
            if (draftModalOverlay) {
                draftModalOverlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeDraftModal();
                    }
                });
            }

            // 按 Escape 鍵也可關閉
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && draftModalOverlay.classList.contains('active')) {
                    closeDraftModal();
                }
            });
            })();
        }
        
    </script>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

    <script>
// 存儲當前選中的器材/空間的可用性數據（按日期緩存）
window.availabilityCache = window.availabilityCache || {};
const availabilityCache = window.availabilityCache;
window.existingSpaceReservations = <?= json_encode($existingSpaceReservations ?? []); ?>;
window.existingEquipmentReservations = <?= json_encode($existingEquipmentReservations ?? []); ?>;
window.periodSlotsMap = <?= json_encode($periodSlots); ?>;
const existingSpaceReservations = window.existingSpaceReservations;
const existingEquipmentReservations = window.existingEquipmentReservations;
const periodSlotsMap = window.periodSlotsMap;

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

        if (!selDate) {
            if (typeof window.refreshResourceAvailability === 'function') {
                window.refreshResourceAvailability();
            }
            return;
        }

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

        if (typeof window.refreshResourceAvailability === 'function') {
            window.refreshResourceAvailability();
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

let selectedDays = 0;

// 計算 n 個工作天後的日期 (跳過六、日)
function getWorkingDaysFromToday(days) {
    let date = new Date();
    let addedDays = 0;
    while (addedDays < days) {
        date.setDate(date.getDate() + 1);
        if (date.getDay() !== 0 && date.getDay() !== 6) {
            addedDays++;
        }
    }
    return date;
}

// 根據人數取得最短限制日期
function getMinDateByParticipantCount(countValue) {
    if (countValue === '100~200人' || countValue === '200人以上') {
        // 大於 100 人：30 天 (日曆天)
        let d = new Date();
        d.setDate(d.getDate() + 30);
        return d;
    } else {
        // 一般情況：7 天後 (日曆天)
        let d = new Date();
        d.setDate(d.getDate() + 7);
        return d;
    }
}

let initialMinDate = getMinDateByParticipantCount(document.getElementById('participant_count') ? document.getElementById('participant_count').value : ''); 

// Resolve locale safely: prefer registered zh_tw locale object, fallback to no locale option
let _flatpickrLocale = null;
if (typeof flatpickr !== 'undefined' && flatpickr.l10ns) {
    _flatpickrLocale = flatpickr.l10ns.zh_tw || flatpickr.l10ns['zh_tw'] || flatpickr.l10ns.zh || null;
}

const fpStartDate = flatpickr("#borrow_start_date", Object.assign({
    minDate: initialMinDate,
    dateFormat: "Y-m-d"
}, _flatpickrLocale ? { locale: _flatpickrLocale } : {}));

const fpEndDate = flatpickr("#borrow_end_date", Object.assign({
    minDate: initialMinDate,
    dateFormat: "Y-m-d"
}, _flatpickrLocale ? { locale: _flatpickrLocale } : {}));

// 當改變人數時，動態更新鎖定日期
const participantSelect = document.getElementById('participant_count');
if (participantSelect) {
    participantSelect.addEventListener('change', function(e) {
        const newMinDate = getMinDateByParticipantCount(e.target.value);
        
        // 更新日曆的最小可選日期
        fpStartDate.set('minDate', newMinDate);
        fpEndDate.set('minDate', newMinDate);
        
        // 如果目前已選日期早於新規定日期，則清空重選
        const startSel = fpStartDate.selectedDates;
        if (startSel.length > 0) {
            const minTime = new Date(newMinDate).setHours(0,0,0,0);
            if (startSel[0].getTime() < minTime) {
                alert("人數達 100 人以上之大型活動需提前 30 天申請！\n系統已清空您的舊日期，請重新按規定選擇。");
                fpStartDate.clear();
                fpEndDate.clear();
            }
        }
    });
}

function goToStep(stepNo) {
    const currentStepInput = document.getElementById("current_step");
    const currentStep = parseInt(currentStepInput ? currentStepInput.value : 1);

    if (stepNo > currentStep + 1) {
        alert("請依序完成每個步驟，無法直接略過喔！");
        return;
    }

    if (stepNo > 1 && currentStep === 1) {
        const proposalFile = document.getElementById('proposal_file');
        if (!proposalFile || !proposalFile.value) {
            alert("請先上傳活動企劃書！");
            return;
        }

        const startDate = document.getElementById('borrow_start_date').value;
        const endDate = document.getElementById('borrow_end_date').value;
        if (!startDate || !endDate) {
            alert("請先選擇活動起訖日期！");
            return;
        }

        const start = new Date(startDate);
        const end = new Date(endDate);
        const timeDiff = Math.abs(end.getTime() - start.getTime());
        const days = Math.ceil(timeDiff / (1000 * 3600 * 24)) + 1;
        
        if (days > 4) {
            alert("活動天數最多不可超過 4 天，請重新選擇！");
            return;
        }
        
    }

    if (currentStepInput) {
        currentStepInput.value = stepNo;
    }

    document.querySelectorAll('.step-content').forEach(function(el) {
        el.classList.remove('active');
    });
    
    const nextStep = document.getElementById('step-content-' + stepNo);
    if(nextStep) {
        nextStep.classList.add('active');
    }

    for(let i=1; i<=5; i++) {
        let st = document.getElementById('stepper-' + i);
        if (st) st.classList.remove('active');
    }
    for(let i=1; i<=stepNo; i++) {
        let st = document.getElementById('stepper-' + i);
        if (st) st.classList.add('active');
    }
}
</script>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && $borrowError !== '') { 
    $targetStep = 1;
    if (mb_strpos($borrowError, '器材') !== false || mb_strpos($borrowError, '場地') !== false || mb_strpos($borrowError, '空間') !== false || mb_strpos($borrowError, '數量') !== false) {
        $targetStep = 3;
    } elseif (mb_strpos($borrowError, '駁回') !== false || mb_strpos($borrowError, '酒精') !== false || mb_strpos($borrowError, '明火') !== false || mb_strpos($borrowError, '擺攤') !== false) {
        $targetStep = 2;
    } elseif (mb_strpos($borrowError, '時間') !== false || mb_strpos($borrowError, '日期') !== false || mb_strpos($borrowError, '用途') !== false) {
        $targetStep = 1;
    } else {
        $targetStep = (isset($_POST['current_step']) ? (int)$_POST['current_step'] : 1);
    }
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    goToStep(<?php echo $targetStep; ?>);
});
</script>
<?php } ?>



<!-- 草稿載入後：強制還原第三步右側「已選取項目」 -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    if (!params.get('draft_id')) return;

    // 等 initializeBorrowForm() 完成後，再補跑一次，避免右側清單被初始化清空。
    setTimeout(function () {
        if (typeof window.restoreBorrowCartFromHidden === 'function') {
            window.restoreBorrowCartFromHidden();
        }
    }, 100);
});
</script>

<!-- 草稿載入後：若插立旗幟為「是」，強制顯示旗幟插立申請表 -->
<script>
(function () {
    function getDraftByUrlId() {
        const params = new URLSearchParams(window.location.search);
        const loadId = params.get('draft_id');
        if (!loadId) return null;

        const drafts = JSON.parse(localStorage.getItem('borrow_drafts') || '[]');
        return drafts.find(function (d) {
            return d && String(d.draftId) === String(loadId);
        }) || null;
    }

    function getDraftFormData(draft) {
        if (!draft) return {};
        return draft.formData || draft.data || draft.form_data || {};
    }

    function restoreFlagFormAfterDraftLoad() {
        const draft = getDraftByUrlId();
        if (!draft) return;

        const data = getDraftFormData(draft);
        const flagValue = data.setup_flags || data.flag_setup || '';
        const isYes = flagValue === 'yes' || flagValue === '1' || flagValue === 1 || flagValue === true;

        const yesRadio = document.querySelector('input[name="setup_flags"][value="yes"]');
        const noRadio = document.querySelector('input[name="setup_flags"][value="no"]');
        const detailsSection = document.getElementById('flagDetailsSection');

        if (isYes) {
            if (yesRadio) yesRadio.checked = true;
            if (noRadio) noRadio.checked = false;

            if (detailsSection) {
                detailsSection.style.display = 'block';
                detailsSection.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.removeAttribute('disabled');
                });
            }
        } else {
            if (detailsSection) {
                detailsSection.style.display = 'none';
            }
            return;
        }

        // 把草稿內旗幟表單欄位重新塞回去
        [
            'flag_count',
        ].forEach(function (name) {
            const value = data[name];
            const els = document.querySelectorAll('[name="' + name + '"]');

            els.forEach(function (el) {
                if (el.type === 'checkbox') {
                    el.checked = value === '1' || value === 1 || value === true;
                } else if (value !== undefined && value !== null) {
                    el.value = value;
                }
            });
        });

        // 若原本函式存在，再跑一次，讓日期與顯示狀態同步
        if (typeof window.toggleFlagDetails === 'function') {
            window.toggleFlagDetails();
        }

        if (typeof window.syncFlagForm === 'function') {
            window.syncFlagForm();
        }

        // 防止其他舊程式後面又把它隱藏，最後再強制打開一次
        if (isYes && detailsSection) {
            detailsSection.style.display = 'block';
            detailsSection.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.removeAttribute('disabled');
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        restoreFlagFormAfterDraftLoad();
        setTimeout(restoreFlagFormAfterDraftLoad, 100);
        setTimeout(restoreFlagFormAfterDraftLoad, 300);
    });
})();
</script>



<!-- 草稿載入/暫存：強制同步第三步右側「已選取項目」 -->
<script>
(function () {
    function getDraftByUrlId() {
        const params = new URLSearchParams(window.location.search);
        const loadId = params.get('draft_id');
        if (!loadId) return null;
        try {
            const drafts = JSON.parse(localStorage.getItem('borrow_drafts') || '[]');
            return drafts.find(function (d) {
                return d && String(d.draftId) === String(loadId);
            }) || null;
        } catch (e) {
            console.error('讀取草稿失敗', e);
            return null;
        }
    }

    function parseCartItemsFromDraft(draft) {
        if (!draft) return [];
        const data = draft.formData || draft.data || draft.form_data || {};
        const raw = data.cart_items || draft.cart_items || draft.cartItems || '[]';

        if (Array.isArray(raw)) return raw;

        try {
            const parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            console.error('草稿 cart_items 解析失敗', e, raw);
            return [];
        }
    }

    function normalizeCartItems(items) {
        return (Array.isArray(items) ? items : []).map(function (item) {
            return {
                code: String(item.code || item.equipment_code || item.space_id || ''),
                name: String(item.name || item.equipment_name || item.space_name || item.code || item.space_id || ''),
                quantity: parseInt(item.quantity || item.qty || 1, 10) || 1,
                type: String(item.type || (item.space_id ? 'space' : 'equipment'))
            };
        }).filter(function (item) {
            return item.code !== '';
        });
    }

    function syncHiddenCartBeforeSaving() {
        const cartInput = document.querySelector('input[name="cart_items"]');
        if (!cartInput) return;

        if (typeof window.getBorrowCartItems === 'function') {
            cartInput.value = JSON.stringify(window.getBorrowCartItems());
        }
    }

    function restoreDraftCartToRightPanel() {
        const draft = getDraftByUrlId();
        if (!draft) return;

        const items = normalizeCartItems(parseCartItemsFromDraft(draft));
        const cartInput = document.querySelector('input[name="cart_items"]');

        if (cartInput) {
            cartInput.value = JSON.stringify(items);
        }

        if (typeof window.setBorrowCartItems === 'function') {
            window.setBorrowCartItems(items);
        } else if (typeof window.restoreBorrowCartFromHidden === 'function') {
            window.restoreBorrowCartFromHidden();
        }
    }

    // 在原本的暫存 click handler 執行前，先把右側已選取項目同步進 hidden input。
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.saveDraftBtn, #saveDraftBtn');
        if (btn) syncHiddenCartBeforeSaving();
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        if (!new URLSearchParams(window.location.search).get('draft_id')) return;

        let tries = 0;
        const timer = setInterval(function () {
            restoreDraftCartToRightPanel();
            tries++;
            if (typeof window.setBorrowCartItems === 'function' || tries >= 20) {
                clearInterval(timer);
            }
        }, 100);
    });
})();
</script>

</body>
</html>


