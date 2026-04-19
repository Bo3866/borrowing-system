<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'lib/PHPMailer/PHPMailer.php';
require 'lib/PHPMailer/SMTP.php';
require 'lib/PHPMailer/Exception.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=approve.php');
    exit;
}

$currentUserId = (string)$_SESSION['user_id'];
$currentRole = (string)($_SESSION['role_name'] ?? '');

// Allow manager roles
if (!in_array($currentRole, ['2', '3'], true)) {
    http_response_code(403);
    echo "<p style=\"padding:1rem;background:#ffecec;border-radius:6px;\">存取被拒：此功能僅限課指組老師。</p>";
    exit;
}

$link = mysqli_connect('localhost', 'root', '', 'borrowing_system',3307);
if (!$link) {
    $dbError = '資料庫連線失敗：' . mysqli_connect_error();
} else {
    mysqli_set_charset($link, 'utf8mb4');
}

function pickExistingColumn(array $columns, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }

    return null;
}

$actionMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_id'], $_POST['action'])) {
    $reservationId = (int)$_POST['reservation_id'];
    $action = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
    $comment = trim((string)($_POST['comment'] ?? '')) ?: null;

    if ($link) {
        mysqli_begin_transaction($link);
        try {
            // Only update pending reservations to avoid race conditions
            $updateStmt = mysqli_prepare($link, 'UPDATE reservations SET approval_status = ?, updated_at = NOW() WHERE reservation_id = ? AND approval_status = "pending"');
            if (!$updateStmt) {
                throw new RuntimeException('更新預約狀態準備失敗：' . mysqli_error($link));
            }
            mysqli_stmt_bind_param($updateStmt, 'si', $action, $reservationId);
            mysqli_stmt_execute($updateStmt);
            $affected = mysqli_stmt_affected_rows($updateStmt);
            mysqli_stmt_close($updateStmt);

            if ($affected <= 0) {
                throw new RuntimeException('更新失敗：該申請可能已被審核。');
            }

            $logStmt = mysqli_prepare($link, 'INSERT INTO approval_logs (reservation_id, reviewer_id, review_result, review_comment) VALUES (?, ?, ?, ?)');
            if (!$logStmt) {
                throw new RuntimeException('建立審核紀錄失敗：' . mysqli_error($link));
            }
            mysqli_stmt_bind_param($logStmt, 'isss', $reservationId, $currentUserId, $action, $comment);
            mysqli_stmt_execute($logStmt);
            mysqli_stmt_close($logStmt);

            // 若為拒絕，需將相關器材狀態還原為可借 (operation_status = 1)
            if ($action === 'rejected') {
                $restoreStmt = mysqli_prepare(
                    $link,
                    'UPDATE equipments e JOIN equipment_reservation_items eri ON e.equipment_id = eri.equipment_id SET e.operation_status = 1 WHERE eri.reservation_id = ? AND e.operation_status = 2'
                );
                if (!$restoreStmt) {
                    throw new RuntimeException('還原器材狀態失敗：' . mysqli_error($link));
                }
                mysqli_stmt_bind_param($restoreStmt, 'i', $reservationId);
                mysqli_stmt_execute($restoreStmt);
                mysqli_stmt_close($restoreStmt);
                // 若為拒絕，將該申請所關聯的空間還原為可借狀態 (space_status = '1')
                $restoreSpaceStmt = mysqli_prepare(
                    $link,
                    'UPDATE spaces s JOIN space_reservation_items sri ON s.space_id = sri.space_id SET s.space_status = ? WHERE sri.reservation_id = ? AND s.space_status = ?'
                );
                if ($restoreSpaceStmt) {
                    $avail = '1';
                    $currentBorrowed = '2';
                    mysqli_stmt_bind_param($restoreSpaceStmt, 'sis', $avail, $reservationId, $currentBorrowed);
                    mysqli_stmt_execute($restoreSpaceStmt);
                    mysqli_stmt_close($restoreSpaceStmt);
                }
            }

            // 取得申請人資訊以寄送郵件
            $userEmail = '';
            $userName = '';
            $userQuery = mysqli_prepare($link, 'SELECT u.email, u.full_name FROM users u JOIN reservations r ON u.user_id = r.user_id WHERE r.reservation_id = ?');
            if ($userQuery) {
                mysqli_stmt_bind_param($userQuery, 'i', $reservationId);
                mysqli_stmt_execute($userQuery);
                $userResult = mysqli_stmt_get_result($userQuery);
                if ($userData = mysqli_fetch_assoc($userResult)) {
                    $userEmail = $userData['email'];
                    $userName = $userData['full_name'];
                }
                mysqli_stmt_close($userQuery);
            }

            mysqli_commit($link);
            $actionMsg = $action === 'approved' ? '已核准此申請。' : '已拒絕此申請。';

            // 如果有申請人信箱，則寄送通知信
            if ($userEmail) {
                $mail = new PHPMailer(true);
                try {
                    //Server settings
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com'; // Gmail SMTP 伺服器
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'sasass041919@gmail.com'; // 您的 Gmail 信箱
                    $mail->Password   = 'xogusuplsoapxayc';    // Gmail 應用程式密碼
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port       = 465;
                    $mail->CharSet    = 'UTF-8';

                    //Recipients
                    $mail->setFrom('sasass041919@gmail.com', '器材借用系統');
                    $mail->addAddress($userEmail, $userName);

                    //Content
                    $mail->isHTML(true);
                    $mail->Subject = '您的借用申請狀態已更新';
                    $statusText = $action === 'approved' ? '已被核准' : '已被拒絕';
                    $mail->Body    = "您好 {$userName}：<br><br>您提出的借用申請（編號：{$reservationId}）{$statusText}。<br><br>審核意見：<br>" . nl2br(htmlspecialchars($comment ?? '無')) . "<br><br>感謝您！";
                    $mail->AltBody = "您好 {$userName}：\n\n您提出的借用申請（編號：{$reservationId}）{$statusText}。\n\n審核意見：\n" . htmlspecialchars($comment ?? '無') . "\n\n感謝您！";

                    $mail->send();
                    $actionMsg .= ' 已寄出通知信。';
                } catch (Exception $e) {
                    // 郵件寄送失敗，但不要影響主要操作結果
                    $actionMsg .= " 但通知信寄送失敗： {$mail->ErrorInfo}";
                }
            }

        } catch (Throwable $e) {
            mysqli_rollback($link);
            $actionMsg = '處理失敗：' . $e->getMessage();
        }
    }
}

// Fetch pending reservations (support reservations.applicant_id OR reservations.user_id)
$pending = [];

if (isset($dbError) && $dbError !== '') {
    // DB error already set; skip fetching
} else {
    // Collect reservation columns and pick applicant column
    $reservationColumns = [];
    $columnResult = mysqli_query($link, 'SHOW COLUMNS FROM reservations');
    if ($columnResult) {
        while ($columnRow = mysqli_fetch_assoc($columnResult)) {
            $reservationColumns[] = (string)$columnRow['Field'];
        }
    }

    $applicantColumn = null;
    if (in_array('applicant_id', $reservationColumns, true)) {
        $applicantColumn = 'applicant_id';
    } elseif (in_array('user_id', $reservationColumns, true)) {
        $applicantColumn = 'user_id';
    }

    if ($applicantColumn === null) {
        $dbError = '資料表 reservations 缺少 applicant_id 或 user_id，無法顯示審核資料。';
    } else {
        $submittedAtExpr = in_array('submitted_at', $reservationColumns, true) ? 'r.submitted_at' : 'r.created_at';
        $sql = sprintf(
            "SELECT r.reservation_id, r.`%s` AS applicant_user_id, %s AS submitted_at, r.borrow_start_at, r.borrow_end_at, u.full_name, u.email
             FROM reservations r
             JOIN users u ON r.`%s` = u.user_id
             WHERE r.approval_status = 'pending'
             ORDER BY %s ASC
             LIMIT 200",
            $applicantColumn,
            $submittedAtExpr,
            $applicantColumn,
            $submittedAtExpr
        );

        $res = mysqli_query($link, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $pending[] = $row;
            }
        } else {
            $dbError = '讀取待審核申請失敗：' . mysqli_error($link);
        }
    }
}

function fetchItems(mysqli $link, int $reservationId): array
{
    $items = [];
    $stmt = mysqli_prepare($link, 'SELECT eri.equipment_item_id, e.equipment_id, e.equipment_code, ec.equipment_name FROM equipment_reservation_items eri JOIN equipments e ON eri.equipment_id = e.equipment_id JOIN equipment_categories ec ON e.equipment_code = ec.equipment_code WHERE eri.reservation_id = ?');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $reservationId);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        if ($r) {
            while ($it = mysqli_fetch_assoc($r)) {
                $items[] = $it;
            }
        }
        mysqli_stmt_close($stmt);
    }
    return $items;
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>審核面板｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" type="button" disabled><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['user_id'], ENT_QUOTES, 'UTF-8'); ?></button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card">
                <h2>審核面板（待審核申請）</h2>

                <?php if ($actionMsg !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>

                <?php if (isset($dbError) && $dbError !== '') { ?>
                    <div class="login-alert"><?php echo htmlspecialchars($dbError, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } else { ?>

                    <?php if (count($pending) === 0) { ?>
                        <p>目前沒有待審核的申請。</p>
                    <?php } else { ?>
                        <?php foreach ($pending as $p) { $items = fetchItems($link, (int)$p['reservation_id']); ?>
                            <div class="card admin-application-card" style="margin-bottom:1rem;">
                                <div style="display:flex;justify-content:space-between;align-items:center;">
                                    <div>
                                        <strong>申請編號：</strong><?php echo (int)$p['reservation_id']; ?>
                                        &nbsp; <strong>申請人：</strong><?php echo htmlspecialchars($p['full_name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($p['applicant_user_id'], ENT_QUOTES, 'UTF-8'); ?>)
                                    </div>
                                    <div><small>送出時間：<?php echo htmlspecialchars($p['submitted_at'], ENT_QUOTES, 'UTF-8'); ?></small></div>
                                </div>

                                <div style="margin-top:0.5rem;">
                                    <strong>借用時段：</strong>
                                    <?php echo htmlspecialchars($p['borrow_start_at'], ENT_QUOTES, 'UTF-8'); ?> ～ <?php echo htmlspecialchars($p['borrow_end_at'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>

                                <div style="margin-top:0.5rem;">
                                    <strong>申請項目：</strong>
                                    <ul>
                                        <?php foreach ($items as $it) { ?>
                                            <li><?php echo htmlspecialchars($it['equipment_code'] . ' - ' . $it['equipment_name'], ENT_QUOTES, 'UTF-8'); ?></li>
                                        <?php } ?>
                                    </ul>
                                </div>

                                <form method="post" style="margin-top:0.5rem;display:flex;gap:0.5rem;align-items:flex-end;">
                                    <input type="hidden" name="reservation_id" value="<?php echo (int)$p['reservation_id']; ?>">
                                    <div style="flex:1;">
                                        <label>審核備註（可選）：</label>
                                        <textarea name="comment" rows="2" style="width:100%;"></textarea>
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:0.5rem;">
                                        <button type="submit" name="action" value="approve" class="btn-primary" onclick="return confirm('確認要核准此申請？')">核准</button>
                                        <button type="submit" name="action" value="reject" class="btn-secondary" onclick="return confirm('確認要拒絕此申請？')">拒絕</button>
                                    </div>
                                </form>
                            </div>
                        <?php } ?>
                    <?php } ?>

                <?php } ?>
            </section>
        </main>
        <footer class="footer"><p>&copy; 2024 校園資源租借系統。所有權利保留。</p></footer>
    </div>
</body>
</html>
