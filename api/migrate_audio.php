<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE progress ADD COLUMN audio_url VARCHAR(500) DEFAULT NULL AFTER practice_seconds');
    echo "Added audio_url column to progress table.";
} catch (Exception $e) {
    echo "Column may already exist: " . $e->getMessage();
}
