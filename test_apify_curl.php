<?php
require 'vendor/autoload.php';
$token = getenv('APIFY_API_TOKEN') ?: '';

// Test 1: Standard cURL to https://api.apify.com/v2/users/me
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.apify.com/v2/users/me?token={$token}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_VERBOSE, true);

$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

$res = curl_exec($ch);
$err = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

rewind($verbose);
$verboseLog = stream_get_contents($verbose);

echo "Test 1 Result: Code={$code}, Error={$err}\n";
echo "Response: " . substr($res, 0, 300) . "\n";
echo "Verbose:\n" . $verboseLog . "\n";
