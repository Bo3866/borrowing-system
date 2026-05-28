<?php
$c = file_get_contents('c:/xampp/htdocs/borrowing-system/borrow.php');
$start1 = strpos($c, '$periodSlots = [');
$end1 = strpos($c, '$userPhone = ');
$start2 = strpos($c, '$equipmentMap = ');
$end2 = strpos($c, '$reservationApplicantColumn = ');

$part1 = substr($c, $start1, $end1 - $start1);
$part2 = substr($c, $start2, $end2 - $start2);

file_put_contents('c:/xampp/htdocs/borrowing-system/temp_mapsInfo.txt', $part1 . "\n" . $part2);
echo "Extracted.\n";
