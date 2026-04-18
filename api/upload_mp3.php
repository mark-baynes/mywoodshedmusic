<?php
// My Woodshed Music — MP3 Upload endpoint
// POST /api/upload_mp3.php — upload MP3 file, returns URL
// Auto-migrates audio_url column on content table if missing

require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required'], 405);
}

// Auto-migrate: add audio_url column to content if missing
try {
    $db->exec('ALTER TABLE content ADD COLUMN audio_url VARCHAR(500) DEFAULT NULL');
} catch (PDOException $e) {
    // Column already exists
}

// Auto-migrate: create settings table if missing
try {
    $db->exec('CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )');
    // Default max upload size: 10 MB
    $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('max_upload_mb', '10')");
} catch (PDOException $e) {}

// Get max upload size from settings
$maxStmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'max_upload_mb'");
$maxRow = $maxStmt->fetch();
$maxMB = $maxRow ? (int)$maxRow['setting_value'] : 10;
$maxBytes = $maxMB * 1024 * 1024;

if (!isset($_FILES['mp3'])) {
    jsonResponse(['error' => 'No MP3 file uploaded'], 400);
}

$file = $_FILES['mp3'];

// Validate type
$allowed = ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'audio/x-mp3'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($file['type'], $allowed) && $ext !== 'mp3') {
    jsonResponse(['error' => 'Only MP3 files are accepted'], 400);
}

// Validate size
if ($file['size'] > $maxBytes) {
    jsonResponse(['error' => "File too large (max {$maxMB}MB)"], 400);
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'Upload error: ' . $file['error']], 400);
}

$uploadDir = __DIR__ . '/../uploads/audio/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$filename = bin2hex(random_bytes(12)) . '.mp3';
$dest = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    jsonResponse(['error' => 'Failed to save MP3 file'], 500);
}

$audioUrl = 'uploads/audio/' . $filename;

// If content_id provided, update the content record
$contentId = $_POST['content_id'] ?? '';
if ($contentId) {
    $stmt = $db->prepare('UPDATE content SET audio_url = ? WHERE id = ? AND teacher_id = ?');
    $stmt->execute([$audioUrl, $contentId, $teacherId]);
}

jsonResponse([
    'url' => $audioUrl,
    'filename' => $filename,
    'size' => $file['size'],
    'max_mb' => $maxMB
], 201);
