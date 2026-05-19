$c = file_get_contents("amend_application.php"); $c = str_replace("gap: 8px; margin: 0;", "gap: 8px; margin: 0; white-space: nowrap;", $c); file_put_contents("amend_application.php", $c);
