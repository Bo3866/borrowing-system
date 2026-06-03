<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/config/database.php';

if (!function_exists('sendRegisterVerificationCode')) {
    function sendRegisterVerificationCode(string $toEmail, string $toName, string $code): void
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
        $mail->Subject = '【系統驗證碼】註冊驗證碼';
        $mail->Body = "您好，{$toName}：<br><br>您正在註冊帳號，驗證碼如下：<br><br><div style='font-size:24px;font-weight:700;letter-spacing:4px;'>" . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . "</div><br>此驗證碼 10 分鐘內有效，請回到系統輸入後再設定密碼。<br><br>若非本人操作，請忽略此信件。";
        $mail->AltBody = "您好，{$toName}：\n\n您正在註冊帳號，驗證碼如下：\n\n{$code}\n\n此驗證碼 10 分鐘內有效，請回到系統輸入後再設定密碼。\n\n若非本人操作，請忽略此信件。";
        $mail->send();
    }
}

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$registerState = $_SESSION['register_state'] ?? null;
$registerError = '';
$registerSuccess = '';
$userId = '';
$fullName = '';
$phone = '';
$derivedEmail = '';
$showCodeForm = false;
$showPasswordForm = false;

$linkError = '';
$link = getMysqliConnection($linkError);

if (!$link) {
    $registerError = '資料庫連線失敗：' . $linkError;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'send_code') {
        $userId = trim((string)($_POST['user_id'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));

        if ($userId === '' || $fullName === '') {
            $registerError = '請完整填寫帳號與姓名。';
        } elseif (strlen($userId) > 10) {
            $registerError = '帳號長度不可超過 10 碼。';
        } else {
            $derivedEmail = $userId . '@cloud.fju.edu.tw';
            $checkStmt = mysqli_prepare($link, 'SELECT user_id FROM users WHERE user_id = ? LIMIT 1');

            if (!$checkStmt) {
                $registerError = '註冊查詢失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($checkStmt, 's', $userId);
                mysqli_stmt_execute($checkStmt);
                $result = mysqli_stmt_get_result($checkStmt);
                $existingUser = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($checkStmt);

                if ($existingUser) {
                    $registerError = '這個帳號已經被使用，請更換 user_id。';
                } else {
                    $verificationCode = (string)random_int(100000, 999999);
                    $state = [
                        'user_id' => $userId,
                        'full_name' => $fullName,
                        'phone' => $phone,
                        'email' => $derivedEmail,
                        'code_hash' => password_hash($verificationCode, PASSWORD_DEFAULT),
                        'code_expires_at' => time() + 600,
                        'code_verified' => false,
                        'attempts' => 0,
                    ];

                    try {
                        sendRegisterVerificationCode($derivedEmail, $fullName !== '' ? $fullName : $userId, $verificationCode);
                        $_SESSION['register_state'] = $state;
                        $registerState = $state;
                        $showCodeForm = true;
                        $registerSuccess = '驗證碼已寄出到 ' . $derivedEmail . '，請輸入後再設定密碼。';
                    } catch (Throwable $throwable) {
                        $registerError = '驗證碼寄送失敗：' . $throwable->getMessage();
                    }
                }
            }
        }
    } elseif ($action === 'verify_code') {
        $code = trim((string)($_POST['verification_code'] ?? ''));
        $state = is_array($registerState) ? $registerState : null;

        if (!$state) {
            $registerError = '請先填寫帳號、姓名並取得驗證碼。';
        } elseif (($state['code_expires_at'] ?? 0) < time()) {
            unset($_SESSION['register_state']);
            $registerError = '驗證碼已過期，請重新取得驗證碼。';
        } elseif (($state['attempts'] ?? 0) >= 5) {
            unset($_SESSION['register_state']);
            $registerError = '驗證次數過多，請重新取得驗證碼。';
        } elseif ($code === '') {
            $registerError = '請輸入驗證碼。';
            $showCodeForm = true;
        } elseif (!password_verify($code, (string)($state['code_hash'] ?? ''))) {
            $state['attempts'] = (int)($state['attempts'] ?? 0) + 1;
            $_SESSION['register_state'] = $state;
            $registerState = $state;
            $showCodeForm = true;

            if ($state['attempts'] >= 5) {
                unset($_SESSION['register_state']);
                $registerError = '驗證次數過多，請重新取得驗證碼。';
                $showCodeForm = false;
            } else {
                $registerError = '驗證碼不正確，請再試一次。';
            }
        } else {
            $state['code_verified'] = true;
            $state['verified_at'] = time();
            $_SESSION['register_state'] = $state;
            $registerState = $state;
            $showPasswordForm = true;
            $registerSuccess = '驗證成功，請設定密碼。';
        }
    } elseif ($action === 'resend_code') {
        $state = is_array($registerState) ? $registerState : null;

        if (!$state) {
            $registerError = '請先填寫帳號、姓名並取得驗證碼。';
        } else {
            $verificationCode = (string)random_int(100000, 999999);
            $state['code_hash'] = password_hash($verificationCode, PASSWORD_DEFAULT);
            $state['code_expires_at'] = time() + 600;
            $state['code_verified'] = false;
            $state['attempts'] = 0;

            try {
                sendRegisterVerificationCode((string)$state['email'], (string)$state['full_name'], $verificationCode);
                $_SESSION['register_state'] = $state;
                $registerState = $state;
                $showCodeForm = true;
                $registerSuccess = '驗證碼已重新寄出到 ' . (string)$state['email'] . '。';
            } catch (Throwable $throwable) {
                $registerError = '驗證碼寄送失敗：' . $throwable->getMessage();
            }
        }
    } elseif ($action === 'register_account') {
        $state = is_array($registerState) ? $registerState : null;
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if (!$state || empty($state['code_verified'])) {
            $registerError = '請先完成驗證碼確認。';
            $showCodeForm = true;
        } elseif (($state['code_expires_at'] ?? 0) < time()) {
            unset($_SESSION['register_state']);
            $registerError = '驗證流程已過期，請重新取得驗證碼。';
        } elseif ($password === '' || $confirmPassword === '') {
            $registerError = '請輸入密碼與確認密碼。';
            $showPasswordForm = true;
        } elseif ($password !== $confirmPassword) {
            $registerError = '兩次輸入的密碼不一致。';
            $showPasswordForm = true;
        } elseif (strlen($password) < 4) {
            $registerError = '密碼至少需要 4 碼。';
            $showPasswordForm = true;
        } else {
            $checkStmt = mysqli_prepare($link, 'SELECT user_id FROM users WHERE user_id = ? LIMIT 1');

            if (!$checkStmt) {
                $registerError = '註冊查詢失敗：' . mysqli_error($link);
            } else {
                mysqli_stmt_bind_param($checkStmt, 's', $state['user_id']);
                mysqli_stmt_execute($checkStmt);
                $result = mysqli_stmt_get_result($checkStmt);
                $existingUser = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($checkStmt);

                if ($existingUser) {
                    $registerError = '這個帳號已經被使用，請重新申請。';
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $insertStmt = mysqli_prepare(
                        $link,
                        'INSERT INTO users (user_id, full_name, role_name, email, phone, password) VALUES (?, ?, ?, ?, ?, ?)'
                    );

                    if (!$insertStmt) {
                        $registerError = '建立帳號失敗：' . mysqli_error($link);
                    } else {
                        $roleName = '1';
                        mysqli_stmt_bind_param(
                            $insertStmt,
                            'ssssss',
                            $state['user_id'],
                            $state['full_name'],
                            $roleName,
                            $state['email'],
                            $state['phone'],
                            $hashedPassword
                        );

                        if (mysqli_stmt_execute($insertStmt)) {
                            unset($_SESSION['register_state']);
                            $registerState = null;
                            $registerSuccess = '註冊成功，現在可以使用新帳號登入。';
                            $userId = '';
                            $fullName = '';
                            $phone = '';
                            $derivedEmail = '';
                        } else {
                            $registerError = '建立帳號失敗：' . mysqli_stmt_error($insertStmt);
                            $showPasswordForm = true;
                        }

                        mysqli_stmt_close($insertStmt);
                    }
                }
            }
        }
    }
}

if (is_array($registerState)) {
    $userId = (string)($registerState['user_id'] ?? $userId);
    $fullName = (string)($registerState['full_name'] ?? $fullName);
    $phone = (string)($registerState['phone'] ?? $phone);
    $derivedEmail = (string)($registerState['email'] ?? '');
    $showCodeForm = $showCodeForm || empty($registerState['code_verified']);
    $showPasswordForm = $showPasswordForm || !empty($registerState['code_verified']);
} elseif ($userId !== '') {
    $derivedEmail = $userId . '@cloud.fju.edu.tw';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>註冊｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-body">
    <main class="auth-center">
        <section class="login-card">
            <h2>註冊帳號</h2>
            <p class="login-subtitle">先確認帳號是否重複，再寄送驗證碼到學校信箱。</p>
            <p class="auth-form-hint">email 會自動使用 <strong>user_id@cloud.fju.edu.tw</strong>。</p>

            <?php if ($registerError !== '') { ?>
                <div class="login-alert"><?php echo htmlspecialchars($registerError, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <?php if ($registerSuccess !== '') { ?>
                <div class="auth-success"><?php echo htmlspecialchars($registerSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <form method="post" class="login-form" action="register.php">
                <input type="hidden" name="action" value="send_code">

                <div class="form-group">
                    <label for="user_id">帳號 (user_id)</label>
                    <input type="text" id="user_id" name="user_id" placeholder="請輸入帳號" value="<?php echo htmlspecialchars($userId, ENT_QUOTES, 'UTF-8'); ?>" maxlength="10" required <?php echo $showCodeForm || $showPasswordForm ? 'readonly' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="full_name">姓名 (full_name)</label>
                    <input type="text" id="full_name" name="full_name" placeholder="請輸入姓名" value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>" required <?php echo $showCodeForm || $showPasswordForm ? 'readonly' : ''; ?>>
                </div>

                <div class="form-group">
                    <label for="phone">電話 (phone)</label>
                    <input type="text" id="phone" name="phone" placeholder="請輸入電話（可留空）" value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $showCodeForm || $showPasswordForm ? 'readonly' : ''; ?>>
                </div>

                <div class="form-group">
                    <label>電子郵件</label>
                    <input type="text" value="<?php echo htmlspecialchars($derivedEmail !== '' ? $derivedEmail : '將自動建立為 user_id@cloud.fju.edu.tw', ENT_QUOTES, 'UTF-8'); ?>" readonly>
                </div>

                <button type="submit" class="btn-primary login-button"><?php echo ($showCodeForm || $showPasswordForm) ? '重新發送驗證碼' : '發送驗證碼'; ?></button>
            </form>

            <?php if ($showCodeForm) { ?>
                <form method="post" class="login-form" action="register.php">
                    <input type="hidden" name="action" value="verify_code">

                    <div class="form-group">
                        <label for="verification_code">驗證碼</label>
                        <input type="text" id="verification_code" name="verification_code" placeholder="請輸入 6 碼驗證碼" inputmode="numeric" autocomplete="one-time-code" required>
                    </div>

                    <button type="submit" class="btn-primary login-button">驗證驗證碼</button>
                </form>
            <?php } ?>

            <?php if ($showPasswordForm) { ?>
                <form method="post" class="login-form" action="register.php">
                    <input type="hidden" name="action" value="register_account">

                    <div class="form-group">
                        <label for="password">密碼</label>
                        <input type="password" id="password" name="password" placeholder="請輸入密碼" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">確認密碼</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="再次輸入密碼" required>
                    </div>

                    <button type="submit" class="btn-primary login-button">註冊</button>
                </form>
            <?php } ?>

            <?php if ($showCodeForm && !$showPasswordForm) { ?>
                <div class="login-actions">
                    <form method="post" action="register.php" style="display:inline; margin:0;">
                        <input type="hidden" name="action" value="resend_code">
                        <button type="submit" class="auth-link auth-inline-button">重新寄送驗證碼</button>
                    </form>
                </div>
            <?php } ?>

            <a href="login.php" class="btn-secondary login-home-button">返回登入</a>
        </section>
    </main>
</body>
</html>
