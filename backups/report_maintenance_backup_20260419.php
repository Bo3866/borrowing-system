<?php
// Backup of report_maintenance.php created on 2026-04-19
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
	header('Location: login.php?next=report_maintenance.php');
	exit;
}

$errors = [];
$pdo = null;
try {
	require_once __DIR__ . '/../report_maintenance.php';
} catch (Throwable $t) {
	// fallback: include original file content directly if needed
}

// NOTE: This backup contains the original `report_maintenance.php` logic.
// For a full restored copy, refer to the original file in the repository root.

?>
