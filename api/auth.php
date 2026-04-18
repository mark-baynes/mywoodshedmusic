<?php
// My Woodshed Music — Authentication API
// POST /api/auth.php?action=register      — create teacher account (optional invite_code for auto-approve)
// POST /api/auth.php?action=login         — teacher login (must be approved)
// POST /api/auth.php?action=student_login — student login with PIN

require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$body = getBody();

switch ($action) {

    case 'register':
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $inviteCode = trim($body['inviteCode'] ?? '');

        if (!$name || !$email || strlen($password) < 6) {
            jsonResponse(['error' => 'Name, email, and password (min 6 chars) required'], 400);
        }

        $db = getDB();
        $existing = $db->prepare('SELECT id FROM teachers WHERE email = ?');
        $existing->execute([$email]);
        if ($existing->fetch()) {
            jsonResponse(['error' => 'Email already registered'], 409);
        }

        // Check invite code if provided
        $approved = 0;
        if ($inviteCode) {
            $codeStmt = $db->prepare('SELECT id, code FROM invite_codes WHERE code = ? AND used_by IS NULL');
            $codeStmt->execute([strtoupper($inviteCode)]);
            $codeRow = $codeStmt->fetch();
            if ($codeRow) {
                $approved = 1;
            } else {
                jsonResponse(['error' => 'Invalid or already-used invite code'], 400);
            }
        }

        $id = generateId();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO teachers (id, name, email, password_hash, approved) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$id, $name, $email, $hash, $approved]);

        // Mark invite code as used
        if ($inviteCode && $approved) {
            $db->prepare('UPDATE invite_codes SET used_by = ?, used_at = NOW() WHERE code = ?')->execute([$id, strtoupper($inviteCode)]);
        }

        if (!$approved) {
            jsonResponse(['pending' => true, 'message' => 'Account created! Your account is pending approval by the studio owner.']);
            break;
        }

        $token = createToken(['teacher_id' => $id, 'role' => 'teacher', 'is_admin' => false]);
        jsonResponse(['token' => $token, 'teacher' => ['id' => $id, 'name' => $name, 'email' => $email, 'is_admin' => false]]);
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

        // Check if approved
        if (!$teacher['approved']) {
            jsonResponse(['pending' => true, 'message' => 'Your account is pending approval by the studio owner. Please check back soon!']);
            break;
        }

        $isAdmin = (bool)($teacher['is_admin'] ?? 0);
        $token = createToken(['teacher_id' => $teacher['id'], 'role' => 'teacher', 'is_admin' => $isAdmin]);
        jsonResponse(['token' => $token, 'teacher' => [
            'id' => $teacher['id'],
            'name' => $teacher['name'],
            'email' => $teacher['email'],
            'is_admin' => $isAdmin
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
            $stmt = $db->prepare('SELECT * FROM students WHERE id = ? AND pin = ?');
            $stmt->execute([$studentId, $pin]);
        } else {
            $stmt = $db->prepare('SELECT * FROM students WHERE pin = ?');
            $stmt->execute([$pin]);
        }

        $student = $stmt->fetch();

        if (!$student) {
            jsonResponse(['error' => 'Invalid PIN'], 401);
        }

        // Get teacher name for student UI
        $teacherStmt = $db->prepare('SELECT name FROM teachers WHERE id = ?');
        $teacherStmt->execute([$student['teacher_id']]);
        $teacherRow = $teacherStmt->fetch();
        $teacherName = $teacherRow ? $teacherRow['name'] : 'your teacher';

        $token = createToken(['student_id' => $student['id'], 'teacher_id' => $student['teacher_id'], 'role' => 'student']);
        jsonResponse(['token' => $token, 'student' => [
            'id' => $student['id'],
            'name' => $student['name'],
            'level' => $student['level'],
            'teacher_id' => $student['teacher_id'],
            'teacher_name' => $teacherName
        ]]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: register, login, student_login'], 400);
}
