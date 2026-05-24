<script>
document.addEventListener('DOMContentLoaded', function() {
    const fireCategories = [
        { key: 'fire_performers', label: '表演人員', min: 0 },
        { key: 'fire_oilers', label: '上油人員', min: 1 },
        { key: 'fire_extinguishers', label: '滅火人員', min: 1 },
        { key: 'fire_security', label: '維安人員', min: 3 },
        { key: 'fire_emergency', label: '緊急狀況處理人員', min: 1 },
        { key: 'fire_medical', label: '醫療人員', min: 1 }
    ];

    let initialFireData = {
        fire_performers: [],
        fire_oilers: [],
        fire_extinguishers: [],
        fire_security: [],
        fire_emergency: [],
        fire_medical: []
    };
    try {
        initialFireData = {
            fire_performers: JSON.parse('<?php echo $formData['fire_performers'] ?: '[]'; ?>'),
            fire_oilers: JSON.parse('<?php echo $formData['fire_oilers'] ?: '[]'; ?>'),
            fire_extinguishers: JSON.parse('<?php echo $formData['fire_extinguishers'] ?: '[]'; ?>'),
            fire_security: JSON.parse('<?php echo $formData['fire_security'] ?: '[]'; ?>'),
            fire_emergency: JSON.parse('<?php echo $formData['fire_emergency'] ?: '[]'; ?>'),
            fire_medical: JSON.parse('<?php echo $formData['fire_medical'] ?: '[]'; ?>')
        };
    } catch(e) {}

    const container = document.getElementById('fire_personnel_container');

    function renderFirePersonnel() {
        if (!container) return;
        container.innerHTML = '';
        fireCategories.forEach(function(cat) {
            const wrap = document.createElement('div');
            wrap.style.border = '1px solid #cbd5e1';
            wrap.style.padding = '10px';
            wrap.style.borderRadius = '4px';
            wrap.style.background = '#fff';

            const header = document.createElement('div');
            header.style.display = 'flex';
            header.style.justifyContent = 'space-between';
            header.style.alignItems = 'center';
            header.style.marginBottom = '10px';
            
            const title = document.createElement('strong');
            let minText = cat.min > 0 ?  (至少 + cat.min + 人) : '';
            title.textContent = cat.label + minText;
            header.appendChild(title);

            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'btn btn-sm btn-outline-primary';
            addBtn.innerHTML = '+ 新增';
            addBtn.onclick = function() { addFireRow(cat.key); };
            header.appendChild(addBtn);

            wrap.appendChild(header);

            const rowsWrap = document.createElement('div');
            rowsWrap.id = 'fire_wrap_' + cat.key;
            rowsWrap.style.display = 'flex';
            rowsWrap.style.flexDirection = 'column';
            rowsWrap.style.gap = '8px';
            wrap.appendChild(rowsWrap);

            container.appendChild(wrap);

            const dat = initialFireData[cat.key] || [];
            const count = Math.max(dat.length, cat.min);
            for (let i = 0; i < count; i++) {
                addFireRow(cat.key, dat[i] || '');
            }
        });
    }

    function addFireRow(key, val) {
        val = val || '';
        const wrap = document.getElementById('fire_wrap_' + key);
        if (!wrap) return;

        const row = document.createElement('div');
        row.style.display = 'flex';
        row.style.gap = '10px';

        const input = document.createElement('input');
        input.type = 'text';
        input.name = key + '[]';
        input.className = 'form-control form-control-sm';
        input.placeholder = '請輸入姓名';
        input.value = val;
        
        const rmBtn = document.createElement('button');
        rmBtn.type = 'button';
        rmBtn.className = 'btn btn-sm btn-outline-danger';
        rmBtn.innerHTML = '刪除';
        rmBtn.onclick = function() { row.parentNode.removeChild(row); };

        row.appendChild(input);
        row.appendChild(rmBtn);
        wrap.appendChild(row);
    }

    if (document.getElementById('fireDetailsSection')) {
        renderFirePersonnel();
    }

    const fdInput = document.getElementById('fire_date');
    if (fdInput) {
        const min30Date = new Date();
        min30Date.setDate(min30Date.getDate() + 30);
        const y = min30Date.getFullYear();
        const m = String(min30Date.getMonth() + 1).padStart(2, '0');
        const d = String(min30Date.getDate()).padStart(2, '0');
        fdInput.min = y + '-' + m + '-' + d;
    }

    ['start', 'end'].forEach(function(p) {
        const sh = document.getElementById('fire_time_' + p + '_h');
        const sm = document.getElementById('fire_time_' + p + '_m');
        const hidden = document.getElementById('fire_time_' + p);
        if (sh && sm && hidden) {
            const updateTime = function() { hidden.value = (sh.value && sm.value) ? sh.value + ':' + sm.value : ''; };
            sh.addEventListener('change', updateTime);
            sm.addEventListener('change', updateTime);
        }
    });

    window.validateFireForm = function() {
        if (typeof isFireEnabled === 'function' && !isFireEnabled()) return true;
        
        const actName = document.getElementById('fire_activity_name')?.value;
        const fd = document.getElementById('fire_date')?.value;
        const ts = document.getElementById('fire_time_start')?.value;
        const te = document.getElementById('fire_time_end')?.value;
        const fl = document.getElementById('fire_location')?.value;

        if (!actName || !fd || !ts || !te || !fl) {
            alert('上火確認表：請確實填寫活動名稱、日期、時間及地點！');
            return false;
        }

        const limits = {
            'fire_oilers': { min: 1, label: '上油人員' },
            'fire_extinguishers': { min: 1, label: '滅火人員' },
            'fire_security': { min: 3, label: '維安人員' },
            'fire_emergency': { min: 1, label: '緊急狀況處理人員' },
            'fire_medical': { min: 1, label: '醫療人員' }
        };

        for (let key in limits) {
            const inputs = document.querySelectorAll('input[name="' + key + '[]"]');
            let count = 0;
            inputs.forEach(function(el) { if(el.value.trim() !== '') count++; });
            if (count < limits[key].min) {
                alert('上火確認表：' + limits[key].label + '至少需要 ' + limits[key].min + ' 人，請確實填寫！');
                return false;
            }
        }
        return true;
    };
});
</script>
