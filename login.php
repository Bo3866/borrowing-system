<?php
declare(strict_types=1);

session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$loginError = '';
$userId = '';
$email = '';

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system', 3307);

if (!$link) {
    $loginError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($userId === '' || $email === '') {
            $loginError = '請輸入學號/員編與 Email。';
        } else {
            $statement = mysqli_prepare(
                $link,
                'SELECT user_id, full_name, role_name, email FROM users WHERE user_id = ? AND email = ? LIMIT 1'
            );

            if (!$statement) {
                $loginError = '登入查詢失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($statement, 'ss', $userId, $email);
                mysqli_stmt_execute($statement);
                $result = mysqli_stmt_get_result($statement);
                $user = $result ? mysqli_fetch_assoc($result) : null;

                if ($user) {
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role_name'] = $user['role_name'];
                    $_SESSION['email'] = $user['email'];

                    header('Location: index.php');
                    exit;
                }

                $loginError = '帳號或 Email 不正確，請再試一次。';
                mysqli_stmt_close($statement);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-body">
    <main class="login-shell">
        <section class="login-hero">
            <span class="hero-pill">CAMPUS RESOURCE HUB</span>
            <h1>校園資源租借系統</h1>
            <p>登入後即可查詢空間、器材與審核進度，讓申請流程集中在同一個系統完成。</p>
            <div class="login-highlight">
                <span>支援登入</span>
                <strong>學號 / 員編 + Email</strong>
            </div>
            <div class="login-highlight">
                <span>資料表</span>
                <strong>users</strong>
            </div>
        </section>

        <section class="login-card">
            <h2>登入系統</h2>
            <p class="login-subtitle">請使用資料庫中已建立的帳號登入。</p>

            <?php if ($loginError !== '') { ?>
                <div class="login-alert"><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <form method="post" class="login-form" action="login.php">
                <div class="form-group">
                    <label for="user_id">學號 / 員編</label>
                    <input
                        type="text"
                        id="user_id"
                        name="user_id"
                        placeholder="請輸入學號或員編"
                        value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="請輸入註冊 Email"
                        value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                </div>

                <button type="submit" class="btn-primary login-button">登入</button>
            </form>

            <div class="login-footer-note">
                目前 schema 沒有密碼欄位，所以這個版本先用學號/員編 + Email 驗證。
            </div>
        </section>
    </main>
</body>
</html>