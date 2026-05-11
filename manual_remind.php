<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/database.php';
// include the auto_remind function definition
if (!file_exists(__DIR__ . '/auto_remind.php')) {
    echo json_encode(['ok' => false, 'error' => 'auto_remind.php not found']);
    exit;
}
require_once __DIR__ . '/auto_remind.php';

// capture any stdout from run_auto_remind()
ob_start();
try {
    if (function_exists('run_auto_remind')) {
        run_auto_remind();
        $output = ob_get_clean();
        echo json_encode(['ok' => true, 'output' => $output]);
        exit;
    }
    $out = ob_get_clean();
    echo json_encode(['ok' => false, 'error' => 'run_auto_remind not available', 'output' => $out]);
} catch (Throwable $e) {
    $out = ob_get_clean();
    error_log('manual_remind error: '.$e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'output' => $out]);
}
