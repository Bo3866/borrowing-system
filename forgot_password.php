<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (!function_exists('sendForgotPasswordVerificationCode')) {
    function sendForgotPasswordVerificationCode(string $toEmail, string $toName, string $code): void
    {
        require_once __DIR__ . '/config/mail.php';
        require_once __DIR__ . '/lib/PHPMailer/Exception.php';
        require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

        if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
            throw new RuntimeException('郵件設定未啟用或未完成，請檢查 config/mail.php');
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $MAIL_USERNAME;
        $mail->Password = $MAIL_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = '【系統驗證碼】忘記密碼驗證碼';
        $mail->Body = "您好，{$toName}：<br><br>您正在申請重設密碼，驗證碼如下：<br><br><div style='font-size:24px;font-weight:700;letter-spacing:4px;'>" . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . "</div><br>此驗證碼 10 分鐘內有效，請回到系統輸入後再設定新密碼。<br><br>若非本人操作，請忽略此信件。";
        $mail->AltBody = "您好，{$toName}：\n\n您正在申請重設密碼，驗證碼如下：\n\n{$code}\n\n此驗證碼 10 分鐘內有效，請回到系統輸入後再設定新密碼。\n\n若非本人操作，請忽略此信件。";
        $mail->send();
    }
}

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$forgotPasswordState = $_SESSION['forgot_password_state'] ?? null;
$resetError = '';
$resetSuccess = '';
$userId = '';
$email = '';
$userName = '';
$showCodeForm = false;
$showPasswordForm = false;

$linkError = '';
$link = getMysqliConnection($linkError);

if (!$link) {
    $resetError = '資料庫連線失敗：' . $linkError;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'send_code') {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($userId === '' || $email === '') {
            $resetError = '請輸入帳號與電子郵件。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $resetError = '請輸入有效的電子郵件。';
        } else {
            $statement = mysqli_prepare($link, 'SELECT user_id, full_name, email FROM users WHERE user_id = ? AND email = ? LIMIT 1');

            if (!$statement) {
                $resetError = '查詢失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($statement, 'ss', $userId, $email);
                mysqli_stmt_execute($statement);
                $result = mysqli_stmt_get_result($statement);
                $user = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($statement);

                if (!$user) {
                    $resetError = '找不到符合的帳號與電子郵件。';
                } else {
                    $verificationCode = (string)random_int(100000, 999999);
                    $state = [
                        'user_id' => $userId,
                        'email' => $email,
                        'full_name' => (string)($user['full_name'] ?? ''),
                        'code_hash' => password_hash($verificationCode, PASSWORD_DEFAULT),
                        'code_expires_at' => time() + 600,
                        'code_verified' => false,
                        'attempts' => 0,
                    ];

                    try {
                        sendForgotPasswordVerificationCode($email, $state['full_name'] !== '' ? $state['full_name'] : $userId, $verificationCode);
                        $_SESSION['forgot_password_state'] = $state;
                        $forgotPasswordState = $state;
                        $showCodeForm = true;
                        $resetSuccess = '驗證碼已寄出，請到信箱查看後輸入。';
                    } catch (Throwable $throwable) {
                        $resetError = '驗證碼寄送失敗：' . $throwable->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'verify_code') {
        $code = trim((string)($_POST['verification_code'] ?? ''));
        $state = is_array($forgotPasswordState) ? $forgotPasswordState : null;

        if (!$state) {
            $resetError = '請先輸入帳號與電子郵件並取得驗證碼。';
        } elseif (($state['code_expires_at'] ?? 0) < time()) {
            unset($_SESSION['forgot_password_state']);
            $resetError = '驗證碼已過期，請重新取得驗證碼。';
        } elseif (($state['attempts'] ?? 0) >= 5) {
            unset($_SESSION['forgot_password_state']);
            $resetError = '驗證次數過多，請重新取得驗證碼。';
        } elseif ($code === '') {
            $resetError = '請輸入驗證碼。';
            $showCodeForm = true;
        } elseif (!password_verify($code, (string)($state['code_hash'] ?? ''))) {
            $state['attempts'] = (int)($state['attempts'] ?? 0) + 1;
            $_SESSION['forgot_password_state'] = $state;
            $forgotPasswordState = $state;
            $showCodeForm = true;

            if ($state['attempts'] >= 5) {
                unset($_SESSION['forgot_password_state']);
                $resetError = '驗證次數過多，請重新取得驗證碼。';
                $showCodeForm = false;
            } else {
                $resetError = '驗證碼不正確，請再試一次。';
            }
        } else {
            $state['code_verified'] = true;
            $state['verified_at'] = time();
            $_SESSION['forgot_password_state'] = $state;
            $forgotPasswordState = $state;
            $showPasswordForm = true;
            $resetSuccess = '驗證成功，請輸入新的密碼。';
        }
    } elseif ($action === 'reset_password') {
        $state = is_array($forgotPasswordState) ? $forgotPasswordState : null;
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!$state || empty($state['code_verified'])) {
            $resetError = '請先完成驗證碼確認。';
            $showCodeForm = true;
        } elseif (($state['code_expires_at'] ?? 0) < time()) {
            unset($_SESSION['forgot_password_state']);
            $resetError = '驗證流程已過期，請重新取得驗證碼。';
        } elseif ($newPassword === '' || $confirmPassword === '') {
            $resetError = '請輸入新密碼與確認密碼。';
            $showPasswordForm = true;
        } elseif ($newPassword !== $confirmPassword) {
            $resetError = '兩次輸入的新密碼不一致。';
            $showPasswordForm = true;
        } elseif (strlen($newPassword) < 4) {
            $resetError = '新密碼至少需要 4 碼。';
            $showPasswordForm = true;
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = mysqli_prepare($link, 'UPDATE users SET password = ? WHERE user_id = ? AND email = ? LIMIT 1');

            if (!$updateStmt) {
                $resetError = '更新密碼失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param(
                    $updateStmt,
                    'sss',
                    $hashedPassword,
                    $state['user_id'],
                    $state['email']
                );

                if (mysqli_stmt_execute($updateStmt)) {
                    unset($_SESSION['forgot_password_state']);
                    $forgotPasswordState = null;
                    $resetSuccess = '密碼已更新，現在可以使用新密碼登入。';
                    $userId = '';
                    $email = '';
                } else {
                    $resetError = '更新密碼失敗：' . mysqli_stmt_error($updateStmt);
                    $showPasswordForm = true;
                }

                mysqli_stmt_close($updateStmt);
            }
        }
    }
}

if (is_array($forgotPasswordState)) {
    $userId = (string)($forgotPasswordState['user_id'] ?? $userId);
    $email = (string)($forgotPasswordState['email'] ?? $email);
    $userName = (string)($forgotPasswordState['full_name'] ?? '');
    $showCodeForm = $showCodeForm || empty($forgotPasswordState['code_verified']);
    $showPasswordForm = $showPasswordForm || !empty($forgotPasswordState['code_verified']);
}

if (!$showCodeForm && !$showPasswordForm) {
    $showCodeForm = false;
    $showPasswordForm = false;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>忘記密碼｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body class="login-body">

    <?php include __DIR__ . '/nav.php'; ?>

    <div class="container login-container">
        <main class="main-content login-main auth-center">
        <section class="login-card">
            <h2>忘記密碼</h2>
            <p class="login-subtitle">先確認帳號與信箱，再輸入驗證碼後才能設定新密碼。</p>
            <p class="auth-form-hint">驗證碼會寄到註冊信箱，並在 10 分鐘內有效。</p>

            <?php if ($resetError !== '') { ?>
                <div class="login-alert"><?php echo htmlspecialchars($resetError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <?php if ($resetSuccess !== '') { ?>
                <div class="auth-success"><?php echo htmlspecialchars($resetSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <form method="post" class="login-form" action="forgot_password.php">
                <input type="hidden" name="action" value="send_code">
                <div class="form-group">
                    <label for="user_id">帳號 (user_id)</label>
                    <input type="text" id="user_id" name="user_id" placeholder="請輸入帳號" value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $showPasswordForm || $showCodeForm ? 'readonly' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="email">電子郵件</label>
                    <input type="email" id="email" name="email" placeholder="請輸入註冊信箱" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $showPasswordForm || $showCodeForm ? 'readonly' : ''; ?>>
                </div>

                <button type="submit" class="btn-primary login-button"><?php echo ($showCodeForm || $showPasswordForm) ? '重新發送驗證碼' : '發送驗證碼'; ?></button>
            </form>

            <?php if ($showCodeForm) { ?>
                <form method="post" class="login-form" action="forgot_password.php">
                    <input type="hidden" name="action" value="verify_code">

                    <div class="form-group">
                        <label for="verification_code">驗證碼</label>
                        <input type="text" id="verification_code" name="verification_code" placeholder="請輸入 6 碼驗證碼" inputmode="numeric" autocomplete="one-time-code" required>
                    </div>

                    <button type="submit" class="btn-primary login-button">驗證驗證碼</button>
                </form>
            <?php } ?>

            <?php if ($showPasswordForm) { ?>
                <form method="post" class="login-form" action="forgot_password.php">
                    <input type="hidden" name="action" value="reset_password">

                    <div class="form-group">
                        <label for="new_password">新密碼</label>
                        <input type="password" id="new_password" name="new_password" placeholder="請輸入新密碼" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">確認新密碼</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="再次輸入新密碼" required>
                    </div>

                    <button type="submit" class="btn-primary login-button">重設密碼</button>
                </form>
            <?php } ?>

            <a href="login.php" class="btn-secondary login-home-button">返回登入</a>
        </section>
        </main>
    </div>
</body>
</html>
