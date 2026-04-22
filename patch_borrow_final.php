<?php
$c = file_get_contents('borrow.php');

$jsQuery = "
\$existingSpaceReservations = [];
if (\$dbError === '') {
    \$esrSql = \"SELECT sri.space_id, DATE(r.borrow_start_at) as borrow_date, TIME(r.borrow_start_at) as start_time, TIME(r.borrow_end_at) as end_time 
                FROM space_reservation_items sri 
                JOIN reservations r ON r.reservation_id = sri.reservation_id 
                WHERE r.approval_status IN ('pending', 'approved') AND r.borrow_end_at >= NOW()\";
    \$esrRes = mysqli_query(\$link, \$esrSql);
    if (\$esrRes) {
        while (\$r = mysqli_fetch_assoc(\$esrRes)) {
            \$existingSpaceReservations[] = [
                'space_id' => (string)\$r['space_id'],
                'date' => (string)\$r['borrow_date'],
                'start' => (string)\$r['start_time'],
                'end' => (string)\$r['end_time']
            ];
        }
    }
}
";

// Remove the `if (!in_array($spaceStatusVal, ['available', '1'], true)) { continue; }` line from space options parsing
$c = preg_replace('/if \(!in_array\(\$spaceStatusVal, \[\'available\', \'1\'\], true\)\) \{\s*continue;\s*\}/', '', $c);

// Add the JS data query before HTML output starts
$c = str_replace('?>'."\n".'<!DOCTYPE html>', $jsQuery . '?>'."\n".'<!DOCTYPE html>', $c);

$jsLogic = "
<script>
const existingSpaceReservations = <?= json_encode(\$existingSpaceReservations); ?>;
const periodSlotsMap = <?= json_encode(\$periodSlots); ?>;

document.addEventListener('DOMContentLoaded', () => {
    const spaceIdEl = document.getElementById('space_id');
    const borrowDateEl = document.getElementById('borrow_date');
    const startPeriodEl = document.getElementById('start_period_code');
    const endPeriodEl = document.getElementById('end_period_code');
    const resTypeEl = document.getElementById('resource_type');

    function updatePeriodOptions() {
        if (!resTypeEl || resTypeEl.value !== 'space') return;

        const selSpace = spaceIdEl.value;
        const selDate = borrowDateEl.value;
        
        // Reset all options
        if (startPeriodEl) Array.from(startPeriodEl.options).forEach(opt => { 
            if(opt.value) {
                opt.disabled = false; 
                opt.innerHTML = opt.innerHTML.replace(' (不可借用)', '');
            }
        });
        if (endPeriodEl) Array.from(endPeriodEl.options).forEach(opt => { 
            if(opt.value) {
                opt.disabled = false;
                opt.innerHTML = opt.innerHTML.replace(' (不可借用)', '');
            }
        });

        if (!selSpace || !selDate) return;

        // Find conflicts
        const conflicts = existingSpaceReservations.filter(r => r.space_id === selSpace && r.date === selDate);
        
        conflicts.forEach(c => {
            for (const [code, times] of Object.entries(periodSlotsMap)) {
                if (times.start < c.end && times.end > c.start) {
                    if (startPeriodEl) {
                        const opt1 = startPeriodEl.querySelector(`option[value=\"\${code}\"]`);
                        if (opt1) {
                            opt1.disabled = true;
                            if(!opt1.innerHTML.includes('不可借用')) opt1.innerHTML += ' (不可借用)';
                        }
                    }
                    if (endPeriodEl) {
                        const opt2 = endPeriodEl.querySelector(`option[value=\"\${code}\"]`);
                        if (opt2) {
                            opt2.disabled = true;
                            if(!opt2.innerHTML.includes('不可借用')) opt2.innerHTML += ' (不可借用)';
                        }
                    }
                }
            }
        });
    }

    if (spaceIdEl && borrowDateEl) {
        spaceIdEl.addEventListener('change', updatePeriodOptions);
        borrowDateEl.addEventListener('change', updatePeriodOptions);
        resTypeEl.addEventListener('change', updatePeriodOptions);
        updatePeriodOptions();
    }
});
</script>
</body>";

if (strpos($c, 'const existingSpaceReservations') === false) {
    echo "adding js logic\n";
    $c = str_replace('</body>', $jsLogic, $c);
}

file_put_contents('borrow.php', $c);

