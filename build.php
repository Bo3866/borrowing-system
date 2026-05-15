<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

$amendContent = file_get_contents("amend_application.php");
$borrowContent = file_get_contents("borrow.php");

$phpBlockPos = strpos($amendContent, "<!DOCTYPE html>");
$phpBlock = substr($amendContent, 0, $phpBlockPos);

$htmlBlockPos = strpos($borrowContent, "<!DOCTYPE html>");
$htmlBlock = substr($borrowContent, $htmlBlockPos);

$htmlBlock = str_replace("<title>場地借用申請</title>", "<title>修改借用申請</title>", $htmlBlock);
$htmlBlock = str_replace("<h2>場地借用申請</h2>", "<h2>修改借用申請</h2>", $htmlBlock);

// Replace all $draftData with $revisionData carefully
$htmlBlock = str_replace("\$draftData", "\$revisionData", $htmlBlock);

// Replace form action
$htmlBlock = preg_replace("/action=\"borrow\.php[^\"]*\"/", "action=\"amend_application.php?id=<?php echo htmlspecialchars(\$id ?? '', ENT_QUOTES, 'UTF-8'); ?>\"", $htmlBlock);

file_put_contents("amend_application.php", $phpBlock . $htmlBlock);
echo "Done replacing HTML in amend_application.php!\n";

