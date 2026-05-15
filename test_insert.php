<?php
require_once "config/database.php";
$link = getMysqliConnection($error);

$insertCols = ["user_id", "borrow_start_at", "borrow_end_at", "organization_name"];
$colsSql = implode(", ", $insertCols) . ", approval_status, created_at";
$placeholders = "?, ?, ?, ?, \"pending\", NOW()";

$insertReservationSql = sprintf("INSERT INTO reservations ( %s ) VALUES (%s)", $colsSql, $placeholders);
echo $insertReservationSql . "\n";
                $reservationStmt = mysqli_prepare($link, $insertReservationSql);
                if (!$reservationStmt) {
                    echo "ERROR: " . mysqli_error($link) . "\n";
                } else {
                    echo "Prepare SUCCESS.\n";
                }

