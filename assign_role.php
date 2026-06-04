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
    'a' => '學務長老師',
    'b' => '軍訓室教師',
    'c' => '輔導人員',
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
    <style>
        .container { max-width: 800px; margin: 2rem auto; padding: 0 1rem; font-family: sans-serif; }
        .search-box { background: #f4f4f9; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; }
        .user-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .user-table th, .user-table td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        .user-table th { background-color: #eee; }
        .alert { padding: 1rem; margin-bottom: 1rem; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .danger { background: #f8d7da; color: #721c24; }
        .role-select { padding: 5px; border-radius: 4px; }
        .btn-update { background: #28a745; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        .btn-update:hover { background: #218838; }
        .nav-btn { padding: 8px 12px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>

<?php include 'nav.php'; ?>

<div class="container">
    <h2>👤 指定使用者身份</h2>
    <p>您可以搜尋學號或姓名，並手動調整使用者的系統權限。</p>

    <?php echo $message; ?>

    <div class="search-box">
        <form method="GET" action="">
            <input type="text" name="search" placeholder="輸入學號或姓名..." value="<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 8px; width: 250px; border: 1px solid #ccc; border-radius: 4px;">
            <button type="submit" class="nav-btn">搜尋使用者代碼</button>
        </form>
    </div>

    <?php if ($searchKeyword !== ''): ?>
        <h3>搜尋結果：</h3>
        <?php if (!empty($searchRows)): ?>
            <table class="user-table">
                <thead>
                    <tr>
                        <th>學號/帳號</th>
                        <th>姓名</th>
                        <th>目前身份</th> <th>變更身份</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($searchRows as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)$user['user_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td>
                                <?php 
                                    // 💡 關鍵改動：透過剛才頂部的 $roleMap 對應表，將資料庫撈出來的 1、3、a 等代碼換成中文
                                    $code = (string)$user['role_name'];
                                    echo htmlspecialchars($roleMap[$code] ?? "未知代碼 ({$code})", ENT_QUOTES, 'UTF-8'); 
                                ?>
                            </td>
                            <td>
                                <form method="POST" style="display: flex; gap: 5px; margin: 0;">
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars((string)$user['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <select name="new_role" class="role-select">
                                        <?php foreach ($roleMap as $code => $name): ?>
                                            <option value="<?php echo $code; ?>" <?php echo ((string)$user['role_name'] === $code) ? 'selected' : ''; ?>>
                                                <?php echo $name; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-update">更新</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>找不到符合「<?php echo htmlspecialchars($searchKeyword, ENT_QUOTES, 'UTF-8'); ?>」的使用者。</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>