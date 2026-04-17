<?php
// My Woodshed Music — Image Upload API
// POST /api/upload.php — upload an image, returns URL

require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST only'], 405);
}

if (!isset($_FILES['image'])) {
    jsonResponse(['error' => 'No image file provided'], 400);
}

$file = $_FILES['image'];
$maxSize = 5 * 1024 * 1024; // 5MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'Upload failed (code ' . $file['error'] . ')'], 400);
}

if ($file['size'] > $maxSize) {
    jsonResponse(['error' => 'File too large (max 5MB)'], 400);
}

// Validate image type
$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime, $allowed)) {
    jsonResponse(['error' => 'Only JPG, PNG, GIF, and WebP images allowed'], 400);
}

// Create uploads directory if needed
$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
$filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
    jsonResponse(['error' => 'Failed to save file'], 500);
}

$url = 'uploads/' . $filename;

jsonResponse(['url' => $url, 'filename' => $filename]);
