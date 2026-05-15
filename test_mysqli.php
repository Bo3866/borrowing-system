<?php
require_once "config/database.php";
$link = getMysqliConnection($error);
if (!$link) {
    die("Mysqli error: $error\n");
}
$res = mysqli_query($link, "SELECT database()");
$row = mysqli_fetch_row($res);
echo "Mysqli DB: " . $row[0] . "\n";
$res2 = mysqli_query($link, "SHOW COLUMNS FROM reservations");
while($row = mysqli_fetch_assoc($res2)) {
    if ($row["Field"] === "organization_name") {
        echo "Found organization_name in mysqli!\n";
    }
}

