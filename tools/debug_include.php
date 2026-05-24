<?php
// Debug runner for approve_detail.php (CLI only)
chdir(__DIR__ . '/..');
// simulate web GET and SESSION
$_GET['reservation_id'] = $argv[1] ?? 50;
// minimal session simulation
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['user_id'] = $_SESSION['user_id'] ?? '1';
$_SESSION['full_name'] = $_SESSION['full_name'] ?? 'Tester';
$_SESSION['role_name'] = $_SESSION['role_name'] ?? '3';

ob_start();
include __DIR__ . '/../approve_detail.php';
$out = ob_get_clean();
echo "---- BEGIN OUTPUT ----\n";
echo $out;
echo "\n---- END OUTPUT ----\n";
