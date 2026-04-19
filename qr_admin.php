<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=qr_admin.php');
    exit;
}

$currentRole = (string)($_SESSION['role_name'] ?? '');
if ($currentRole !== '3') {
    http_response_code(403);
    echo '僅 role_name=3 可產生報到 QR Code。';
    exit;
}

$payload = 'CHECKIN_GATE_V1';
$relativeCheckinPath = 'checkin.php?qr=' . urlencode($payload);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>生成報到 QR Code｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <nav class="navbar">
            <div class="navbar-brand"><h1>📚 校園資源租借系統</h1></div>
            <div class="navbar-menu">
                <button class="nav-btn" onclick="location.href='index.php'">回首頁</button>
                <button class="nav-btn" onclick="location.href='report_maintenance.php'">報修</button>
                <button class="nav-btn" onclick="location.href='logout.php'">登出</button>
            </div>
        </nav>

        <main class="main-content">
            <section class="card checkin-admin-card">
                <h2>統一報到 QR Code</h2>
                <p>此 QR Code 為全系統共用，申請者登入後掃描可進入報到流程。</p>

                <div class="checkin-qr-wrap">
                    <div id="qrcodeCanvas" class="checkin-qrcode"></div>
                </div>

                <div class="checkin-code-hint">
                    <p><strong>固定內容：</strong><span id="qrPayloadText"></span></p>
                    <p><strong>導向網址：</strong><a id="qrTargetUrl" href="#"></a></p>
                </div>

                <div class="hero-actions">
                    <button class="btn-primary" type="button" onclick="window.print()">列印 QR Code</button>
                    <button class="btn-secondary" type="button" onclick="renderQrCode()">重新生成</button>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        const checkinPayload = <?php echo json_encode($payload, JSON_UNESCAPED_UNICODE); ?>;
        const relativePath = <?php echo json_encode($relativeCheckinPath, JSON_UNESCAPED_UNICODE); ?>;

        function renderQrCode() {
            const currentPath = window.location.pathname;
            const currentDir = currentPath.substring(0, currentPath.lastIndexOf('/') + 1);
            const targetAbsoluteUrl = `${window.location.origin}${currentDir}${relativePath}`;
            const qrContainer = document.getElementById('qrcodeCanvas');
            const payloadText = document.getElementById('qrPayloadText');
            const targetLink = document.getElementById('qrTargetUrl');

            if (!qrContainer || !payloadText || !targetLink) {
                return;
            }

            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: targetAbsoluteUrl,
                width: 240,
                height: 240,
                correctLevel: QRCode.CorrectLevel.M
            });

            payloadText.textContent = checkinPayload;
            targetLink.textContent = targetAbsoluteUrl;
            targetLink.href = targetAbsoluteUrl;
        }

        renderQrCode();
    </script>
</body>
</html>
