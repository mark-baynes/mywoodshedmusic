<?php
// My Woodshed Music — Authentication API
// POST /api/auth.php?action=register      — create teacher account
// POST /api/auth.php?action=login         — teacher login
// POST /api/auth.php?action=student_login — student login with PIN (supports PIN-only or studentId+PIN)

require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$body = getBody();

switch ($action) {

    case 'register':
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$name || !$email || strlen($password) < 6) {
            jsonResponse(['error' => 'Name, email, and password (min 6 chars) required'], 400);
        }

        $db = getDB();
        $existing = $db->prepare('SELECT id FROM teachers WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetch()) {
            jsonResponse(['error' => 'Email already registered'], 409);
        }

        $id = generateId();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO teachers (id, name, email, password_hash) VALUES (?, ?, ?, ?)');
        $stmt->execute([$id, $name, $email, $hash]);

        $token = createToken(['teacher_id' => $id, 'role' => 'teacher']);
        jsonResponse(['token' => $token, 'teacher' => ['id' => $id, 'name' => $name, 'email' => $email]]);
        break;

    case 'login':
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            jsonResponse(['error' => 'Email and password required'], 400);
        }

        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM teachers WHERE email = ?');
        $stmt->execute([$email]);
        $teacher = $stmt->fetch();

        if (!$teacher || !password_verify($password, $teacher['password_hash'])) {
            jsonResponse(['error' => 'Invalid email or password'], 401);
        }

        $token = createToken(['teacher_id' => $teacher['id'], 'role' => 'teacher']);
        jsonResponse(['token' => $token, 'teacher' => [
            'id' => $teacher['id'],
            'name' => $teacher['name'],
            'email' => $teacher['email']
        ]]);
        break;

    case 'student_login':
        $studentId = trim($body['student_id'] ?? $body['studentId'] ?? '');
        $pin = trim($body['pin'] ?? '');

        if (!$pin) {
            jsonResponse(['error' => 'PIN required'], 400);
        }

        $db = getDB();

        if ($studentId) {
            // Lookup by student ID + PIN (used with ?student=ID shareable links)
            $stmt = $db->prepare('SELECT * FROM students WHERE id = ? AND pin = ?');
            $stmt->execute([$studentId, $pin]);
        } else {
            // PIN-only lookup (v2 — PIN is unique across all students)
            $stmt = $db->prepare('SELECT * FROM students WHERE pin = ?');
            $stmt->execute([$pin]);
        }

        $student = $stmt->fetch();

        if (!$student) {
            jsonResponse(['error' => 'Invalid PIN'], 401);
        }

        $token = createToken(['student_id' => $student['id'], 'teacher_id' => $student['teacher_id'], 'role' => 'student']);
        jsonResponse(['token' => $token, 'student' => [
            'id' => $student['id'],
            'name' => $student['name'],
            'level' => $student['level'],
            'teacher_id' => $student['teacher_id']
        ]]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: register, login, student_login'], 400);
}
