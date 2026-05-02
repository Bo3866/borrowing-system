<?php
require_once __DIR__ . '/config/database.php';
$err = '';
$link = getMysqliConnection($err);
if (!$link) {
    echo 'DB_ERR: ' . $err . PHP_EOL;
    exit(0);
}
$sql = "SELECT cl.*, r.user_id AS res_user_id FROM checkin_logs cl LEFT JOIN reservations r ON r.reservation_id = cl.reservation_id ORDER BY cl.checkin_id DESC LIMIT 10";
$res = mysqli_query($link, $sql);
if (!$res) {
    echo 'SQL_ERR: ' . mysqli_error($link) . PHP_EOL;
    exit(0);
}
while ($row = mysqli_fetch_assoc($res)) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
mysqli_close($link);
