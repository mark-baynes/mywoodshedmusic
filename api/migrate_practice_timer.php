<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE progress ADD COLUMN practice_seconds INT DEFAULT 0 AFTER feedback_note');
    echo "Added practice_seconds column to progress table.";
} catch (Exception $e) {
    echo "Column may already exist: " . $e->getMessage();
}
