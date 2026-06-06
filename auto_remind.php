<?php
// auto_remind.php
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

    try {
        $link = getDatabaseConnection();
        $link->exec("SET time_zone = '+08:00'");

        // ── 1. 統計逾期未歸還筆數（永遠執行，不受冷卻影響）─────────────
        $countSql = "
            SELECT COUNT(DISTINCT r.reservation_id)
            FROM reservations r
            LEFT JOIN handover_schedules h ON h.reservation_id = r.reservation_id
            WHERE r.approval_status = 'approved'
              AND r.actual_return_at IS NOT NULL
              AND r.actual_return_at < NOW()
              AND (
                  (h.handover_id IS NOT NULL AND h.returned_at IS NULL)
                  OR (r.returned_at IS NULL)
              )
        ";
        $totalUnreturned = (int)$link->query($countSql)->fetchColumn();
        $messages .= "目前共有 {$totalUnreturned} 筆申請逾期未完成歸還／離場。\n";

        // ── 2. 冷卻檢查（手動觸發時 force=true 可略過）──────────────────
        if (!$force && file_exists($lockFile)) {
            $lastRun = (int)file_get_contents($lockFile);
            if (($now - $lastRun) < 3600) {
                $remaining = ceil((3600 - ($now - $lastRun)) / 60);
                $messages .= "距上次偵查未滿 1 小時（還需等待 {$remaining} 分鐘），略過寄信。\n";
                return ['sent' => 0, 'found' => $totalUnreturned, 'output' => $messages];
            }
        }

        // ── 3. 找出所有逾期未歸還的預約 ─────────────────────────────────
        $sql = "
            SELECT
                r.reservation_id,
                r.user_id,
                u.email,
                u.full_name,
                r.actual_return_at,
                r.returned_at AS space_returned_at,
                (
                    SELECT h2.returned_at
                    FROM handover_schedules h2
                    WHERE h2.reservation_id = r.reservation_id
                    ORDER BY h2.handover_id DESC
                    LIMIT 1
                ) AS equip_returned_at,
                (
                    SELECT COUNT(*)
                    FROM handover_schedules h3
                    WHERE h3.reservation_id = r.reservation_id
                ) AS has_equipment
            FROM reservations r
            JOIN users u ON r.user_id = u.user_id
            WHERE r.approval_status = 'approved'
              AND r.actual_return_at IS NOT NULL
              AND r.actual_return_at < NOW()
            HAVING
                (has_equipment > 0 AND equip_returned_at IS NULL)
                OR (space_returned_at IS NULL)
            LIMIT 50
        ";

        $stmt = $link->query($sql);
        $overdueList = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $foundCount = count($overdueList);

        if ($foundCount === 0) {
            $messages .= "無需寄送提醒（無符合條件的逾期申請）。\n";
            file_put_contents($lockFile, $now);
            return ['sent' => 0, 'found' => $totalUnreturned, 'output' => $messages];
        }

        $messages .= "本次將寄送提醒給 {$foundCount} 筆逾期申請。\n";

        // ── 4. 初始化 PHPMailer ───────────────────────────────────────────
        require_once __DIR__ . '/config/mail.php';
        if (empty($MAIL_ENABLED) || empty($MAIL_USERNAME) || empty($MAIL_PASSWORD)) {
            throw new RuntimeException('郵件設定未啟用或未完成，請檢查 config/mail.php');
        }

        $mail = new PHPMailer(true);
        $mailFrom = !empty($MAIL_FROM) ? $MAIL_FROM : $MAIL_USERNAME;
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $MAIL_USERNAME;
        $mail->Password   = $MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($mailFrom, $MAIL_FROM_NAME ?? '器材借用系統');
        $mail->isHTML(true);

        // ── 5. 逐筆寄信 ──────────────────────────────────────────────────
        foreach ($overdueList as $row) {
            if (empty($row['email'])) {
                $messages .= "⚠️ 跳過單號 {$row['reservation_id']}：使用者信箱為空。\n";
                continue;
            }

            $hasEquip  = (int)$row['has_equipment'] > 0;
            $equipDone = !is_null($row['equip_returned_at']);
            $spaceDone = !is_null($row['space_returned_at']);

            $pendingItems = [];
            if ($hasEquip && !$equipDone) $pendingItems[] = '器材尚未歸還';
            if (!$spaceDone)               $pendingItems[] = '場地尚未辦理離場';
            $pendingStr = implode('、', $pendingItems);

            try {
                $mail->clearAddresses();
                $mail->addAddress($row['email'], $row['full_name']);
                $mail->Subject = '【系統通知】預約器材／場地逾期提醒';
                $endAt = $row['actual_return_at'];

                $mail->Body = "您好，{$row['full_name']}：<br><br>"
                    . "您的預約單號 <b>{$row['reservation_id']}</b> 預計歸還／離場時間為 <b>{$endAt}</b>，"
                    . "但系統偵測到以下項目尚未完成：<br>"
                    . "<b>{$pendingStr}</b><br><br>"
                    . "請注意：若持續未辦理，系統將<b>每小時自動寄送本提醒</b>。<br><br>"
                    . "請盡速至系統辦理相關手續，謝謝！";

                $mail->AltBody = "您好，{$row['full_name']}：\n\n"
                    . "您的預約單號 {$row['reservation_id']} 預計歸還／離場時間為 {$endAt}，"
                    . "但系統偵測到以下項目尚未完成：\n"
                    . "{$pendingStr}\n\n"
                    . "請注意：若持續未辦理，系統將每小時自動寄送本提醒。\n\n"
                    . "請盡速至系統辦理相關手續，謝謝！";

                $mail->send();
                $sentCount++;
                $messages .= "✅ 已寄送：{$row['full_name']}（單號 {$row['reservation_id']}，{$pendingStr}）\n";
            } catch (Exception $e) {
                $messages .= "❌ 寄送失敗：{$row['full_name']}（單號 {$row['reservation_id']}）→ {$mail->ErrorInfo}\n";
                error_log("remind 寄信失敗 (單號 {$row['reservation_id']}): " . $mail->ErrorInfo);
            }
        }

        file_put_contents($lockFile, $now);
        return ['sent' => $sentCount, 'found' => $totalUnreturned, 'output' => $messages];

    } catch (Throwable $e) {
        $messages .= "執行錯誤：" . $e->getMessage() . "\n";
        error_log("run_auto_remind 錯誤: " . $e->getMessage());
        return ['sent' => $sentCount, 'found' => 0, 'output' => $messages];
    }
}