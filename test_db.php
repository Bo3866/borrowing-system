<?php
require_once "config/database.php";
echo "DB: " . envOrNull("DB_NAME") . "\n";
print_r(getDatabaseConfig());
$pdo = getDatabaseConnection();
$res = $pdo->query("SELECT database()")->fetchColumn();
echo "Actual database: " . $res . "\n";

