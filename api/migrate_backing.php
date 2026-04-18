<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    $db->exec('ALTER TABLE content ADD COLUMN is_backing_track TINYINT(1) NOT NULL DEFAULT 0');
    echo "Added is_backing_track column to content table.\n";
} catch (Exception $e) {
    echo "is_backing_track: " . $e->getMessage() . "\n";
}
// Mark any existing "Backing Track" type content
$db->exec("UPDATE content SET is_backing_track = 1 WHERE type = 'Backing Track'");
echo "Marked existing Backing Track content.\nDone!";
