<?php
// My Woodshed Music — Students API
// GET    /api/students.php          — list all students
// POST   /api/students.php          — add a student
// PUT    /api/students.php?id=X     — update a student
// DELETE /api/students.php?id=X     — remove a student

require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $stmt = $db->prepare('SELECT id, name, level, notes, pin, created_at FROM students WHERE teacher_id = ? ORDER BY name');
        $stmt->execute([$teacherId]);
        jsonResponse($stmt->fetchAll());
        break;

    case 'POST':
        $body = getBody();
        $name = trim($body['name'] ?? '');
        if (!$name) jsonResponse(['error' => 'Name required'], 400);

        $id = generateId();
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $stmt = $db->prepare('INSERT INTO students (id, teacher_id, name, level, notes, pin) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $teacherId, $name, $body['level'] ?? '', $body['notes'] ?? '', $pin]);

        jsonResponse(['id' => $id, 'name' => $name, 'level' => $body['level'] ?? '', 'notes' => $body['notes'] ?? '', 'pin' => $pin], 201);
        break;

    case 'PUT':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Student ID required'], 400);

        $body = getBody();
        $stmt = $db->prepare('UPDATE students SET name = COALESCE(?, name), level = COALESCE(?, level), notes = COALESCE(?, notes) WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$body['name'] ?? null, $body['level'] ?? null, $body['notes'] ?? null, $id, $teacherId]);

        jsonResponse(['success' => true]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Student ID required'], 400);

        $stmt = $db->prepare('DELETE FROM students WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$id, $teacherId]);

        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
