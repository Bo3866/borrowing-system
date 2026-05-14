<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=drafts.php');
    exit;
}

$userId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);
$roleName = (string)($_SESSION['role_name'] ?? '');

$dbError = '';
$link = getMysqliConnection($dbError);

$message = '';
$messageType = 'success';

if ($dbError === '') {
    if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['draft_id']) && trim((string)$_GET['draft_id']) !== '') {
        $draftId = trim((string)$_GET['draft_id']);
        $stmt = mysqli_prepare($link, 'DELETE FROM reservations WHERE reservation_id = ? AND user_id = ? AND status = 0');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $draftId, $userId);
            if (mysqli_stmt_execute($stmt)) {
                $message = '草稿已刪除。';
                $messageType = 'success';
            } else {
                $message = '刪除草稿時發生錯誤。';
                $messageType = 'error';
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = '刪除草稿指令建立失敗。';
            $messageType = 'error';
        }
    }
}

$drafts = [];
if ($dbError === '') {
    $stmt = mysqli_prepare($link, 'SELECT reservation_id, created_at, updated_at, draft_data FROM reservations WHERE user_id = ? AND status = 0 ORDER BY updated_at DESC');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $draftData = json_decode($row['draft_data'] ?? '', true);
            if (!is_array($draftData)) {
                $draftData = [];
            }
            $summaryParts = [];
            if (!empty($draftData['space_id'])) {
                $summaryParts[] = '場地：' . (string)$draftData['space_id'];
            }
            if (!empty($draftData['cart_items']) && is_array($draftData['cart_items'])) {
                foreach ($draftData['cart_items'] as $item) {
                    if (isset($item['name'], $item['quantity'])) {
                        $summaryParts[] = (string)$item['name'] . ' x' . ((int)$item['quantity']);
                    }
                }
            }
            if (empty($summaryParts)) {
                $summaryParts[] = '尚未選擇借用項目';
            }
            $purposeText = trim((string)($draftData['purpose'] ?? ''));
            if ($purposeText === '') {
                $purposeText = '尚未填寫用途說明';
            }
            $drafts[] = [
                'reservation_id' => (int)$row['reservation_id'],
                'updated_at' => $row['updated_at'],
                'created_at' => $row['created_at'],
                'summary' => implode('；', $summaryParts),
                'purpose' => $purposeText,
                'borrow_date' => (string)($draftData['borrow_date'] ?? ''),
                'start_period_code' => (string)($draftData['start_period_code'] ?? ''),
                'end_period_code' => (string)($draftData['end_period_code'] ?? ''),
            ];
        }
        mysqli_stmt_close($stmt);
    } else {
        $dbError = '無法讀取草稿列表。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>草稿箱｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand">
                <h1>📋 草稿箱</h1>
            </div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='borrow.php'">回借用頁面</button>
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="borrow-page">
                <h2>我的草稿</h2>
                <p class="borrow-subtitle">這裡依照上次儲存時間顯示尚未送出的草稿。</p>

                <?php if ($dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } elseif ($message !== '') { ?>
                    <div class="borrow-success <?php echo $messageType === 'error' ? 'borrow-error' : ''; ?>">
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php } ?>

                <?php if (empty($drafts)) { ?>
                    <div class="card borrow-form-card" style="padding: 24px; text-align: center;">
                        <h3>目前沒有草稿</h3>
                        <p>您可以前往借用申請頁面建立新的草稿。</p>
                        <button class="btn-primary" onclick="location.href='borrow.php'">前往借用申請</button>
                    </div>
                <?php } else { ?>
                    <div class="card borrow-form-card" style="padding: 24px; overflow-x:auto;">
                        <table class="draft-table">
                            <thead>
                                <tr>
                                    <th>草稿編號</th>
                                    <th>最後更新</th>
                                    <th>借用摘要</th>
                                    <th>借用日期</th>
                                    <th>用途</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($drafts as $draft) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($draft['reservation_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($draft['updated_at'] ?: $draft['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($draft['summary'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($draft['borrow_date'] . ' ' . $draft['start_period_code'] . '～' . $draft['end_period_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($draft['purpose'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="display: flex; gap: 8px; flex-wrap: wrap;">
                                            <button class="btn-secondary" onclick="location.href='borrow.php?draft_id=<?php echo urlencode($draft['reservation_id']); ?>'">繼續編輯</button>
                                            <button class="btn-draft btn-draft-manage" onclick="if(confirm('確認要刪除此草稿嗎？')) location.href='drafts.php?action=delete&draft_id=<?php echo urlencode($draft['reservation_id']); ?>';">刪除</button>
                                        </td>
                                    </tr>
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
