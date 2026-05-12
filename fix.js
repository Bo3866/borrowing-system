const fs = require('fs');
let c = fs.readFileSync('borrow.php', 'utf8');

c = c.replace(/\\\ = '';\s+if \([\s\S]*?\ = '借用開始時間不可為過去時間。';\s+\}\s+\}/,
\\\\ = '';
        
        if (
            \\\['borrow_start_date'] !== '' &&
            \\\['borrow_start_time'] !== '' &&
            \\\['borrow_end_date'] !== '' &&
            \\\['borrow_end_time'] !== ''
        ) {
            \\\ = \\\['borrow_start_date'] . ' ' . \\\['borrow_start_time'];
            \\\ = \\\['borrow_end_date'] . ' ' . \\\['borrow_end_time'];
            if (strtotime(\\\) < time()) {
                \\\ = '借用開始時間不可為過去時間。';
            } elseif (strtotime(\\\) <= strtotime(\\\)) {
                \\\ = '結束時間不可早於開始時間。';
            }
        }\);

fs.writeFileSync('borrow.php', c);

