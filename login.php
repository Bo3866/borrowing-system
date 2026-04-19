<?php
declare(strict_types=1);

session_start();

function getSafeRedirectTarget(?string $next): string
{
    $allowedTargets = ['index.php', 'borrow.php', 'approve.php', 'return_management.php', 'checkin.php', 'qr_admin.php'];
    if ($next !== null && in_array($next, $allowedTargets, true)) {
        return $next;
    }

    return 'index.php';
}

$redirectTarget = getSafeRedirectTarget(isset($_GET['next']) ? (string)$_GET['next'] : null);

if (isset($_SESSION['user_id'])) {
    header('Location: ' . $redirectTarget);
    exit;
}

$loginError = '';
$userId = '';
$password = '';

$link = mysqli_connect('localhost', 'root', '12345678', 'borrowing_system');

if (!$link) {
    $loginError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($userId === '' || $password === '') {
            $loginError = '請輸入帳號與密碼。';
        } else {
            $statement = mysqli_prepare(
                $link,
                'SELECT user_id, full_name, role_name, email, password FROM users WHERE user_id = ? LIMIT 1'
            );

            if (!$statement) {
                $loginError = '登入查詢失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($statement, 's', $userId);
                mysqli_stmt_execute($statement);
                $result = mysqli_stmt_get_result($statement);
                $user = $result ? mysqli_fetch_assoc($result) : null;

                $isPasswordValid = false;
                if ($user && isset($user['password'])) {
                    $dbPassword = (string)$user['password'];
                    $isPasswordValid = password_verify($password, $dbPassword) || hash_equals($dbPassword, $password);
                }

                if ($user && $isPasswordValid) {
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role_name'] = $user['role_name'];
                    $_SESSION['email'] = $user['email'];

                    header('Location: ' . $redirectTarget);
                    exit;
                }

                $loginError = '帳號或密碼不正確，請再試一次。';
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
                <strong>user_id + password</strong>
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

            <form method="post" class="login-form" action="login.php?next=<?php echo urlencode($redirectTarget); ?>">
                <div class="form-group">
                    <label for="user_id">帳號 (user_id)</label>
                    <input
                        type="text"
                        id="user_id"
                        name="user_id"
                        placeholder="請輸入 user_id"
                        value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">密碼 (password)</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="請輸入密碼"
                        required
                    >
                </div>

                <button type="submit" class="btn-primary login-button">登入</button>
            </form>
        </section>
    </main>
</body>
</html>