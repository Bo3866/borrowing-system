<?php
declare(strict_types=1);
session_start();

// 1. 引入資料庫連線
require_once __DIR__ . '/config/database.php';

// 2. 權限檢查（僅限登入人員）
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$dbError = '';
$link = getMysqliConnection($dbError);

$pageError = '';
$pageSuccess = $_SESSION['success_msg'] ?? '';
unset($_SESSION['success_msg']); 

// 獲取搜尋關鍵字
$searchId = trim((string)($_GET['search_id'] ?? ''));

// 【處理 A：一鍵賦予器材證的 POST 請求】
if ($link && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_grant') {
    $targetUserId = trim((string)($_POST['target_user_id'] ?? ''));
    
    if ($targetUserId !== '') {
        $issueDate = date('Y-m-d H:i:s');
        $validUntil = date('Y-m-d H:i:s', strtotime('+1 year'));

        $insertSql = "INSERT INTO equipment_certificates (holder_id, issue_date, valid_until) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($link, $insertSql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sss', $targetUserId, $issueDate, $validUntil);
            if (mysqli_stmt_execute($stmt)) {
                $newCertId = mysqli_insert_id($link);
                $_SESSION['success_msg'] = "🎉 成功賦予使用者【{$targetUserId}】全新器材證！證照編號：第 {$newCertId} 號。";
                header('Location: ' . $_SERVER['PHP_SELF'] . '?search_id=' . urlencode($searchId));
                exit;
            } else {
                $pageError = '賦予器材證失敗：' . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// 【處理 B：處理手動銷點的 POST 請求】
if ($link && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reduce_points') {
    $targetUserId = trim((string)($_POST['target_user_id'] ?? ''));
    $reducePoints = (int)($_POST['reduce_points'] ?? 0);
    
    if ($targetUserId !== '' && $reducePoints > 0) {
        $customReason = "[系統銷點] 管理員手動撤銷點數";
        
        // 💡 修正：已移除 log_date 欄位，僅寫入 3 個必須欄位
        $insertLogSql = "INSERT INTO violation_logs (user_id, points, custom_reason) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($link, $insertLogSql);
        
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'sis', $targetUserId, $reducePoints, $customReason);
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success_msg'] = "成功為使用者【{$targetUserId}】新增一筆 {$reducePoints} 點的銷點紀錄！";
                header('Location: ' . $_SERVER['PHP_SELF'] . '?search_id=' . urlencode($searchId));
                exit;
            } else {
                $pageError = '銷點失敗：' . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// 存放最終清單
$userListWithCertStatus = []; 

if ($link && $searchId !== '') {
    $sql = "SELECT 
                u.user_id, 
                u.full_name, 
                u.email,
                ec.certificate_id,
                ec.valid_until,
                IFNULL(v.total_points, 0) AS total_points,
                NOW() AS current_db_time
            FROM users u
            LEFT JOIN (
                SELECT certificate_id, holder_id, valid_until
                FROM equipment_certificates 
                WHERE (holder_id, valid_until) IN (
                    SELECT holder_id, MAX(valid_until) FROM equipment_certificates GROUP BY holder_id
                )
            ) ec ON u.user_id = ec.holder_id
            LEFT JOIN (
                -- 統計累計點數：當 custom_reason 為開頭 [系統銷點] 時，在加總中轉為負數做抵銷
                SELECT user_id, 
                       GREATEST(SUM(CASE WHEN custom_reason LIKE '[系統銷點]%' THEN -points ELSE points END), 0) AS total_points 
                FROM violation_logs 
                GROUP BY user_id
            ) v ON u.user_id = v.user_id
            WHERE u.user_id LIKE ? OR u.full_name LIKE ?
            ORDER BY u.user_id ASC";
    
    $userStmt = mysqli_prepare($link, $sql);
    if ($userStmt) {
        $likeSearch = "%" . $searchId . "%";
        mysqli_stmt_bind_param($userStmt, 'ss', $likeSearch, $likeSearch);
        mysqli_stmt_execute($userStmt);
        $userResult = mysqli_stmt_get_result($userStmt);
        
        if ($userResult) {
            while ($row = mysqli_fetch_assoc($userResult)) {
                if (empty($row['valid_until'])) {
                    $row['cert_status'] = 'NONE'; 
                } else {
                    $validTime = strtotime($row['valid_until']);
                    $nowTime = strtotime($row['current_db_time']);
                    $row['cert_status'] = ($nowTime <= $validTime) ? 'VALID' : 'EXPIRED';
                }
                $userListWithCertStatus[] = $row;
            }
        }
        mysqli_stmt_close($userStmt);
    }
    mysqli_close($link);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>快速核發與管理器材證</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <style>
        .form-box { max-width: 1050px; margin: 2rem auto; }
        .form-group { margin-bottom: 1.2rem; }
        .form-group label { display: block; margin-bottom: 0.4rem; font-weight: bold; color: #334155; }
        .form-control { width: 100%; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        .search-row { display: flex; gap: 0.5rem; width: 100%;}
        .search-row .form-control { flex: 1; }
        .search-row button { 
            width: 110px; /* 嚴格限制搜尋按鈕的寬度（變短） */
            flex-shrink: 0; /* 防止按鈕被彈性佈局壓縮變形 */
            padding: 0; /* 移除內邊距改用固定寬度控制 */
            background: #0f766e; 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: bold; 
        }
        .divider { border-top: 1px solid #e2e8f0; margin: 2rem 0; }
        .result-table { width: 100%; border-collapse: collapse; margin-top: 1rem; font-size: 14px; }
        .result-table th, .result-table td { padding: 0.8rem; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        .result-table th { background: #f1f5f9; color: #475569; }
        
        .badge { padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 12px; font-weight: bold; display: inline-block; }
        .badge-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .badge-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .badge-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .point-alert { color: #dc2626; font-weight: bold; }
        .point-safe { color: #64748b; }
        .text-danger-bold { color: #b91c1c; font-weight: bold; font-size: 13px; }
        
        .btn-grant { background: #2563eb; color: white; border: none; padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; }
        .btn-grant:hover { background: #1d4ed8; }
        
        .reduce-box { display: flex; gap: 0.3rem; align-items: center; }
        .select-sm { padding: 0.3rem; font-size: 12px; border-radius: 4px; border: 1px solid #cbd5e1; }
        .btn-reduce { background: #ea580c; color: white; border: none; padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 12px; font-weight: bold; cursor: pointer; }
        .btn-reduce:hover { background: #c2410c; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/nav.php'; ?>

    <div class="container">
        <main class="main-content">
            <section class="card form-box">
                <h2>快速檢查、核發與點數銷退管理</h2>

                <?php if ($pageSuccess !== '') { ?>
                    <div class="borrow-success" style="margin-bottom: 1rem; color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; padding: 0.75rem; border-radius: 4px;"><?php echo htmlspecialchars($pageSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if ($pageError !== '') { ?>
                    <div class="login-alert" style="margin-bottom: 1rem; color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 0.75rem; border-radius: 4px;"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <form method="GET" action="">
                    <div class="form-group">
                        <label for="search_id">請輸入搜尋關鍵字（學號或姓名）</label>
                        <div class="search-row">
                            <input type="text" id="search_id" name="search_id" class="form-control" required value="<?php echo htmlspecialchars($searchId, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit">搜尋</button>
                        </div>
                    </div>
                </form>

                <?php if (!empty($userListWithCertStatus)) { ?>
                    <div class="divider"></div>
                    <table class="result-table">
                        <thead>
                            <tr>
                                <th>學號/工號</th>
                                <th>姓名</th>
                                <th>目前截止日期</th>
                                <th>目前記點</th>
                                <th>銷點功能</th>
                                <th>狀態檢查</th>
                                <th>快捷操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userListWithCertStatus as $user) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $user['valid_until'] ? htmlspecialchars($user['valid_until'], ENT_QUOTES, 'UTF-8') : '<span style="color:#94a3b8;">--</span>'; ?></td>
                                    <td>
                                        <?php if ($user['total_points'] > 0) { ?>
                                            <span class="point-alert">⚠️ <?php echo htmlspecialchars((string)$user['total_points'], ENT_QUOTES, 'UTF-8'); ?> 點</span>
                                        <?php } else { ?>
                                            <span class="point-safe">0 點</span>
                                        <?php } ?>
                                    </td>
                                    
                                    <td>
                                        <?php if ($user['total_points'] > 0) { ?>
                                            <form method="POST" action="" class="reduce-box" style="margin:0;">
                                                <input type="hidden" name="action" value="reduce_points">
                                                <input type="hidden" name="target_user_id" value="<?php echo htmlspecialchars($user['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <select name="reduce_points" class="select-sm" required>
                                                    <?php 
                                                    for ($i = 1; $i <= min($user['total_points'], 5); $i++) {
                                                        echo "<option value='{$i}'>銷 {$i} 點</option>";
                                                    }
                                                    ?>
                                                </select>
                                                <button type="submit" class="btn-reduce" onclick="return confirm('確定要為此學生進行手動銷點嗎？\n系統將寫入一筆抵銷日誌。');">確定</button>
                                            </form>
                                        <?php } else { ?>
                                            <span style="color:#94a3b8; font-size:12px;">無點可銷</span>
                                        <?php } ?>
                                    </td>
                                    
                                    <td>
                                        <?php if ($user['cert_status'] === 'VALID') { ?>
                                            <span class="badge badge-success">✓ 有器材證</span>
                                        <?php } elseif ($user['cert_status'] === 'EXPIRED') { ?>
                                            <span class="badge badge-warning">⏳ 已過期</span>
                                        <?php } else { ?>
                                            <span class="badge badge-danger">❌ 無器材證</span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <?php if ($user['total_points'] >= 3) { ?>
                                            <span class="text-danger-bold">🛑 無法借用 (已達懲戒標準)</span>
                                        <?php } else { ?>
                                            <?php if ($user['cert_status'] === 'EXPIRED' || $user['cert_status'] === 'NONE') { ?>
                                                <form method="POST" action="" style="margin:0;">
                                                    <input type="hidden" name="action" value="quick_grant">
                                                    <input type="hidden" name="target_user_id" value="<?php echo htmlspecialchars($user['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <button type="submit" class="btn-grant" onclick="return confirm('確定要核發器材證嗎？');">➕ 核發器材證</button>
                                                </form>
                                            <?php } else { ?>
                                                <span style="color: #166534; font-size: 12px; font-weight: bold;">✓ 資格正常</span>
                                            <?php } ?>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } ?>
            </section>
        </main>
    </div>
</body>
</html>