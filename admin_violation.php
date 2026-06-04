<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// TODO: 這裡要引入你原本的資料庫連接與權限檢查
// 範例：確認是管理員或課指組老師才能進來
// if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'staff') { die('權限不足'); }

require_once __DIR__ . '/config/database.php';

$dbError = '';
$link = getMysqliConnection($dbError);

$searchKeyword = trim((string)($_GET['search'] ?? ''));
$foundUser = null;
$actionMsg = '';
$errorMsg = '';

// ====== 1. 處理搜尋邏輯 (允許多筆結果版) ======
$foundUsers = []; // 💡 改用陣列儲存多筆
if ($dbError === '' && $searchKeyword !== '') {
    $safeKeyword = mysqli_real_escape_string($link, $searchKeyword);
    
    $whereClause = "u.full_name LIKE '%{$safeKeyword}%' 
                 OR u.user_id LIKE '%{$safeKeyword}%' 
                 OR ec.certificate_id LIKE '%{$safeKeyword}%'";
    
    $searchSql = "
        SELECT u.user_id, u.full_name, ec.certificate_id,
               (SELECT COALESCE(SUM(points), 0) FROM violation_logs WHERE user_id = u.user_id) as total_points
        FROM equipment_certificates ec
        JOIN users u ON ec.holder_id = u.user_id
        WHERE {$whereClause}
        ORDER BY 
            CASE 
                WHEN ec.certificate_id = '{$safeKeyword}' THEN 1
                WHEN ec.certificate_id LIKE '{$safeKeyword}%' THEN 2
                WHEN u.user_id = '{$safeKeyword}' THEN 3
                ELSE 4
            END ASC
        LIMIT 20 -- 💡 放大限制，最多顯示 20 筆符合的學生
    ";
    
    $result = mysqli_query($link, $searchSql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $foundUsers[] = $row; // 💡 把所有符合的學生塞進陣列
        }
    }
    
    if (empty($foundUsers)) {
        $errorMsg = '找不到符合條件的學生。請注意：該學生必須已辦理「器材證」才能在此查到。';
    }
}

// 💡 另外接收老師點選了哪一個特定的學號
$selectedUserId = trim((string)($_GET['select_user'] ?? ''));
$foundUser = null;
if (!empty($foundUsers)) {
    if ($selectedUserId !== '') {
        // 如果老師有點選特定學生，就從陣列裡找出來
        foreach ($foundUsers as $user) {
            if ($user['user_id'] === $selectedUserId) {
                $foundUser = $user;
                break;
            }
        }
    } else {
        // 如果老師剛搜尋完、還沒點選，且結果「剛好只有一個」，就自動選取
        if (count($foundUsers) === 1) {
            $foundUser = $foundUsers[0];
        }
    }
}

// ====== 2. 處理記點提交邏輯 ======
if ($dbError === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $targetUserId = trim((string)($_POST['target_user_id'] ?? ''));
    $reasonRule = trim((string)($_POST['reason_rule'] ?? ''));
    $customReason = trim((string)($_POST['custom_reason'] ?? ''));
    
    // 根據老師選擇的規則，自動判定點數
    $points = 0;
    if ($reasonRule === 'rule_1') $points = 1;
    elseif ($reasonRule === 'rule_2') $points = 2;
    elseif ($reasonRule === 'rule_3') $points = 3;
    elseif ($reasonRule === 'rule_other') $points = 1; // 其他通常先預設1點，或依情節另計
    elseif ($reasonRule === 'rule_cancel') $points = 0; // 註銷不計點，直接處理證件狀態

    if ($targetUserId !== '' && $reasonRule !== '') {
        mysqli_begin_transaction($link);
        try {
            // 寫入違規紀錄表
            $insertSql = "INSERT INTO violation_logs (user_id, points, reason_category, custom_reason, created_by) VALUES (?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($link, $insertSql);
            $teacherId = $_SESSION['user_id'] ?? 'SYSTEM';
            mysqli_stmt_bind_param($stmt, 'sisss', $targetUserId, $points, $reasonRule, $customReason, $teacherId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 如果選擇的是「註銷」，同時去把該使用者的器材證狀態改成註銷/失效
            if ($reasonRule === 'rule_cancel') {
                // 假設你的證件狀態欄位叫做 status
                $updateCertSql = "UPDATE equipment_certificates SET status = 'cancelled' WHERE holder_id = ?";
                $stmtCert = mysqli_prepare($link, $updateCertSql);
                mysqli_stmt_bind_param($stmtCert, 's', $targetUserId);
                mysqli_stmt_execute($stmtCert);
                mysqli_stmt_close($stmtCert);
                $actionMsg = '已成功登記违規紀錄，並註銷該申請人之器材證！';
            } else {
                $actionMsg = "成功記點！已對該使用者登錄 {$points} 點處分。";
            }
            
            mysqli_commit($link);
            
            // 重新整理該使用者畫面資料
            if ($searchKeyword !== '') {
                header("Location: admin_violation.php?search=" . urlencode($searchKeyword) . "&msg=" . urlencode($actionMsg));
                exit;
            }
        } catch (Throwable $e) {
            mysqli_rollback($link);
            $errorMsg = '系統處理失敗：' . $e->getMessage();
        }
    }
}

// 接收轉跳後的成功訊息
if (isset($_GET['msg'])) {
    $actionMsg = (string)$_GET['msg'];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>課指組違規記點管理</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen">

    <?php include __DIR__ . '/nav.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-10">
        <header class="mb-8">
            <h1 class="text-2xl font-bold text-slate-800"><i class="fa-solid fa-triangle-exclamation text-amber-500 mr-2"></i>課指組資源管理：違規記點系統</h1>
            <p class="text-slate-500 text-sm mt-1">請輸入學生資訊進行查詢，並依校方規範執行記點或註銷處分。</p>
        </header>

        <?php if ($actionMsg !== ''): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6 shadow-sm flex items-center gap-2">
                <i class="fa-solid fa-circle-check text-emerald-500"></i> <?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMsg !== ''): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl mb-6 shadow-sm flex items-center gap-2">
                <i class="fa-solid fa-circle-xmark text-rose-500"></i> <?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <section class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm mb-6">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">第一步：搜尋對象 (支援：姓名 / 學號 / 器材證號)</h2>
            <form method="GET" action="" class="flex gap-2">
                <div class="relative flex-1">
                    <i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                    <input type="text" name="search" required
                           placeholder="請輸入學生姓名、學號或器材證 ID..." 
                           value="<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>" 
                           class="w-full pl-11 pr-4 py-3 border border-slate-300 rounded-xl focus:outline-none focus:border-indigo-500 text-slate-800 transition shadow-sm">
                </div>
                <button type="submit" class="px-6 py-3 bg-slate-800 hover:bg-slate-900 text-white rounded-xl font-medium transition shadow-sm">
                    查詢資料
                </button>
            </form>
        </section>

            <?php if (count($foundUsers) > 1): ?>
    <section class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm mb-6">
        <h3 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-users text-indigo-500 mr-1"></i> 找到多筆符合的學生，請點擊選擇：</h3>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($foundUsers as $user): ?>
                <?php 
                    $isSelected = ($selectedUserId === $user['user_id'] || (count($foundUsers) === 1)); 
                    $btnClass = $isSelected 
                        ? 'bg-indigo-600 text-white border-indigo-600 shadow-sm' 
                        : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100';
                ?>
                <a href="admin_violation.php?search=<?php echo urlencode($searchKeyword); ?>&select_user=<?php echo urlencode($user['user_id']); ?>" 
                   class="px-4 py-2 rounded-xl border text-sm font-medium transition flex items-center gap-2 <?php echo $btnClass; ?>">
                    <i class="fa-solid fa-user-id-card opacity-70"></i>
                    <span><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($user['user_id'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                    <span class="text-xs px-2 py-0.5 rounded-md <?php echo $isSelected ? 'bg-indigo-700 text-indigo-200' : 'bg-slate-200 text-slate-500'; ?>">
                        證:<?php echo htmlspecialchars($user['certificate_id'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

        <?php if ($foundUser): ?>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block mb-1">Student Profile</span>
                        <h3 class="text-xl font-bold text-slate-800 mb-4"><?php echo htmlspecialchars($foundUser['full_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        
                        <div class="space-y-3 text-sm text-slate-600">
                            <p><strong class="text-slate-400">學號/帳號：</strong><br><?php echo htmlspecialchars($foundUser['user_id'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong class="text-slate-400">器材證編號：</strong><br><?php echo htmlspecialchars($foundUser['certificate_id'] ?? '未辦理器材證', ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>

                    <div class="mt-6 pt-4 border-t border-slate-100">
                        <p class="text-xs text-slate-400 font-medium mb-1">目前已累積違規點數</p>
                        <span class="text-3xl font-black <?php echo $foundUser['total_points'] >= 3 ? 'text-rose-600' : 'text-amber-500'; ?>">
                            <?php echo (int)$foundUser['total_points']; ?> <span class="text-sm font-normal text-slate-500">點</span>
                        </span>
                    </div>
                </div>

                <div class="md:col-span-2 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
                    <h3 class="text-lg font-bold text-slate-800 mb-4">第二步：登記違規處分</h3>
                    
                    <form method="POST" action="" onsubmit="return confirm('確定要對該學生發佈此處分紀錄嗎？');">
                        <input type="hidden" name="action_type" value="submit_violation">
                        <input type="hidden" name="target_user_id" value="<?php echo htmlspecialchars($foundUser['user_id'], ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-slate-700 mb-2">違規事由與記點標準</label>
                            <select name="reason_rule" id="reason_rule" required onchange="toggleCustomReason(this.value)"
                                    class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:outline-none focus:border-indigo-500 text-slate-800">
                                <option value="">-- 請選擇課指組官方違規條款 --</option>
                                <option value="rule_1">[1 點] 器材逾期領取或逾期歸還 / 未按時領取且未事先取消 / 電力完全耗盡</option>
                                <option value="rule_2">[2 點] 領取或歸還器材時，器材證持有人未親自到場</option>
                                <option value="rule_3">[3 點] 未於規定時間內辦理器材預約 (臨時預約)</option>
                                <option value="rule_other">[其他] 器材損壞、遺失或依情節另行記點 (需填寫下方備註)</option>
                                <option value="rule_cancel" class="text-rose-600 font-semibold">[註銷] 器材超過兩日未領/未還且未通知，直接註銷器材證</option>
                            </select>
                        </div>

                        <div class="mb-5" id="custom_reason_block">
                            <label class="block text-sm font-medium text-slate-700 mb-2">詳細事由備註 / 照價賠償說明</label>
                            <textarea name="custom_reason" rows="3" placeholder="若選擇「其他」或有損壞賠償細節，請在此處輸入詳細說明..."
                                      class="w-full px-4 py-2.5 border border-slate-300 rounded-xl text-sm focus:outline-none focus:border-indigo-500 text-slate-800"></textarea>
                        </div>

                        <button type="submit" class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl font-medium transition shadow-md">
                            確認送出處分
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleCustomReason(val) {
            const textarea = document.querySelector('textarea[name="custom_reason"]');
            if (val === 'rule_other') {
                textarea.required = true;
                textarea.placeholder = "【必填】請輸入器材損壞狀況、遺失物品名稱或照價賠償之具體協議內容...";
            } else {
                textarea.required = false;
                textarea.placeholder = "若有額外備註（如器材名稱、遲到多久等），可在此處輸入...";
            }
        }
    </script>
</body>
</html>