# 水平進度條 - 快速參考指南

## 🎯 核心概念

水平進度條有 4 個階段，根據申請的實際狀態自動轉換：

```
申請送出 ──→ 審核中 ──→ 使用中 ──→ 已歸還
   (1)        (2)       (3)       (4)
```

---

## 📝 修改進度階段

### 步驟 1：編輯 PHP 狀態計算邏輯

在 `return_management.php` 中找到：

```php
// 根據狀態計算進度：1=申請送出 2=審核中 3=使用中 4=已歸還
$progressStatus = 1;
if ($isReturned) {
    $progressStatus = 4;
} elseif ($isPickup) {
    $progressStatus = 3;
} else {
    $progressStatus = 2;
}
```

**自訂範例**：如果想添加第5個階段「已評價」
```php
$progressStatus = 1;
if ($isRated) {                          // 新增條件
    $progressStatus = 5;
} elseif ($isReturned) {
    $progressStatus = 4;
} elseif ($isPickup) {
    $progressStatus = 3;
} else {
    $progressStatus = 2;
}
```

### 步驟 2：添加新的進度節點 HTML

在 `return_management.php` 表格展開行中添加新節點：

```html
<!-- 第 4 個節點之後添加第 5 個 -->
<div class="stepper-connector"></div>

<div class="stepper-step" data-step="5">
    <div class="stepper-node"></div>
    <div class="stepper-label">
        <span class="step-title">已評價</span>
        <span class="step-time"><?php echo isset($row['rated_at']) ? htmlspecialchars($row['rated_at'], ENT_QUOTES, 'UTF-8') : ''; ?></span>
    </div>
</div>
```

### 步驟 3：驗證 JavaScript 邏輯

JavaScript 會自動根據節點數量和 `data-status` 值進行處理，無需修改。

---

## 🎨 自訂顏色

### 已完成節點/連接線
在 `styles.css` 找到：

```css
.stepper-node.active {
    background-color: #3498db;  /* 改為需要的顏色 */
    box-shadow: 0 0 0 2px #3498db;
}

.stepper-connector.active {
    background-color: #3498db;  /* 改為需要的顏色 */
}
```

**常用顏色參考**：
- 藍色：`#3498db` 或 `#2980b9`
- 綠色：`#27ae60` 或 `#2ecc71`
- 紫色：`#9b59b6` 或 `#8e44ad`
- 紅色：`#e74c3c` 或 `#c0392b`

### 未完成節點/連接線
```css
.stepper-node {
    background-color: #d0d7de;  /* 改為需要的淺色 */
    box-shadow: 0 0 0 2px #d0d7de;
}

.stepper-connector {
    background-color: #d0d7de;  /* 改為需要的淺色 */
}
```

### 面板邊框和標籤
```css
.accordion-panel {
    border-left: 4px solid #3498db;  /* 改為需要的顏色 */
}

.step-title {
    color: #2c3e50;  /* 改為需要的字體色 */
}
```

---

## ⏱️ 調整動畫速度

在 `styles.css` 中找到：

```css
@keyframes slideDown {
    from {
        opacity: 0;
        max-height: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        max-height: 1000px;
        transform: translateY(0);
    }
}

.accordion-content {
    animation: slideDown 0.3s ease-out;  /* 改變 0.3s */
}
```

**時間參考**：
- 快速：`0.15s` 或 `0.2s`
- 正常：`0.3s` 或 `0.4s`
- 緩慢：`0.6s` 或 `0.8s`

---

## 📱 響應式調整

### 改變斷點

在 `styles.css` 找到響應式 media queries：

```css
/* 目前：768px 轉為垂直 */
@media (max-width: 768px) {
    .stepper-container {
        flex-direction: column;  /* 垂直排列 */
    }
}

/* 自訂範例：1200px 轉為垂直 */
@media (max-width: 1200px) {
    .stepper-container {
        flex-direction: column;
    }
}
```

### 調整節點大小

找到：
```css
.stepper-node {
    width: 40px;   /* 改變大小 */
    height: 40px;
}

@media (max-width: 1024px) {
    .stepper-node {
        width: 36px;   /* 平板版 */
        height: 36px;
    }
}

@media (max-width: 768px) {
    .stepper-node {
        width: 36px;   /* 手機版 */
        height: 36px;
    }
}
```

---

## 📊 自訂標籤和時間戳

### 改變階段名稱

在 `return_management.php` 的進度節點中：

```html
<!-- 原始 -->
<span class="step-title">申請送出</span>

<!-- 自訂為 -->
<span class="step-title">請求已提交</span>
```

### 條件式顯示時間戳

```html
<!-- 原始：只在已報到時顯示 -->
<span class="step-time"><?php echo $isPickup ? htmlspecialchars(...) : ''; ?></span>

<!-- 自訂：始終顯示（如果有時間戳） -->
<span class="step-time"><?php echo htmlspecialchars($row['some_timestamp'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
```

---

## 🔧 JavaScript 自訂

### 修改 Accordion 行為

在 `return_management.php` 末尾的 `<script>` 中找到 `toggleAccordion()` 函數：

```javascript
// 預設：關閉其他已開啟的 accordion（單選模式）
document.querySelectorAll('.accordion-content').forEach(item => {
    if (item.id !== `accordion-${reservationId}`) {
        item.style.display = 'none';
    }
});

// 改為多選模式：允許同時開多個
// 註釋掉上面的代碼，改為：
// （省略，直接切換當前）
```

### 改變展開/收起圖標

```javascript
// 原始
icon.textContent = '▶';  // 收起
icon.textContent = '▼';  // 展開

// 改為
icon.textContent = '+';  // 收起
icon.textContent = '-';  // 展開

// 或使用箭頭
icon.textContent = '→';  // 收起
icon.textContent = '↓';  // 展開
```

---

## 🐛 常見問題排查

### Q1：進度條沒有顯示顏色

**原因**：JavaScript 未執行 `initializeSteppers()`

**解決**：檢查瀏覽器控制台是否有錯誤，確保 `document.addEventListener('DOMContentLoaded', ...)` 正確執行。

### Q2：手機版進度條看起來有問題

**原因**：響應式斷點不符合設計

**解決**：
```css
/* 嘗試調整斷點 */
@media (max-width: 640px) {  /* 改為 640px */
    .stepper-container {
        flex-direction: column;
    }
}
```

### Q3：時間戳不顯示

**原因**：資料庫中沒有相應的時間字段

**解決**：檢查 SQL 查詢是否正確抓取時間字段：
```php
// 確保這些字段存在
'pickup_confirmed_at',  // checkin_logs.checked_in_at
'return_confirmed_at'   // reservations.returned_at
```

### Q4：展開/收起不夠平滑

**原因**：動畫時間太短或過長

**解決**：調整 `styles.css` 中的 animation：
```css
animation: slideDown 0.5s ease-out;  /* 增加時間 */
```

---

## 📚 相關文件快速導航

| 文件 | 位置 | 用途 |
|------|------|------|
| return_management.php | 主文件 | HTML結構、PHP邏輯、JavaScript |
| styles.css | 樣式文件 | CSS樣式、響應式設計 |
| STEPPER_IMPLEMENTATION.md | 文檔 | 完整功能說明 |

---

## ✨ 實用程式碼片段

### 添加新的進度狀態
```php
// PHP 中
$progressStatus = 1;
if ($isCompleted) {
    $progressStatus = 5;
} elseif ($isReturned) {
    $progressStatus = 4;
} elseif ($isPickup) {
    $progressStatus = 3;
} else {
    $progressStatus = 2;
}
```

### 自訂進度條顏色（深色主題）
```css
.stepper-node.active {
    background-color: #2c3e50;
    box-shadow: 0 0 0 2px #2c3e50;
}

.stepper-connector.active {
    background-color: #2c3e50;
}
```

### 快速隱藏進度條
```css
/* 在樣式中添加 */
.horizontal-stepper {
    display: none;  /* 隱藏進度條 */
}
```

### 禁用 Accordion 展開
```javascript
// 在 HTML 中移除或註釋掉
// onclick="toggleAccordion(this, reservationId)"
```

---

**最後修改日期**：2026-04-29
**版本**：1.0

