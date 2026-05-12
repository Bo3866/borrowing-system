<?php
/**
 * API: 加载草稿数据
 * 请求方式：GET /api/load_draft.php?id=<reservation_id>
 * 返回：JSON 格式的草稿数据
 */

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只接受 GET 请求']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '缺少 id 参数']);
    exit;
}

$reservationId = (int)$_GET['id'];
$userId = (string)$_SESSION['user_id'];

require_once dirname(__DIR__) . '/config/database.php';

$dbError = '';
$link = getMysqliConnection($dbError);

if ($dbError !== '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

try {
    $stmt = mysqli_prepare($link, 
        'SELECT reservation_id, space_id, resource_type, selected_equipment, selected_spaces, 
                borrow_start_at, borrow_end_at, phone, purpose, status
         FROM reservations 
         WHERE reservation_id = ? AND user_id = ? LIMIT 1'
    );
    
    if (!$stmt) {
        throw new Exception('准备查询失败：' . mysqli_error($link));
    }
    
    mysqli_stmt_bind_param($stmt, 'is', $reservationId, $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (!$result || mysqli_num_rows($result) === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '草稿不存在或无权限访问']);
        mysqli_stmt_close($stmt);
        mysqli_close($link);
        exit;
    }
    
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    // 解析借用日期和时段
    $borrowStartAt = $row['borrow_start_at'];
    $borrowEndAt = $row['borrow_end_at'];
    $borrowDate = null;
    $startPeriodCode = null;
    $endPeriodCode = null;
    
    if ($borrowStartAt && $borrowEndAt) {
        // 提取日期部分
        $borrowDate = substr($borrowStartAt, 0, 10); // YYYY-MM-DD
        
        // 根据时间查找对应的节次代码
        $periodSlots = [
            'D0' => ['label' => '日间第0节', 'start' => '07:10:00', 'end' => '08:00:00'],
            'D1' => ['label' => '日间第1节', 'start' => '08:10:00', 'end' => '09:00:00'],
            'D2' => ['label' => '日间第2节', 'start' => '09:10:00', 'end' => '10:00:00'],
            'D3' => ['label' => '日间第3节', 'start' => '10:10:00', 'end' => '11:00:00'],
            'D4' => ['label' => '日间第4节', 'start' => '11:10:00', 'end' => '12:00:00'],
            'DN' => ['label' => '日间第5节', 'start' => '12:40:00', 'end' => '13:30:00'],
            'D5' => ['label' => '日间第6节', 'start' => '13:40:00', 'end' => '14:30:00'],
            'D6' => ['label' => '日间第7节', 'start' => '14:40:00', 'end' => '15:30:00'],
            'D7' => ['label' => '日间第8节', 'start' => '15:40:00', 'end' => '16:30:00'],
            'D8' => ['label' => '夜间第1节', 'start' => '16:40:00', 'end' => '17:30:00'],
            'E0' => ['label' => '夜间第2节', 'start' => '17:40:00', 'end' => '18:30:00'],
            'E1' => ['label' => '夜间第3节', 'start' => '18:40:00', 'end' => '19:30:00'],
            'E2' => ['label' => '夜间第4节', 'start' => '19:35:00', 'end' => '20:20:00'],
            'E3' => ['label' => '夜间第5节', 'start' => '20:30:00', 'end' => '21:20:00'],
            'E4' => ['label' => '夜间第6节', 'start' => '21:25:00', 'end' => '22:10:00'],
        ];
        
        $startTime = substr($borrowStartAt, 11, 8); // HH:MM:SS
        $endTime = substr($borrowEndAt, 11, 8);
        
        foreach ($periodSlots as $code => $config) {
            if ($config['start'] === $startTime && !$startPeriodCode) {
                $startPeriodCode = $code;
            }
            if ($config['end'] === $endTime) {
                $endPeriodCode = $code;
            }
        }
    }
    
    $draftData = [
        'reservation_id' => (int)$row['reservation_id'],
        'space_id' => $row['space_id'],
        'resource_type' => $row['resource_type'],
        'selected_equipment' => $row['selected_equipment'],
        'selected_spaces' => $row['selected_spaces'],
        'borrow_date' => $borrowDate,
        'start_period_code' => $startPeriodCode,
        'end_period_code' => $endPeriodCode,
        'phone' => $row['phone'],
        'purpose' => $row['purpose'],
        'status' => (int)$row['status']
    ];
    
    http_response_code(200);
    echo json_encode(['success' => true, 'data' => $draftData]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')]);
} finally {
    mysqli_close($link);
}
?>
