<?php
// Audio upload endpoint — saves recordings from students
require_once __DIR__ . '/helpers.php';

$tokenData = requireAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required'], 405);
}

if (!isset($_FILES['audio'])) {
    jsonResponse(['error' => 'No audio file uploaded'], 400);
}

$file = $_FILES['audio'];
$allowed = ['audio/webm', 'audio/ogg', 'audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/mp3'];
if (!in_array($file['type'], $allowed) && !preg_match('/^audio\//', $file['type'])) {
    jsonResponse(['error' => 'Invalid audio file type: ' . $file['type']], 400);
}

if ($file['size'] > 20 * 1024 * 1024) {
    jsonResponse(['error' => 'File too large (max 20MB)'], 400);
}

$uploadDir = __DIR__ . '/../uploads/audio/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = 'webm';
if (strpos($file['type'], 'mp4') !== false) $ext = 'mp4';
elseif (strpos($file['type'], 'ogg') !== false) $ext = 'ogg';
elseif (strpos($file['type'], 'wav') !== false) $ext = 'wav';
elseif (strpos($file['type'], 'mpeg') !== false || strpos($file['type'], 'mp3') !== false) $ext = 'mp3';

$filename = bin2hex(random_bytes(12)) . '.' . $ext;
$dest = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    jsonResponse(['error' => 'Failed to save audio file'], 500);
}

// Save reference in database
$stepId = $_POST['step_id'] ?? '';
$assignmentId = $_POST['assignment_id'] ?? '';
$studentId = $_POST['student_id'] ?? '';

if ($stepId && $assignmentId && $studentId) {
    $stmt = $db->prepare('
        INSERT INTO progress (student_id, assignment_id, step_id, completed, audio_url)
        VALUES (?, ?, ?, 0, ?)
        ON DUPLICATE KEY UPDATE audio_url = VALUES(audio_url)
    ');
    $stmt->execute([$studentId, $assignmentId, $stepId, 'uploads/audio/' . $filename]);
}

jsonResponse([
    'url' => 'uploads/audio/' . $filename,
    'filename' => $filename
], 201);
