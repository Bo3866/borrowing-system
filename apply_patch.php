<?php
require_once "config/database.php";
$link = getMysqliConnection($error);
if (!$link) {
    echo "Cannot connect to DB: $error\n";
    exit;
}
$sql = file_get_contents("add_missing_columns.sql");
if (mysqli_multi_query($link, $sql)) {
    do {
        if ($res = mysqli_store_result($link)) {
            mysqli_free_result($res);
        }
    } while (mysqli_more_results($link) && mysqli_next_result($link));
    echo "SQL Patch Applied via Web Server!\n";
} else {
    echo "SQL Patch Failed: " . mysqli_error($link) . "\n";
}

