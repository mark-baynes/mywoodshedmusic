<?php
// PDF upload endpoint
require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required'], 405);
}

if (!isset($_FILES['pdf'])) {
    jsonResponse(['error' => 'No PDF file uploaded'], 400);
}

$file = $_FILES['pdf'];
$allowed = ['application/pdf'];
if (!in_array($file['type'], $allowed)) {
    jsonResponse(['error' => 'Only PDF files are allowed. Got: ' . $file['type']], 400);
}

if ($file['size'] > 50 * 1024 * 1024) {
    jsonResponse(['error' => 'File too large (max 50MB)'], 400);
}

$uploadDir = __DIR__ . '/../uploads/pdf/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$filename = bin2hex(random_bytes(12)) . '.pdf';
$dest = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    jsonResponse(['error' => 'Failed to save PDF file'], 500);
}

$pdfUrl = 'uploads/pdf/' . $filename;

// Optionally link to a content item
$contentId = $_POST['content_id'] ?? '';
if ($contentId) {
    $stmt = $db->prepare('UPDATE content SET pdf_url = ? WHERE id = ? AND teacher_id = ?');
    $stmt->execute([$pdfUrl, $contentId, $teacherId]);
}

jsonResponse([
    'url' => $pdfUrl,
    'filename' => $filename
], 201);
