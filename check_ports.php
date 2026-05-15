<?php
require_once "config/database.php";
$candidates = getDatabaseConnectionCandidates();
foreach ($candidates as $config) {
    try {
        $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $config["host"], $config["port"], $config["database"]);
        $pdo = new PDO($dsn, $config["username"], $config["password"]);
        
        $stmt = $pdo->query("SHOW COLUMNS FROM reservations");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasOrg = false;
        foreach($cols as $c) {
            if ($c["Field"] === "organization_name") {
                $hasOrg = true;
                break;
            }
        }
        echo "Port {$config["port"]}: Success. Has Organization Name? " . ($hasOrg ? "YES" : "NO") . "\n";
    } catch(Exception $e) {
        echo "Port {$config["port"]}: Failed -> " . current(explode("\n", $e->getMessage())) . "\n";
    }
}

