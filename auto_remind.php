<?php
// auto_remind.php
// 負責在背景自動執行寄信提醒，由 index.php 掛載啟動
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function run_auto_remind($force = false) {
    $lockFile = __DIR__ . '/last_remind_time.txt';
    $now = time();
    $messages = '';
    $sentCount = 0;
    $foundCount = 0;

    // 檢查上次執行時間，避免頻繁寄信（冷卻時間：60分鐘 = 3600秒）
    if (!$force && file_exists($lockFile)) {
        $lastRun = (int)file_get_contents($lockFile);
        if (($now - $lastRun) < 3600) {
            $messages .= "冷卻時間內，略過執行\n";
            return ['sent' => 0, 'output' => $messages]; // 還在冷卻時間內，直接結束
        }
    }

    try {
        $link = getDatabaseConnection(); // 取得 PDO 連線
        
        // 強制設定資料庫時間為台灣時間 (+08:00)，確保 NOW() 與使用者時間一致
        $link->exec("SET time_zone = '+08:00'");
        
                // 找出所有 `approval_status` = 'approved'
                // 以 `actual_return_at` 為逾期判定基準：已記錄實際離場時間且該時間早於 NOW()
                // 並且尚未標記為已歸還 (`returned_at` 為 NULL)
                // 且過去一小時內「沒有被提醒過」的單子
                $sql = "
                        SELECT r.reservation_id, r.user_id, u.email, u.full_name, r.actual_return_at, r.approval_status
                        FROM reservations r
                        JOIN users u ON r.user_id = u.user_id
                        WHERE r.approval_status = 'approved'
                            AND r.returned_at IS NULL
                            AND r.actual_return_at IS NOT NULL
                            AND r.actual_return_at < NOW()
                            AND (r.reminder_sent_at IS NULL OR r.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
                        LIMIT 50
                ";
        
        $stmt = $link->query($sql);
        $overdueReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $foundCount = count($overdueReservations);

        if ($foundCount > 0) {
            $messages .= "找到 {$foundCount} 筆符合逾期條件的預約。\n";
            require_once __DIR__ . '/config/mail.php';
            if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
                throw new RuntimeException('郵件設定未啟用或未完成，請檢查 config/mail.php');
            }
            $mail = new PHPMailer(true);
            $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = $MAIL_USERNAME; // 寄件帳號
            $mail->Password   = $MAIL_PASSWORD; // 應用程式密碼
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';
            
            // 不使用大學名稱，改為一般的「借用系統」
            $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
            $mail->isHTML(true);

            // 準備更新已經寄過信的欄位
            $updateStmt = $link->prepare("UPDATE reservations SET reminder_sent_at = NOW() WHERE reservation_id = :id");

            foreach ($overdueReservations as $row) {
                if (empty($row['email'])) continue;

                try {
                    $mail->clearAddresses();
                    $mail->addAddress($row['email'], $row['full_name']);
                    $mail->Subject = '【系統通知】預約器材/場地逾期未歸還或離場提醒';
                    $endAt = isset($row['actual_return_at']) ? $row['actual_return_at'] : '';
                    $mail->Body    = "您好，{$row['full_name']}：<br><br>您的預約單號：{$row['reservation_id']} 在系統中的實際離場時間記錄為 ({$endAt})，但尚未辦理歸還或離場手續。<br><br>請注意：如果您一直沒有辦理確認動作，系統將會<b>每小時自動寄送本提醒一次</b>。<br><br>請盡速回到系統處理，謝謝！";
                    $mail->AltBody = "您好，{$row['full_name']}：\n\n您的預約單號：{$row['reservation_id']} 在系統中的實際離場時間記錄為 ({$endAt})，但尚未辦理歸還或離場手續。\n\n請注意：如果您一直沒有辦理確認動作，系統將會每小時自動寄送本提醒一次。\n\n請盡速回到系統處理，謝謝！";

                    $mail->send();

                    // 更新此單號的提醒時間為此時此刻 (NOW)
                    $updateStmt->execute([':id' => $row['reservation_id']]);
                    $sentCount++;
                    $messages .= "✅ 成功寄送逾期提醒給：{$row['full_name']} (預約單號：{$row['reservation_id']})\n";
                } catch (Exception $e) {
                    $messages .= "❌ 寄送失敗給：{$row['full_name']} (單號 {$row['reservation_id']}) -> {$mail->ErrorInfo}\n";
                    error_log("Web Cron 寄信失敗 (單號 {$row['reservation_id']}): " . $mail->ErrorInfo);
                }
            }
        }

        // 如果找到候選，但實際上沒有寄出任何信件，提供診斷提示
        if ($foundCount > 0 && $sentCount === 0) {
            $messages .= "注意：找到 {$foundCount} 筆候選，但未寄出任何信件（可能為收件人信箱為空或郵件設定未啟用）。\n";
        }

        // 把當下的時間戳記寫入 lock 檔，確保要再隔 1 小時才會第二次執行 Web Cron
        file_put_contents($lockFile, $now);

        if ($messages === '') {
            $messages = "檢查完成，無逾期申請需要寄送。\n";
        }

        return ['sent' => $sentCount, 'output' => $messages];

    } catch (Throwable $e) {
        error_log("Web Cron 執行錯誤: " . $e->getMessage());
        $messages .= "執行錯誤: " . $e->getMessage() . "\n";
        return ['sent' => $sentCount, 'output' => $messages];
    }
}

// Note: do not auto-run on include; callers should explicitly invoke `run_auto_remind()`.
// This allows other pages to call the function (e.g. via an admin button) without
// causing duplicate execution when the file is included.
