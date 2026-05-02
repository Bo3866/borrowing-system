<?php
require_once __DIR__ . '/config/database.php';
$dbError = '';
$link = getMysqliConnection($dbError);
if (!$link) { echo 'CONNECT_ERR: '.$dbError."\n"; exit(0); }
$res = mysqli_query($link, 'SELECT cl.*, r.user_id AS res_user_id FROM checkin_logs cl LEFT JOIN reservations r ON r.reservation_id = cl.reservation_id ORDER BY cl.checkin_id DESC LIMIT 20');
if (!$res) { echo 'SQL_ERR: '.mysqli_error($link)."\n"; exit(0); }
while ($row = mysqli_fetch_assoc($res)) { echo json_encode($row, JSON_UNESCAPED_UNICODE)."\n"; }
mysqli_close($link);
