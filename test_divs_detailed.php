<?php
$html = file_get_contents('c:/AppServ/www/borrowing-system/borrow.php');
$start = strpos($html, '<div class="step-content" id="step-content-2">');
$end = strpos($html, '<div class="step-content" id="step-content-3">');

$step2 = substr($html, $start, $end - $start);
$lines = explode("\n", $step2);

$depth = 0;
foreach ($lines as $i => $line) {
    preg_match_all('/<div\b[^>]*>|<\/div>/i', $line, $matches);
    foreach ($matches[0] as $tag) {
        if (strtolower($tag) === '</div>') {
            $depth--;
            // echo sprintf("%04d %3d %s\n", $i, $depth, $tag);
        } else {
            // echo sprintf("%04d %3d %s\n", $i, $depth, $tag);
            $depth++;
        }
    }
}
echo "Final depth: $depth\n";
