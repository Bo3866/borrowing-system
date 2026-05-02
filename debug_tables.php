<?php
require_once __DIR__ . '/config/database.php';
$dbError = '';
$link = getMysqliConnection($dbError);
if (!$link) { echo 'CONNECT_ERR: '.$dbError."\n"; exit(0); }
$res = mysqli_query($link, "SHOW TABLES LIKE 'checkin_logs'");
$found = [];
while ($r = mysqli_fetch_row($res)) { $found[] = $r[0]; }
echo 'checkin_logs table exists: ' . (count($found) ? 'yes' : 'no') . PHP_EOL;
$res2 = mysqli_query($link, "SHOW COLUMNS FROM reservations");
if (!$res2) { echo 'SHOW COLUMNS ERR: '.mysqli_error($link)."\n"; exit(0); }
$cols = [];
while ($c = mysqli_fetch_assoc($res2)) { $cols[] = $c['Field']; }
echo 'reservations columns: ' . implode(', ', $cols) . PHP_EOL;
mysqli_close($link);
