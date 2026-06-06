<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 1. 引入你的資料庫連線定義檔
require_once __DIR__ . '/config/database.php';

// 權限檢查：只有課指組老師 (role_name = 3) 可以進入
$currentRole = (string)($_SESSION['role_name'] ?? '');
if ($currentRole !== '3') {
    die("權限不足：只有課指組老師可以存取此頁面。");
}

$message = '';
$searchRows = [];

// 💡 這裡就是我們在 PHP 定義的「虛擬對應表」
$roleMap = [
    '1' => '學生',
    '3' => '課指組老師',
    'a' => '輔導人員',
    'b' => '軍訓室教師',
    'c' => '學務長老師',
    '8' => '工友',
    '9' => '工讀生'
];

try {
    // 呼叫 database.php 寫好的函式來取得 PDO 連線物件
    $db = getDatabaseConnection(); 
} catch (Throwable $e) {
    die("系統連線失敗：" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

// 處理「指定身份」的提交 (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_role') {
    $targetUserId = trim((string)($_POST['user_id'] ?? ''));
    $newRole = trim((string)($_POST['new_role'] ?? ''));

    if (array_key_exists($newRole, $roleMap) && $targetUserId !== '') {
        try {
            $updateSql = "UPDATE users SET role_name = :role_name WHERE user_id = :user_id";
            $stmt = $db->prepare($updateSql);
            $result = $stmt->execute([
                ':role_name' => $newRole,
                ':user_id' => $targetUserId
            ]);

            if ($result) {
                $message = "<div class='alert success'>成功！已將使用者 " . htmlspecialchars($targetUserId, ENT_QUOTES, 'UTF-8') . " 的身份更新為 {$roleMap[$newRole]}。</div>";
            }
        } catch (PDOException $e) {
            $message = "<div class='alert danger'>更新失敗：" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
        }
    }
}

// 處理「搜尋使用者」 (GET)
$searchKeyword = trim((string)($_GET['search'] ?? ''));
if ($searchKeyword !== '') {
    try {
        $sql = "SELECT user_id, full_name, role_name FROM users 
                WHERE user_id LIKE :keyword1 OR full_name LIKE :keyword2 
                LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':keyword1' => "%{$searchKeyword}%",
            ':keyword2' => "%{$searchKeyword}%"
        ]);
        $searchRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = "<div class='alert danger'>搜尋失敗：" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>指定使用者身份 - 校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
    
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f3f4f6;
            color: #1f2937;
            margin: 0;
        }
        .main-content.container {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px;
        }
        .card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            padding: 24px;
            margin-bottom: 20px;
        }
        .card h2, .card h3 {
            margin-top: 0;
            color: #111827;
        }
        
        /* 搜尋區塊專用輸入框 */
        .page-search-input {
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            width: 260px;
            outline: none;
        }
        .page-search-input:focus {
            border-color: #2998e4; /* 焦點換成天空藍 */
        }

        /* 🔵 下方按鈕：改為你指定的天空藍色（不影響導覽列） */
        .page-action-btn {
            background-color: #2998e4; /* 圖片對應的天空藍色 */
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.15s;
        }
        .page-action-btn:hover {
            background-color: #1d82cb; /* 滑鼠懸停時稍微深一點的天空藍 */
        }

        /* 提示訊息 */
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-top: 12px;
            font-weight: 500;
        }
        .alert.success { background-color: #def7ec; color: #03543f; border: 1px solid #bcf0da; }
        .alert.danger { background-color: #fde8e8; color: #9b1c1c; border: 1px solid #fbd5d5; }

        /* 📊 表格格子完美對齊設定 */
        .user-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            background: #fff;
        }
        .user-table th, .user-table td {
            padding: 14px 16px; 
            border-bottom: 1px solid #e5e7eb;
            font-size: 15px;
            vertical-align: middle;
        }
        .user-table th {
            background-color: #f9fafb;
            color: #374151;
            font-weight: 600;
        }
        .user-table tr:hover {
            background-color: #f9fafb;
        }
        
        /* 水平置中、置左對齊 */
        .user-table .left { text-align: left; }
        .user-table .center { text-align: center; }
        
        /* 內頁表格下拉選單 */
        .page-role-select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background-color: #fff;
            color: #374151;
            font-size: 14px;
            outline: none;
            cursor: pointer;
        }
    </style>
</head>
<body>

<?php include 'nav.php'; ?>

<main class="container main-content">
    <section class="card">
        <h2>👤 指定使用者身份</h2>
        <p style="color: #4b5563;">您可以搜尋學號或姓名，並手動調整使用者的系統權限。</p>

        <?php echo $message; ?>

        <div class="search-box" style="margin-top:16px;">
            <form method="GET" action="" style="display:flex; gap:8px; align-items:center;">
                <input type="text" name="search" class="page-search-input" placeholder="輸入學號或姓名..." value="<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="page-action-btn">搜尋使用者代碼</button>
            </form>
        </div>
    </section>

    <?php if ($searchKeyword !== ''): ?>
        <section class="card" style="margin-top:16px;">
            <h3>搜尋結果：</h3>
            <?php if (!empty($searchRows)): ?>
                <div style="overflow:auto;">
                <table class="user-table">
                    <thead>
                        <tr>
                            <th class="left">學號/帳號</th>
                            <th class="left">姓名</th>
                            <th class="center">目前身份</th>
                            <th class="center">變更身份</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchRows as $user): ?>
                            <tr>
                                <td class="left"><?php echo htmlspecialchars((string)$user['user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="left"><?php echo htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center">
                                    <?php 
                                        $code = (string)$user['role_name'];
                                        echo htmlspecialchars($roleMap[$code] ?? "未知代碼 ({$code})", ENT_QUOTES, 'UTF-8'); 
                                    ?>
                                </td>
                                <td class="center action-cell">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="update_role">
                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string)$user['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <div style="display:flex; gap:8px; align-items:center; justify-content:center;">
                                            <select name="new_role" class="page-role-select" aria-label="變更身份">
                                                <?php foreach ($roleMap as $code => $name): ?>
                                                    <option value="<?php echo $code; ?>" <?php echo ((string)$user['role_name'] === $code) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="page-action-btn" style="padding: 8px 14px;">更新</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p style="color: #6b7280;">找不到符合「<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>」的使用者。</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

</body>
</html>