<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/database.php';

if (!file_exists(__DIR__ . '/auto_remind.php')) {
    echo json_encode(['ok' => false, 'error' => 'auto_remind.php not found']);
    exit;
}
require_once __DIR__ . '/auto_remind.php';

try {
    if (function_exists('run_auto_remind')) {
        // force=false：受冷卻限制，老師按鈕也要等1小時才能再寄
        $res = run_auto_remind(false);
        echo json_encode([
            'ok'    => true,
            'sent'  => (int)($res['sent'] ?? 0),
            'found' => (int)($res['found'] ?? 0),
            'output'=> $res['output'] ?? ''
        ]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => 'run_auto_remind not available']);
} catch (Throwable $e) {
    error_log('manual_remind error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}