<?php
require 'c:/xampp/htdocs/borrowing-system/config/database.php';
$link = getMysqliConnection($err);

echo "--- TABLE SCHEMA ---\n";
$res = mysqli_query($link, 'SHOW CREATE TABLE reservations');
$row = mysqli_fetch_row($res);
echo $row[1] . "\n\n";

echo "--- TRIGGERS ---\n";
$res2 = mysqli_query($link, 'SHOW TRIGGERS');
while ($row2 = mysqli_fetch_assoc($res2)) {
    print_r($row2);
}

echo "--- TIMEZONES ---\n";
echo "PHP Default Timezone: " . date_default_timezone_get() . "\n";
echo "Current PHP Time: " . date('Y-m-d H:i:s') . "\n";

$res3 = mysqli_query($link, 'SELECT @@global.time_zone, @@session.time_zone');
$row3 = mysqli_fetch_assoc($res3);
echo "MySQL Global Timezone: " . $row3['@@global.time_zone'] . "\n";
echo "MySQL Session Timezone: " . $row3['@@session.time_zone'] . "\n";
