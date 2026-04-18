<?php
// My Woodshed Music — Content Library API
// GET    /api/content.php            — list all content
// GET    /api/content.php?track=Jazz  — filter by track
// POST   /api/content.php            — add content
// PUT    /api/content.php?id=X       — update content
// DELETE /api/content.php?id=X       — remove content

require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $track = $_GET['track'] ?? '';
        if ($track && $track !== 'All') {
            $stmt = $db->prepare('SELECT * FROM content WHERE teacher_id = ? AND track = ? ORDER BY created_at DESC');
            $stmt->execute([$teacherId, $track]);
        } else {
            $stmt = $db->prepare('SELECT * FROM content WHERE teacher_id = ? ORDER BY created_at DESC');
            $stmt->execute([$teacherId]);
        }
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $body = getBody();
        $title = trim($body['title'] ?? '');
        if (!$title) jsonResponse(['error' => 'Title required'], 400);

        $id = generateId();
        $stmt = $db->prepare('INSERT INTO content (id, teacher_id, title, type, track, description, url, pdf_url, lesson_content) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $id, $teacherId, $title,
            $body['type'] ?? 'Practice',
            $body['track'] ?? 'Foundation',
            $body['description'] ?? '',
            $body['url'] ?? '',
            $body['pdf_url'] ?? null,
            $body['lesson_content'] ?? null
        ]);

        jsonResponse(['id' => $id, 'title' => $title, 'type' => $body['type'] ?? 'Practice', 'track' => $body['track'] ?? 'Foundation', 'description' => $body['description'] ?? '', 'url' => $body['url'] ?? '', 'pdf_url' => $body['pdf_url'] ?? null, 'lesson_content' => $body['lesson_content'] ?? null, 'created_at' => date('Y-m-d H:i:s')], 201);
        break;

    case 'PUT':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Content ID required'], 400);

        $body = getBody();
        $stmt = $db->prepare('UPDATE content SET title = COALESCE(?, title), type = COALESCE(?, type), track = COALESCE(?, track), description = COALESCE(?, description), url = COALESCE(?, url), pdf_url = COALESCE(?, pdf_url), lesson_content = COALESCE(?, lesson_content) WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$body['title'] ?? null, $body['type'] ?? null, $body['track'] ?? null, $body['description'] ?? null, $body['url'] ?? null, $body['pdf_url'] ?? null, $body['lesson_content'] ?? null, $id, $teacherId]);

        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Content ID required'], 400);

        $stmt = $db->prepare('DELETE FROM content WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$id, $teacherId]);

        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
