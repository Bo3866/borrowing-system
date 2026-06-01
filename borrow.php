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
    $cursorColumnResult = mysqli_query($link, "SHOW COLUMNS FROM equipment_categories LIKE 'borrow_cursor_equipment_id'");
    if (!($cursorColumnResult && mysqli_num_rows($cursorColumnResult) > 0)) {
        if (!mysqli_query($link, "ALTER TABLE equipment_categories ADD COLUMN borrow_cursor_equipment_id BIGINT UNSIGNED NULL COMMENT '下一次配發起點器材編號' AFTER borrow_limit_quantity")) {
            $dbError = '建立器材輪轉欄位失敗：' . mysqli_error($link);
        }
    }

    $equipmentSql = "
        SELECT
            ec.equipment_code,
            ec.equipment_name,
            ec.borrow_limit_quantity,
            ec.borrow_cursor_equipment_id,
            COALESCE(SUM(CASE WHEN e.operation_status = 1 THEN 1 ELSE 0 END), 0) - COALESCE(COUNT(eri.equipment_id), 0) AS available_quantity
        FROM equipment_categories ec
        LEFT JOIN equipments e ON e.equipment_code = ec.equipment_code
        LEFT JOIN equipment_reservation_items eri ON e.equipment_id = eri.equipment_id
        LEFT JOIN reservations r ON eri.reservation_id = r.reservation_id
            AND r.borrow_start_at <= NOW()
            AND r.borrow_end_at > NOW()
            AND r.approval_status IN ('pending', 'approved')
            AND r.returned_at IS NULL
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
                'borrow_cursor_equipment_id' => $row['borrow_cursor_equipment_id'] !== null ? (int)$row['borrow_cursor_equipment_id'] : null,
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
                                    AND r.returned_at IS NULL
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
                                    AND r.returned_at IS NULL
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

// 讀取 holidays 表供前端顯示（若存在）
$pageHolidayDates = [];
$holTableResPage = mysqli_query($link, "SHOW TABLES LIKE 'holidays'");
if ($holTableResPage && mysqli_num_rows($holTableResPage) > 0) {
    $holResPage = mysqli_query($link, "SELECT `date` FROM `holidays` ORDER BY `date` ASC");
    if ($holResPage) {
        while ($hrow = mysqli_fetch_assoc($holResPage)) {
            $pageHolidayDates[] = $hrow['date'];
        }
    }
}

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
    'flag_count' => null,
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
    'sales_location' => '',
    'sales_count' => '',
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
    $formData['flag_count'] = ($formData['setup_flags'] === 'yes' && isset($_POST['flag_count']) && $_POST['flag_count'] !== '')
        ? (int)$_POST['flag_count']
        : null;
    // Flag-specific fields (sync/backfill from step1 when not provided)
    $formData['flag_organization_name'] = trim((string)($_POST['flag_organization_name'] ?? $formData['organization_name']));
    $formData['flag_activity_name'] = trim((string)($_POST['flag_activity_name'] ?? $formData['activity_name']));
    $formData['flag_responsible_person'] = trim((string)($_POST['flag_responsible_person'] ?? $formData['activity_coordinator']));
    $formData['flag_contact_phone'] = trim((string)($_POST['flag_contact_phone'] ?? $formData['coordinator_phone']));
    $formData['flag_agreement'] = isset($_POST['flag_agreement']) ? '1' : '';
    $formData['has_alcohol'] = isset($_POST['has_alcohol']) ? '1' : '';
    $formData['has_fire'] = isset($_POST['has_fire']) ? '1' : '';
    $formData['has_sales'] = isset($_POST['has_sales']) ? '1' : '';
    $formData['sales_location'] = trim((string)($_POST['sales_location'] ?? ''));
    $formData['sales_count'] = trim((string)($_POST['sales_count'] ?? ''));
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

    // 企劃書後端檔案路徑：必須在前面先抓，後面驗證才不會誤判沒有企劃書
    $draftProposalFileForReservation = null;
    $draftProposalUploadedAtForReservation = null;
    $draftProposalOriginalNameForReservation = trim((string)($_POST['draft_proposal_original_name'] ?? ''));

    $formData['draft_proposal_file'] = trim((string)($_POST['draft_proposal_file'] ?? ''));
    $formData['draft_proposal_original_name'] = trim((string)($_POST['draft_proposal_original_name'] ?? ''));
    $formData['draft_proposal_uploaded_at'] = trim((string)($_POST['draft_proposal_uploaded_at'] ?? ''));

    if ($formData['draft_proposal_file'] !== '') {
        $draftProposalFileForReservation = $formData['draft_proposal_file'];
        $draftProposalUploadedAtForReservation = $formData['draft_proposal_uploaded_at'] !== ''
            ? $formData['draft_proposal_uploaded_at']
            : date('Y-m-d H:i:s');
    } else {
        $currentDraftIdForProposalEarly = isset($_POST['current_draft_id']) ? (int)$_POST['current_draft_id'] : 0;

        if ($currentDraftIdForProposalEarly > 0) {
            $draftProposalStmtEarly = mysqli_prepare(
                $link,
                'SELECT proposal_file, proposal_original_name, proposal_uploaded_at
                 FROM reservation_drafts
                 WHERE draft_id = ? AND user_id = ?
                 LIMIT 1'
            );

            if ($draftProposalStmtEarly) {
                mysqli_stmt_bind_param($draftProposalStmtEarly, 'is', $currentDraftIdForProposalEarly, $userId);
                mysqli_stmt_execute($draftProposalStmtEarly);
                $draftProposalResultEarly = mysqli_stmt_get_result($draftProposalStmtEarly);
                $draftProposalRowEarly = $draftProposalResultEarly ? mysqli_fetch_assoc($draftProposalResultEarly) : null;
                mysqli_stmt_close($draftProposalStmtEarly);

                if ($draftProposalRowEarly && !empty($draftProposalRowEarly['proposal_file'])) {
                    $draftProposalFileForReservation = (string)$draftProposalRowEarly['proposal_file'];
                    if (!empty($draftProposalRowEarly['proposal_original_name'])) {
                        $draftProposalOriginalNameForReservation = (string)$draftProposalRowEarly['proposal_original_name'];
                    }
                    $draftProposalUploadedAtForReservation = !empty($draftProposalRowEarly['proposal_uploaded_at'])
                        ? (string)$draftProposalRowEarly['proposal_uploaded_at']
                        : date('Y-m-d H:i:s');

                    $formData['draft_proposal_file'] = $draftProposalFileForReservation;
                    $formData['draft_proposal_original_name'] = $draftProposalOriginalNameForReservation;
                    $formData['draft_proposal_uploaded_at'] = $draftProposalUploadedAtForReservation;
                }
            }
        }
    }

    $formData['alcohol_coordinator'] = trim((string)($_POST['alcohol_coordinator'] ?? ''));
    $formData['alcohol_president'] = trim((string)($_POST['alcohol_president'] ?? ''));

    // 明火表單 JSON 解析
$formData['fire_activity_name'] = trim((string)($_POST['fire_activity_name'] ?? ''));
    $formData['fire_date'] = trim((string)($_POST['fire_date'] ?? ''));
    $formData['fire_location'] = trim((string)($_POST['fire_location'] ?? ''));

$formData['fire_date'] = !empty($_POST['fire_date']) ? trim((string)$_POST['fire_date']) : null;

    $fsh = $_POST['fire_start_time_h'] ?? '';
    $fsm = $_POST['fire_start_time_m'] ?? '';
    $formData['fire_start_time'] = ($fsh !== '' && $fsm !== '') ? sprintf('%02d:%02d:00', $fsh, $fsm) : null;

    $feh = $_POST['fire_end_time_h'] ?? '';
    $fem = $_POST['fire_end_time_m'] ?? '';
    $formData['fire_end_time'] = ($feh !== '' && $fem !== '') ? sprintf('%02d:%02d:00', $feh, $fem) : null;

    $formData['fire_start_time_h'] = $fsh;
    $formData['fire_start_time_m'] = $fsm;
    $formData['fire_end_time_h'] = $feh;
    $formData['fire_end_time_m'] = $fem;

    // 將六種人員合併成一個 Array 再轉 JSON
    // 支援多種前端送出格式：陣列、JSON 字串、或逗號/換行分隔字串
    $parseStaffField = function($input) {
        if (is_array($input)) {
            $vals = array_filter(array_map('trim', $input), function($v) { return $v !== ''; });
            return array_values($vals);
        }
        if (is_string($input)) {
            $s = trim($input);
            if ($s === '') {
                return [];
            }
            $decoded = json_decode($s, true);
            if (is_array($decoded)) {
                $vals = array_filter(array_map('trim', $decoded), function($v) { return $v !== ''; });
                return array_values($vals);
            }
            $parts = preg_split('/[,\n\r]+/', $s);
            $vals = array_filter(array_map('trim', $parts), function($v) { return $v !== ''; });
            return array_values($vals);
        }
        return [];
    };

    $staffData = [];
    $fieldMap = [
        'fire_performers' => 'fire_staff_performer',
        'fire_oilers' => 'fire_staff_oiler',
        'fire_extinguishers' => 'fire_staff_extinguisher',
        'fire_security' => 'fire_staff_security',
        'fire_emergency' => 'fire_staff_emergency',
        'fire_medical' => 'fire_staff_medical',
    ];
    foreach (array_keys($fieldMap) as $key) {
        $alt = $fieldMap[$key];
        $value = null;
        if (isset($_POST[$key])) {
            $value = $_POST[$key];
        } elseif (isset($_POST[$alt])) {
            $value = $_POST[$alt];
        }
        $staffData[$key] = $parseStaffField($value);
    }
    $formData['fire_staff_json'] = json_encode($staffData, JSON_UNESCAPED_UNICODE);

    // Also populate individual longtext columns from the staff arrays
    $formData['fire_performers'] = !empty($staffData['fire_performers']) ? implode("\n", $staffData['fire_performers']) : null;
    $formData['fire_oilers'] = !empty($staffData['fire_oilers']) ? implode("\n", $staffData['fire_oilers']) : null;
    $formData['fire_extinguishers'] = !empty($staffData['fire_extinguishers']) ? implode("\n", $staffData['fire_extinguishers']) : null;
    $formData['fire_security'] = !empty($staffData['fire_security']) ? implode("\n", $staffData['fire_security']) : null;
    $formData['fire_emergency'] = !empty($staffData['fire_emergency']) ? implode("\n", $staffData['fire_emergency']) : null;
    $formData['fire_medical'] = !empty($staffData['fire_medical']) ? implode("\n", $staffData['fire_medical']) : null;
    @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " STAFF PARSE: " . json_encode(['staffData' => $staffData, 'fire_performers' => $formData['fire_performers'], 'fire_oilers' => $formData['fire_oilers'], 'fire_extinguishers' => $formData['fire_extinguishers'], 'fire_security' => $formData['fire_security'], 'fire_emergency' => $formData['fire_emergency'], 'fire_medical' => $formData['fire_medical']], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
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
                $borrowError = '活動結束時間必須晚於活動開始時間。';
            } else {
                $startDateOnly = strtotime($formData['borrow_start_date']);
                $endDateOnly = strtotime($formData['borrow_end_date']);

                if ($startDateOnly !== false && $endDateOnly !== false) {
                    $diffDays = (int)ceil(($endDateOnly - $startDateOnly) / 86400);

                    if ($diffDays > 4) {
                        $borrowError = '活動天數最多不可超過 4 天，請重新選擇。';
                    }
                }
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
                            // 1. 強制鎖定 reservations 的 user_id 欄位，並確保查詢的是「目前登入者」的 Session ID
                            $tqSql = 'SELECT COALESCE(COUNT(eri.equipment_id), 0) AS total_quantity
                                    FROM reservations r
                                    JOIN equipment_reservation_items eri ON r.reservation_id = eri.reservation_id
                                    JOIN equipments e ON eri.equipment_id = e.equipment_id
                                    WHERE r.user_id = ? 
                                        AND r.approval_status IN ("pending", "approved")
                                        AND r.approval_status NOT IN ("returned", "rejected", "canceled") 
                                        AND r.returned_at IS NULL
                                        AND e.equipment_code = ?';

                            $tqStmt = mysqli_prepare($link, $tqSql);
                            if ($tqStmt) {
                                // 2. 關鍵修正：將原本模糊的 $userId 強制改為 $_SESSION['user_id']
                                // 這樣能保證算出來的「未完成預約」百分之百是此時此刻正在填表的這個人
                                $currentLoggedInUser = $_SESSION['user_id'];
                                mysqli_stmt_bind_param($tqStmt, 'ss', $currentLoggedInUser, $cCode);
                                
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
        } elseif (
            (!isset($_FILES['proposal_file']) || $_FILES['proposal_file']['error'] === UPLOAD_ERR_NO_FILE) &&
            empty($draftProposalFileForReservation)
        ) {
            $borrowError = '請上傳企劃書。';
        } elseif ($formData['setup_flags'] === 'yes' && $formData['flag_count'] > 20) {
            $borrowError = '宣傳旗幟最多只能選 20 支。';
        } elseif ($formData['setup_flags'] === 'yes' && $formData['flag_count'] < 1) {
            $borrowError = '宣傳旗幟數量至少為 1 支。';
        } elseif ($formData['setup_flags'] === 'yes' && !isset($_POST['flag_agreement'])) {
            $borrowError = '請勾選：已閱讀並同意旗幟插立注意事項。';
        } elseif ($formData['setup_flags'] === 'yes' && $formData['borrow_start_date'] !== '' && strtotime($formData['borrow_start_date']) < strtotime('+7 weekdays', strtotime(date('Y-m-d')))) {
            $borrowError = '插立旗幟使用日期只能選 7 個工作天之後的日期。';
        } else {
            $requires30Days = false;
            if (
                $formData['has_alcohol'] === '1' || 
                $formData['has_fire'] === '1' || 
                $formData['has_sales'] === '1' || 
                $formData['participant_count'] === '100~200人' || 
                $formData['participant_count'] === '200人以上' ||
                (isset($formData['staff_count']) && (int)$formData['staff_count'] >= 100)
            ) {
                $requires30Days = true;
            }

            $min30Timestamp = strtotime('+30 days', strtotime(date('Y-m-d')));

            if ($requires30Days && $formData['borrow_start_date'] !== '' && strtotime($formData['borrow_start_date']) < $min30Timestamp) {
                $borrowError = '包含特殊性質（酒精、明火、攤販或超過100人）的活動，開始日期必須在 30 天之後。';
            } elseif ($requires30Days && $formData['borrow_end_date'] !== '' && strtotime($formData['borrow_end_date']) < $min30Timestamp) {
                $borrowError = '包含特殊性質（酒精、明火、攤販或超過100人）的活動，結束日期也必須在 30 天之後。';
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
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,    organization_name VARCHAR(100) NULL,
    activity_name VARCHAR(100) NULL,
    participant_count VARCHAR(50) NULL,
    staff_count INT NULL DEFAULT 0,
    club_president VARCHAR(100) NULL,
    activity_coordinator VARCHAR(100) NULL,
    coordinator_department VARCHAR(100) NULL,
    coordinator_phone VARCHAR(30) NULL,
    coordinator_other_contact VARCHAR(255) NULL,
    vehicle_entry VARCHAR(10) NULL DEFAULT 'no',
    setup_flags VARCHAR(10) NULL DEFAULT 'no',
    flag_count INT NULL DEFAULT NULL,
    purpose VARCHAR(255) NULL,
    approval_stage VARCHAR(10) NULL DEFAULT 'a',
    has_alcohol VARCHAR(1) NULL DEFAULT '',
    has_fire VARCHAR(1) NULL DEFAULT '',
    has_sales VARCHAR(1) NULL DEFAULT '',
    alcohol_coordinator VARCHAR(50) NULL,
    alcohol_president VARCHAR(50) NULL,

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

                // 草稿載入後 input[type=file] 無法自動回填，因此正式送出時要沿用草稿後端已存檔案
                $draftProposalFileForReservation = null;
                $draftProposalUploadedAtForReservation = null;
                
                // 首先檢查隱藏表單字段中是否有草稿企劃書信息（來自步驟導航時的保留值）
                $hiddenProposalFile = isset($_POST['draft_proposal_file']) ? trim((string)$_POST['draft_proposal_file']) : '';
                $hiddenProposalUploadedAt = isset($_POST['draft_proposal_uploaded_at']) ? trim((string)$_POST['draft_proposal_uploaded_at']) : '';
                
                if (!empty($hiddenProposalFile)) {
                    $draftProposalFileForReservation = $hiddenProposalFile;
                    $draftProposalUploadedAtForReservation = !empty($hiddenProposalUploadedAt) ? $hiddenProposalUploadedAt : date('Y-m-d H:i:s');
                } else {
                    // 如果隱藏字段中沒有，則查詢草稿表
                    $currentDraftIdForProposal = isset($_POST['current_draft_id']) ? (int)$_POST['current_draft_id'] : 0;

                    if ($currentDraftIdForProposal > 0) {
                        $draftProposalStmt = mysqli_prepare(
                            $link,
                            'SELECT proposal_file, proposal_original_name, proposal_uploaded_at
                             FROM reservation_drafts
                             WHERE draft_id = ? AND user_id = ?
                             LIMIT 1'
                        );

                        if ($draftProposalStmt) {
                            mysqli_stmt_bind_param($draftProposalStmt, 'is', $currentDraftIdForProposal, $userId);
                            mysqli_stmt_execute($draftProposalStmt);
                            $draftProposalResult = mysqli_stmt_get_result($draftProposalStmt);
                            $draftProposalRow = $draftProposalResult ? mysqli_fetch_assoc($draftProposalResult) : null;
                            mysqli_stmt_close($draftProposalStmt);

                            if ($draftProposalRow && !empty($draftProposalRow['proposal_file'])) {
                                $draftProposalFileForReservation = (string)$draftProposalRow['proposal_file'];
                                if (!empty($draftProposalRow['proposal_original_name'])) {
                                    $draftProposalOriginalNameForReservation = (string)$draftProposalRow['proposal_original_name'];
                                }
                                $draftProposalUploadedAtForReservation = !empty($draftProposalRow['proposal_uploaded_at'])
                                    ? (string)$draftProposalRow['proposal_uploaded_at']
                                    : date('Y-m-d H:i:s');
                            }
                        }
                    }
                }

                // Ensure `reservations` has `proposal_file` and `proposal_uploaded_at` columns.
                $reservationColsRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'proposal_file\'');
                if (!($reservationColsRes && mysqli_num_rows($reservationColsRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN proposal_file VARCHAR(255) NULL")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add proposal_file failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
                $reservationOriginalNameRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'proposal_original_name\'');
                if (!($reservationOriginalNameRes && mysqli_num_rows($reservationOriginalNameRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN proposal_original_name VARCHAR(255) NULL COMMENT '企劃書原始檔名' AFTER proposal_file")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add proposal_original_name failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
                $reservationUploadedAtRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'proposal_uploaded_at\'');
                if (!($reservationUploadedAtRes && mysqli_num_rows($reservationUploadedAtRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN proposal_uploaded_at DATETIME NULL")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add proposal_uploaded_at failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }

                // Ensure `reservations` has `has_alcohol`, `has_fire`, `has_sales` columns
                $hasAlcoholRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'has_alcohol\'');
                if (!($hasAlcoholRes && mysqli_num_rows($hasAlcoholRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN has_alcohol VARCHAR(1) NULL COMMENT '是否含酒精'")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add has_alcohol failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
                $hasFireRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'has_fire\'');
                if (!($hasFireRes && mysqli_num_rows($hasFireRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN has_fire VARCHAR(1) NULL COMMENT '是否含明火'")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add has_fire failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
                $hasSalesRes = mysqli_query($link, 'SHOW COLUMNS FROM reservations LIKE \'has_sales\'');
                if (!($hasSalesRes && mysqli_num_rows($hasSalesRes) > 0)) {
                    if (!mysqli_query($link, "ALTER TABLE reservations ADD COLUMN has_sales VARCHAR(1) NULL COMMENT '是否含攤販'")) {
                        @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " ALTER reservations add has_sales failed: " . mysqli_error($link) . "\n", FILE_APPEND | LOCK_EX);
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


                // 自動補齊 reservations 缺少欄位，避免 Unknown column xxx in field list
                $requiredReservationColumns = [
                    'organization_name' => "VARCHAR(100) NULL COMMENT '單位名稱/主辦社團'",
                    'activity_name' => "VARCHAR(100) NULL COMMENT '活動名稱'",
                    'participant_count' => "VARCHAR(50) NULL COMMENT '活動對象人數'",
                    'staff_count' => "INT NULL DEFAULT 0 COMMENT '工作人員人數'",
                    'club_president' => "VARCHAR(100) NULL COMMENT '社/會長'",
                    'activity_coordinator' => "VARCHAR(100) NULL COMMENT '活動負責人'",
                    'coordinator_department' => "VARCHAR(100) NULL COMMENT '系級'",
                    'coordinator_phone' => "VARCHAR(30) NULL COMMENT '聯絡電話'",
                    'coordinator_other_contact' => "VARCHAR(255) NULL COMMENT '其他聯絡方式'",
                    'vehicle_entry' => "VARCHAR(10) NULL DEFAULT 'no' COMMENT '是否車輛入校'",
                    'setup_flags' => "VARCHAR(10) NULL DEFAULT 'no' COMMENT '是否插旗'",
                    'flag_count' => "INT NULL DEFAULT NULL COMMENT '旗幟數量'",
                    'purpose' => "VARCHAR(255) NULL COMMENT '用途'",
                    'submitted_at' => "DATETIME NULL COMMENT '送出時間'",
                    'approval_stage' => "VARCHAR(10) NULL DEFAULT 'a' COMMENT '審核階段'",
                    'has_alcohol' => "VARCHAR(1) NULL DEFAULT '' COMMENT '是否含酒精'",
                    'has_fire' => "VARCHAR(1) NULL DEFAULT '' COMMENT '是否含明火'",
                    'has_sales' => "VARCHAR(1) NULL DEFAULT '' COMMENT '是否含販售/攤販'",
                    'alcohol_coordinator' => "VARCHAR(50) NULL COMMENT '酒精活動負責人'",
                    'alcohol_president' => "VARCHAR(50) NULL COMMENT '酒精活動社長'",
                    'proposal_file' => "VARCHAR(255) NULL COMMENT '企劃書路徑'",
                    'proposal_uploaded_at' => "DATETIME NULL COMMENT '企劃書上傳時間'",
                    'certificate_id' => "BIGINT UNSIGNED NULL COMMENT '證照編號'",
                    'fire_activity_name' => "VARCHAR(255) NULL COMMENT '明火活動名稱'",
                    'fire_date' => "DATE NULL COMMENT '明火日期'",
                    'fire_start_time' => "TIME NULL COMMENT '明火開始時間'",
                    'fire_end_time' => "TIME NULL COMMENT '明火結束時間'",
                    'fire_location' => "VARCHAR(255) NULL COMMENT '明火地點'",
                    'fire_staff_json' => "JSON NULL COMMENT '明火工作人員清單'",
                    'sales_location' => "VARCHAR(50) NULL COMMENT '攤位地點'",
                    'sales_count' => "INT NULL DEFAULT 0 COMMENT '攤位數量'"
                ];

                foreach ($requiredReservationColumns as $columnName => $columnDefinition) {
                    $safeColumnName = mysqli_real_escape_string($link, $columnName);
                    $columnCheckResult = mysqli_query($link, "SHOW COLUMNS FROM reservations LIKE '{$safeColumnName}'");

                    if (!($columnCheckResult && mysqli_num_rows($columnCheckResult) > 0)) {
                        $alterSql = "ALTER TABLE reservations ADD COLUMN {$columnName} {$columnDefinition}";

                        if (!mysqli_query($link, $alterSql)) {
                            @file_put_contents(
                                __DIR__ . '/borrow_debug.log',
                                date('c') . " ALTER reservations add {$columnName} failed: " . mysqli_error($link) . "\n",
                                FILE_APPEND | LOCK_EX
                            );
                        }
                    }
                }

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
                $hasAlcoholCol = in_array('has_alcohol', $reservationCols, true);
                $hasFireCol = in_array('has_fire', $reservationCols, true);
                $hasSalesCol = in_array('has_sales', $reservationCols, true);

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

                // 計算例假日天數與費用（伺服器端）
                $holiday_fee_count = 0;
                $holiday_fee = 0;
                $HOLIDAY_RATE = 200;
                $startDateStr = $formData['borrow_start_date'] ?? '';
                $endDateStr = $formData['borrow_end_date'] ?? '';
                if ($startDateStr !== '' && $endDateStr !== '') {
                    $startDate = DateTime::createFromFormat('Y-m-d', $startDateStr);
                    $endDate = DateTime::createFromFormat('Y-m-d', $endDateStr);
                    if ($startDate && $endDate && $startDate <= $endDate) {
                        // 讀取 holidays 表（若存在）
                        $holidayDates = [];
                        $holTableRes = mysqli_query($link, "SHOW TABLES LIKE 'holidays'");
                        if ($holTableRes && mysqli_num_rows($holTableRes) > 0) {
                            $safeStart = mysqli_real_escape_string($link, $startDate->format('Y-m-d'));
                            $safeEnd = mysqli_real_escape_string($link, $endDate->format('Y-m-d'));
                            $holRes = mysqli_query($link, "SELECT `date` FROM `holidays` WHERE `date` BETWEEN '{$safeStart}' AND '{$safeEnd}'");
                            if ($holRes) {
                                while ($hrow = mysqli_fetch_assoc($holRes)) {
                                    $holidayDates[] = $hrow['date'];
                                }
                            }
                        }

                        // 若沒有 holidays table，將週末視為假日（fallback）
                        $d = clone $startDate;
                        while ($d <= $endDate) {
                            $ymd = $d->format('Y-m-d');
                            $weekday = (int)$d->format('w'); // 0 = Sunday, 6 = Saturday
                            $isHoliday = in_array($ymd, $holidayDates, true);
                            if (empty($holidayDates) && ($weekday === 0 || $weekday === 6)) {
                                $isHoliday = true;
                            }
                            if ($isHoliday) $holiday_fee_count++;
                            $d->modify('+1 day');
                        }
                        $holiday_fee = $holiday_fee_count * $HOLIDAY_RATE;
                    }
                }

                $insertCols = [$applicantColumn, 'borrow_start_at', 'borrow_end_at'];
                $bindValuesTemplate = [$userId, $borrowStartAtSql, $borrowEndAtSql];
                $bindTypesTemplate = 'sss';

                $optionalReservationValues = [
                    'organization_name' => ['type' => 's', 'value' => $formData['organization_name']],
                    'activity_name' => ['type' => 's', 'value' => $formData['activity_name']],
                    'participant_count' => ['type' => 's', 'value' => $formData['participant_count']],
                    'staff_count' => ['type' => 'i', 'value' => (int)$formData['staff_count']],
                    'club_president' => ['type' => 's', 'value' => $formData['club_president']],
                    'activity_coordinator' => ['type' => 's', 'value' => $formData['activity_coordinator']],
                    'coordinator_department' => ['type' => 's', 'value' => $formData['coordinator_department']],
                    'coordinator_phone' => ['type' => 's', 'value' => $formData['coordinator_phone']],
                    'coordinator_other_contact' => ['type' => 's', 'value' => $formData['coordinator_other_contact']],
                    'vehicle_entry' => ['type' => 's', 'value' => $formData['vehicle_entry']],
                    'setup_flags' => ['type' => 's', 'value' => $formData['setup_flags']],
                    'flag_count' => ['type' => 's', 'value' => $formData['setup_flags'] === 'yes' && $formData['flag_count'] !== null ? (string)(int)$formData['flag_count'] : null],
                    'purpose' => ['type' => 's', 'value' => $formData['purpose']],
                    'submitted_at' => ['type' => 's', 'value' => $submittedAtVal],
                    'approval_stage' => ['type' => 's', 'value' => 'a'],
                    'certificate_id' => ['type' => 'i', 'value' => $certificateId],
                    'has_alcohol' => ['type' => 's', 'value' => $formData['has_alcohol']],
                    'has_fire' => ['type' => 's', 'value' => $formData['has_fire']],
                    'has_sales' => ['type' => 's', 'value' => $formData['has_sales']],
                    'alcohol_coordinator' => ['type' => 's', 'value' => $formData['alcohol_coordinator'] ?? ''],
                    'alcohol_president' => ['type' => 's', 'value' => $formData['alcohol_president'] ?? ''],
                    'fire_activity_name' => ['type' => 's', 'value' => $formData['fire_activity_name'] !== '' ? $formData['fire_activity_name'] : null],
                    'fire_date' => ['type' => 's', 'value' => $formData['fire_date']],
                    'fire_start_time' => ['type' => 's', 'value' => $formData['fire_start_time']],
                    'fire_end_time' => ['type' => 's', 'value' => $formData['fire_end_time']],
                    'fire_location' => ['type' => 's', 'value' => $formData['fire_location'] !== '' ? $formData['fire_location'] : null],
                    // Individual longtext columns for backward compatibility
                    'fire_performers' => ['type' => 's', 'value' => $formData['fire_performers']],
                    'fire_oilers' => ['type' => 's', 'value' => $formData['fire_oilers']],
                    'fire_extinguishers' => ['type' => 's', 'value' => $formData['fire_extinguishers']],
                    'fire_security' => ['type' => 's', 'value' => $formData['fire_security']],
                    'fire_emergency' => ['type' => 's', 'value' => $formData['fire_emergency']],
                    'fire_medical' => ['type' => 's', 'value' => $formData['fire_medical']],
                    'fire_staff_json' => ['type' => 's', 'value' => $formData['fire_staff_json']],
                    'sales_location' => ['type' => 's', 'value' => $formData['sales_location'] !== '' ? $formData['sales_location'] : null],
                    'sales_count' => ['type' => 'i', 'value' => $formData['sales_count'] !== '' ? (int)$formData['sales_count'] : null]
                    ,
                    // 假日費用欄位（若存在資料表則會自動加入）
                    'holiday_fee_count' => ['type' => 'i', 'value' => $holiday_fee_count],
                    'holiday_fee' => ['type' => 'i', 'value' => $holiday_fee]
                ];

                foreach ($optionalReservationValues as $columnName => $config) {
                    if (in_array($columnName, $reservationCols, true)) {
                        $insertCols[] = $columnName;
                        $bindValuesTemplate[] = $config['value'];
                        $bindTypesTemplate .= $config['type'];
                    }
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
                @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " INSERT SQL: " . $insertReservationSql . "\nBind Types: " . $bindTypesTemplate . "\nBind Values: " . json_encode($bindValuesTemplate) . "\n", FILE_APPEND | LOCK_EX);
                mysqli_stmt_bind_param($reservationStmt, $bindTypesTemplate, ...$bindValuesTemplate);
                if (!mysqli_stmt_execute($reservationStmt)) {
                    @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " Execute failed: " . mysqli_stmt_error($reservationStmt) . "\n", FILE_APPEND | LOCK_EX);
                    throw new RuntimeException('建立預約主檔失敗：' . mysqli_stmt_error($reservationStmt));
                }
                $commonReservationId = (int)mysqli_insert_id($link);
                @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " Insert ID: " . $commonReservationId . "\n", FILE_APPEND | LOCK_EX);
                if ($commonReservationId === 0) {
                    throw new RuntimeException('建立預約主檔失敗：無法取得預約編號');
                }
                mysqli_stmt_close($reservationStmt);

                $createdReservationIds = [$commonReservationId];
                $reservationId = $commonReservationId;

                if (!empty($cartEquipments)) {
                    $stockCheckStmt = mysqli_prepare(
                        $link,
                            'SELECT COUNT(*) AS available_count FROM equipments WHERE equipment_code = ? AND operation_status = 1 FOR UPDATE'
                    );
                    $cursorStmt = mysqli_prepare(
                        $link,
                        'SELECT borrow_cursor_equipment_id FROM equipment_categories WHERE equipment_code = ? FOR UPDATE'
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
                         ORDER BY CASE WHEN e.equipment_id > ? THEN 0 ELSE 1 END, e.equipment_id ASC LIMIT ?'
                    );
                    $reservationItemStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO equipment_reservation_items (reservation_id, equipment_id) VALUES (?, ?)'
                    );
                    $updateEquipmentStatusStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments SET operation_status = 2 WHERE equipment_id = ? AND operation_status = 1 AND ? <= NOW()'
                    );
                    $updateCursorStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipment_categories SET borrow_cursor_equipment_id = ? WHERE equipment_code = ?'
                    );
                    if (!$stockCheckStmt || !$cursorStmt || !$selectEquipmentStmt || !$reservationItemStmt || !$updateEquipmentStatusStmt || !$updateCursorStmt) {
                        throw new RuntimeException('建立器材預約明細指令失敗：' . mysqli_error($link));
                    }

                    // 針對購物車內【每一個器材項目】建立各自獨立的預約單 (reservation)
                    foreach ($cartEquipments as $item) {
                        $cCode = $item['code'];
                        $cQty = (int)$item['quantity'];
                        $borrowCursorId = 0;

                        mysqli_stmt_bind_param($cursorStmt, 's', $cCode);
                        mysqli_stmt_execute($cursorStmt);
                        $cursorResult = mysqli_stmt_get_result($cursorStmt);
                        $cursorRow = $cursorResult ? mysqli_fetch_assoc($cursorResult) : null;
                        if ($cursorRow && $cursorRow['borrow_cursor_equipment_id'] !== null) {
                            $borrowCursorId = (int)$cursorRow['borrow_cursor_equipment_id'];
                        }

                                                // 檢查該天是否已有任何預約（器材按天單位）
                                                $overlapCheckSql = "
                                                        SELECT COALESCE(COUNT(eri.equipment_id), 0) AS used_qty
                                                        FROM equipment_reservation_items eri
                                                        JOIN reservations r ON r.reservation_id = eri.reservation_id
                                                        JOIN equipments e ON e.equipment_id = eri.equipment_id
                                                        WHERE e.equipment_code = ?
                                                            AND r.approval_status IN ('pending', 'approved')
                                                               AND r.returned_at IS NULL
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

                        mysqli_stmt_bind_param($selectEquipmentStmt, 'sssii', $cCode, $borrowEndAtSql, $borrowStartAtSql, $borrowCursorId, $cQty);
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
                        $lastAllocatedEquipmentId = null;
                        foreach ($equipmentIds as $equipmentId) {
                            mysqli_stmt_bind_param($reservationItemStmt, 'ii', $itemReservationId, $equipmentId);
                            if (!mysqli_stmt_execute($reservationItemStmt)) {
                                throw new RuntimeException('新增器材預約明細失敗：' . mysqli_stmt_error($reservationItemStmt));
                            }
                            
                            mysqli_stmt_bind_param($updateEquipmentStatusStmt, 'is', $equipmentId, $borrowStartAtSql);
                            if (!mysqli_stmt_execute($updateEquipmentStatusStmt)) {
                                throw new RuntimeException('更新器材狀態失敗：' . mysqli_stmt_error($updateEquipmentStatusStmt));
                            }
                            $lastAllocatedEquipmentId = $equipmentId;
                        }

                        if ($lastAllocatedEquipmentId !== null) {
                            mysqli_stmt_bind_param($updateCursorStmt, 'is', $lastAllocatedEquipmentId, $cCode);
                            if (!mysqli_stmt_execute($updateCursorStmt)) {
                                throw new RuntimeException('更新器材輪轉指標失敗：' . mysqli_stmt_error($updateCursorStmt));
                            }
                        }
                    }

                    mysqli_stmt_close($stockCheckStmt);
                    mysqli_stmt_close($cursorStmt);
                    mysqli_stmt_close($selectEquipmentStmt);
                    mysqli_stmt_close($reservationItemStmt);
                    mysqli_stmt_close($updateEquipmentStatusStmt);
                    mysqli_stmt_close($updateCursorStmt);
                }

                $proposalFileForReservation = null;
                $proposalOriginalNameForReservation = null;
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
                    $proposalOriginalNameForReservation = basename((string)$file['name']);
                    $proposalUploadedAtForReservation = date('Y-m-d H:i:s');
                    $uploadedProposalPath = $targetPath;

                    $updateProposalStmt = mysqli_prepare(
                        $link,
                        'UPDATE reservations SET proposal_file = ?, proposal_original_name = ?, proposal_uploaded_at = ? WHERE reservation_id = ?'
                    );
                    if (!$updateProposalStmt) {
                        throw new RuntimeException('更新企劃書資訊失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($updateProposalStmt, 'sssi', $proposalFileForReservation, $proposalOriginalNameForReservation, $proposalUploadedAtForReservation, $commonReservationId);
                    mysqli_stmt_execute($updateProposalStmt);
                    mysqli_stmt_close($updateProposalStmt);
                } elseif ($draftProposalFileForReservation !== null) {
                    $proposalFileForReservation = $draftProposalFileForReservation;
                    $proposalOriginalNameForReservation = $draftProposalOriginalNameForReservation !== ''
                        ? $draftProposalOriginalNameForReservation
                        : basename((string)$draftProposalFileForReservation);
                    $proposalUploadedAtForReservation = $draftProposalUploadedAtForReservation;

                    $updateProposalStmt = mysqli_prepare(
                        $link,
                        'UPDATE reservations SET proposal_file = ?, proposal_original_name = ?, proposal_uploaded_at = ? WHERE reservation_id = ?'
                    );
                    if (!$updateProposalStmt) {
                        throw new RuntimeException('沿用草稿企劃書失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($updateProposalStmt, 'sssi', $proposalFileForReservation, $proposalOriginalNameForReservation, $proposalUploadedAtForReservation, $commonReservationId);
                    mysqli_stmt_execute($updateProposalStmt);
                    mysqli_stmt_close($updateProposalStmt);
                } else {
                    throw new RuntimeException('請先上傳活動企劃書！');
                }

                if (!empty($formData['space_id'])) {
                    $spaceConflictStmt = mysqli_prepare(
                        $link,
                        'SELECT COUNT(*) AS conflict_count
                         FROM space_reservation_items sri
                         JOIN reservations r ON r.reservation_id = sri.reservation_id
                         WHERE sri.space_id = ?
                             AND r.approval_status IN ("pending", "approved")
                             AND r.returned_at IS NULL
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
                    if (!mysqli_stmt_execute($spaceItemStmt)) {
                        throw new RuntimeException('建立空間預約明細失敗：' . mysqli_stmt_error($spaceItemStmt));
                    }
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
                            require_once __DIR__ . '/config/mail.php';
                            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                            try {
                                if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
                                    throw new RuntimeException('郵件設定未啟用或未完成，請檢查 config/mail.php');
                                }
                                $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;
                                $mail->isSMTP();
                                $mail->Host       = 'smtp.gmail.com'; 
                                $mail->SMTPAuth   = true;
                                $mail->Username   = $MAIL_USERNAME;
                                $mail->Password   = $MAIL_PASSWORD;
                                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                                $mail->Port       = 465;
                                $mail->CharSet    = 'UTF-8';
                                $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
                                $mail->addAddress($userEmail, $displayName);
                                $mail->isHTML(true);
                                $mail->Subject = '【系統通知】預約申請已成功送出';
                                $mail->Body    = "您好，{$displayName}：<br><br>您的預約申請（單號：{$idsStr}）已經成功送出，目前狀態為<b>「審核中」</b>。<br><br>系統管理員將會儘速處理您的申請，審核結果出爐後會再次以 Email 通知您。<br><br>感謝您的使用！";
                                $mail->AltBody = "您好，{$displayName}：\n\n您的預約申請（單號：{$idsStr}）已經成功送出，目前狀態為「審核中」。\n\n系統管理員將會儘速處理您的申請，審核結果出爐後會再次以 Email 通知您。\n\n感謝您的使用！";
                                $mail->send();
                                @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " 預約成功信件已寄送至: " . $userEmail . "\n", FILE_APPEND | LOCK_EX);
                            } catch (Exception $e) {
                                @file_put_contents(__DIR__ . '/borrow_debug.log', date('c') . " 預約成功信件寄送失敗 (to: {$userEmail}): " . $e->getMessage() . " | ErrorInfo: " . $mail->ErrorInfo . "\n", FILE_APPEND | LOCK_EX);
                                error_log("預約成功信件寄送失敗 (to: {$userEmail}): " . $e->getMessage());
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

        /* 草稿保存选择模态窗口 */
        .draft-choice-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease;
        }

        .draft-choice-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .draft-choice-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            0% { transform: translateY(30px); opacity: 0; }
            100% { transform: translateY(0); opacity: 1; }
        }

        .draft-choice-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 15px;
            text-align: center;
        }

        .draft-choice-draft-id {
            text-align: center;
            font-size: 14px;
            color: #64748b;
            margin-bottom: 20px;
            background: #f1f5f9;
            padding: 10px;
            border-radius: 8px;
            font-weight: 500;
        }

        .draft-choice-description {
            font-size: 15px;
            color: #475569;
            margin-bottom: 25px;
            line-height: 1.6;
            text-align: center;
        }

        .draft-choice-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .draft-btn-choice {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .draft-btn-update {
            background: #3b82f6;
            color: white;
        }

        .draft-btn-update:hover {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .draft-btn-new {
            background: #f59e0b;
            color: white;
        }

        .draft-btn-new:hover {
            background: #d97706;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

    </style>
    <!-- 引入 Flatpickr -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh-tw.js"></script>
</head>
<body>
    <!-- 草稿保存选择模态窗口 -->
    <div id="draftChoiceModal" class="draft-choice-modal">
        <div class="draft-choice-content">
            <div class="draft-choice-title">💾 草稿保存方式</div>
            <div class="draft-choice-draft-id">草稿編號：<span id="draftIdDisplay">-</span></div>
            <div class="draft-choice-description">
                您已有一個正在進行中的草稿，請選擇保存方式：
            </div>
            <div class="draft-choice-buttons">
                <button type="button" class="draft-btn-choice draft-btn-update" id="draftBtnUpdate">
                    ✓ 覆蓋更新
                </button>
                <button type="button" class="draft-btn-choice draft-btn-new" id="draftBtnNew">
                    ✚ 新建草稿
                </button>
            </div>
        </div>
    </div>

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
                            <input type="hidden" name="draft_proposal_file" id="draft_proposal_file" value="">
                            <input type="hidden" name="draft_proposal_original_name" id="draft_proposal_original_name" value="<?php echo htmlspecialchars((string)($formData['draft_proposal_original_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="draft_proposal_uploaded_at" id="draft_proposal_uploaded_at" value="">
                            <input type="file" id="proposal_file" name="proposal_file" accept=".pdf,application/pdf" style="opacity: 0; position: absolute; z-index: -1; width: 0; height: 0;" onchange="if (this.files.length > 0) { document.getElementById('proposal_file_name_display').innerText = '已上傳新企劃書：' + this.files[0].name; const f=document.getElementById('draft_proposal_file'); const n=document.getElementById('draft_proposal_original_name'); const t=document.getElementById('draft_proposal_uploaded_at'); if(f)f.value=''; if(n)n.value=''; if(t)t.value=''; } else { document.getElementById('proposal_file_name_display').innerText = ''; }">
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
                                    <div style="display: flex; gap: 10px;">
                                        <select id="org_type_selector" class="form-control" style="flex: 1;" required>
                                            <option value="">請選擇類型</option>
                                            <option value="系所">系所</option>
                                            <option value="社團">社團</option>
                                        </select>
                                        <select id="organization_name" name="organization_name" class="form-control" style="flex: 2;" required>
                                            <option value="">請先選擇類型</option>
                                        </select>
                                    </div>
                                    <script>
                                    (function(){
                                        const typeSel = document.getElementById('org_type_selector');
                                        const nameSel = document.getElementById('organization_name');
                                        
                                        // 【選項放置區】等一下您可以把大量的選項直接填入這個物件對應的陣列中
                                        const optionsData = {
                                            "系所": [
                                                "文學院", "中國文學系", "歷史學系", "哲學系",
                                                "藝術學院", "音樂學系", "應用美術學系", "景觀設計學系", "藝術與文化創意學士學位學程",
                                                "傳播學院", "影像傳播學系", "新聞傳播學系", "廣告傳播學系", "大眾傳播學研究所", "大眾傳播學士學位學程",
                                                "教育學院", "體育學系", "圖書資訊學系", "教育領導與發展研究所", "師資培育中心", "教育領導與科技發展學士學位學程", "運動休閒管理學士學位學程",
                                                "醫學院", "醫學系", "護理學系", "公共衛生學系", "臨床心理學系", "職能治療學系", "呼吸治療學系", "生物醫學暨藥學研究所", "跨專業長期照護碩士學位學程", "生技醫藥博士學位學程", "生物醫學海量資料分析碩士學位學程", "跨醫療思考服務設計微學分學程",
                                                "理工學院", "數學系", "物理學系", "化學系", "生命科學系", "資訊工程學系", "電機工程學系", "醫學資訊與創新應用學士學位學程", "應用科學與工程研究所博士班", "軟體工程與數位創意學士學位學程", "醫學資訊與健康科技進修學士學位學程",
                                                "外國語文學院", "英國語文學系", "德語語文學系", "法國語文學系", "西班牙語文學系", "日本語文學系", "義大利語文學系", "跨文化研究所",
                                                "民生學院", "兒童與家庭學系、所", "餐旅管理學系、所", "食品科學系", "營養科學系", "食品營養博士學位學程",
                                                "法律學院", "法律學系、所", "財經法律學系、所", "學士後法律學系",
                                                "社會科學院", "社會學系、所", "社會工作學系、所", "經濟學系、所", "宗教學系、所", "心理學系、所", "天主教研修學士學位學程", "非營利組織管理碩士學位學程",
                                                "管理學院", "企業管理學系", "會計學系", "統計資訊學系", "金融與國際企業學系", "資訊管理學系", "商學研究所", "科技管理碩士學位學程", "國際創業與經營管理碩士學位學程", "三邊雙聯國際創業與經營管理碩士學位學程", "國際經營管理碩士班", "社會企業碩士學位學程", "商業管理學士學位學程",
                                                "織品服裝學院", "織品服裝學系", "博物館學研究所", "品牌與時尚經營管理碩士學位學程"
                                            ],
                                            "社團": [
                                                "健言社", "大千社", "天文社", "中華醫藥研習社", "國際經濟商管學生會", "占星塔羅社", "信望愛社", "淨仁社", "學園團契社", "禪學社", "聖經研究社", "教育學程學會", "福智青年社", "性別研究社", "永續影響力大使社", "創新創業社", "租稅研究社", "光鹽社", "金融投資研究社",
                                                "僑生聯誼會", "高中校友聯合總會", "轉學生聯誼會", "野營社", "魔術社", "棋藝社", "飲料調製社", "努瑪社", "國際菁英學生會", "桌上遊戲社", "電子競技社", "二輪社", "咖啡研究社", "韓國流行文化研究社",
                                                "登山社", "國術社", "跆拳道社", "柔道社", "劍道社", "擊劍社", "羽球社", "桌球社", "網球社", "射箭社", "同心救生社", "空手道社", "黑輪社", "合氣道社", "歐洲劍術社", "撞球社", "Kali武術社", "自由潛水社", "跑步社", "袋棍球社",
                                                "書法社", "攝影社", "熱舞社", "戲劇社", "國際標準舞蹈社", "廣播演藝社", "動漫電玩研習社", "影片創作社", "弓道社", "光火藝術社", "民俗體育社", "生活花藝設計社",
                                                "國樂社", "管弦樂社", "民謠吉他社", "搖滾音樂研究社", "鋼琴社", "數位音樂創作研習社", "烏克麗麗社", "嘻哈文化社", "爵士鋼琴社",
                                                "同舟共濟服務社", "醒新愛愛服務社", "急救康輔社", "崇德志工服務社", "基層文化服務社", "慈濟青年社", "繪本服務學習社", "勵德青少年服務社"
                                            ]
                                        };

                                        // 負責根據類型渲染第二個下拉選單的函數
                                        function renderOptions(type, forceValue = '') {
                                            nameSel.innerHTML = '<option value="">' + (type ? '請選擇單位名稱' : '請先選擇類型') + '</option>';
                                            if (optionsData[type]) {
                                                optionsData[type].forEach(function(optText) {
                                                    const opt = document.createElement('option');
                                                    opt.value = optText;
                                                    opt.textContent = optText;
                                                    nameSel.appendChild(opt);
                                                });
                                            }
                                            // 預防舊資料或是草稿紀錄的值不在選單內時，強制新增讓其可見
                                            if (forceValue && (!optionsData[type] || !optionsData[type].includes(forceValue))) {
                                                const opt = document.createElement('option');
                                                opt.value = forceValue;
                                                opt.textContent = forceValue;
                                                nameSel.appendChild(opt);
                                            }
                                        }

                                        // 第一層被選擇時的連動事件
                                        typeSel.addEventListener('change', function(){
                                            renderOptions(this.value);
                                            nameSel.value = ''; 
                                            // 手動觸發事件以連動原本的「旗幟表單」等其他JS程式
                                            nameSel.dispatchEvent(new Event('change', { bubbles: true }));
                                            nameSel.dispatchEvent(new Event('input', { bubbles: true }));
                                        });

                                        // 【核心相容處理】攔截 value 的設定操作，確保草稿一鍵載入功能依然可以完美運作，完全不需要動到草稿腳本
                                        const originalDescriptor = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
                                        Object.defineProperty(nameSel, 'value', {
                                            set: function(val) {
                                                let foundType = '';
                                                for (let t in optionsData) {
                                                    if (optionsData[t].includes(val)) {
                                                        foundType = t;
                                                        break;
                                                    }
                                                }
                                                if (foundType) {
                                                    typeSel.value = foundType;
                                                    renderOptions(foundType);
                                                } else if (val) {
                                                    renderOptions('', val);
                                                }
                                                originalDescriptor.set.call(this, val);
                                                this.dispatchEvent(new Event('change', { bubbles: true }));
                                                this.dispatchEvent(new Event('input', { bubbles: true }));
                                            },
                                            get: function() {
                                                return originalDescriptor.get.call(this);
                                            }
                                        });

                                        // 處理頁面刷新或PHP傳回的初始值
                                        const initVal = "<?php echo htmlspecialchars($formData['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>";
                                        if (initVal) {
                                            nameSel.value = initVal; // 觸發上方設定的 setter 自動歸類
                                        }
                                    })();
                                    </script>
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
                                <div class="form-group">
                                    <label style="font-weight: bold; color: #333;">活動負責人 <span style="color:red">*</span></label>
                                    <input type="text" id="activity_coordinator" name="activity_coordinator" class="form-control" placeholder="請輸入活動負責人姓名" required value="<?php echo htmlspecialchars($formData['activity_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
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

                                <div class="form-group" style="margin-top: 10px;">
                                    <label>活動特殊性質（可複選）- 勾選則下一頁將出現表單</label>
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
                                            <?php for($h=7; $h<=22; $h++) { 
                                                $selected = ($curBsh !== '' && $curBsh === $h) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $h; ?>" <?php echo $selected; ?>><?php echo $h; ?></option>
                                            <?php } ?>
                                        </select>
                                        <span>時</span>
                                        
                                        <select name="borrow_start_time_m" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <option value="00" <?php echo ($curBsm !== '' && $curBsm === 0) ? 'selected' : ''; ?>>00</option>
                                            <option value="10" <?php echo ($curBsm !== '' && $curBsm === 10) ? 'selected' : ''; ?>>10</option>
                                            <option value="20" <?php echo ($curBsm !== '' && $curBsm === 20) ? 'selected' : ''; ?>>20</option>
                                            <option value="30" <?php echo ($curBsm !== '' && $curBsm === 30) ? 'selected' : ''; ?>>30</option>
                                            <option value="40" <?php echo ($curBsm !== '' && $curBsm === 40) ? 'selected' : ''; ?>>40</option>
                                            <option value="50" <?php echo ($curBsm !== '' && $curBsm === 50) ? 'selected' : ''; ?>>50</option>
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
                                            <?php for($h=7; $h<=22; $h++) { 
                                                $selected = ($curBeh !== '' && $curBeh === $h) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo $h; ?>" <?php echo $selected; ?>><?php echo $h; ?></option>
                                            <?php } ?>
                                        </select>
                                        <span>時</span>
                                        
                                        <select name="borrow_end_time_m" class="form-control" required style="padding: 8px; width: 80px;">
                                            <option value="">選擇</option>
                                            <option value="00" <?php echo ($curBem !== '' && $curBem === 0) ? 'selected' : ''; ?>>00</option>
                                            <option value="10" <?php echo ($curBem !== '' && $curBem === 10) ? 'selected' : ''; ?>>10</option>
                                            <option value="20" <?php echo ($curBem !== '' && $curBem === 20) ? 'selected' : ''; ?>>20</option>
                                            <option value="30" <?php echo ($curBem !== '' && $curBem === 30) ? 'selected' : ''; ?>>30</option>
                                            <option value="40" <?php echo ($curBem !== '' && $curBem === 40) ? 'selected' : ''; ?>>40</option>
                                            <option value="50" <?php echo ($curBem !== '' && $curBem === 50) ? 'selected' : ''; ?>>50</option>
                                        </select>
                                        <span>分</span>
                                    </div>
                                    <div class="form-group" style="margin-top:10px;">
                                        <label>例假日加收</label>
                                        <div id="holiday-fee-display" style="padding:8px; background:#fff; border:1px solid #e6e6e6; border-radius:6px;">例假日收場地費 200 元/次。已選 0 天，費用：0 元</div>
                                        <input type="hidden" name="holiday_fee_count" id="holiday_fee_count" value="<?php echo isset($holiday_fee_count)?(int)$holiday_fee_count:((int)($formData['holiday_fee_count'] ?? 0)); ?>">
                                        <input type="hidden" name="holiday_fee" id="holiday_fee" value="<?php echo isset($holiday_fee)?(int)$holiday_fee:((int)($formData['holiday_fee'] ?? 0)); ?>">
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

                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start; margin-bottom:12px; padding: 20px 20px 0 20px;">
                                        <div>
                                            <label>申請單位 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_organization_name" name="flag_organization_name" class="form-control" readonly style="background-color: #e2e8f0; cursor: not-allowed; value="<?php echo htmlspecialchars($formData['flag_organization_name'] ?? $formData['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?> >
                                        </div>
                                        <div>

                                            <label>活動名稱 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_activity_name" name="flag_activity_name" class="form-control" readonly style="background-color: #e2e8f0; cursor: not-allowed; value="<?php echo htmlspecialchars($formData['flag_activity_name'] ?? $formData['activity_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>>
                                        </div>
                                        <div>
                                            <label>負責人 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_responsible_person" name="flag_responsible_person" class="form-control" value="<?php echo htmlspecialchars($formData['flag_responsible_person'] ?? $formData['activity_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                        <div>
                                            <label>連絡電話 <span style="color:red">*</span></label>
                                            <input type="text" id="flag_contact_phone" name="flag_contact_phone" class="form-control" value="<?php echo htmlspecialchars($formData['flag_contact_phone'] ?? $formData['coordinator_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>

                                    <div style="display:flex; gap:15px; align-items:center; margin-bottom:15px; padding: 0 20px;">
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

                                    <div style="display:flex; gap:15px; align-items:center; margin-bottom:15px; padding: 0 20px 20px 20px;">
                                        <div style="flex:1;">
                                            <label>宣傳旗幟 <span style="color:red">*</span></label>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span>共</span>
                                                <input type="number" name="flag_count" id="flag_count" class="form-control" min="1" max="20" step="1" style="width:100px;height:38px;" placeholder="最多20" value="<?php echo htmlspecialchars(($formData['setup_flags'] === 'yes' ? (string)($formData['flag_count'] ?? 1) : ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                <span>支</span>
                                            </div>
                                        </div>
                                        <div style="flex:1;">
                                            <label>懸掛位置</label>
                                            <div style="padding:8px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:4px;">中央走道</div>
                                            <input type="hidden" id="flag_location" name="flag_location" value="中央走道">
                                        </div>
                                    </div>

                                    <label style="display: flex; align-items: flex-start; gap: 8px; margin: 0; font-weight: normal; cursor: pointer; background: #eff6ff; padding: 15px 20px; border-top: 1px solid #cbd5e1; border-radius: 0 0 8px 8px;">
                                        <input type="checkbox" name="flag_agreement" id="flag_agreement" value="1" <?php echo (isset($formData['flag_agreement']) && $formData['flag_agreement'] == '1') ? 'checked' : ''; ?> style="margin-top: 2px;" required>
                                        <span style="color: #1e3a8a; line-height: 1.5; font-size: 14px;">本人為旗幟插立總負責人，已詳細閱讀並遵守以下各項注意事項，為維護校園安全與景觀，願無條件承擔所插旗幟所致之一切賠償責任，特此聲明。 <span style="color:red">*</span></span>
                                    </label>
                                </div>

                                <!-- 👇 這裡幫你把遺失的【酒精申請表 HTML】補回來了 👇 -->
                                <div id="alcoholDetailsSection" style="display:none; margin-top:20px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">
                                    <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
                                        輔仁大學學生自治組織暨社團辦理提供酒精飲品活動須知
                                    </div>
                                    <div style="padding: 20px;">
                                        <p style="color: #1e293b; font-size: 15px; margin-bottom: 15px; line-height: 1.6; font-weight: bold;">
                                            關於本校學生社團活動具酒精飲品活動，為避免參與人員酒後行為脫序、危及自身或他人安全，或造成飲用人健康上之負擔，請確認以下事項皆已納入活動規劃，並遵守相關規範︰
                                        </p>
                                        
                                        <div style="display: flex; flex-direction: column; gap: 15px; margin-bottom: 25px;">
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin-left: -20px; font-weight: bold; color: #3b82f6;">
                                                <input type="checkbox" id="alcohol_agree_all" onchange="toggleAllAlcoholAgreements(this)" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">同意全部</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_1" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">辦理活動供應酒精性飲品者，需於活動申請時，於企劃書中敘明酒精飲品種類、準備數量、活動形式，連同活動申請表及本須知於活動前一個月送至課外活動指導組。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_2" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">為避免同學酒後行為脫序、危及自身或他人安全，或造成飲用人健康上之負擔，請於企劃書敘明失序行為因應措施。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_3" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">於活動期間主辦單位務必於活動現場明顯處所加註「未滿十八歲請勿購買/領取酒精性飲品」及「飲酒過量有害身體健康」與「禁止酒駕」之警語，提醒活動參與者避免飲酒過量。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_4" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">依「兒童及少年福利與權益保障法」規定，販賣、交付或供應酒或檳榔予兒童及少年者，處新臺幣一萬元以上十萬元以下罰鍰。主辦單位應要求活動中發送或販賣酒精飲料之人員核對領取/購買人身分證明文件，並禁止對未滿十八歲之人發送或販賣。</span>
                                            </label>
                                            <label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
                                                <input type="checkbox" name="alcohol_agree_5" value="yes" style="margin-top: 5px; width: 18px; height: 18px; flex-shrink: 0;">
                                                <span style="font-size: 15px; line-height: 1.6;">主辦單位應提供《辦理提供酒精飲品活動理性飲酒同意書》 供有飲酒意願之參加人員簽署，並提醒參加人員有關警語所示事項(包含未滿十八歲請勿飲酒，於活動中飲用酒精飲料者不得駕駛汽車、機車、腳踏車等)。於活動結束翌日(遇例假日順延)將該同意書送至課外活動指導組備查</span>
                                            </label>
                                        </div>

                                       <div style="display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; align-items: center; background: #f8fafc; padding: 20px; border-radius: 6px; border: 1px solid #e2e8f0;">
                                            <!-- <div style="display: flex; align-items: center; gap: 8px;">
                                                <label for="alcohol_coordinator" style="margin: 0; font-size: 15px; font-weight: bold; white-space: nowrap;">活動負責人</label>
                                                <input type="text" id="alcohol_coordinator" name="alcohol_coordinator" placeholder="姓名" value="<?php echo htmlspecialchars($formData['alcohol_coordinator'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 150px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                            </div> -->
                                            <!-- <div style="display: flex; align-items: center; gap: 8px;">
                                                <label for="alcohol_president" style="margin: 0; font-size: 15px; font-weight: bold; white-space: nowrap;">社長</label>
                                                <input type="text" id="alcohol_president" name="alcohol_president" placeholder="姓名" value="<?php echo htmlspecialchars($formData['alcohol_president'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" style="width: 150px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                            </div> -->
                                            <span style="font-size: 15px; color: #1e293b; font-weight: bold;">(一) 活動負責人與社長已知悉以上事項，願負一切責任。</span>
                                            <span style="font-size: 15px; color: #1e293b; font-weight: bold;">(二) 活動時所有接觸酒精飲品與會者請親自簽署《酒精飲品活動理性飲酒同意書》，請於活動結束翌日(遇例假日順延)送課指組備查。</span>

                                        </div>

                                      </div>
                                    <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
                                        輔仁大學學生活動上火確認表(火舞)
                                    </div>
                                    <div style="padding: 20px;">
                                        <div class="form-group" style="margin-bottom: 15px;">
                                            <label for="fire_activity_name">活動名稱 <span style="color:red">*</span></label>
                                        <input type="text" id="fire_activity_name" name="fire_activity_name" class="form-control" readonly style="background-color: #e2e8f0; cursor: not-allowed;">
                                        </div>

                                        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
                                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                                <label for="fire_date">日期 (限30天後) <span style="color:red">*</span></label>
                                                <input type="date" id="fire_date" name="fire_date" class="form-control" min="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" value="<?php echo htmlspecialchars($formData['fire_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                            <div class="form-group" style="flex: 1; min-width: 150px;">
                                                <label for="fire_location">地點 <span style="color:red">*</span></label>
                                                <input type="text" id="fire_location" name="fire_location" class="form-control" placeholder="請輸入地點" value="<?php echo htmlspecialchars($formData['fire_location'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        </div>

                                        <div class="form-group" style="margin-bottom: 15px;">
                                            <label>時間 <span style="color:red">*</span></label>
                                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                                <?php
                                                $curFsh = $formData['fire_start_time_h'] ?? '';
                                                $curFsm = $formData['fire_start_time_m'] ?? '';
                                                $curFeh = $formData['fire_end_time_h'] ?? '';
                                                $curFem = $formData['fire_end_time_m'] ?? '';
                                                ?>
                                                <select name="fire_start_time_h" class="form-control" style="padding: 8px; width: 80px;">
                                                    <option value="">選擇</option>
                                                    <?php for($h=7; $h<=22; $h++) { ?>
                                                        <option value="<?php echo $h; ?>" <?php echo ($curFsh !== '' && (int)$curFsh === $h) ? 'selected' : ''; ?>><?php echo $h; ?></option>
                                                    <?php } ?>
                                                </select>
                                                <span>時</span>
                                                <select name="fire_start_time_m" class="form-control" style="padding: 8px; width: 80px;">
                                                    <option value="">選擇</option>
                                                    <?php foreach(['00','10','20','30','40','50'] as $m) { ?>
                                                        <option value="<?php echo $m; ?>" <?php echo ($curFsm !== '' && $curFsm === $m) ? 'selected' : ''; ?>><?php echo $m; ?></option>
                                                    <?php } ?>
                                                </select>
                                                <span>分</span>
                                                
                                                <span style="margin: 0 10px;">～</span>
                                                
                                                <select name="fire_end_time_h" class="form-control" style="padding: 8px; width: 80px;">
                                                    <option value="">選擇</option>
                                                    <?php for($h=7; $h<=22; $h++) { ?>
                                                        <option value="<?php echo $h; ?>" <?php echo ($curFeh !== '' && (int)$curFeh === $h) ? 'selected' : ''; ?>><?php echo $h; ?></option>
                                                    <?php } ?>
                                                </select>
                                                <span>時</span>
                                                <select name="fire_end_time_m" class="form-control" style="padding: 8px; width: 80px;">
                                                    <option value="">選擇</option>
                                                    <?php foreach(['00','10','20','30','40','50'] as $m) { ?>
                                                        <option value="<?php echo $m; ?>" <?php echo ($curFem !== '' && $curFem === $m) ? 'selected' : ''; ?>><?php echo $m; ?></option>
                                                    <?php } ?>
                                                </select>
                                                <span>分</span>
                                            </div>
                                        </div>

                                        <!-- 👇 這裡新增各種人員的表格 👇 -->
                                        <hr style="margin: 30px 0; border: 0; border-top: 1px solid #e2e8f0;">
                                        <h4 style="margin: 0 0 15px 0; color: #1e40af; font-size: 16px; font-weight: bold;">活動相關人員名單</h4>
                                        <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;">請點擊「＋新增」按鈕來增加列數，您也可以點擊「刪除」移除列。</p>

                                        <style>
                                            .fire-staff-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 14px; }
                                            .fire-staff-table th, .fire-staff-table td { border: 1px solid #cbd5e1; padding: 8px; text-align: left; }
                                            .fire-staff-table th { background: #f8fafc; font-weight: 600; color: #334155; }
                                            .fire-staff-title { font-size: 15px; font-weight: bold; margin: 0 0 8px 0; color: #334155; }
                                            .fire-staff-wrapper { margin-bottom: 25px; padding: 15px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; }
                                            .btn-add-staff { background: #3b82f6; color: white; border: none; padding: 5px 12px; border-radius: 5px; cursor: pointer; font-size: 13px; transition: 0.2s; }
                                            .btn-add-staff:hover { background: #2563eb; }
                                            .btn-del-staff { color: #ef4444; background: none; border: none; cursor: pointer; font-size: 13px; padding: 4px; transition: 0.2s; }
                                            .btn-del-staff:hover { color: #b91c1c; background: rgba(239, 68, 68, 0.1); border-radius: 4px; }
                                        </style>

                                        <div class="fire-staff-wrapper">
                                            <h5 class="fire-staff-title">表演人員</h5>
                                            <table class="fire-staff-table" id="table_staff_performer">
                                                <thead><tr><th>姓名</th><th style="width: 70px; text-align: center;">操作</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_performer[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn-add-staff" onclick="addFireStaffRow('table_staff_performer', 'fire_staff_performer[]')">＋ 新增人員</button>
                                        </div>

                                        <div class="fire-staff-wrapper">
                                            <h5 class="fire-staff-title">上油人員 <span style="color: #ef4444; font-weight: normal; font-size: 13px;">(至少一人)</span></h5>
                                            <table class="fire-staff-table" id="table_staff_oiler">
                                                <thead><tr><th>姓名</th><th style="width: 70px; text-align: center;">操作</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_oiler[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn-add-staff" onclick="addFireStaffRow('table_staff_oiler', 'fire_staff_oiler[]')">＋ 新增人員</button>
                                        </div>

                                        <div class="fire-staff-wrapper">
                                            <h5 class="fire-staff-title">滅火人員 <span style="color: #ef4444; font-weight: normal; font-size: 13px;">(至少一人)</span></h5>
                                            <table class="fire-staff-table" id="table_staff_extinguisher">
                                                <thead><tr><th>姓名</th><th style="width: 70px; text-align: center;">操作</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_extinguisher[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn-add-staff" onclick="addFireStaffRow('table_staff_extinguisher', 'fire_staff_extinguisher[]')">＋ 新增人員</button>
                                        </div>

                                        <div class="fire-staff-wrapper">
                                            <h5 class="fire-staff-title">維安人員 <span style="color: #ef4444; font-weight: normal; font-size: 13px;">(至少三人)</span></h5>
                                            <table class="fire-staff-table" id="table_staff_security">
                                                <thead><tr><th>姓名</th><th style="width: 70px; text-align: center;">操作</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_security[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_security[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_security[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn-add-staff" onclick="addFireStaffRow('table_staff_security', 'fire_staff_security[]')">＋ 新增人員</button>
                                        </div>

                                        <div class="fire-staff-wrapper">
                                            <h5 class="fire-staff-title">緊急狀況處理人員 <span style="color: #ef4444; font-weight: normal; font-size: 13px;">(至少一人)</span></h5>
                                            <table class="fire-staff-table" id="table_staff_emergency">
                                                <thead><tr><th>姓名</th><th style="width: 70px; text-align: center;">操作</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_emergency[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn-add-staff" onclick="addFireStaffRow('table_staff_emergency', 'fire_staff_emergency[]')">＋ 新增人員</button>
                                        </div>

                                        <div class="fire-staff-wrapper">
                                            <h5 class="fire-staff-title">醫療人員 <span style="color: #ef4444; font-weight: normal; font-size: 13px;">(至少一人)</span></h5>
                                            <table class="fire-staff-table" id="table_staff_medical">
                                                <thead><tr><th>姓名</th><th style="width: 70px; text-align: center;">操作</th></tr></thead>
                                                <tbody>
                                                    <tr>
                                                        <td><input type="text" name="fire_staff_medical[]" class="form-control" placeholder="請輸入姓名"></td>
                                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                            <button type="button" class="btn-add-staff" onclick="addFireStaffRow('table_staff_medical', 'fire_staff_medical[]')">＋ 新增人員</button>
                                        </div>
                                        <!-- 👆 新增各種人員的表格結束 👆 -->
                                        
                                    </div>
                                </div>
                                <!-- 👆 明火申請表 HTML 結束 👆 -->
<div id="salesDetailsSection" style="display:none; margin-top:20px; background:#fff; border:1px solid #cbd5e1; border-radius:8px;">
    <div style="font-weight: bold; font-size: 16px; padding: 15px 20px; border-bottom: 1px solid #e2e8f0; color: #1e293b;">
        一般臨時攤位申請
    </div>
    <div style="padding: 20px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start; margin-bottom:15px;">
            <div class="form-group">
                <label style="font-weight: bold; color: #333;">申請單位 (自動帶入)</label>
                <input type="text" id="sales_organization_name" name="sales_organization_name" class="form-control" readonly style="background:#f8fafc;" value="<?php echo htmlspecialchars($formData['organization_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label style="font-weight: bold; color: #333;">日期 (自動帶入)</label>
                <div style="display:flex; gap:8px; align-items:center;">
                    <input type="date" id="sales_use_start" name="sales_use_start" class="form-control" readonly style="background:#f8fafc;" value="<?php echo htmlspecialchars($formData['borrow_start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    <span>至</span>
                    <input type="date" id="sales_use_end" name="sales_use_end" class="form-control" readonly style="background:#f8fafc;" value="<?php echo htmlspecialchars($formData['borrow_end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
        </div>
        
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 15px;">
            <div class="form-group" style="flex: 2; min-width: 250px;">
                <label style="font-weight: bold; color: #333;">攤位地點 <span style="color:red">*</span></label>
                <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 10px;">
                    <label style="display: inline-flex; align-items: center; justify-content: flex-start; gap: 8px; font-weight: normal; cursor: pointer; margin: 0; width: fit-content; text-align: left;">
                        <input type="radio" name="sales_location" value="風華再現廣場 - 單側" style="margin: 0;" <?php echo (($formData['sales_location'] ?? '') === '風華再現廣場 - 單側') ? 'checked' : ''; ?>> 
                        <span>風華再現廣場 - 單側</span>
                    </label>
                    <label style="display: inline-flex; align-items: center; justify-content: flex-start; gap: 8px; font-weight: normal; cursor: pointer; margin: 0; width: fit-content; text-align: left;">
                        <input type="radio" name="sales_location" value="風華再現廣場 - 雙側" style="margin: 0;" <?php echo (($formData['sales_location'] ?? '') === '風華再現廣場 - 雙側') ? 'checked' : ''; ?>> 
                        <span>風華再現廣場 - 雙側</span>
                    </label>
                    <label style="display: inline-flex; align-items: center; justify-content: flex-start; gap: 8px; font-weight: normal; cursor: pointer; margin: 0; width: fit-content; text-align: left;">
                        <input type="radio" name="sales_location" value="真善美聖廣場" style="margin: 0;" <?php echo (($formData['sales_location'] ?? '') === '真善美聖廣場') ? 'checked' : ''; ?>> 
                        <span>真善美聖廣場</span>
                    </label>
                </div>
            </div>
            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label for="sales_count" style="font-weight: bold; color: #333;">攤位數量 (至多20) <span style="color:red">*</span></label>
                <input type="number" id="sales_count" name="sales_count" class="form-control" placeholder="請輸入數量" max="20" min="1" value="<?php echo htmlspecialchars($formData['sales_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" oninput="if(this.value>20)this.value=20;if(this.value<0)this.value=1;">
            </div>
        </div>

        <hr style="margin: 25px 0; border: 0; border-top: 1px solid #e2e8f0;">
        
        <div class="form-group" style="margin-bottom: 25px;">
            <h4 style="margin: 0 0 10px 0; color: #1e40af; font-size: 16px; font-weight: bold;">上傳攤位圖冊</h4>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 10px;">請上傳您的攤位配置圖 (接受 JPG, PNG 格式)。</p>
            <input type="file" id="sales_layout_map" name="sales_layout_map" class="form-control" accept="image/png, image/jpeg, image/jpg" style="padding: 6px;">
        </div>

        <div class="sales-roster-wrapper" style="margin-bottom: 15px; padding: 20px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
            <h4 style="margin: 0 0 10px 0; color: #1e40af; font-size: 16px; font-weight: bold;">攤位清冊</h4>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 15px;">請列出各攤位名冊。可點擊「＋新增攤位」增加列數。</p>
            
            <style>
                #table_sales_roster { width: 100%; background: #fff; border-collapse: collapse; text-align: left; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden; }
                #table_sales_roster th { background-color: #f1f5f9; color: #1e293b; padding: 12px 15px; font-weight: bold; border: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
                #table_sales_roster td { padding: 12px 15px; border: 1px solid #e2e8f0; vertical-align: middle; }
                #table_sales_roster .form-control { width: 100%; box-sizing: border-box; padding: 10px 12px; font-size: 14px; border-radius: 6px; border: 1px solid #cbd5e1; background-color: #fff; transition: border-color 0.2s, box-shadow 0.2s; margin: 0; }
                #table_sales_roster .form-control:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); outline: none; }
            </style>
            
            <table id="table_sales_roster">
                <thead>
                    <tr>
                        <th style="width: 15%;">攤位編號</th>
                        <th style="width: 25%;">攤位名稱</th>
                        <th style="width: 15%;">現場負責人</th>
                        <th style="width: 20%;">聯絡電話</th>
                        <th style="width: 25%;">內容</th>
                        <th style="width: 80px; text-align: center;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="sales_booth_no[]" class="form-control" placeholder="數字"></td>
                        <td><input type="text" name="sales_booth_name[]" class="form-control" placeholder="名稱"></td>
                        <td><input type="text" name="sales_booth_manager[]" class="form-control" placeholder="姓名"></td>
                        <td><input type="text" name="sales_booth_phone[]" class="form-control" placeholder="電話"></td>
                        <td><input type="text" name="sales_booth_content[]" class="form-control" placeholder="販售內容..."></td>
                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeSalesRow(this)" style="padding: 6px 12px;">刪除</button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" class="btn-add-staff" onclick="addSalesRow()" style="margin-top: 15px; padding: 8px 16px; font-size: 14px;">＋ 新增攤位</button>
        </div>

    </div>
</div>
                                <script>
                                function addFireStaffRow(tableId, inputName) {
                                    const tbody = document.getElementById(tableId).querySelector('tbody');
                                    const tr = document.createElement('tr');
                                    tr.innerHTML = `
                                        <td><input type="text" name="${inputName}" class="form-control" placeholder="請輸入姓名"></td>
                                        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeFireStaffRow(this)">刪除</button></td>
                                    `;
                                    tbody.appendChild(tr);
                                }
                                function removeFireStaffRow(btn) {
                                    const tr = btn.closest('tr');
                                    const tbody = tr.parentNode;
                                    // 防呆：如果刪到剩最後一筆，只清空內容不刪除整列
                                    if (tbody.querySelectorAll('tr').length <= 1) {
                                        tr.querySelector('input').value = '';
                                        return;
                                    }
                                    tr.remove();
                                }
                                


                                </script>
<script>
// 新增攤位清冊的列
function addSalesRow() {
    const tbody = document.getElementById('table_sales_roster').querySelector('tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" name="sales_booth_no[]" class="form-control" placeholder="例: A1"></td>
        <td><input type="text" name="sales_booth_name[]" class="form-control" placeholder="請輸入名稱"></td>
        <td><input type="text" name="sales_booth_manager[]" class="form-control" placeholder="姓名"></td>
        <td><input type="text" name="sales_booth_phone[]" class="form-control" placeholder="電話"></td>
        <td><input type="text" name="sales_booth_content[]" class="form-control" placeholder="販售內容..."></td>
        <td style="text-align: center;"><button type="button" class="btn-del-staff" onclick="removeSalesRow(this)" style="padding: 6px 12px;">刪除</button></td>
    `;
    tbody.appendChild(tr);
}

// 移除攤位清冊的列
function removeSalesRow(btn) {
    const tr = btn.closest('tr');
    const tbody = tr.parentNode;
    // 防呆：如果刪到剩最後一筆，只清空內容不刪除整列
    if (tbody.querySelectorAll('tr').length <= 1) {
        tr.querySelectorAll('input').forEach(input => input.value = '');
        return;
    }
    tr.remove();
}
</script>
                                <script>
                                // 👇 這裡幫你把遺失的【酒精驗證 Javascript】補回來了 👇
                                function toggleAllAlcoholAgreements(source) {
                                    const checkboxes = document.querySelectorAll('input[name^="alcohol_agree_"]');
                                    checkboxes.forEach(function(cb) {
                                        if(cb !== source) {
                                            cb.checked = source.checked;
                                        }
                                    });
                                }

                                function isAlcoholEnabled() {
// 改為直接抓取 checkbox 的 checked 屬性
    const checkbox = document.querySelector('input[name="has_alcohol"]');
    return checkbox ? checkbox.checked : false;
                                }

                                function toggleAlcoholDetails() {
                                    const alcSection = document.getElementById('alcoholDetailsSection');
                                    if (!alcSection) return;
                                    const show = isAlcoholEnabled();
                                    alcSection.style.display = show ? 'block' : 'none';
                                    
                                    alcSection.querySelectorAll('input').forEach(function(el) {
                                        if (show) {
                                            el.removeAttribute('disabled');
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });
                                }

function validateAlcoholForm() {
    if (!isAlcoholEnabled()) return true;
    
    const checkboxes = document.querySelectorAll('#alcoholDetailsSection input[type="checkbox"]');
    let allChecked = true;
    checkboxes.forEach(function(cb) {
        if (!cb.checked) allChecked = false;
    });
    
    // 👇 已經刪除 coordinator 和 president 的變數宣告
    
    if (!allChecked) {
        alert('請先勾選並確認遵守「酒精飲品活動須知」的所有規範事項。');
        return false;
    }
    
    // 👇 已經刪除底下這兩個 if 判斷式
    // if (!coordinator) { ... }
    // if (!president) { ... }
    
    return true;
}
                                // 👆 酒精驗證 Javascript 結束 👆

                                // 👇 明火驗證 Javascript 開始 👇
                                function isFireEnabled() {
const checkbox = document.querySelector('input[name="has_fire"]');
    return checkbox ? checkbox.checked : false;
                                }

                                function toggleFireDetails() {
                                    const fireSection = document.getElementById('fireDetailsSection');
                                    if (!fireSection) return;
                                    const show = isFireEnabled();
                                    fireSection.style.display = show ? 'block' : 'none';
                                    
                                    fireSection.querySelectorAll('input').forEach(function(el) {
                                        if (show) {
                                            el.removeAttribute('disabled');
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });
                                }

function validateFireForm() {
                                    if (!isFireEnabled()) return true;
                                    // 之後如果有明火表單內容的必填欄位，可在此撰寫驗證邏輯
                                    return true;
                                }
                                // 👆 明火驗證 Javascript 結束 👆

                                // 👇 攤位驗證 Javascript 開始 👇
                                function isSalesEnabled() {
                                    const checkbox = document.querySelector('input[name="has_sales"]');
                                    return checkbox ? checkbox.checked : false;
                                }

                                function toggleSalesDetails() {
                                    const salesSection = document.getElementById('salesDetailsSection');
                                    if (!salesSection) return;
                                    const show = isSalesEnabled();
                                    salesSection.style.display = show ? 'block' : 'none';
                                    
                                    salesSection.querySelectorAll('input, select').forEach(function(el) {
                                        if (show) {
                                            el.removeAttribute('disabled');
                                            if (el.id === 'sales_organization_name' || el.id === 'sales_use_start' || el.id === 'sales_use_end') {
                                                el.setAttribute('readonly', 'readonly'); // 保證這三格永遠鎖死
                                            }
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });
                                    if (show) syncSalesForm();
                                }

                                function syncSalesForm() {
                                    if (!isSalesEnabled()) return;
                                    const orgSrc = document.getElementById('organization_name');
                                    const orgDst = document.getElementById('sales_organization_name');
                                    if (orgSrc && orgDst) orgDst.value = orgSrc.value || '';

                                    const startSrc = document.getElementById('borrow_start_date');
                                    const startDst = document.getElementById('sales_use_start');
                                    if (startSrc && startDst) startDst.value = startSrc.value || '';

                                    const endSrc = document.getElementById('borrow_end_date');
                                    const endDst = document.getElementById('sales_use_end');
                                    if (endSrc && endDst) endDst.value = endSrc.value || '';
                                }

                                function validateSalesForm() {
                                    if (!isSalesEnabled()) return true;
                                    const locationChecked = document.querySelector('input[name="sales_location"]:checked');
                                    if (!locationChecked) {
                                        alert('請選擇攤位地點。');
                                        return false;
                                    }
                                    const count = document.getElementById('sales_count');
                                    if (!count || !count.value || parseInt(count.value) < 1 || parseInt(count.value) > 20) {
                                        alert('請輸入有效的攤位數量 (1-20)。');
                                        return false;
                                    }
                                    return true;
                                }
                                // 👆 攤位驗證 Javascript 結束 👆

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

                                    detailsSection.querySelectorAll('input, select, textarea').forEach(function (el) {
                                        if (show) {
                                            el.removeAttribute('disabled');
                                        } else {
                                            el.setAttribute('disabled', 'disabled');
                                        }
                                    });

                                    if (show) {
                                        const flagCount = document.getElementById('flag_count');
                                        if (flagCount && flagCount.value === '') {
                                            flagCount.value = '1';
                                        }
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

                                    const bs = document.getElementById('borrow_start_date');
                                    const be = document.getElementById('borrow_end_date');
                                    const fus = document.getElementById('flag_use_start');
                                    const fue = document.getElementById('flag_use_end');
                                    
                                    const mapping = [
                                        ['organization_name', 'flag_organization_name'],
                                        ['activity_name', 'flag_activity_name'],
                                        ['activity_coordinator', 'flag_responsible_person'],
                                        ['coordinator_phone', 'flag_contact_phone']
                                    ];
                                    mapping.forEach(function(pair){
                                        const s = document.getElementById(pair[0]);
                                        const d = document.getElementById(pair[1]);
                                        if (s && d && (d.value === '' || d.value === null)) {
                                            d.value = s.value || '';
                                        }
                                    });
                                    if (fus && fue && bs && be) {
                                        fus.value = bs.value || '';
                                        fue.value = be.value || '';
                                        try {
                                            const min = getMinFlagDate();
                                            fus.setAttribute('min', min);
                                            fue.setAttribute('min', min);
                                        } catch (e) {}

                                        if (bs.value) {
                                            const minDate = new Date(getMinFlagDate());
                                            const startDate = new Date(bs.value);
                                            if (startDate < minDate) {
                                                alert('插立旗幟使用日期必須為 7 個工作天之後，請將活動開始日期調整至 ' + getMinFlagDate() + '（或更晚）。');
                                                bs.focus();
                                            }
                                        }
                                    }
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

                                    // 假日費用顯示同步
                                    function countHolidayDaysJS(startStr, endStr) {
                                        if (!startStr || !endStr) return 0;
                                        const s = new Date(startStr);
                                        const e = new Date(endStr);
                                        if (isNaN(s.getTime()) || isNaN(e.getTime()) || s > e) return 0;
                                        // holidays from server (YYYY-MM-DD strings)
                                        const holidays = (window.__PAGE_HOLIDAYS__ || []);
                                        let cnt = 0;
                                        for (let d = new Date(s.getFullYear(), s.getMonth(), s.getDate()); d <= e; d.setDate(d.getDate()+1)) {
                                            const y = d.getFullYear();
                                            const m = String(d.getMonth()+1).padStart(2,'0');
                                            const day = String(d.getDate()).padStart(2,'0');
                                            const key = `${y}-${m}-${day}`;
                                            if (holidays.length > 0) {
                                                if (holidays.indexOf(key) !== -1) cnt++;
                                            } else {
                                                // fallback: treat weekends as holidays
                                                const wd = d.getDay();
                                                if (wd === 0 || wd === 6) cnt++;
                                            }
                                        }
                                        return cnt;
                                    }

                                    function updateHolidayFeeDisplay() {
                                        const start = document.getElementById('borrow_start_date')?.value || '';
                                        const end = document.getElementById('borrow_end_date')?.value || '';
                                        const cnt = countHolidayDaysJS(start, end);
                                        const fee = cnt * 200;
                                        const disp = document.getElementById('holiday-fee-display');
                                        if (disp) disp.textContent = `例假日收場地費 200 元/次。已選 ${cnt} 天，費用：${fee} 元`;
                                        const hfCnt = document.getElementById('holiday_fee_count');
                                        const hf = document.getElementById('holiday_fee');
                                        if (hfCnt) hfCnt.value = cnt;
                                        if (hf) hf.value = fee;
                                    }

                                    // expose server holidays to JS
                                    try {
                                        window.__PAGE_HOLIDAYS__ = <?php echo json_encode($pageHolidayDates, JSON_HEX_TAG); ?> || [];
                                    } catch(e) { window.__PAGE_HOLIDAYS__ = []; }

                                    document.getElementById('borrow_start_date')?.addEventListener('change', updateHolidayFeeDisplay);
                                    document.getElementById('borrow_end_date')?.addEventListener('change', updateHolidayFeeDisplay);
                                    // 首次載入顯示
                                    updateHolidayFeeDisplay();

['borrow_start_date', 'borrow_end_date', 'organization_name', 'activity_name', 'coordinator_phone', 'activity_coordinator', 'participant_count'].forEach(function (id) {
                                        const el = document.getElementById(id);
                                        if (el) {
                                            if (id === 'borrow_start_date' || id === 'participant_count') {
                                                el.addEventListener('change', function() {
                                                    validateStartDate();
                                                    syncFlagForm();
                                                    if(typeof syncSalesForm === 'function') syncSalesForm();
                                                });
                                            } else {
                                                el.addEventListener('change', function() {
                                                    syncFlagForm();
                                                    if(typeof syncSalesForm === 'function') syncSalesForm();
                                                });
                                            }
                                            el.addEventListener('input', function() {
                                                syncFlagForm();
                                                if(typeof syncSalesForm === 'function') syncSalesForm();
                                            });
                                        }
                                    });

                                    // 👇 綁定切換按鈕，勾選酒精時會跳出申請表 👇
                                    ['has_alcohol', 'has_fire', 'has_sales'].forEach(function(name) {
                                        const el = document.querySelector('input[name="' + name + '"]');
                                        if (el) {
                                            el.addEventListener('change', function() {
                                                if (name === 'has_alcohol' && typeof toggleAlcoholDetails === 'function') {
                                                    toggleAlcoholDetails();
                                                }
                                                if (name === 'has_fire' && typeof toggleFireDetails === 'function') {
                                                    toggleFireDetails();
                                                }
                                                if (name === 'has_sales' && typeof toggleSalesDetails === 'function') {
                                                    toggleSalesDetails();
                                                }
                                            });
                                        }
                                    });
                                    
                                    // 確保重整網頁時如果有勾選，也能正確顯示
                                    if (typeof toggleAlcoholDetails === 'function') {
                                        toggleAlcoholDetails();
                                    }
                                    if (typeof toggleFireDetails === 'function') {
                                        toggleFireDetails();
                                    }
                                    if (typeof toggleSalesDetails === 'function') {
                                        toggleSalesDetails();
                                    }
                                    // 👆 綁定結束 👆

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
                                            dst.value = src.value || dst.value || '';
                                            src.addEventListener('input', function(){ dst.value = src.value; });
                                            src.addEventListener('change', function(){ dst.value = src.value; });
                                        });
                                    })();

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

                                    const draftBtns = document.querySelectorAll('.openDraftBoxBtn');
                                    draftBtns.forEach(function (btn) {
                                        btn.addEventListener('click', function () {
                                            window.location.href = 'drafts.php';
                                        });
                                    });
                                });
                                </script>

                                    <div class="step-actions">
                                        <button type="button" class="btn btn-secondary" onclick="goToStep(1)"> ⬅ 回上一步</button>
                                        <button type="button" class="btn btn-primary btn-next" onclick="if(validateAlcoholForm() && validateFireForm() && validateSalesForm()) { goToStep(3); }">下一步 ➔ 挑選器材與場地</button>
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
    <button type="submit" id="borrowSubmitBtn" class="btn btn-primary btn-next">✅ 送出申請</button>
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
// 新增這個函數，確保頁面載入時檢查 radio/checkbox 狀態
function initializeFormVisibility() {
    // 延遲一下確保 DOM 穩定，或是確保在數據綁定後執行
    setTimeout(() => {
        if (typeof toggleAlcoholDetails === 'function') toggleAlcoholDetails();
        if (typeof toggleFireDetails === 'function') toggleFireDetails();
        if (typeof toggleFlagDetails === 'function') toggleFlagDetails();
    }, 300); // 給 JS 載入資料一點時間
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

        async function loadDraftFromDatabase() {
            const params = new URLSearchParams(window.location.search);
            const draftId = params.get('draft_id');

            if (!draftId) return;

            try {
                const res = await fetch(
                    'api/load_draft.php?draft_id=' + encodeURIComponent(draftId)
                );

                const data = await res.json();

                if (!data.success) {
                    alert(data.message || '草稿載入失敗');
                    return;
                }

const draft = data.draft || {};
                const formData = draft.formData || {};

                Object.keys(formData).forEach(function (name) {
                    let values = formData[name];
                    let targetName = name;
                    let els = document.querySelectorAll(`[name="${targetName}"]`);

                    // 1. 如果找不到元素，有可能是因為 PHP 把陣列的 '[]' 拿掉了，我們幫它加回來找找看
                    if (els.length === 0) {
                        targetName = name + '[]';
                        els = document.querySelectorAll(`[name="${targetName}"]`);
                    }

                    // 2. 如果這個欄位存的是陣列 (例如明火表單的人員名單)
                    if (Array.isArray(values)) {
                        let tableId = '';
                        if (targetName === 'fire_staff_performer[]') tableId = 'table_staff_performer';
                        else if (targetName === 'fire_staff_oiler[]') tableId = 'table_staff_oiler';
                        else if (targetName === 'fire_staff_extinguisher[]') tableId = 'table_staff_extinguisher';
                        else if (targetName === 'fire_staff_security[]') tableId = 'table_staff_security';
                        else if (targetName === 'fire_staff_emergency[]') tableId = 'table_staff_emergency';
                        else if (targetName === 'fire_staff_medical[]') tableId = 'table_staff_medical';

                        if (tableId) {
                            const tbody = document.getElementById(tableId).querySelector('tbody');
                            if (tbody) {
                                // 動態把缺少的列數補齊 (確保輸入框數量等於陣列長度)
                                while (tbody.querySelectorAll('tr').length < values.length) {
                                    addFireStaffRow(tableId, targetName);
                                }
                                // 重新抓取所有輸入框，依序把值填進去
                                const inputs = document.querySelectorAll(`[name="${targetName}"]`);
                                values.forEach((val, idx) => {
                                    if (inputs[idx]) inputs[idx].value = val;
                                });
                            }
                        } else {
                            // 預留給其他一般的多選 checkbox
                            els.forEach(el => {
                                if (el.type === 'checkbox' || el.type === 'radio') {
                                    el.checked = values.includes(el.value);
                                }
                            });
                        }
                    } else {
                        // 3. 一般的單一欄位處理
                        els.forEach(function (el) {
                            if (el.type === 'checkbox') {
                                el.checked = values === '1' || values === 1 || values === true || String(values).toLowerCase() === 'yes';
                            } else if (el.type === 'radio') {
                                el.checked = el.value === String(values);
                            } else if (el.type !== 'file') {
                                el.value = values;
                            }
                        });
                    }
                });

                const currentDraftIdInput = document.getElementById('current_draft_id');
                if (currentDraftIdInput) {
                    currentDraftIdInput.value = draft.draft_id || draft.draftId || draftId;
                }

                const fileDisplay = document.getElementById('proposal_file_name_display');
                const draftProposalFileInput = document.getElementById('draft_proposal_file');
                const draftProposalOriginalNameInput = document.getElementById('draft_proposal_original_name');
                const draftProposalUploadedAtInput = document.getElementById('draft_proposal_uploaded_at');

                if (draft.proposal_file && draftProposalFileInput) {
                    draftProposalFileInput.value = draft.proposal_file;
                }
                if (draft.proposal_original_name && draftProposalOriginalNameInput) {
                    draftProposalOriginalNameInput.value = draft.proposal_original_name;
                }
                if (draft.proposal_uploaded_at && draftProposalUploadedAtInput) {
                    draftProposalUploadedAtInput.value = draft.proposal_uploaded_at;
                }
                if (fileDisplay && draft.proposal_original_name) {
                    fileDisplay.innerText = '已上傳企劃書：' + draft.proposal_original_name;
                }

                if (window.borrowCartDraftBridge) {
                    const bridgeDraft = {
                        formData: formData,
                        cart_items: formData.cart_items || formData.cartItems || []
                    };

                    window.borrowCartDraftBridge.restoreRightPanel(bridgeDraft);

                    setTimeout(function () {
                        window.borrowCartDraftBridge.restoreRightPanel(bridgeDraft);
                    }, 200);
                }

                if (typeof toggleFlagDetails === 'function') {
                    toggleFlagDetails();
                }

                if (typeof syncFlagForm === 'function') {
                    syncFlagForm();
                }

                const step = draft.current_step || draft.currentStep || formData.current_step || '1';

                const currentStepInput = document.getElementById('current_step');
                if (currentStepInput) {
                    currentStepInput.value = step;
                }

                if (typeof showStep === 'function') {
                    showStep(step);
                } else if (typeof goToStep === 'function') {
                    goToStep(Number(step));
                }
                // --- 在這裡加入我們說的強制觸發區塊 ---
    
// 強制 UI 更新，確保載入後的顯示正確
    setTimeout(() => {
        console.log('執行強制 UI 更新檢查...');
        if (typeof toggleAlcoholDetails === 'function') toggleAlcoholDetails();
        if (typeof toggleFireDetails === 'function') toggleFireDetails();
        if (typeof toggleFlagDetails === 'function') toggleFlagDetails();
        if (typeof toggleSalesDetails === 'function') toggleSalesDetails();
    }, 500);
            } catch (error) {
                console.error(error);
                alert('草稿載入失敗：' + error.message);
            }
        }

        function initializeDraftManagement() {
            const saveDraftBtn = document.getElementById('saveDraftBtn');
            const openDraftBoxBtn = document.getElementById('openDraftBoxBtn');
            const msg = document.getElementById('submitDebugMsg');

            async function saveDraft(e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                try {
                    if (window.borrowCartDraftBridge) {
                        window.borrowCartDraftBridge.syncHiddenBeforeSave();
                    }

                    const form = document.getElementById('multistep_form');
                    if (!form) {
                        alert('找不到表單，無法暫存。');
                        return;
                    }

                    const fd = new FormData(form);

                    const currentDraftId =
                        document.getElementById('current_draft_id')?.value || '';

                    const currentStep =
                        document.getElementById('current_step')?.value || '1';

                    fd.set('draft_id', currentDraftId);
                    fd.set('currentStep', currentStep);

                    // 如果已有草稿ID，詢問用戶是否覆蓋或新建
                    let saveAction = 'update'; // 默認更新
                    if (currentDraftId && currentDraftId !== '') {
                        // 显示模态窗口并等待用户选择
                        const choice = await new Promise((resolve) => {
                            const modal = document.getElementById('draftChoiceModal');
                            const updateBtn = document.getElementById('draftBtnUpdate');
                            const newBtn = document.getElementById('draftBtnNew');
                            const draftIdDisplay = document.getElementById('draftIdDisplay');

                            if (!modal || !updateBtn || !newBtn) {
                                resolve('update'); // 回退到更新
                                return;
                            }

                            draftIdDisplay.textContent = currentDraftId;
                            modal.classList.add('show');

                            const handleUpdate = () => {
                                modal.classList.remove('show');
                                updateBtn.removeEventListener('click', handleUpdate);
                                newBtn.removeEventListener('click', handleNew);
                                resolve('update');
                            };

                            const handleNew = () => {
                                modal.classList.remove('show');
                                updateBtn.removeEventListener('click', handleUpdate);
                                newBtn.removeEventListener('click', handleNew);
                                resolve('new');
                            };

                            updateBtn.addEventListener('click', handleUpdate);
                            newBtn.addEventListener('click', handleNew);
                        });

                        saveAction = choice;
                        if (choice === 'new') {
                            // 如果选择新建，清除draft_id來創建新草稿
                            fd.set('draft_id', '');
                            fd.set('action', 'new');
                        } else {
                            fd.set('action', 'update');
                        }
                    }

                    const res = await fetch('api/save_draft.php', {
                        method: 'POST',
                        body: fd
                    });

                    const data = await res.json();

                    if (data.success) {
                        const draftId = data.draft_id || data.draftId || data.reservation_id || '';
                        const currentDraftInput = document.getElementById('current_draft_id');
                        if (currentDraftInput) currentDraftInput.value = draftId;

                        const actionText = saveAction === 'new' ? '新增' : '更新';
                        const successMsg = '✅ 草稿已' + actionText + '，草稿編號：' + draftId;
                        if (msg) {
                            msg.textContent = successMsg;
                        }

                        alert('草稿已' + actionText + '成功！\n\n草稿編號：' + draftId);
                    } else {
                        if (msg) {
                            msg.textContent = '❌ ' + (data.message || '草稿暫存失敗');
                        }
                        alert(data.message || '草稿暫存失敗');
                    }
                } catch (error) {
                    console.error(error);
                    if (msg) {
                        msg.textContent = '❌ 暫存失敗：' + error.message;
                    }
                    alert('暫存失敗：' + error.message);
                }
            }

            if (saveDraftBtn) {
                saveDraftBtn.addEventListener('click', saveDraft);
            }

            document.querySelectorAll('.saveDraftBtn').forEach(function (btn) {
                btn.addEventListener('click', saveDraft);
            });

            if (openDraftBoxBtn) {
                openDraftBoxBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.href = 'drafts.php';
                });
            }

            document.querySelectorAll('.openDraftBoxBtn').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.href = 'drafts.php';
                });
            });

            loadDraftFromDatabase();
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
        // 一般情況：7 個工作天後
        return getWorkingDaysFromToday(7);
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

    // 在導航到第2、3步時（從第1步）檢查企劃書
    if (stepNo > 1 && currentStep === 1) {
        // 檢查是否有新上傳的文件 OR 草稿中已有的企劃書
        const proposalFile = document.getElementById('proposal_file');
        const draftProposalFile = document.getElementById('draft_proposal_file');
        const draftProposalName = document.getElementById('draft_proposal_original_name');
        
        const hasNewFile = proposalFile && proposalFile.value;
        const hasDraftFile = (draftProposalFile && draftProposalFile.value) || 
                            (draftProposalName && draftProposalName.value);
        
        if (!hasNewFile && !hasDraftFile) {
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
if (typeof window.toggleAlcoholDetails === 'function') {
            window.toggleAlcoholDetails();
        }
if (typeof window.toggleFireDetails === 'function') {
            window.toggleFireDetails();
        }
        if (typeof window.toggleSalesDetails === 'function') {
            window.toggleSalesDetails();
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




<script>
/**
 * 活動日期與特殊條件驗證最終版
 *
 * 規則：
 * 1. 開始日期、結束日期都不可早於今天
 * 2. 結束時間必須晚於開始時間
 * 3. 活動天數 diffDays > 4 才擋下
 * 4. 活動對象人數 100 人以上、工作人員人數 100 人以上、酒精、明火、販售活動，都必須提前 30 天
 * 5. 不管先選日期、先勾特殊項目、後改人數、或送出前，都會重新檢查
 */
(function () {
    function getDateOnly(value) {
        if (!value) return null;

        const date = new Date(value + 'T00:00:00');

        if (isNaN(date.getTime())) return null;

        date.setHours(0, 0, 0, 0);

        return date;
    }

    function getTimePart(name) {
        const el = document.querySelector('[name="' + name + '"]');

        if (!el || el.value === '') return null;

        return String(el.value).padStart(2, '0');
    }

    function getDateTime(dateValue, hourName, minuteName) {
        const h = getTimePart(hourName);
        const m = getTimePart(minuteName);

        if (!dateValue || h === null || m === null) return null;

        const date = new Date(dateValue + 'T' + h + ':' + m + ':00');

        return isNaN(date.getTime()) ? null : date;
    }

    function formatDate(date) {
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');

        return y + '-' + m + '-' + d;
    }

    function isCheckedByName(name) {
        const el = document.querySelector('input[name="' + name + '"]');

        return !!(el && el.checked);
    }

    function getParticipantCountValue() {
        const el =
            document.getElementById('participant_count') ||
            document.querySelector('[name="participant_count"]');

        return el ? (el.value || '') : '';
    }

    function getStaffCountValue() {
        const el =
            document.getElementById('staff_count') ||
            document.querySelector('[name="staff_count"]');

        const value = el ? parseInt(el.value || '0', 10) : 0;

        return isNaN(value) ? 0 : value;
    }

    window.is30DaysRequired = function () {
        const participantCount = getParticipantCountValue();
        const staffCount = getStaffCountValue();

        return (
            isCheckedByName('has_alcohol') ||
            isCheckedByName('has_fire') ||
            isCheckedByName('has_sales') ||
            participantCount === '100~200人' ||
            participantCount === '200人以上' ||
            staffCount >= 100
        );
    };

    window.validateActivityDateRange = function (shouldClearInvalidDates = true) {
        const startDateInput = document.getElementById('borrow_start_date');
        const endDateInput = document.getElementById('borrow_end_date');

        if (!startDateInput || !endDateInput) return true;

        const startDateValue = startDateInput.value;
        const endDateValue = endDateInput.value;

        const startDate = getDateOnly(startDateValue);
        const endDate = getDateOnly(endDateValue);

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        if (startDate && startDate < today) {
            alert('活動開始日期不可早於今天，請重新選擇！');

            if (shouldClearInvalidDates) {
                startDateInput.value = '';
                endDateInput.value = '';
            }

            return false;
        }

        if (endDate && endDate < today) {
            alert('活動結束日期不可早於今天，請重新選擇！');

            if (shouldClearInvalidDates) {
                endDateInput.value = '';
            }

            return false;
        }

        if (!startDate || !endDate) return true;

        if (startDate > endDate) {
            alert('活動開始日期不能晚於活動結束日期！');

            if (shouldClearInvalidDates) {
                endDateInput.value = '';
            }

            return false;
        }

        const startDateTime = getDateTime(
            startDateValue,
            'borrow_start_time_h',
            'borrow_start_time_m'
        );

        const endDateTime = getDateTime(
            endDateValue,
            'borrow_end_time_h',
            'borrow_end_time_m'
        );

        if (startDateTime && endDateTime && endDateTime <= startDateTime) {
            alert('活動結束時間必須晚於活動開始時間！');

            if (shouldClearInvalidDates) {
                const endHour = document.querySelector('[name="borrow_end_time_h"]');
                const endMin = document.querySelector('[name="borrow_end_time_m"]');

                if (endHour) endHour.value = '';
                if (endMin) endMin.value = '';
            }

            return false;
        }

        const diffDays = Math.ceil(
            (endDate.getTime() - startDate.getTime()) / (1000 * 60 * 60 * 24)
        );

        if (diffDays > 4) {
            alert('活動天數最多不可超過 4 天，請重新選擇！');

            if (shouldClearInvalidDates) {
                endDateInput.value = '';
            }

            return false;
        }

        if (window.is30DaysRequired()) {
            const minAllowedDate = new Date(today);

            minAllowedDate.setDate(minAllowedDate.getDate() + 30);

            if (startDate < minAllowedDate || endDate < minAllowedDate) {
                alert(
                    '注意：由於您的活動包含特殊性質（酒精、明火、攤販、活動對象100人以上或工作人員100人以上），必須在 30 天之前申請！\n' +
                    '系統已清空不合規的日期，請重新選擇至少為 ' + formatDate(minAllowedDate) + ' 的日期。'
                );

                if (shouldClearInvalidDates) {
                    startDateInput.value = '';
                    endDateInput.value = '';
                }

                return false;
            }
        }

        return true;
    };

    window.validateStartDate = function () {
        return window.validateActivityDateRange(true);
    };

    function bindDateRuleValidation() {
        const dateAndTimeSelectors = [
            '#borrow_start_date',
            '#borrow_end_date',
            '[name="borrow_start_time_h"]',
            '[name="borrow_start_time_m"]',
            '[name="borrow_end_time_h"]',
            '[name="borrow_end_time_m"]'
        ];

        document.querySelectorAll(dateAndTimeSelectors.join(',')).forEach(function (el) {
            el.addEventListener('change', function () {
                window.validateActivityDateRange(true);
            });

            el.addEventListener('blur', function () {
                window.validateActivityDateRange(true);
            });
        });

        document.querySelectorAll('#participant_count, [name="participant_count"], #staff_count, [name="staff_count"]').forEach(function (el) {
            el.addEventListener('change', function () {
                window.validateActivityDateRange(true);
            });

            el.addEventListener('input', function () {
                window.validateActivityDateRange(true);
            });
        });

['has_alcohol', 'has_fire', 'has_sales'].forEach(function (key) {
            document.querySelectorAll('#' + key + ', input[name="' + key + '"]').forEach(function (el) {
                el.addEventListener('change', function () {
                    window.validateActivityDateRange(true);
                    // 補回觸發酒精表單的靈魂！
                    if (key === 'has_alcohol' && typeof toggleAlcoholDetails === 'function') {
                        toggleAlcoholDetails();
                    }
                    if (key === 'has_fire' && typeof toggleFireDetails === 'function') {
                        toggleFireDetails();
                    }
                });

                el.addEventListener('click', function () {
                    setTimeout(function () {
                        window.validateActivityDateRange(true);
                    }, 0);
                });
            });
        });

        // 確保網頁重整、載入草稿或切換上一步時，如果酒精已勾選，能正確把表單開起來
        if (typeof toggleAlcoholDetails === 'function') {
            toggleAlcoholDetails();
        }
        if (typeof toggleFireDetails === 'function') {
            toggleFireDetails();
        }

        const originalGoToStep = window.goToStep;

        window.goToStep = function (stepNo) {
            const currentStepInput = document.getElementById('current_step');
            const currentStep = parseInt(currentStepInput ? currentStepInput.value : '1', 10);

            if (stepNo > 1 && currentStep === 1) {
                if (!window.validateActivityDateRange(true)) {
                    return;
                }
            }

            if (typeof originalGoToStep === 'function') {
                return originalGoToStep(stepNo);
            }
        };

        const form = document.getElementById('multistep_form');

        if (form) {
            form.addEventListener('submit', function (e) {
                if (!window.validateActivityDateRange(true)) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }, true);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindDateRuleValidation);
    } else {
        bindDateRuleValidation();
    }
})();
</script>


<script>
(function () {
    function hasProposalForSubmit() {
        const proposalInput = document.getElementById('proposal_file');
        const draftProposalInput = document.getElementById('draft_proposal_file');
        const hasNewFile = proposalInput && proposalInput.files && proposalInput.files.length > 0;
        const hasDraftFile = draftProposalInput && draftProposalInput.value && draftProposalInput.value.trim() !== '';
        return hasNewFile || hasDraftFile;
    }

    window.hasProposalForSubmit = hasProposalForSubmit;

    function bindProposalSubmitCheck() {
        const form = document.getElementById('multistep_form');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            if (!hasProposalForSubmit()) {
                e.preventDefault();
                e.stopPropagation();
                alert('請先上傳活動企劃書！');
                return false;
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindProposalSubmitCheck);
    } else {
        bindProposalSubmitCheck();
    }
})();
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {

    if (typeof loadDraftFromDatabase === 'function') {

        const originalLoadDraft = loadDraftFromDatabase;

        loadDraftFromDatabase = async function (...args) {

            const result = await originalLoadDraft.apply(this, args);

            try {
                if (window.currentDraftData) {

                    const draft = window.currentDraftData;

                    const hiddenInput = document.getElementById('draft_proposal_file');
                    const originalNameInput = document.getElementById('draft_proposal_original_name');
                    const uploadedAtInput = document.getElementById('draft_proposal_uploaded_at');

                    if (hiddenInput) {
                        hiddenInput.value = draft.proposal_file || '';
                    }

                    if (originalNameInput) {
                        originalNameInput.value = draft.proposal_original_name || '';
                    }

                    if (uploadedAtInput) {
                        uploadedAtInput.value = draft.proposal_uploaded_at || '';
                    }
                }
            } catch (e) {
                console.log(e);
            }

            return result;
        };
    }
});
</script>


<script>
/**
 * 企劃書焊住機制
 * input[type=file] 不能被 JS 自動回填，所以必須用：
 * hidden input + sessionStorage + reservation_drafts.proposal_file
 * 來保留後端已存的 PDF 路徑。
 */
(function () {
    function isProposalDraftMode() {
        const params = new URLSearchParams(window.location.search);
        const urlDraftId = params.get('draft_id') || params.get('id') || '';
        const hiddenDraftId = document.getElementById('current_draft_id')?.value || '';

        return (
            (urlDraftId !== '' && urlDraftId !== '0') ||
            (hiddenDraftId !== '' && hiddenDraftId !== '0')
        );
    }

    function clearProposalStateForNewApplication() {
        sessionStorage.removeItem('draft_proposal_file');
        sessionStorage.removeItem('draft_proposal_original_name');
        sessionStorage.removeItem('draft_proposal_uploaded_at');

        const fileInput = document.getElementById('draft_proposal_file');
        const nameInput = document.getElementById('draft_proposal_original_name');
        const timeInput = document.getElementById('draft_proposal_uploaded_at');
        const display = document.getElementById('proposal_file_name_display');

        if (fileInput) fileInput.value = '';
        if (nameInput) nameInput.value = '';
        if (timeInput) timeInput.value = '';
        if (display) display.innerText = '';
    }

    function setProposalState(file, originalName, uploadedAt) {
        const fileInput = document.getElementById('draft_proposal_file');
        const nameInput = document.getElementById('draft_proposal_original_name');
        const timeInput = document.getElementById('draft_proposal_uploaded_at');
        const display = document.getElementById('proposal_file_name_display');

        if (fileInput && file) fileInput.value = file;
        if (nameInput && originalName) nameInput.value = originalName;
        if (timeInput && uploadedAt) timeInput.value = uploadedAt;

        if (file) sessionStorage.setItem('draft_proposal_file', file);
        if (originalName) sessionStorage.setItem('draft_proposal_original_name', originalName);
        if (uploadedAt) sessionStorage.setItem('draft_proposal_uploaded_at', uploadedAt);

        if (display && originalName) {
            display.innerText = '已上傳企劃書：' + originalName;
        }
    }

    function restoreProposalState() {
        if (!isProposalDraftMode()) {
            clearProposalStateForNewApplication();
            return;
        }

        const file = sessionStorage.getItem('draft_proposal_file') || '';
        const name = sessionStorage.getItem('draft_proposal_original_name') || '';
        const time = sessionStorage.getItem('draft_proposal_uploaded_at') || '';

        const fileInput = document.getElementById('draft_proposal_file');
        const nameInput = document.getElementById('draft_proposal_original_name');
        const timeInput = document.getElementById('draft_proposal_uploaded_at');
        const display = document.getElementById('proposal_file_name_display');

        if (fileInput && !fileInput.value && file) fileInput.value = file;
        if (nameInput && !nameInput.value && name) nameInput.value = name;
        if (timeInput && !timeInput.value && time) timeInput.value = time;

        if (display && name && !display.innerText.trim()) {
            display.innerText = '已上傳企劃書：' + name;
        }
    }

    function hasProposalForSubmit() {
        if (isDraftMode) {
            restoreProposalState();
        }
        const currentDraftId =
            document.getElementById('current_draft_id')?.value || '';

        const isDraftMode =
            currentDraftId !== '' &&
            currentDraftId !== '0';
        const proposalInput = document.getElementById('proposal_file');
        const draftProposalInput = document.getElementById('draft_proposal_file');

        const hasNewFile =
            proposalInput &&
            proposalInput.files &&
            proposalInput.files.length > 0;

        const hasDraftFile =
            draftProposalInput &&
            draftProposalInput.value &&
            draftProposalInput.value.trim() !== '';

        return hasNewFile || hasDraftFile;
    }

    window.setProposalState = setProposalState;
    window.restoreProposalState = restoreProposalState;
    window.hasProposalForSubmit = hasProposalForSubmit;

    const originalGoToStep = window.goToStep;
    if (typeof originalGoToStep === 'function' && !window.__proposalGoToStepWrapped) {
        window.__proposalGoToStepWrapped = true;

        window.goToStep = function () {
            if (isProposalDraftMode()) restoreProposalState();
            const result = originalGoToStep.apply(this, arguments);
            if (isProposalDraftMode()) restoreProposalState();
            return result;
        };
    }

    const originalFetch = window.fetch;
    if (typeof originalFetch === 'function' && !window.__proposalFetchWrapped) {
        window.__proposalFetchWrapped = true;

        window.fetch = function (input, init) {
            try {
                const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');

                if (url.includes('api/save_draft.php') && init && init.body instanceof FormData) {
                    if (isProposalDraftMode()) {
                        restoreProposalState();
                    }

                    const f = isProposalDraftMode() ? (document.getElementById('draft_proposal_file')?.value || '') : '';
                    const n = document.getElementById('draft_proposal_original_name')?.value || '';
                    const t = document.getElementById('draft_proposal_uploaded_at')?.value || '';

                    if (f) init.body.set('draft_proposal_file', f);
                    if (n) init.body.set('draft_proposal_original_name', n);
                    if (t) init.body.set('draft_proposal_uploaded_at', t);
                }
            } catch (e) {}

            return originalFetch.apply(this, arguments).then(function (response) {
                try {
                    response.clone().json().then(function (data) {
                        if (data && data.proposal_file) {
                            setProposalState(
                                data.proposal_file,
                                data.proposal_original_name || '',
                                data.proposal_uploaded_at || ''
                            );
                        }
                    }).catch(function () {});
                } catch (e) {}

                return response;
            });
        };
    }

    function bindProposalWeld() {
        if (isProposalDraftMode()) {
            restoreProposalState();
        } else {
            clearProposalStateForNewApplication();
        }

        const form = document.getElementById('multistep_form');

        if (form) {
            form.addEventListener('submit', function (e) {
                restoreProposalState();

                if (!hasProposalForSubmit()) {
                    e.preventDefault();
                    e.stopPropagation();
                    alert('請先上傳活動企劃書！');
                    return false;
                }
            }, true);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindProposalWeld);
    } else {
        bindProposalWeld();
    }

    if (isDraftMode) {
        if (isProposalDraftMode()) {
        setInterval(restoreProposalState, 500);
    } else {
        clearProposalStateForNewApplication();
    }
    }
})();
</script>


<script>
/**
 * 新申請頁面保險清除：
 * 沒有 draft_id/id 時，企劃書欄位必須是空的。
 */
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    const urlDraftId = params.get('draft_id') || params.get('id') || '';
    const currentDraftId = document.getElementById('current_draft_id')?.value || '';

    const isDraftMode =
        (urlDraftId !== '' && urlDraftId !== '0') ||
        (currentDraftId !== '' && currentDraftId !== '0');

    if (!isDraftMode) {
        sessionStorage.removeItem('draft_proposal_file');
        sessionStorage.removeItem('draft_proposal_original_name');
        sessionStorage.removeItem('draft_proposal_uploaded_at');

        const draftFile = document.getElementById('draft_proposal_file');
        const draftName = document.getElementById('draft_proposal_original_name');
        const draftTime = document.getElementById('draft_proposal_uploaded_at');
        const display = document.getElementById('proposal_file_name_display');

        if (draftFile) draftFile.value = '';
        if (draftName) draftName.value = '';
        if (draftTime) draftTime.value = '';
        if (display) display.innerText = '';
    }
});

document.addEventListener("DOMContentLoaded", function() {
// 1. 第一步的來源欄位 ID (抓取使用者一開始輸入的格子)
const primaryOrgInput = document.getElementById('organization_name'); 
const primaryActInput = document.getElementById('activity_name');     

// 2. 第二步要被自動帶入的目標欄位 ID (改成你目前 HTML 實際的 id)
const flagOrg = document.getElementById('flag_organization_name'); // 對應旗幟的申請單位
const flagAct = document.getElementById('flag_activity_name');     // 對應旗幟的活動名稱
const fireOrg = document.getElementById('fire_organization_name'); // 對應上火的申請單位
const fireAct = document.getElementById('fire_activity_name');     // 對應上火的活動名稱

    // 3. 設定同步動態監聽
    if (primaryOrgInput) {
        primaryOrgInput.addEventListener('input', function() {
            if (flagOrg) flagOrg.value = this.value;
            if (fireOrg) fireOrg.value = this.value;
        });
        // 初始執行一次防止頁面重新整理或草稿帶入時沒抓到
        if (flagOrg) flagOrg.value = primaryOrgInput.value;
        if (fireOrg) fireOrg.value = primaryOrgInput.value;
    }

    if (primaryActInput) {
        primaryActInput.addEventListener('input', function() {
            if (flagAct) flagAct.value = this.value;
            if (fireAct) fireAct.value = this.value;
        });
        // 初始執行一次
        if (flagAct) flagAct.value = primaryActInput.value;
        if (fireAct) fireAct.value = primaryActInput.value;
    }
});

// 假設你控制進入下一步的按鈕是透過點擊事件 (請根據你實際的按鈕 id 或 class 綁定)
// 如果你的表單本來就是靠 HTML 內建的 required 阻擋，這段可以不加；
// 但如果你的「下一步」是 JS 寫的，請把這段加入你的「切換步驟函數」裡面：


</script>


</body>
</html>

