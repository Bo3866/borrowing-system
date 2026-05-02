# 「我的申請紀錄」水平進度條實現文檔

## 概述
已成功修改「我的申請紀錄」頁面（return_management.php），新增展開式水平進度條功能，用戶可點擊表格行查看詳細申請進度。

---

## 功能特性

### 1. 展開式 Accordion 效果
- **觸發方式**：點擊表格中的任一行記錄
- **展開動畫**：平滑的 slideDown 效果（0.3秒）
- **展開圖標**：左側包含展開/收起箭頭（▶/▼）
- **多行互斥**：一次只能展開一個詳細區塊

### 2. 水平進度條設計

#### 進度階段（4個節點）
1. **申請送出** - 第1階段（初始狀態）
2. **審核中** - 第2階段（申請已批准但未報到）
3. **使用中** - 第3階段（已報到）
4. **已歸還** - 第4階段（已歸還）

#### 視覺呈現
- **已完成節點**：藍色圓形 (#3498db) + 綠色勾選符號 (✓)
- **未完成節點**：灰色圓形 (#d0d7de)
- **連接線**：已完成為藍色，未完成為灰色
- **節點大小**：40px × 40px（桌面版）
- **節點標籤**：包含階段名稱 + 對應時間戳

#### 時間戳顯示規則
| 階段 | 時間來源 | 條件 |
|------|---------|------|
| 申請送出 | - | 不顯示 |
| 審核中 | - | 不顯示 |
| 使用中 | checkin_logs.checked_in_at | 僅在已報到時顯示 |
| 已歸還 | reservations.returned_at | 僅在已歸還時顯示 |

### 3. 詳細資訊區塊

展開後顯示以下內容：

**申請詳情**（網格佈局）
- 申請編號
- 申請人
- 借用起始時間
- 借用結束時間
- 借用項目（器材/空間）

**狀態進度**（時間線風格）
- 申請狀態：已批准
- 報到狀態：已/未報到（含時間戳）
- 歸還狀態：已/未歸還（含時間戳）

---

## 技術實現

### HTML 結構
```html
<!-- 主表格行（可點擊） -->
<tr class="accordion-trigger" onclick="toggleAccordion(this, reservationId)">
    <td><span class="accordion-icon">▶</span></td>
    <!-- 其他列... -->
</tr>

<!-- 隱藏的展開行 -->
<tr class="accordion-content" id="accordion-{reservationId}" style="display: none;">
    <td colspan="6">
        <div class="accordion-panel">
            <!-- 水平進度條 -->
            <div class="horizontal-stepper" data-status="2">
                <!-- 進度節點... -->
            </div>
            
            <!-- 詳細資訊 -->
            <div class="accordion-details">
                <!-- 詳情內容... -->
            </div>
        </div>
    </td>
</tr>
```

### JavaScript 邏輯

#### toggleAccordion(row, reservationId)
- 切換目標 accordion 顯示/隱藏
- 自動關閉其他已開啟的 accordion
- 更新展開/收起圖標

#### initializeSteppers()
- 頁面加載時初始化所有進度條
- 根據 `data-status` 屬性更新視覺狀態

#### updateStepper(stepper, status)
- 根據狀態值 (1-4) 點亮相應的進度節點和連接線
- status = 1: 只點亮第1個節點
- status = 2: 點亮第1、2個節點及連接線
- status = 3: 點亮第1、2、3個節點及連接線
- status = 4: 全部點亮

### CSS 類別

| 類別名 | 用途 |
|-------|------|
| `.accordion-trigger` | 可點擊的表格行 |
| `.accordion-content` | 隱藏的展開內容行 |
| `.accordion-icon` | 展開/收起箭頭 |
| `.accordion-panel` | 展開區塊容器 |
| `.horizontal-stepper` | 進度條容器 |
| `.stepper-node` | 進度節點（圓形） |
| `.stepper-node.active` | 已完成節點 |
| `.stepper-connector` | 節點間連接線 |
| `.stepper-connector.active` | 已完成連接線 |
| `.accordion-details` | 詳細資訊區塊 |

---

## 響應式設計

### 桌面版（≥ 768px）
- 進度條水平排列
- 節點大小：40px
- 完整展示所有信息

### 平板版（1024px - 768px）
- 進度條略微縮小
- 節點大小：36px
- 連接線寬度減小

### 手機版（< 768px）
- **進度條轉為垂直排列**
- 節點轉為左側對齐，標籤在右側
- 節點大小：36px
- 連接線變為垂直

### 超小屏幕（< 480px）
- 節點大小：32px
- 狀態時間線改為上下堆疊
- 緊湊佈局

---

## 狀態判斷邏輯

PHP 中的進度狀態計算：

```php
$progressStatus = 1; // 預設：申請送出

if ($isReturned) {
    $progressStatus = 4; // 已歸還
} elseif ($isPickup) {
    $progressStatus = 3; // 使用中（已報到）
} else {
    $progressStatus = 2; // 審核中（已批准但未報到）
}
```

**狀態來源：**
- `$isPickup`：根據 `checkin_logs.checked_in_at` 是否為 NULL
- `$isReturned`：根據 `reservations.returned_at` 是否為 NULL

---

## 交互說明

### 用戶操作流程

1. **查看申請列表** → 表格顯示所有已批准的申請
2. **點擊任一行** → 下方平滑展開詳細區塊
3. **查看進度條** → 清晰看到當前進度階段
4. **查看詳細信息** → 包括申請編號、借用時段、狀態時間線等
5. **點擊其他行或相同行** → 展開/關閉當前區塊

### 按鈕互動

- 展開行中的「確認歸還／離場」按鈕：
  - 使用 `onclick="event.stopPropagation()"` 防止觸發 accordion 切換
  - 提交表單進行歸還確認

---

## 顏色方案

| 元素 | 顏色 | 用途 |
|------|------|------|
| 已完成節點 | #3498db (藍色) | 進度指示 |
| 未完成節點 | #d0d7de (灰色) | 未進行狀態 |
| 節點邊框 | #ffffff (白色) | 視覺分離 |
| 連接線(active) | #3498db (藍色) | 已完成路徑 |
| 連接線(inactive) | #d0d7de (灰色) | 待進行路徑 |
| 面板邊框 | #3498db (藍色) | 視覺強調 |
| 背景色 | #f8fafc (淺藍灰) | 區塊區隔 |

---

## 瀏覽器兼容性

✅ 支援所有現代瀏覽器：
- Chrome / Edge 90+
- Firefox 88+
- Safari 14+
- 移動瀏覽器（iOS Safari, Chrome Mobile）

---

## 文件修改清單

### 1. [return_management.php](return_management.php)
- ✅ 修改表格 HTML 結構，添加展開行
- ✅ 計算進度狀態（$progressStatus）
- ✅ 新增水平進度條 HTML
- ✅ 新增詳細資訊區塊 HTML
- ✅ 添加 JavaScript 函數：
  - `toggleAccordion()`
  - `initializeSteppers()`
  - `updateStepper()`

### 2. [styles.css](styles.css)
- ✅ 新增 Accordion 相關樣式（.accordion-*)
- ✅ 新增進度條樣式（.stepper-*)
- ✅ 新增詳細資訊樣式（.accordion-details, .details-grid）
- ✅ 新增響應式 media queries
  - 1024px 斷點
  - 768px 斷點（轉為垂直）
  - 480px 超小屏幕

---

## 使用說明

### 開發者使用

1. **修改進度狀態值** - 編輯 PHP 中的狀態計算邏輯
2. **自訂顏色** - 修改 CSS 中的 `--primary-color` 或具體的顏色值
3. **調整動畫速度** - 修改 `animation: slideDown 0.3s ease-out` 的時間參數
4. **更改進度階段** - 修改 `.stepper-step` 數量和標籤

### 最終用戶使用

1. 點擊「我的申請」進入申請記錄頁面
2. 點擊任一行記錄展開詳細信息
3. 查看水平進度條了解申請進度
4. 根據狀態進行相應操作（報到、歸還等）

---

## 注意事項

1. ⚠️ Accordion 內的表單提交必須添加 `event.stopPropagation()` 防止誤觸發
2. ⚠️ 進度條更新基於資料庫的 `checkin_logs` 和 `returned_at` 字段
3. ⚠️ 手機版進度條變為垂直排列，需確保足夠的屏幕高度
4. ✅ 所有時間戳自動從資料庫讀取，無需手動輸入

---

## 未來擴展建議

1. 📋 添加審核意見欄位
2. 📸 支持上傳圖片或證明文件
3. 🔔 集成通知系統（進度狀態變更時自動推送）
4. 📊 統計儀表板（展示各階段申請數量）
5. 🔍 搜索和篩選功能（按日期、狀態等）

---

## 技術棧

- **後端**：PHP 7.4+
- **前端**：原生 HTML/CSS/JavaScript（無外部依賴）
- **資料庫**：MySQL
- **樣式框架**：自訂 CSS（無 Tailwind/Bootstrap）

---

## 聯絡支持

如有任何問題或建議，請檢查以下文件：
- [return_management.php](return_management.php) - 主邏輯
- [styles.css](styles.css) - 樣式定義
- 資料庫 `reservations` 和 `checkin_logs` 表結構

