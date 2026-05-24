<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=drafts.php');
    exit;
}

$userId = (string)$_SESSION['user_id'];
$displayName = (string)($_SESSION['full_name'] ?? $_SESSION['user_id']);

$dbError = '';
$link = getMysqliConnection($dbError);

$message = '';
$messageType = 'success';

if ($dbError === '') {
    if (
        isset($_GET['action']) &&
        $_GET['action'] === 'delete' &&
        isset($_GET['draft_id'])
    ) {
        $draftId = (int)$_GET['draft_id'];

        $stmt = mysqli_prepare(
            $link,
            'DELETE FROM reservation_drafts WHERE draft_id = ? AND user_id = ?'
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $draftId, $userId);

            if (mysqli_stmt_execute($stmt)) {
                $message = '草稿已刪除。';
            } else {
                $message = '刪除草稿時發生錯誤。';
                $messageType = 'error';
            }

            mysqli_stmt_close($stmt);
        }
    }
}

$drafts = [];

if ($dbError === '') {
    $stmt = mysqli_prepare(
        $link,
        "SELECT
            draft_id,
            activity_name,
            purpose,
            proposal_original_name,
            proposal_uploaded_at,
            current_step,
            draft_data,
            created_at,
            updated_at
         FROM reservation_drafts
         WHERE user_id = ?
         ORDER BY updated_at DESC"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $userId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $decoded = json_decode($row['draft_data'] ?? '', true);
            $formData = $decoded['formData'] ?? [];

            $drafts[] = [
                'draft_id' => (int)$row['draft_id'],
                'activity_name' => $row['activity_name'] ?: '未填寫活動名稱',
                'purpose' => $row['purpose'] ?: '尚未填寫用途',
                'proposal_original_name' => $row['proposal_original_name'],
                'proposal_uploaded_at' => $row['proposal_uploaded_at'],
                'current_step' => $row['current_step'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'borrow_start_date' => $formData['borrow_start_date'] ?? '',
                'borrow_end_date' => $formData['borrow_end_date'] ?? '',
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
            <button class="nav-btn" type="button" disabled>
                <?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
        </div>
    </nav>

    <main class="main-content">
        <section class="borrow-page">
            <h2>我的草稿</h2>
            <p class="borrow-subtitle">這裡會顯示尚未正式送出的草稿。</p>

            <?php if ($dbError !== '') { ?>
                <div class="login-alert">
                    <?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php } elseif ($message !== '') { ?>
                <div class="borrow-success <?php echo $messageType === 'error' ? 'borrow-error' : ''; ?>">
                    <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php } ?>

            <?php if (empty($drafts)) { ?>
                <div class="card borrow-form-card" style="padding:24px;text-align:center;">
                    <h3>目前沒有草稿</h3>
                    <p>您可以前往借用申請頁面建立新的草稿。</p>
                    <button class="btn-primary" onclick="location.href='borrow.php'">前往借用申請</button>
                </div>
            <?php } else { ?>
                <div class="card borrow-form-card" style="padding:24px;overflow-x:auto;">
                    <table class="draft-table">
                        <thead>
                        <tr>
                            <th>草稿編號</th>
                            <th>活動名稱</th>
                            <th>用途</th>
                            <th>企劃書</th>
                            <th>目前步驟</th>
                            <th>最後更新</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($drafts as $draft) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($draft['draft_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($draft['activity_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($draft['purpose'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <?php if (!empty($draft['proposal_original_name'])) { ?>
                                        已上傳：
                                        <?php echo htmlspecialchars($draft['proposal_original_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php } else { ?>
                                        尚未上傳
                                    <?php } ?>
                                </td>
                                <td>第 <?php echo htmlspecialchars($draft['current_step'], ENT_QUOTES, 'UTF-8'); ?> 步</td>
                                <td><?php echo htmlspecialchars($draft['updated_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button class="btn-secondary"
                                            onclick="location.href='borrow.php?draft_id=<?php echo urlencode((string)$draft['draft_id']); ?>'">
                                        繼續編輯
                                    </button>

                                    <button class="btn-draft btn-draft-manage"
                                            onclick="if(confirm('確認要刪除此草稿嗎？')) location.href='drafts.php?action=delete&draft_id=<?php echo urlencode((string)$draft['draft_id']); ?>';">
                                        刪除
                                    </button>
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