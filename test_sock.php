<?php
$s = @fsockopen('api.apify.com', 443, $errno, $errstr, 3);
if ($s) {
    echo "CONNECTED\n";
    fclose($s);
} else {
    echo "FAILED: $errstr ($errno)\n";
}
