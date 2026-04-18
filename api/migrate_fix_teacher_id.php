<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE teacher_observations MODIFY COLUMN teacher_id VARCHAR(100) NOT NULL');
    echo "Fixed teacher_id column to VARCHAR(100).\n";
    // Clean up bad rows with teacher_id = 0
    $db->exec("DELETE FROM teacher_observations WHERE teacher_id = '0' OR teacher_id = ''");
    echo "Cleaned up invalid rows.\n";
    echo "Done!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
