<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=return_management.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system',3307);
$dbError = '';
if (!$link) {
    $dbError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');
}

function pickExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$actionMsg = '';
$rows = [];

if ($dbError === '') {
    $reservationColumns = [];
    $columnResult = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($columnResult) {
        while ($columnRow = mysqli_fetch_assoc($columnResult)) {
            $reservationColumns[] = (string)$columnRow['Field'];
        }
    }

    $applicantColumn = pickExistingColumn($reservationColumns, ['applicant_id', 'user_id']);
    if ($applicantColumn === null) {
        $dbError = 'reservations 缺少 applicant_id（或 user_id）欄位，無法關聯 users 資料。';
    }

    $borrowStartColumn = pickExistingColumn($reservationColumns, ['borrow_start_at', 'borrow_start_time']);
    $borrowEndColumn = pickExistingColumn($reservationColumns, ['borrow_end_at', 'borrow_ene_at', 'borrow_end_time']);
    if ($dbError === '' && ($borrowStartColumn === null || $borrowEndColumn === null)) {
        $dbError = 'reservations 缺少借用起訖欄位，請確認 borrow_start_at / borrow_end_at（或 borrow_ene_at）。';
    }

    // 自動遷移：確保 returned_at 欄位存在
    if ($dbError === '') {
        try {
            if (!in_array('returned_at', $reservationColumns, true)) {
                $migrationSql = 'ALTER TABLE reservations ADD COLUMN returned_at DATETIME NULL COMMENT "歸還完成時間"';
                mysqli_query($link, $migrationSql);
                $reservationColumns[] = 'returned_at';
            }
        } catch (Throwable $e) {
            // 忽略列已存在錯誤
        }
    }

    // 報到狀態：使用 checkin_logs（無需再查 pickup 欄位）
    // 歸還狀態：使用 returned_at 欄位

    if ($dbError === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        try {
            if (in_array($action, ['confirm_return'], true)) {
                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                if ($reservationId <= 0) {
                    throw new RuntimeException('無效的申請編號。');
                }

                $ownershipStmt = mysqli_prepare(
                    $link,
                    'SELECT r.reservation_id, r.returned_at, cl.checked_in_at
                     FROM reservations r
                     LEFT JOIN checkin_logs cl
                       ON cl.reservation_id = r.reservation_id
                      AND cl.applicant_id COLLATE utf8mb4_unicode_ci = r.`' . $applicantColumn . '` COLLATE utf8mb4_unicode_ci
                     WHERE r.reservation_id = ?
                       AND r.`' . $applicantColumn . '` COLLATE utf8mb4_unicode_ci = ?
                     LIMIT 1'
                );
                if (!$ownershipStmt) {
                    throw new RuntimeException('驗證申請權限失敗：' . mysqli_error($link));
                }

                mysqli_stmt_bind_param($ownershipStmt, 'is', $reservationId, $currentUserId);
                mysqli_stmt_execute($ownershipStmt);
                $ownershipResult = mysqli_stmt_get_result($ownershipStmt);
                $reservationRow = $ownershipResult ? mysqli_fetch_assoc($ownershipResult) : null;
                mysqli_stmt_close($ownershipStmt);

                if (!$reservationRow) {
                    throw new RuntimeException('找不到可操作的申請資料，或此申請不屬於目前使用者。');
                }

                if (empty($reservationRow['checked_in_at'])) {
                    throw new RuntimeException('尚未報到，無法確認歸還或離場。');
                }

                if (!empty($reservationRow['returned_at'])) {
                    throw new RuntimeException('此申請已完成歸還或離場。');
                }

                mysqli_begin_transaction($link);

                if ($action === 'confirm_return') {
                    // 更新歸還時間
                    $returnSql = 'UPDATE reservations SET returned_at = COALESCE(returned_at, NOW()) WHERE reservation_id = ?';
                    $returnStmt = mysqli_prepare($link, $returnSql);
                    if (!$returnStmt) {
                        throw new RuntimeException('更新歸還狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($returnStmt, 'i', $reservationId);
                    mysqli_stmt_execute($returnStmt);
                    mysqli_stmt_close($returnStmt);

                    // 還原借出的器材狀態
                    $restoreStmt = mysqli_prepare(
                        $link,
                        'UPDATE equipments e
                         JOIN equipment_reservation_items eri ON eri.equipment_id = e.equipment_id
                         SET e.operation_status = 1
                         WHERE eri.reservation_id = ? AND e.operation_status = 2'
                    );
                    if (!$restoreStmt) {
                        throw new RuntimeException('還原器材可借狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($restoreStmt, 'i', $reservationId);
                    mysqli_stmt_execute($restoreStmt);
                    mysqli_stmt_close($restoreStmt);

                    $actionMsg = '已確認歸還／離場。';
                }

                mysqli_commit($link);
            }
        } catch (Throwable $e) {
            mysqli_rollback($link);
            $actionMsg = '處理失敗：' . $e->getMessage();
        }
    }

    if ($dbError === '') {
        $safeUserId = mysqli_real_escape_string($link, $currentUserId);
        $listWhere = "r.`{$applicantColumn}` = '{$safeUserId}'";

        // 查詢邏輯：
        // - pickup_confirmed: 使用 checkin_logs.checked_in_at 判斷（NULL = 未報到，NOT NULL = 已報到）
        // - return_confirmed: 使用 reservations.returned_at 判斷（NULL = 未歸還，NOT NULL = 已歸還）
        $listSql = "
            SELECT
                r.reservation_id,
                r.`{$applicantColumn}` AS applicant_user_id,
                u.full_name,
                u.email,
                r.`{$borrowStartColumn}` AS borrow_start_at,
                r.`{$borrowEndColumn}` AS borrow_end_at,
                r.approval_status,
                (cl.checked_in_at IS NOT NULL) AS pickup_confirmed,
                cl.checked_in_at AS pickup_confirmed_at,
                cl.checked_in_space_id,
                (r.returned_at IS NOT NULL) AS return_confirmed,
                r.returned_at AS return_confirmed_at,
                (
                    SELECT GROUP_CONCAT(DISTINCT ec.equipment_code ORDER BY ec.equipment_code SEPARATOR ', ')
                    FROM equipment_reservation_items eri
                    JOIN equipments e ON e.equipment_id = eri.equipment_id
                    JOIN equipment_categories ec ON ec.equipment_code = e.equipment_code
                    WHERE eri.reservation_id = r.reservation_id
                ) AS equipment_codes,
                (
                    SELECT GROUP_CONCAT(DISTINCT sri.space_id ORDER BY sri.space_id SEPARATOR ', ')
                    FROM space_reservation_items sri
                    WHERE sri.reservation_id = r.reservation_id
                ) AS space_ids,
                (
                    SELECT GROUP_CONCAT(DISTINCT s.space_name ORDER BY s.space_name SEPARATOR ', ')
                    FROM space_reservation_items sri
                    JOIN spaces s ON s.space_id = sri.space_id
                    WHERE sri.reservation_id = r.reservation_id
                ) AS space_names
            FROM reservations r
            JOIN users u ON u.user_id COLLATE utf8mb4_unicode_ci = r.`{$applicantColumn}` COLLATE utf8mb4_unicode_ci
            LEFT JOIN checkin_logs cl ON cl.reservation_id = r.reservation_id AND cl.applicant_id COLLATE utf8mb4_unicode_ci = r.`{$applicantColumn}` COLLATE utf8mb4_unicode_ci
            WHERE {$listWhere}
            ORDER BY r.`{$borrowEndColumn}` DESC
            LIMIT 300
        ";

        $listResult = mysqli_query($link, $listSql);
        if ($listResult) {
            while ($row = mysqli_fetch_assoc($listResult)) {
                $rows[] = $row;
            }
        } else {
            $dbError = '讀取借還管理資料失敗：' . mysqli_error($link);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>借還管理｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card">
                <h2>我的申請紀錄</h2>

                <?php if ($actionMsg !== '') { ?>
                    <div class="borrow-success"><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } else { ?>
                    <div class="borrow-table-wrapper">
                        <table class="management-table return-management-table">
                            <thead>
                                <tr>
                                    <th>申請人</th>
                                    <th>借用時段</th>
                                    <th>借用項目</th>
                                    <th>申請狀態</th>
                                    <th>是否已報到</th>
                                    <th>歸還</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) === 0) { ?>
                                    <tr><td colspan="6">目前沒有可顯示的申請資料。</td></tr>
                                <?php } else { ?>
                                    <?php foreach ($rows as $row) { ?>
                                        <?php
                                            $resourceParts = [];
                                            if (!empty($row['equipment_codes'])) {
                                                $resourceParts[] = '器材: ' . $row['equipment_codes'];
                                            }
                                            if (!empty($row['space_ids'])) {
                                                $resourceParts[] = '空間: ' . $row['space_ids'];
                                            }
                                            $resourceText = count($resourceParts) > 0 ? implode(' | ', $resourceParts) : '-';
                                            $isPickup = (int)$row['pickup_confirmed'] === 1;
                                            $isReturned = (int)$row['return_confirmed'] === 1;
                                        ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($row['full_name'] . ' (' . $row['applicant_user_id'] . ')', ENT_QUOTES, 'UTF-8'); ?><br>
                                                <small><?php echo htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8'); ?></small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars((string)$row['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?><br>
                                                ～ <?php echo htmlspecialchars((string)$row['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string)$row['approval_status'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($resourceText, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php
                                                    $isPickup = (int)$row['pickup_confirmed'] === 1;
                                                    $checkinTime = $row['pickup_confirmed_at'];
                                                    $checkinSpace = $row['checked_in_space_id'];
                                                ?>
                                                <span class="return-status <?php echo $isPickup ? 'return-status-ok' : 'return-status-pending'; ?>">
                                                    <?php echo $isPickup ? '已報到' : '未報到'; ?>
                                                </span>
                                                <?php if ($isPickup) { ?>
                                                    <br><small>時間: <?php echo htmlspecialchars((string)$checkinTime, ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <br><small>位置: <?php echo htmlspecialchars((string)$checkinSpace, ENT_QUOTES, 'UTF-8'); ?></small>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $isReturned = (int)$row['return_confirmed'] === 1;
                                                    $returnTime = $row['return_confirmed_at'];
                                                ?>
                                                <span class="return-status <?php echo $isReturned ? 'return-status-ok' : 'return-status-pending'; ?>">
                                                        <?php echo $isReturned ? '已離場' : '可離場'; ?>
                                                </span>
                                                <?php if ($isReturned) { ?>
                                                        <br><small>時間: <?php echo htmlspecialchars((string)$returnTime, ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <?php } elseif ($isPickup) { ?>
                                                    <br>
                                                    <form method="post" style="margin-top: 8px;">
                                                        <input type="hidden" name="action" value="confirm_return">
                                                        <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                            <button type="submit" class="btn-secondary" style="font-size: 12px; padding: 4px 12px;" onclick="return confirm('確認此申請已歸還或離場？')">確認歸還／離場</button>
                                                    </form>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>
