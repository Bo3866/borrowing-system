<?php

$file = 'c:\xampp\htdocs\borrowing-system\borrow.php';
$content = file_get_contents($file);

$js = <<<'EOF'
            // 如果數量大於 1，新增成功一筆後我們需要更新剩餘數量，可以把剛剛加入的減掉或者重新fetch（這裡用簡單的前端扣減，不然太頻繁打API）
            // 在這套系統中，我們是手動輸入「要借幾個」，然後加入購物單
            form.reset();
        });
        
        // ================== 月曆即時計算與渲染邏輯 ==================
        
        const calData = {
            currentDate: new Date(), // 當前決定顯示哪一年的哪一個月
            selectedItemType: null,
            selectedItemId: null,
            selectedItemName: null,
            totalCapacity: 0,
            reservations: [],
            periodSlots: <?php echo json_encode($periodSlots); ?>, // 節次設定從後端帶入
            periodOrder: <?php echo json_encode($periodOrder); ?>  // D0, D1... 等等順序
        };
        
        const calUI = {
            select: document.getElementById('calItemSelect'),
            prevBtn: document.getElementById('calPrevBtn'),
            nextBtn: document.getElementById('calNextBtn'),
            monthLabel: document.getElementById('calMonthLabel'),
            loading: document.getElementById('calLoading'),
            emptyMsg: document.getElementById('calEmptyMsg'),
            container: document.getElementById('calContainer'),
            grid: document.getElementById('calGrid'),
            details: document.getElementById('calDetails'),
            detailsTitle: document.getElementById('calDetailsTitle'),
            detailsGrid: document.getElementById('calDetailsGrid')
        };
        
        // 基礎設置: 設定預設月份(可以為今天)
        calData.currentDate.setDate(1); // 固定為1號
        
        function updateCalendarHeader() {
            const y = calData.currentDate.getFullYear();
            const m = calData.currentDate.getMonth() + 1; // 0-based
            if(calUI.monthLabel) calUI.monthLabel.textContent = `${y} 年 ${m} 月`;
        }
        
        // 解析選擇的項目
        if(calUI.select) {
            calUI.select.addEventListener('change', function(e) {
                const val = e.target.value;
                if (!val) {
                    calData.selectedItemType = null;
                    calData.selectedItemId = null;
                    calData.selectedItemName = null;
                    calUI.emptyMsg.style.display = 'block';
                    calUI.container.style.display = 'none';
                    calUI.details.style.display = 'none';
                    return;
                }
                
                const parts = val.split('|');
                calData.selectedItemType = parts[0];
                calData.selectedItemId = parts[1];
                calData.selectedItemName = e.target.options[e.target.selectedIndex].text;
                
                calUI.emptyMsg.style.display = 'none';
                calUI.container.style.display = 'block';
                fetchAvailability();
            });
        }
        
        // 月份切換
        if(calUI.prevBtn) {
            calUI.prevBtn.addEventListener('click', () => {
                calData.currentDate.setMonth(calData.currentDate.getMonth() - 1);
                updateCalendarHeader();
                if(calData.selectedItemId) fetchAvailability();
            });
        }
        
        if(calUI.nextBtn) {
            calUI.nextBtn.addEventListener('click', () => {
                calData.currentDate.setMonth(calData.currentDate.getMonth() + 1);
                updateCalendarHeader();
                if(calData.selectedItemId) fetchAvailability();
            });
        }
        
        // 抓取線上預約紀錄
        function fetchAvailability() {
            calUI.loading.style.display = 'block';
            calUI.grid.style.visibility = 'hidden';
            calUI.details.style.display = 'none';
            
            const year = calData.currentDate.getFullYear();
            const month = calData.currentDate.getMonth() + 1;
            
            fetch(`api_get_availability.php?type=${calData.selectedItemType}&id=${calData.selectedItemId}&year=${year}&month=${month}`)
                .then(res => res.json())
                .then(data => {
                    calUI.loading.style.display = 'none';
                    calUI.grid.style.visibility = 'visible';
                    if (data.error) {
                        alert('載入失敗：' + data.error);
                        return;
                    }
                    calData.totalCapacity = data.total_capacity || 0;
                    calData.reservations = data.reservations || [];
                    renderCalendar();
                })
                .catch(err => {
                    console.error('Calendar Fetch Error:', err);
                    calUI.loading.style.display = 'none';
                    alert('無法讀取即時狀態，請重試。');
                });
        }
        
        // 畫出月曆
        function renderCalendar() {
            calUI.grid.innerHTML = '';
            
            const y = calData.currentDate.getFullYear();
            const m = calData.currentDate.getMonth(); // 0-based
            const firstDayObj = new Date(y, m, 1);
            const lastDayObj = new Date(y, m + 1, 0); // 這個月的最後一天
            
            let currentDayOfWeek = firstDayObj.getDay(); // 0=Sun, 1=Mon...
            const daysInMonth = lastDayObj.getDate();
            
            // 補空前導格子
            for (let i = 0; i < currentDayOfWeek; i++) {
                const emptyCell = document.createElement('div');
                emptyCell.className = 'cal-day-cell empty';
                calUI.grid.appendChild(emptyCell);
            }
            
            // 印出每天的格子
            for (let d = 1; d <= daysInMonth; d++) {
                const dateStr = `${y}-${String(m+1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                const cell = document.createElement('div');
                cell.className = 'cal-day-cell';
                
                // 計算這一天「整天」的大致狀態 (找出一天中最糟的時段狀態)
                const dayStatus = calculateDayStatus(dateStr);
                
                const dateHeader = document.createElement('div');
                dateHeader.className = 'cal-day-date';
                dateHeader.textContent = String(d);
                
                const statusBox = document.createElement('div');
                statusBox.className = `cal-day-status status-${dayStatus.color}`;
                statusBox.innerHTML = `<span>${dayStatus.text}</span>`;
                
                cell.appendChild(dateHeader);
                cell.appendChild(statusBox);
                
                // 點擊查看詳細
                cell.onclick = () => showDayDetails(dateStr, dayStatus);
                
                calUI.grid.appendChild(cell);
            }
        }
        
        // 計算一日的大致狀態（用來標示月曆上的顏色）
        function calculateDayStatus(dateStr) {
            // 這個日期的 00:00 秒數，若比今天還早就顯示未知/過去
            const targetDateObj = new Date(dateStr + 'T00:00:00');
            const today = new Date();
            today.setHours(0,0,0,0);
            if (targetDateObj < today) {
                return { text: '已過去', color: 'unknown' };
            }
            
            let minAvail = calData.totalCapacity;
            let maxAvail = 0;
            
            // 迴圈算該天所有時段
            calData.periodOrder.forEach(pCode => {
                const pData = calData.periodSlots[pCode];
                let avail = calculateAvailableForPeriod(dateStr, pData.start, pData.end);
                if (avail < minAvail) minAvail = avail;
                if (avail > maxAvail) maxAvail = avail;
            });
            
            if (calData.totalCapacity === 0) {
                return { text: '無法提供', color: 'none' };
            }
            
            if (minAvail === calData.totalCapacity) {
                return { text: '全天可借', color: 'full' };
            } else if (minAvail === 0 && maxAvail === 0) {
                return { text: '已借滿', color: 'none' };
            } else {
                return { text: '部分時段', color: 'partial' };
            }
        }
        
        // 核心數學：計算一個指定日期的特定時區，有多少物品剩餘
        function calculateAvailableForPeriod(dateStr, startPeriodTime, endPeriodTime) {
            // pStart, pEnd: 在那天的時間戳記
            let pStart = new Date(`${dateStr}T${startPeriodTime}`).getTime();
            let pEnd = new Date(`${dateStr}T${endPeriodTime}`).getTime();
            
            let used = 0;
            calData.reservations.forEach(r => {
                // r.start: "2026-05-03 08:10:00" -> 轉換成 "T" 相容 JS 解析
                let rStart = new Date(r.start.replace(' ', 'T')).getTime();
                let rEnd = new Date(r.end.replace(' ', 'T')).getTime();
                
                // 重疊條件判定 NOT (end1 <= start2 OR start1 >= end2)
                if (!(pEnd <= rStart || pStart >= rEnd)) {
                    used += r.qty;
                }
            });
            
            let finalAvail = calData.totalCapacity - used;
            return Math.max(0, finalAvail);
        }
        
        // 點開詳細節次清單
        function showDayDetails(dateStr, dayStatus) {
            calUI.details.style.display = 'block';
            calUI.detailsTitle.textContent = `【${calData.selectedItemName}】 ${dateStr} 詳細時段狀態`;
            calUI.detailsGrid.innerHTML = '';
            
            calData.periodOrder.forEach(pCode => {
                const pData = calData.periodSlots[pCode];
                let avail = calculateAvailableForPeriod(dateStr, pData.start, pData.end);
                
                const itemDiv = document.createElement('div');
                itemDiv.className = 'period-item';
                
                let bgColor = '#dcfce7'; // green
                let textColor = '#166534';
                let text = `剩餘 ${avail}`;
                
                if (avail === 0) {
                    bgColor = '#fee2e2'; // red
                    textColor = '#991b1b';
                    text = '已滿';
                } else if (avail < calData.totalCapacity) {
                    bgColor = '#fef9c3'; // yellow
                    textColor = '#854d0e';
                }
                
                // 如果是空間，顯示「可借用」、「已被約走」而不是剩餘1
                if (calData.selectedItemType === 'space') {
                    if (avail === 0) {
                        text = '被約走';
                    } else {
                        text = '可借用';
                    }
                }

                itemDiv.style.backgroundColor = bgColor;
                itemDiv.style.color = textColor;
                
                itemDiv.innerHTML = `
                    <div style="font-weight:bold; margin-bottom:3px;">${pData.label}</div>
                    <div style="font-size:11px; color:#666; margin-bottom:5px;">${pData.start.substring(0,5)} ~ ${pData.end.substring(0,5)}</div>
                    <div style="font-weight:bold; font-size:14px; border-top:1px solid rgba(0,0,0,0.1); padding-top:3px;">${text}</div>
                `;
                calUI.detailsGrid.appendChild(itemDiv);
            });
            
            // Scroll to details
            calUI.details.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        // 初始化月曆 header
        updateCalendarHeader();
EOF;

// Replace the end of JS block before the end of script
if (strpos($content, $js) === false) {
    if (strpos($content, 'form.reset();') !== false) {
        // Just inject before the last closing tags
        $content = preg_replace('/(\s+form\.reset\(\);\s+)(\s*}\);\s*<\/script>\s*<\/body>\s*<\/html>)/si', "$1" . $js . "$2", $content);
    }
}

$css = <<<'EOF'
    <style>
        .calendar-card { margin-top: 20px; }
        .cal-controls { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; }
        .cal-controls select { padding: 8px; border-radius: 4px; border: 1px solid #ccc; min-width: 250px; }
        .cal-month-nav { display: flex; align-items: center; justify-content: center; gap: 20px; margin-bottom: 15px; }
        .cal-month-nav button { padding: 5px 15px; cursor: pointer; border: 1px solid #ccc; background: white; border-radius: 4px; }
        .cal-month-label { font-size: 18px; font-weight: bold; width: 120px; text-align: center; }
        
        .cal-grid-header { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-weight: bold; background: #f3f4f6; color: #4b5563; padding: 10px 0; border: 1px solid #e5e7eb; border-bottom: none; border-radius: 6px 6px 0 0; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); border-left: 1px solid #e5e7eb; border-top: 1px solid #e5e7eb; background: white; }
        .cal-day-cell { border-right: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; min-height: 100px; padding: 8px; display: flex; flex-direction: column; cursor: pointer; transition: background 0.2s; position: relative; }
        .cal-day-cell:hover { background: #f9fafb; outline: 2px solid #3b82f6; z-index: 1; }
        .cal-day-cell.empty { background: #f9fafb; cursor: default; }
        .cal-day-cell.empty:hover { outline: none; z-index: auto; }
        
        .cal-day-date { font-weight: bold; margin-bottom: 5px; color: #374151; font-size: 14px; }
        .cal-day-status { flex-grow: 1; display: flex; align-items: center; justify-content: center; border-radius: 4px; font-size: 12px; font-weight: bold; text-align: center; padding: 4px; }
        
        /* 狀態顏色 */
        .status-full { background-color: #dcfce7; color: #166534; border: 1px solid #bbf7d0; } /* 全天空閒 */
        .status-partial { background-color: #fef9c3; color: #854d0e; border: 1px solid #fef08a; } /* 部分時段被借 */
        .status-none { background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; } /* 皆已借滿 */
        .status-unknown { background-color: #f3f4f6; color: #6b7280; border: 1px dashed #d1d5db; } /* 過去日期 */
        
        .cal-loading { text-align: center; padding: 40px; color: #6b7280; display: none; }
        .cal-empty-msg { text-align: center; padding: 40px; color: #6b7280; background: #f9fafb; border-radius: 6px; border: 1px dashed #d1d5db; }
        
        .cal-details-panel { margin-top: 20px; padding: 15px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f9fafb; display: none; }
        .cal-details-title { font-weight: bold; font-size: 16px; margin-bottom: 15px; color: #1f2937; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }
        .cal-details-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
        .period-item { background: white; border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px; text-align: center; }
    </style>
EOF;

if (strpos($content, 'cal-grid-header') === false) {
    if (strpos($content, '</head>') !== false) {
        $content = str_replace('</head>', $css . "\n</head>", $content);
    }
}

file_put_contents($file, $content);
echo "Patched borrow.php successfully\n";
