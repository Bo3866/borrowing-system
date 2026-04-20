<?php
// auto_remind.php
// 負責在背景自動執行寄信提醒，由 index.php 掛載啟動
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function run_auto_remind() {
    $lockFile = __DIR__ . '/last_remind_time.txt';
    $now = time();

    // 檢查上次執行時間，避免頻繁寄信（冷卻時間：60分鐘 = 3600秒）
    if (file_exists($lockFile)) {
        $lastRun = (int)file_get_contents($lockFile);
        if (($now - $lastRun) < 3600) {
            return; // 還在冷卻時間內，直接結束
        }
    }

    try {
        $link = getDatabaseConnection(); // 取得 PDO 連線
        
        // 強制設定資料庫時間為台灣時間 (+08:00)，確保 NOW() 與使用者時間一致
        $link->exec("SET time_zone = '+08:00'");
        
        // 找出所有 `approval_status` = 'approved' 
        // 且未歸還 / 未離場 (`returned_at` 為 NULL) 
        // 且已經超過借用結束時間 (`borrow_end_at` < NOW())
        // 且過去一小時內「沒有被提醒過」的單子
        $sql = "
            SELECT r.reservation_id, r.user_id, u.email, u.full_name, r.borrow_end_at, r.approval_status
            FROM reservations r
            JOIN users u ON r.user_id = u.user_id
            WHERE r.approval_status = 'approved' 
              AND r.returned_at IS NULL
              AND r.borrow_end_at < NOW()
              AND (r.reminder_sent_at IS NULL OR r.reminder_sent_at < DATE_SUB(NOW(), INTERVAL 1 HOUR))
            LIMIT 50
        ";
        
        $stmt = $link->query($sql);
        $overdueReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($overdueReservations) > 0) {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true;
            $mail->Username   = 'sasass041919@gmail.com'; // 您之前成功寄信的信箱
            $mail->Password   = 'xogusuplsoapxayc';       // 您設定好的應用程式密碼
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;
            $mail->CharSet    = 'UTF-8';
            
            // 不使用大學名稱，改為一般的「借用系統」
            $mail->setFrom('sasass041919@gmail.com', '借用系統通知');
            $mail->isHTML(true);

            // 準備更新已經寄過信的欄位
            $updateStmt = $link->prepare("UPDATE reservations SET reminder_sent_at = NOW() WHERE reservation_id = :id");

            foreach ($overdueReservations as $row) {
                if (empty($row['email'])) continue;

                try {
                    $mail->clearAddresses();
                    $mail->addAddress($row['email'], $row['full_name']);
                    $mail->Subject = '【系統通知】預約器材/場地逾期未歸還或離場提醒';
                    $mail->Body    = "您好，{$row['full_name']}：<br><br>您的預約單號：{$row['reservation_id']} 已經超過借用結束時間 ({$row['borrow_end_at']})，尚未辦理歸還或離場手續。<br><br>請注意：如果您一直沒有辦理確認動作，系統將會<b>每小時自動寄送本提醒一次</b>。<br><br>請盡速回到系統處理，謝謝！";
                    $mail->AltBody = "您好，{$row['full_name']}：\n\n您的預約單號：{$row['reservation_id']} 已經超過借用結束時間 ({$row['borrow_end_at']})，尚未辦理歸還或離場手續。\n\n請注意：如果您一直沒有辦理確認動作，系統將會每小時自動寄送本提醒一次。\n\n請盡速回到系統處理，謝謝！";

                    $mail->send();

                    // 更新此單號的提醒時間為此時此刻 (NOW)
                    $updateStmt->execute([':id' => $row['reservation_id']]);
                    echo "✅ 成功寄送逾期提醒給：{$row['full_name']} (預約單號：{$row['reservation_id']})\n";
                } catch (Exception $e) {
                    echo "❌ 寄送失敗給：{$row['full_name']} (單號 {$row['reservation_id']}) -> {$mail->ErrorInfo}\n";
                    error_log("Web Cron 寄信失敗 (單號 {$row['reservation_id']}): " . $mail->ErrorInfo);
                }
            }
        }

        // 把當下的時間戳記寫入 lock 檔，確保要再隔 1 小時才會第二次執行 Web Cron
        file_put_contents($lockFile, $now);

    } catch (Throwable $e) {
        error_log("Web Cron 執行錯誤: " . $e->getMessage());
    }
}

run_auto_remind();
