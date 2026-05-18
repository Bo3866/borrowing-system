
<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?next=draft_box.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>草稿箱｜校園資源租借系統</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        body {
            background:#f8fafc;
            font-family: Arial, "Microsoft JhengHei", sans-serif;
        }

        .draft-container {
            max-width: 1000px;
            margin: 40px auto;
            background: #fff;
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 8px 20px rgba(0,0,0,.08);
        }

        .draft-header {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:24px;
        }

        .draft-header h2 {
            margin:0;
            color:#1e293b;
        }

        .draft-table {
            width:100%;
            border-collapse:collapse;
        }

        .draft-table th,
        .draft-table td {
            padding:14px;
            border-bottom:1px solid #e2e8f0;
            text-align:left;
        }

        .draft-table th {
            background:#f1f5f9;
            color:#475569;
        }

        .draft-actions {
            display:flex;
            gap:8px;
        }

        .btn-load {
            background:#3b82f6;
            color:white;
            border:none;
            padding:8px 14px;
            border-radius:8px;
            cursor:pointer;
        }

        .btn-delete {
            background:#ef4444;
            color:white;
            border:none;
            padding:8px 14px;
            border-radius:8px;
            cursor:pointer;
        }

        .btn-back {
            background:#64748b;
            color:white;
            text-decoration:none;
            padding:9px 16px;
            border-radius:8px;
        }

        .empty {
            text-align:center;
            color:#64748b;
            padding:40px;
        }
    </style>
</head>
<body>

<div class="draft-container">
    <div class="draft-header">
        <h2>📂 草稿箱</h2>
        <a href="borrow.php" class="btn-back">返回申請頁</a>
    </div>

    <div id="draftList"></div>
</div>

<script src="DraftManager.js"></script>
<script>
const STORAGE_KEY = 'borrow_drafts';

function getDrafts() {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
}

function saveDrafts(drafts) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(drafts));
}

function renderDrafts() {
    const draftList = document.getElementById('draftList');
    const drafts = getDrafts();

    if (drafts.length === 0) {
        draftList.innerHTML = `
            <div class="empty">
                📭 目前沒有暫存申請
            </div>
        `;
        return;
    }

    let html = `
        <table class="draft-table">
            <thead>
                <tr>
                    <th>暫存時間</th>
                    <th>活動名稱</th>
                    <th>用途摘要</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
    `;

    drafts.forEach(draft => {
        html += `
            <tr>
                <td>${draft.timestamp}</td>
                <td>${draft.activityName || '未填寫'}</td>
                <td>${draft.purpose ? draft.purpose.substring(0, 30) + '...' : '未填寫'}</td>
                <td>
                    <div class="draft-actions">
                        <button type="button" onclick="loadDraft('${draft.draftId}')">載入</button>
                        <button class="btn-delete" onclick="deleteDraft('${draft.draftId}')">刪除</button>
                    </div>
                </td>
            </tr>
        `;
    });

    html += `
            </tbody>
        </table>
    `;

    draftList.innerHTML = html;
}

function loadDraft(draftId) {
    window.location.href = 'borrow.php?draft_id=' + encodeURIComponent(draftId);
}

function deleteDraft(draftId) {
    if (!confirm('確定要刪除這筆草稿嗎？')) return;

    const drafts = getDrafts().filter(d => d.draftId !== draftId);
    saveDrafts(drafts);
    renderDrafts();
}

renderDrafts();
</script>

</body>
</html>