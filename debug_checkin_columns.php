<?php
$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system', 3306);
if (!$link) { echo 'CONNECT_ERR: '.mysqli_connect_error()."\n"; exit(0); }
$res = mysqli_query($link, 'SHOW COLUMNS FROM checkin_logs');
if (!$res) { echo 'SQL_ERR: '.mysqli_error($link)."\n"; exit(0); }
while ($r = mysqli_fetch_assoc($res)) { echo $r['Field'] . "\t" . $r['Type'] . "\t" . ($r['Null'] ?? '') . "\t" . ($r['Key'] ?? '') . "\t" . ($r['Default'] ?? '') . PHP_EOL; }
mysqli_close($link);
