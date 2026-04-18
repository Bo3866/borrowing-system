<?php
declare(strict_types=1);

// This script is intended for scheduled CLI execution only.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function pickExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

function buildReturnedExpr(array $columns): string
{
    $returnFlagColumn = pickExistingColumn($columns, ['return_confirmed', 'is_returned', 'returned', 'return_status']);
    if ($returnFlagColumn !== null) {
        return "COALESCE(r.`{$returnFlagColumn}`, 0) = 1";
    }

    $returnAtColumn = pickExistingColumn($columns, ['return_confirmed_at', 'returned_at', 'return_at']);
    if ($returnAtColumn !== null) {
        return "r.`{$returnAtColumn}` IS NOT NULL";
    }

    return '0';
}

function acquireLock(string $lockPath)
{
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        throw new RuntimeException('無法建立鎖檔：' . $lockPath);
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        throw new RuntimeException('已有另一個通知程序執行中，略過本次。');
    }

    return $handle;
}

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system', 3307);
if (!$link) {
    fwrite(STDERR, '資料庫連線失敗：' . mysqli_connect_error() . PHP_EOL);
    exit(2);
}
mysqli_set_charset($link, 'utf8mb4');

$lockHandle = null;

try {
    $lockHandle = acquireLock(__DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'auto_notify_overdue.lock');

    $reservationColumns = [];
    $columnResult = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    while ($columnRow = mysqli_fetch_assoc($columnResult)) {
        $reservationColumns[] = (string)$columnRow['Field'];
    }

    if (!in_array('user_id', $reservationColumns, true)) {
        throw new RuntimeException('reservations 缺少 user_id 欄位。');
    }

    if (!in_array('return_notified', $reservationColumns, true)) {
        throw new RuntimeException('reservations 缺少 return_notified 欄位。');
    }

    $borrowEndColumn = pickExistingColumn($reservationColumns, ['borrow_end_at', 'borrow_ene_at', 'borrow_end_time']);
    if ($borrowEndColumn === null) {
        throw new RuntimeException('reservations 缺少借用結束欄位（borrow_end_at 或 borrow_ene_at）。');
    }

    $returnedSqlExpr = buildReturnedExpr($reservationColumns);

    $sql = "
        SELECT
            r.reservation_id,
            r.`{$borrowEndColumn}` AS borrow_end_at,
            u.full_name,
            u.email
        FROM reservations r
        JOIN users u ON u.user_id = r.user_id
        WHERE r.approval_status = 'approved'
          AND r.`{$borrowEndColumn}` < NOW()
          AND NOT ({$returnedSqlExpr})
          AND COALESCE(r.return_notified, 0) = 0
        ORDER BY r.`{$borrowEndColumn}` ASC
        LIMIT 300
    ";

    $result = mysqli_query($link, $sql);

    $sent = 0;
    $failed = 0;
    $checked = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $checked++;
        $reservationId = (int)$row['reservation_id'];
        $email = (string)$row['email'];
        $fullName = (string)$row['full_name'];
        $borrowEndAt = (string)$row['borrow_end_at'];

        if ($email === '') {
            $failed++;
            continue;
        }

        $subject = '【校園資源租借系統】逾期未歸還提醒';
        $message = "{$fullName} 您好，\n\n"
            . "您的借用申請（編號：{$reservationId}）已超過歸還時間（{$borrowEndAt}）。\n"
            . "請盡速完成歸還，若已歸還請聯繫管理人員確認。\n\n"
            . "校園資源租借系統";
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n"
            . "From: noreply@campus.local\r\n";

        $ok = @mail($email, $subject, $message, $headers);
        if ($ok) {
            $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET return_notified = 1 WHERE reservation_id = ?');
            mysqli_stmt_bind_param($updateStmt, 'i', $reservationId);
            mysqli_stmt_execute($updateStmt);
            mysqli_stmt_close($updateStmt);
            $sent++;
        } else {
            $failed++;
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] overdue_checked={$checked} sent={$sent} failed={$failed}" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '執行失敗：' . $e->getMessage() . PHP_EOL);
    exit(3);
} finally {
    if ($lockHandle !== null) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }

    mysqli_close($link);
}
