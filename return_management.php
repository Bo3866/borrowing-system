<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=return_management.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$currentRole = (string)($_SESSION['role_name'] ?? '');
$isManager = in_array($currentRole, ['2', '3'], true);

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system', 3307);
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

    $applicantColumn = pickExistingColumn($reservationColumns, ['user_id']);
    if ($applicantColumn === null) {
        $dbError = 'reservations 缺少 user_id 欄位，無法關聯 users 資料。';
    }

    $borrowStartColumn = pickExistingColumn($reservationColumns, ['borrow_start_at', 'borrow_start_time']);
    $borrowEndColumn = pickExistingColumn($reservationColumns, ['borrow_end_at', 'borrow_ene_at', 'borrow_end_time']);
    if ($dbError === '' && ($borrowStartColumn === null || $borrowEndColumn === null)) {
        $dbError = 'reservations 缺少借用起訖欄位，請確認 borrow_start_at / borrow_end_at（或 borrow_ene_at）。';
    }

    $pickupFlagColumn = pickExistingColumn($reservationColumns, ['pickup_confirmed', 'is_picked_up', 'picked_up', 'pickup_status']);
    $pickupAtColumn = pickExistingColumn($reservationColumns, ['pickup_confirmed_at', 'picked_up_at', 'pickup_at']);
    $returnFlagColumn = pickExistingColumn($reservationColumns, ['return_confirmed', 'is_returned', 'returned', 'return_status']);
    $returnAtColumn = pickExistingColumn($reservationColumns, ['return_confirmed_at', 'returned_at', 'return_at']);

    $pickupSqlExpr = '0';
    if ($pickupFlagColumn !== null) {
        $pickupSqlExpr = "COALESCE(r.`{$pickupFlagColumn}`, 0) = 1";
    } elseif ($pickupAtColumn !== null) {
        $pickupSqlExpr = "r.`{$pickupAtColumn}` IS NOT NULL";
    }

    $returnedSqlExpr = '0';
    if ($returnFlagColumn !== null) {
        $returnedSqlExpr = "COALESCE(r.`{$returnFlagColumn}`, 0) = 1";
    } elseif ($returnAtColumn !== null) {
        $returnedSqlExpr = "r.`{$returnAtColumn}` IS NOT NULL";
    }

    if ($dbError === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        try {
            if (in_array($action, ['confirm_pickup', 'confirm_return'], true)) {
                if (!$isManager) {
                    throw new RuntimeException('僅管理人員可操作報到與歸還確認。');
                }

                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                if ($reservationId <= 0) {
                    throw new RuntimeException('無效的申請編號。');
                }

                if ($action === 'confirm_pickup' && $pickupFlagColumn === null && $pickupAtColumn === null) {
                    throw new RuntimeException('reservations 尚未設定取件欄位，請先補上 pickup 欄位。');
                }

                if ($action === 'confirm_return' && $returnFlagColumn === null && $returnAtColumn === null) {
                    throw new RuntimeException('reservations 尚未設定歸還欄位，請先補上 return 欄位。');
                }

                mysqli_begin_transaction($link);

                if ($action === 'confirm_pickup') {
                    $setParts = [];
                    if ($pickupFlagColumn !== null) {
                        $setParts[] = "`{$pickupFlagColumn}` = 1";
                    }
                    if ($pickupAtColumn !== null) {
                        $setParts[] = "`{$pickupAtColumn}` = COALESCE(`{$pickupAtColumn}`, NOW())";
                    }

                    $pickupSql = 'UPDATE reservations SET ' . implode(', ', $setParts) . ' WHERE reservation_id = ?';
                    $pickupStmt = mysqli_prepare($link, $pickupSql);
                    if (!$pickupStmt) {
                        throw new RuntimeException('更新取件狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($pickupStmt, 'i', $reservationId);
                    mysqli_stmt_execute($pickupStmt);
                    mysqli_stmt_close($pickupStmt);

                    $actionMsg = '已確認取件。';
                }

                if ($action === 'confirm_return') {
                    $setParts = [];
                    if ($returnFlagColumn !== null) {
                        $setParts[] = "`{$returnFlagColumn}` = 1";
                    }
                    if ($returnAtColumn !== null) {
                        $setParts[] = "`{$returnAtColumn}` = COALESCE(`{$returnAtColumn}`, NOW())";
                    }

                    $returnSql = 'UPDATE reservations SET ' . implode(', ', $setParts) . ' WHERE reservation_id = ?';
                    $returnStmt = mysqli_prepare($link, $returnSql);
                    if (!$returnStmt) {
                        throw new RuntimeException('更新歸還狀態失敗：' . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($returnStmt, 'i', $reservationId);
                    mysqli_stmt_execute($returnStmt);
                    mysqli_stmt_close($returnStmt);

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

                    $actionMsg = '已確認歸還，器材可借狀態已回復。';
                }

                mysqli_commit($link);
            }
        } catch (Throwable $e) {
            mysqli_rollback($link);
            $actionMsg = '處理失敗：' . $e->getMessage();
        }
    }

    if ($dbError === '') {
        $pickupAtSelect = $pickupAtColumn !== null ? "r.`{$pickupAtColumn}`" : 'NULL';
        $returnAtSelect = $returnAtColumn !== null ? "r.`{$returnAtColumn}`" : 'NULL';

        $listWhere = "r.approval_status = 'approved'";
        if (!$isManager) {
            $safeUserId = mysqli_real_escape_string($link, $currentUserId);
            $listWhere .= " AND r.`{$applicantColumn}` = '{$safeUserId}'";
        }

        $listSql = "
            SELECT
                r.reservation_id,
                r.`{$applicantColumn}` AS applicant_user_id,
                u.full_name,
                u.email,
                r.`{$borrowStartColumn}` AS borrow_start_at,
                r.`{$borrowEndColumn}` AS borrow_end_at,
                r.approval_status,
                ({$pickupSqlExpr}) AS pickup_confirmed,
                {$pickupAtSelect} AS pickup_confirmed_at,
                ({$returnedSqlExpr}) AS return_confirmed,
                {$returnAtSelect} AS return_confirmed_at,
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
                ) AS space_ids
            FROM reservations r
            JOIN users u ON u.user_id = r.`{$applicantColumn}`
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
                <?php if ($isManager) { ?>
                    <button class="nav-btn" onclick="location.href='approve.php'">審核面板</button>
                <?php } ?>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card">
                <h2><?php echo $isManager ? '借還確認管理' : '我的申請紀錄'; ?></h2>

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
                                    <th>是否已報到</th>
                                    <th>歸還</th>
                                    <?php if ($isManager) { ?>
                                        <th>操作</th>
                                    <?php } ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($rows) === 0) { ?>
                                    <tr><td colspan="<?php echo $isManager ? '6' : '5'; ?>"><?php echo $isManager ? '目前沒有可管理的已核准借用資料。' : '目前沒有可顯示的申請資料。'; ?></td></tr>
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
                                            <td><?php echo htmlspecialchars($resourceText, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <span class="return-status <?php echo $isPickup ? 'return-status-ok' : 'return-status-pending'; ?>">
                                                    <?php echo $isPickup ? '已報到' : '未報到'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="return-status <?php echo $isReturned ? 'return-status-ok' : 'return-status-pending'; ?>">
                                                    <?php echo $isReturned ? '已歸還' : '未歸還'; ?>
                                                </span>
                                            </td>
                                            <?php if ($isManager) { ?>
                                                <td>
                                                    <div class="return-action-buttons">
                                                        <?php if (!$isPickup) { ?>
                                                            <form method="post">
                                                                <input type="hidden" name="action" value="confirm_pickup">
                                                                <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                                <button type="submit" class="btn-primary">確認取件</button>
                                                            </form>
                                                        <?php } ?>

                                                        <?php if (!$isReturned) { ?>
                                                            <form method="post">
                                                                <input type="hidden" name="action" value="confirm_return">
                                                                <input type="hidden" name="reservation_id" value="<?php echo (int)$row['reservation_id']; ?>">
                                                                <button type="submit" class="btn-secondary" onclick="return confirm('確認此申請已歸還？')">確認歸還</button>
                                                            </form>
                                                        <?php } ?>
                                                    </div>
                                                </td>
                                            <?php } ?>
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
