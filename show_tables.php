<?php
require_once "config/database.php";
$pdo = getDatabaseConnection();
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $t) echo "$t\n";

