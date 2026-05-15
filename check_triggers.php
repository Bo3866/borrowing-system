<?php
require_once "config/database.php";
$pdo = getDatabaseConnection();
$stmt = $pdo->query("SHOW TRIGGERS");
$triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($triggers);

