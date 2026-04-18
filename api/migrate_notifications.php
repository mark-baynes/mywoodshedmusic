<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('CREATE TABLE IF NOT EXISTS completion_notifications (
        assignment_id VARCHAR(100) NOT NULL,
        student_id VARCHAR(100) NOT NULL,
        notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (assignment_id, student_id)
    )');
    echo "Created completion_notifications table.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
