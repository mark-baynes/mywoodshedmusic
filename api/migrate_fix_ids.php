<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE teacher_observations MODIFY COLUMN student_id VARCHAR(100) NOT NULL');
    echo "Fixed teacher_observations.student_id to VARCHAR.\n";
    
    $db->exec('ALTER TABLE student_preferences MODIFY COLUMN student_id VARCHAR(100) NOT NULL');
    echo "Fixed student_preferences.student_id to VARCHAR.\n";
    
    $db->exec('ALTER TABLE student_checkins MODIFY COLUMN student_id VARCHAR(100) NOT NULL');
    echo "Fixed student_checkins.student_id to VARCHAR.\n";
    
    // Clean up bad rows with truncated IDs
    $db->exec("DELETE FROM teacher_observations WHERE student_id = '2147483647'");
    echo "Cleaned up invalid rows.\n";
    
    echo "\nDone!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
