# borrowing-system

## 自動寄送逾期未歸還通知

已新增排程腳本：

- auto_notify_overdue.php

功能：

- 自動找出已核准、已逾期、尚未歸還、且 return_notified = 0 的預約
- 寄送 Email 通知
- 寄送成功後自動把 reservations.return_notified 更新為 1

### 手動測試

在專案根目錄執行：

php auto_notify_overdue.php

### Windows 工作排程（每 10 分鐘）

請用系統管理員 PowerShell 執行（PHP 路徑請依你的環境調整）：

schtasks /Create /SC MINUTE /MO 10 /TN "BorrowingSystemAutoNotify" /TR "C:\xampp\php\php.exe C:\xampp\htdocs\borrowing-system\auto_notify_overdue.php" /F

### 查看執行紀錄

- 成功時會輸出類似：

	[2026-04-18 10:00:00] overdue_checked=3 sent=3 failed=0

- 若有資料表欄位缺漏，腳本會輸出錯誤並結束。