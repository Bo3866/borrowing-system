<?php
$content = file_get_contents("borrow.php");
$pos = strpos($content, "organization_name");
echo bin2hex(substr($content, $pos - 5, 30)) . "\n";

