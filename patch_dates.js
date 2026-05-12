const fs = require('fs');

let content = fs.readFileSync('borrow.php', 'utf8');

// 1. replace backend formData and 
content = content.replace(
  /\\['borrow_date'\] = trim\(\(string\)\(\$_POST\['borrow_date'\] \?\? ''\)\);\n\s+\\['start_period_code'\] = trim\(\(string\)\(\$_POST\['start_period_code'\] \?\? ''\)\);\n\s+\\['end_period_code'\] = trim\(\(string\)\(\$_POST\['end_period_code'\] \?\? ''\)\);/g,
  \['borrow_start_date'] = trim((string)(\['borrow_start_date'] ?? ''));
      \['borrow_start_time'] = trim((string)(\['borrow_start_time'] ?? ''));
      \['borrow_end_date'] = trim((string)(\['borrow_end_date'] ?? ''));
      \['borrow_end_time'] = trim((string)(\['borrow_end_time'] ?? ''));
);

content = content.replace(
  /'borrow_date' => '',\n\s+'start_period_code' => '',\n\s+'end_period_code' => '',/g,
  'borrow_start_date' => '',\n      'borrow_start_time' => '',\n      'borrow_end_date' => '',\n      'borrow_end_time' => '',
);

fs.writeFileSync('borrow.php', content);
