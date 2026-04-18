<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test 1: Can we reach iTunes?
$url = "https://itunes.apple.com/search?term=Blue+Bossa+jazz&media=music&limit=1";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_FOLLOWLOCATION => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo json_encode([
    'curl_works' => $response !== false,
    'http_code' => $httpCode,
    'curl_error' => $error,
    'response_length' => strlen($response ?: ''),
    'first_result' => $response ? json_decode($response, true)['results'][0]['artworkUrl100'] ?? 'none' : 'no response',
    'php_version' => PHP_VERSION,
    'allow_url_fopen' => ini_get('allow_url_fopen'),
], JSON_PRETTY_PRINT);
