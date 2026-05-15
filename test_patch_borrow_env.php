<?php
$content = file_get_contents("borrow.php");
$old = "require_once 'config/database.php';";
$new = "require_once 'config/database.php';\nfile_put_contents(__DIR__ . '/borrow_debug.log', \"CONF: \" . print_r(getDatabaseConfig(), true) . \"\\nDB: \" . envOrNull('DB_NAME') . \"\\n\", FILE_APPEND);";
$content = str_replace($old, $new, $content);
file_put_contents("borrow.php", $content);
echo "Patched borrow.php to log config.\n";

