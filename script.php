<?php
\ = file_get_contents('borrow.php');
\ = str_replace(
    "'borrow_date' => '',\n      'start_period_code' => '',\n      'end_period_code' => '',",
    "'borrow_start_date' => '',\n      'borrow_start_time' => '',\n      'borrow_end_date' => '',\n      'borrow_end_time' => '',",
    \
);
\ = str_replace(
    "\['borrow_date'] = trim((string)(\['borrow_date'] ?? ''));\n      \['start_period_code'] = trim((string)(\['start_period_code'] ?? ''));\n      \['end_period_code'] = trim((string)(\['end_period_code'] ?? ''));",
    "\['borrow_start_date'] = trim((string)(\['borrow_start_date'] ?? ''));\n      \['borrow_start_time'] = trim((string)(\['borrow_start_time'] ?? ''));\n      \['borrow_end_date'] = trim((string)(\['borrow_end_date'] ?? ''));\n      \['borrow_end_time'] = trim((string)(\['borrow_end_time'] ?? ''));",
    \
);

\ = <<<EOD
          if (
              \['borrow_date'] !== '' &&
              \['start_period_code'] !== '' &&
              \['end_period_code'] !== ''
          ) {
              if (isset(\[\['start_period_code']]) && isset(\[\['end_period_code']])) {
                  \ = array_search(\['start_period_code'], \, true);
                  \ = array_search(\['end_period_code'], \, true);
                  if (\ !== false && \ !== false && \ >= \) {
                      \ = \['borrow_date'] . ' ' . \[\['start_period_code']]['start'];
                      \ = \['borrow_date'] . ' ' . \[\['end_period_code']]['end'];
                  }
              }
                      // 若選到的借用開始時間已落在過去，視為無效
                      if (\ !== '' && strtotime(\) < time()) {
                          \ = '借用開始時間不可為過去時間。';
                      }
          }
EOD;

\ = <<<EOD
          if (
              \['borrow_start_date'] !== '' &&
              \['borrow_start_time'] !== '' &&
              \['borrow_end_date'] !== '' &&
              \['borrow_end_time'] !== ''
          ) {
              \ = \['borrow_start_date'] . ' ' . \['borrow_start_time'];
              \ = \['borrow_end_date'] . ' ' . \['borrow_end_time'];
              if (strtotime(\) < time()) {
                  \ = '借用開始時間不可為過去時間。';
              } elseif (strtotime(\) <= strtotime(\)) {
                  \ = '結束時間不可早於開始時間。';
              }
          }
EOD;

\ = str_replace(\, \, \);

\ = <<<EOD
          } elseif (
              \['borrow_date'] === '' ||
              \['start_period_code'] === '' ||
              \['end_period_code'] === ''
          ) {
              \ = '請完整填寫借用日期與起訖節次。';
          } elseif (!isset(\[\['start_period_code']]) || !isset(\[\['end_period_code']])) {
              \ = '節次代號無效，請重新選擇。';
          } elseif (\['purpose'] === '') {
              \ = '請填寫用途說明。';
          } else {
              \ = array_search(\['start_period_code'], \, true);
              \ = array_search(\['end_period_code'], \, true);
  
              if (\ === false || \ === false || \ < \) {
                  \ = '結束節次不可早於開始節次。';
              } else {
EOD;

\ = <<<EOD
          } elseif (
              \['borrow_start_date'] === '' ||
              \['borrow_start_time'] === '' ||
              \['borrow_end_date'] === '' ||
              \['borrow_end_time'] === ''
          ) {
              \ = '請完整填寫借用日期與時間。';
          } elseif (\['purpose'] === '') {
              \ = '請填寫用途說明。';
          } else {
              if (strtotime(\) <= strtotime(\)) {
                  \ = '結束時間不可早於開始時間。';
              } else {
EOD;

\ = str_replace(\, \, \);

file_put_contents('borrow.php', \);
?>
