<?php
require_once __DIR__ . '/config/database.php';

$dbError = '';
$link = getMysqliConnection($dbError);
if ($dbError !== '') {
    echo json_encode(['error' => $dbError]);
    exit;
}

$type = $_GET['type'] ?? '';
$id = $_GET['id'] ?? '';
$year = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('m'));

// Ensure valid month/year
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12) $month = (int)date('m');

$startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));

$response = [
    'total_capacity' => 0,
    'reservations' => []
];

if ($type === 'equipment' && $id !== '') {
    $capSql = "SELECT COUNT(*) as total FROM equipments WHERE equipment_code = ? AND operation_status IN (1, 2)";
    $stmt = mysqli_prepare($link, $capSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $response['total_capacity'] = (int)$row['total'];
        }
        mysqli_stmt_close($stmt);
    }
    
    $resSql = "
        SELECT r.borrow_start_at, r.borrow_end_at, COUNT(eri.equipment_id) as total_qty
        FROM reservations r
        JOIN equipment_reservation_items eri ON r.reservation_id = eri.reservation_id
        JOIN equipments e ON eri.equipment_id = e.equipment_id
        WHERE e.equipment_code = ?
          AND r.approval_status IN ('pending', 'approved')
          AND r.borrow_start_at <= ?
          AND r.borrow_end_at >= ?
        GROUP BY r.reservation_id, r.borrow_start_at, r.borrow_end_at
    ";
    $stmt = mysqli_prepare($link, $resSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sss', $id, $endDate, $startDate);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $response['reservations'][] = [
                'start' => $row['borrow_start_at'],
                'end' => $row['borrow_end_at'],
                'qty' => (int)$row['total_qty']
            ];
        }
        mysqli_stmt_close($stmt);
    }

} elseif ($type === 'space' && $id !== '') {
    $response['total_capacity'] = 1;
    
    $resSql = "
        SELECT r.borrow_start_at, r.borrow_end_at, 1 as total_qty
        FROM reservations r
        JOIN space_reservation_items sri ON r.reservation_id = sri.reservation_id
        WHERE sri.space_id = ?
          AND r.approval_status IN ('pending', 'approved')
          AND r.borrow_start_at <= ?
          AND r.borrow_end_at >= ?
    ";
    $stmt = mysqli_prepare($link, $resSql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sss', $id, $endDate, $startDate);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $response['reservations'][] = [
                'start' => $row['borrow_start_at'],
                'end' => $row['borrow_end_at'],
                'qty' => 1
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

if ($link) {
    mysqli_close($link);
}

header('Content-Type: application/json');
echo json_encode($response);
exit;
