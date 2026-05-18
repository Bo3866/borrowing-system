<?php require "config/database.php"; $link = getMysqliConnection($err); mysqli_query($link, "UPDATE reservations SET checked_in_at = NULL WHERE approval_status != 'approved'"); echo "Fixed2!\n";
