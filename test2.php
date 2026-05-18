<?php require "config/database.php"; $link = getMysqliConnection($error); $r = mysqli_query($link, "SHOW CREATE TABLE reservations"); $row = mysqli_fetch_row($r); echo $row[1];
