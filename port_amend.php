<?php
$borrow_content = file_get_contents("borrow.php");
// It's too dangerous to try making a fully automated transform via regex because of DB constraints... let's build a solid string based replacer

