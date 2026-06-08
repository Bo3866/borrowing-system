<?php
require_once "config/database.php";
$pdo = getDatabaseConnection();
try {
    $pdo->exec("ALTER TABLE reservations 
    ADD COLUMN organization_name VARCHAR(100) NULL,
    ADD COLUMN activity_name VARCHAR(100) NULL,
    ADD COLUMN participant_count VARCHAR(20) NULL,
    ADD COLUMN staff_count INT NULL,
    ADD COLUMN activity_coordinator VARCHAR(50) NULL,
    ADD COLUMN coordinator_department VARCHAR(50) NULL,
    ADD COLUMN coordinator_phone VARCHAR(50) NULL,
    ADD COLUMN coordinator_other_contact VARCHAR(255) NULL,
    ADD COLUMN vehicle_entry VARCHAR(10) NULL,
    ADD COLUMN setup_flags VARCHAR(10) NULL,
    ADD COLUMN purpose VARCHAR(255) NULL;");
    echo "Added columns manually.\n";
} catch(Exception $e) {
    echo "Failed: ".$e->getMessage()."\n";
}

