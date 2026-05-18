<?php
require 'c:/xampp/htdocs/borrowing-system/config/database.php';
$link = getMysqliConnection($err);
$res = mysqli_query($link, 'SELECT * FROM equipment_certificates');
while ($row = mysqli_fetch_assoc($res)) {
    print_r($row);
}
