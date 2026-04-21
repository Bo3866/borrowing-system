<?php
// patch_maintenance.php

$files = ['report_maintenance.php', 'report_maintenance_new.php'];

foreach ($files as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);

    // 1. Add PHPMailer imports if not present
    if (strpos($content, 'use PHPMailer\PHPMailer\PHPMailer;') === false) {
        $importCode = <<<PHP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
PHP;
        $content = str_replace(
            "session_start();\n", 
            "session_start();\n" . $importCode . "\n", 
            $content
        );
    }

    // 2. Equipment Success email
    $eqSuccessOld = "\$success = '器材報修已送出，感謝您的回報。';";
    $eqSuccessNew = <<<'PHP'
$success = '器材報修已送出，感謝您的回報。';
                    try {
                        if ($email !== '') {
                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com'; 
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'sasass041919@gmail.com'; 
                            $mail->Password   = 'xogusuplsoapxayc'; 
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                            $mail->Port       = 465;
                            $mail->CharSet    = 'UTF-8';

                            $mail->setFrom('sasass041919@gmail.com', '校園資源租借系統');
                            $mail->addAddress($email, $name);

                            $mail->isHTML(true);
                            $mail->Subject = '【校園資源租借系統】器材報修申請通知';
                            $mail->Body    = "您好 {$name}：<br><br>我們已收到您的「器材報修」申請，詳細內容如下：<br><br>" . nl2br(htmlspecialchars($fullFaultDescription)) . "<br><br>管理團隊會盡快協助處理，謝謝！";
                            
                            $mail->send();
                            $success .= ' (已同步發送通知信至您的信箱)';
                        }
                    } catch (Exception $e) {
                         $errors[] = "通知信發送失敗: " . $mail->ErrorInfo;
                    }
PHP;

    if (strpos($content, "器材報修申請通知") === false) {
        $content = str_replace($eqSuccessOld, $eqSuccessNew, $content);
    }

    // 3. Space Success email -> Note: It's 空間報修已送出，感謝您的回報。
    $spSuccessOld = "\$success = '空間報修已送出，感謝您的回報。';";
    $spSuccessNew = <<<'PHP'
$success = '空間報修已送出，感謝您的回報。';
                    try {
                        if ($email !== '') {
                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com'; 
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'sasass041919@gmail.com'; 
                            $mail->Password   = 'xogusuplsoapxayc'; 
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                            $mail->Port       = 465;
                            $mail->CharSet    = 'UTF-8';

                            $mail->setFrom('sasass041919@gmail.com', '校園資源租借系統');
                            $mail->addAddress($email, $name);

                            $mail->isHTML(true);
                            $mail->Subject = '【校園資源租借系統】空間報修申請通知';
                            $mail->Body    = "您好 {$name}：<br><br>我們已收到您的「空間報修」申請，詳細內容如下：<br><br>" . nl2br(htmlspecialchars($fullFaultDescription)) . "<br><br>管理團隊會盡快協助處理，謝謝！";
                            
                            $mail->send();
                            $success .= ' (已同步發送通知信至您的信箱)';
                        }
                    } catch (Exception $e) {
                         $errors[] = "通知信發送失敗: " . $mail->ErrorInfo;
                    }
PHP;

    if (strpos($content, "空間報修申請通知") === false) {
        $content = str_replace($spSuccessOld, $spSuccessNew, $content);
    }

    file_put_contents($file, $content);
    echo "Patched $file\n";
}
echo "Done.\n";
?><?php
// patch_maintenance.php

$files = ['report_maintenance.php', 'report_maintenance_new.php'];

foreach ($files as $file) {
    if (!file_exists($file)) continue;

    $content = file_get_contents($file);

    // 1. Add PHPMailer imports if not present
    if (strpos($content, 'use PHPMailer\PHPMailer\PHPMailer;') === false) {
        $importCode = <<<PHP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
PHP;
        $content = str_replace(
            "session_start();\n", 
            "session_start();\n\n" . $importCode . "\n", 
            $content
        );
    }

    // 2. Equipment Success email
    $eqSuccessOld = "\$success = '器材報修已送出，感謝您的反映。';";
    $eqSuccessNew = <<<'PHP'
$success = '器材報修已送出，感謝您的反映。';
                    try {
                        if ($email !== '') {
                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com'; 
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'sasass041919@gmail.com'; 
                            $mail->Password   = 'xogusuplsoapxayc'; 
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                            $mail->Port       = 465;
                            $mail->CharSet    = 'UTF-8';

                            $mail->setFrom('sasass041919@gmail.com', '校園資源租借系統');
                            $mail->addAddress($email, $name);

                            $mail->isHTML(true);
                            $mail->Subject = '【校園資源租借系統】器材報修申請通知';
                            $mail->Body    = "您好 {$name}：<br><br>我們已收到您的「器材報修」申請，詳細內容如下：<br><br>" . nl2br(htmlspecialchars($fullFaultDescription)) . "<br><br>管理團隊會盡快協助處理，謝謝！";
                            
                            $mail->send();
                            $success .= ' (已同步發信至您的信箱)';
                        }
                    } catch (Exception $e) {
                         $errors[] = "通知信發送失敗: " . $mail->ErrorInfo;
                    }
PHP;

    if (strpos($content, "\$success .= ' (已同步發信至您的信箱)';") === false) {
        $content = str_replace($eqSuccessOld, $eqSuccessNew, $content);
    }

    // 3. Space Success email
    $spSuccessOld = "\$success = '場地報修已送出，感謝您的反映。';";
    $spSuccessNew = <<<'PHP'
$success = '場地報修已送出，感謝您的反映。';
                    try {
                        if ($email !== '') {
                            $mail = new PHPMailer(true);
                            $mail->isSMTP();
                            $mail->Host       = 'smtp.gmail.com'; 
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'sasass041919@gmail.com'; 
                            $mail->Password   = 'xogusuplsoapxayc'; 
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                            $mail->Port       = 465;
                            $mail->CharSet    = 'UTF-8';

                            $mail->setFrom('sasass041919@gmail.com', '校園資源租借系統');
                            $mail->addAddress($email, $name);

                            $mail->isHTML(true);
                            $mail->Subject = '【校園資源租借系統】場地報修申請通知';
                            $mail->Body    = "您好 {$name}：<br><br>我們已收到您的「場地報修」申請，詳細內容如下：<br><br>" . nl2br(htmlspecialchars($fullFaultDescription)) . "<br><br>管理團隊會盡快協助處理，謝謝！";
                            
                            $mail->send();
                            $success .= ' (已同步發信至您的信箱)';
                        }
                    } catch (Exception $e) {
                         $errors[] = "通知信發送失敗: " . $mail->ErrorInfo;
                    }
PHP;

    if (strpos($content, "場地報修申請通知") === false) {
        $content = str_replace($spSuccessOld, $spSuccessNew, $content);
    }

    file_put_contents($file, $content);
    echo "Patched $file\n";
}
echo "Done.\n";
?>