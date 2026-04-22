<?php
$lines = file('borrow.php');
$lines[208] = "                        \$borrowError = 'Certificate invalid.';\n";
$lines[209] = "\n";
$lines[212] = "                                \$borrowError = 'Equipment not found.';\n";
$lines[213] = "\n";
$lines[218] = "                                \$borrowError = \"{\$selEq['equipment_name']} limit exceeded.\";\n";
$lines[219] = "\n";
$lines[223] = "                                \$borrowError = \"{\$selEq['equipment_name']} not enough available.\";\n";
$lines[224] = "\n";

file_put_contents('borrow.php', implode('', $lines));
