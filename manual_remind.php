<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/database.php';
// include the auto_remind function definition
if (!file_exists(__DIR__ . '/auto_remind.php')) {
    echo json_encode(['ok' => false, 'error' => 'auto_remind.php not found']);
    exit;
}
require_once __DIR__ . '/auto_remind.php';

// call run_auto_remind with force=true so the manual button bypasses cooldown
try {
    if (function_exists('run_auto_remind')) {
        $res = run_auto_remind(true);
        // $res is expected to be ['sent' => int, 'output' => string]
        $sent = isset($res['sent']) ? (int)$res['sent'] : 0;
        $output = isset($res['output']) ? $res['output'] : '';
        echo json_encode(['ok' => true, 'sent' => $sent, 'output' => $output]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'run_auto_remind not available']);
} catch (Throwable $e) {
    error_log('manual_remind error: '.$e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
