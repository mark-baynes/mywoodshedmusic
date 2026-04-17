<?php
require_once __DIR__ . '/helpers.php';

// Quick migration: add lesson_content column
$db = getDB();

try {
    $db->exec('ALTER TABLE content ADD COLUMN lesson_content MEDIUMTEXT DEFAULT NULL AFTER url');
    echo json_encode(['success' => true, 'message' => 'Added lesson_content column']);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo json_encode(['success' => true, 'message' => 'Column already exists']);
    } else {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
