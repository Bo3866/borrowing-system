<?php
/**
 * API: 保存申請草稿
 * 請求方式：POST /api/save_draft.php
 * 參數：
 *   - reservation_id (可選，用於編輯現有草稿)
 *   - space_id (可選)
 *   - resource_type (可選)
 *   - selected_equipment (可選)
 *   - selected_spaces (可選)
 *   - borrow_date (可選)
 *   - start_period_code (可選)
 *   - end_period_code (可選)
 *   - phone (可選)
 *   - purpose (可選)
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只接受 POST 請求']);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';

$userId = (string)$_SESSION['user_id'];
$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '數據庫連接失敗']);
    exit;
}

try {
    $reservationId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
    $spaceId = isset($_POST['space_id']) ? trim((string)$_POST['space_id']) : null;
    $resourceType = isset($_POST['resource_type']) ? trim((string)$_POST['resource_type']) : null;
    $selectedEquipment = isset($_POST['selected_equipment']) ? trim((string)$_POST['selected_equipment']) : null;
    $selectedSpaces = isset($_POST['selected_spaces']) ? trim((string)$_POST['selected_spaces']) : null;
    $borrowDate = isset($_POST['borrow_date']) ? trim((string)$_POST['borrow_date']) : null;
    $startPeriodCode = isset($_POST['start_period_code']) ? trim((string)$_POST['start_period_code']) : null;
    $endPeriodCode = isset($_POST['end_period_code']) ? trim((string)$_POST['end_period_code']) : null;
    $phone = isset($_POST['phone']) ? trim((string)$_POST['phone']) : null;
    $purpose = isset($_POST['purpose']) ? trim((string)$_POST['purpose']) : null;

    // 如果是編輯現有草稿
    if ($reservationId > 0) {
        // 檢查所有權
        $checkStmt = mysqli_prepare($link, 'SELECT reservation_id FROM reservations WHERE reservation_id = ? AND user_id = ? AND status = 0 LIMIT 1');
        if (!$checkStmt) {
            throw new Exception('準備查詢失敗：' . mysqli_error($link));
        }
        mysqli_stmt_bind_param($checkStmt, 'is', $reservationId, $userId);
        mysqli_stmt_execute($checkStmt);
        $checkResult = mysqli_stmt_get_result($checkStmt);
        
        if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => '無權限編輯此草稿']);
            mysqli_stmt_close($checkStmt);
            mysqli_close($link);
            exit;
        }
        mysqli_stmt_close($checkStmt);
        
        // 構建UPDATE語句
        $updateParts = ['updated_at = NOW()'];
        $params = [];
        $paramTypes = '';
        
        if ($spaceId !== null) {
            $updateParts[] = 'space_id = ?';
            $params[] = $spaceId;
            $paramTypes .= 's';
        }
        if ($resourceType !== null) {
            $updateParts[] = 'resource_type = ?';
            $params[] = $resourceType;
            $paramTypes .= 's';
        }
        if ($selectedEquipment !== null) {
            $updateParts[] = 'selected_equipment = ?';
            $params[] = $selectedEquipment;
            $paramTypes .= 's';
        }
        if ($selectedSpaces !== null) {
            $updateParts[] = 'selected_spaces = ?';
            $params[] = $selectedSpaces;
            $paramTypes .= 's';
        }
        if ($borrowDate !== null && $startPeriodCode !== null && $endPeriodCode !== null) {
            // 假設 periodSlots 配置已定義
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
            
            if (isset($periodSlots[$startPeriodCode]) && isset($periodSlots[$endPeriodCode])) {
                $startDateTime = $borrowDate . ' ' . $periodSlots[$startPeriodCode]['start'];
                $endDateTime = $borrowDate . ' ' . $periodSlots[$endPeriodCode]['end'];
                
                $updateParts[] = 'borrow_start_at = ?';
                $updateParts[] = 'borrow_end_at = ?';
                $params[] = $startDateTime;
                $params[] = $endDateTime;
                $paramTypes .= 'ss';
            }
        }
        if ($phone !== null) {
            $updateParts[] = 'phone = ?';
            $params[] = $phone;
            $paramTypes .= 's';
        }
        if ($purpose !== null) {
            $updateParts[] = 'purpose = ?';
            $params[] = $purpose;
            $paramTypes .= 's';
        }
        
        // 最後添加 WHERE 條件
        $params[] = $reservationId;
        $paramTypes .= 'i';
        
        $updateSql = 'UPDATE reservations SET ' . implode(', ', $updateParts) . ' WHERE reservation_id = ?';
        
        $updateStmt = mysqli_prepare($link, $updateSql);
        if (!$updateStmt) {
            throw new Exception('準備 UPDATE 語句失敗：' . mysqli_error($link));
        }
        
        if (!empty($paramTypes)) {
            mysqli_stmt_bind_param($updateStmt, $paramTypes, ...$params);
        }
        
        if (!mysqli_stmt_execute($updateStmt)) {
            throw new Exception('更新草稿失敗：' . mysqli_error($link));
        }
        
        mysqli_stmt_close($updateStmt);
        
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => '草稿已保存', 'reservation_id' => $reservationId]);
        
    } else {
        // 新建草稿
        // 驗證：至少需要有 user_id
        $insertCols = ['user_id', 'status', 'approval_status', 'submitted_at'];
        $bindParams = [$userId, 0, 'pending', date('Y-m-d H:i:s')];
        $paramTypes = 'siss';
        
        // 預設借用時間（如果提供）
        if ($borrowDate !== null && $startPeriodCode !== null && $endPeriodCode !== null) {
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
            
            if (isset($periodSlots[$startPeriodCode]) && isset($periodSlots[$endPeriodCode])) {
                $startDateTime = $borrowDate . ' ' . $periodSlots[$startPeriodCode]['start'];
                $endDateTime = $borrowDate . ' ' . $periodSlots[$endPeriodCode]['end'];
                
                $insertCols[] = 'borrow_start_at';
                $insertCols[] = 'borrow_end_at';
                $bindParams[] = $startDateTime;
                $bindParams[] = $endDateTime;
                $paramTypes .= 'ss';
            }
        }
        
        // 添加其他可選欄位
        if ($spaceId !== null) {
            $insertCols[] = 'space_id';
            $bindParams[] = $spaceId;
            $paramTypes .= 's';
        }
        if ($resourceType !== null) {
            $insertCols[] = 'resource_type';
            $bindParams[] = $resourceType;
            $paramTypes .= 's';
        }
        if ($selectedEquipment !== null) {
            $insertCols[] = 'selected_equipment';
            $bindParams[] = $selectedEquipment;
            $paramTypes .= 's';
        }
        if ($selectedSpaces !== null) {
            $insertCols[] = 'selected_spaces';
            $bindParams[] = $selectedSpaces;
            $paramTypes .= 's';
        }
        if ($phone !== null) {
            $insertCols[] = 'phone';
            $bindParams[] = $phone;
            $paramTypes .= 's';
        }
        if ($purpose !== null) {
            $insertCols[] = 'purpose';
            $bindParams[] = $purpose;
            $paramTypes .= 's';
        }
        
        $placeholders = array_fill(0, count($insertCols), '?');
        $insertSql = 'INSERT INTO reservations (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        
        $insertStmt = mysqli_prepare($link, $insertSql);
        if (!$insertStmt) {
            throw new Exception('準備 INSERT 語句失敗：' . mysqli_error($link));
        }
        
        mysqli_stmt_bind_param($insertStmt, $paramTypes, ...$bindParams);
        
        if (!mysqli_stmt_execute($insertStmt)) {
            throw new Exception('保存草稿失敗：' . mysqli_error($link));
        }
        
        $newReservationId = mysqli_insert_id($link);
        mysqli_stmt_close($insertStmt);
        
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => '草稿已保存', 'reservation_id' => $newReservationId]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
} finally {
    mysqli_close($link);
}
?>
