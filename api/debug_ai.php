<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain');
echo "Attempting to parse ai.php...\n";
$code = file_get_contents(__DIR__ . '/ai.php');
echo "File size: " . strlen($code) . " bytes\n";
echo "First 100 chars: " . substr($code, 0, 100) . "\n";

// Try to tokenize it
try {
    $tokens = token_get_all($code);
    echo "Tokenization: OK (" . count($tokens) . " tokens)\n";
} catch (Throwable $e) {
    echo "Tokenization error: " . $e->getMessage() . "\n";
}

// Try to include it in a controlled way
echo "\nAttempting require...\n";
$_GET['action'] = 'nonexistent_test_action';
$_SERVER['REQUEST_METHOD'] = 'GET';
try {
    require __DIR__ . '/ai.php';
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . " in " . $e->getFile() . " line " . $e->getLine() . "\n";
}
