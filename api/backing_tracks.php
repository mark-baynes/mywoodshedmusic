<?php
// My Woodshed Music — Backing Tracks API (student access)
// GET /api/backing_tracks.php — returns backing tracks for the student's teacher

require_once __DIR__ . '/helpers.php';

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData) jsonResponse(['error' => 'Unauthorized'], 401);

$db = getDB();

// Get teacher_id — either from teacher token or student token
$teacherId = $tokenData['teacher_id'] ?? null;
if (!$teacherId) jsonResponse(['error' => 'No teacher context'], 400);

// Auto-migrate: add column if missing
try {
    $db->query('SELECT is_backing_track FROM content LIMIT 1');
} catch (Exception $e) {
    $db->exec('ALTER TABLE content ADD COLUMN is_backing_track TINYINT(1) NOT NULL DEFAULT 0');
}

$stmt = $db->prepare('SELECT id, title, description, url, type, track FROM content WHERE teacher_id = ? AND is_backing_track = 1 AND url IS NOT NULL AND url != "" ORDER BY title');
$stmt->execute([$teacherId]);
jsonResponse($stmt->fetchAll());
