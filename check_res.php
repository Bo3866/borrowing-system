<?php
require_once "config/database.php";
$pdo = getDatabaseConnection();
$stmt = $pdo->query("SHOW COLUMNS FROM reservations");
$cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    if(strpos($c["Field"], "organization") !== false) {
        echo "Found: " . $c["Field"] . "\n";
    }
}
echo "Checked.\n";

