<?php
require_once __DIR__ . '/config/database.php';
$dbError = '';
$link = getMysqliConnection($dbError);
if (!$link) { echo 'CONNECT_ERR: '.$dbError."\n"; exit(0); }
$res = mysqli_query($link, 'SHOW COLUMNS FROM checkin_logs');
if (!$res) { echo 'SQL_ERR: '.mysqli_error($link)."\n"; exit(0); }
while ($r = mysqli_fetch_assoc($res)) { echo $r['Field'] . "\t" . $r['Type'] . "\t" . ($r['Null'] ?? '') . "\t" . ($r['Key'] ?? '') . "\t" . ($r['Default'] ?? '') . PHP_EOL; }
mysqli_close($link);
