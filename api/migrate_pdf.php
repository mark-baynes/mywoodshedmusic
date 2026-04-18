<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE content ADD COLUMN pdf_url VARCHAR(500) DEFAULT NULL AFTER url');
    echo "Added pdf_url column to content table.";
} catch (Exception $e) {
    echo "Column may already exist: " . $e->getMessage();
}
