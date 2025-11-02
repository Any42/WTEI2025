<?php
header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');

// Set timezone to Philippines
date_default_timezone_set('Asia/Manila');

// Output current Philippines time
echo date('Y-m-d H:i:s');
?>