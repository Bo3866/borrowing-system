<?php
$s = file_get_contents(__DIR__ . '/../borrow.php');
echo "paren_diff:" . (substr_count($s, '(') - substr_count($s, ')')) . "\n";
echo "brace_diff:" . (substr_count($s, '{') - substr_count($s, '}')) . "\n";
echo "bracket_diff:" . (substr_count($s, '[') - substr_count($s, ']')) . "\n";
// Output line number where first unmatched parenthesis may occur (naive)
$lines = explode("\n", $s);
$paren = 0; $brace = 0; $brack = 0;
foreach ($lines as $i => $line) {
    $paren += substr_count($line, '(') - substr_count($line, ')');
    $brace += substr_count($line, '{') - substr_count($line, '}');
    $brack += substr_count($line, '[') - substr_count($line, ']');
    if ($paren != 0) {
        echo "first_paren_unbalanced_at_line:" . ($i+1) . " paren_balance:" . $paren . "\n";
        break;
    }
}
// find first line where brace balance is negative (extra closing brace)
$paren = $brace = $brack = 0;
foreach ($lines as $i => $line) {
    $brace += substr_count($line, '{') - substr_count($line, '}');
    if ($brace < 0) {
        echo "first_negative_brace_at_line:" . ($i+1) . " brace_balance:" . $brace . "\n";
        break;
    }
}
