<?php
// My Woodshed Music — Profile API
// GET  /api/profile.php                 — get own profile
// GET  /api/profile.php?teacher=1       — student gets teacher's visible profile
// PUT  /api/profile.php                 — update own profile
// POST /api/profile.php (multipart)     — upload profile photo

require_once __DIR__ . '/helpers.php';

// Flexible auth: accept teacher OR student tokens
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData) jsonResponse(['error' => 'Unauthorized'], 401);

$isStudent = (($tokenData['role'] ?? '') === 'student');
$teacherId = $tokenData['teacher_id'] ?? null;
$studentId = $tokenData['student_id'] ?? null;

$db = getDB();

// Auto-migrate: add profile columns to teachers and students
try { $db->exec('ALTER TABLE teachers ADD COLUMN phone VARCHAR(50) DEFAULT NULL'); } catch (PDOException $e) {}
try { $db->exec('ALTER TABLE teachers ADD COLUMN photo_url VARCHAR(500) DEFAULT NULL'); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE teachers ADD COLUMN visible_fields VARCHAR(500) DEFAULT 'name,email'"); } catch (PDOException $e) {}
try { $db->exec('ALTER TABLE students ADD COLUMN email VARCHAR(255) DEFAULT NULL'); } catch (PDOException $e) {}
try { $db->exec('ALTER TABLE students ADD COLUMN phone VARCHAR(50) DEFAULT NULL'); } catch (PDOException $e) {}
try { $db->exec('ALTER TABLE students ADD COLUMN photo_url VARCHAR(500) DEFAULT NULL'); } catch (PDOException $e) {}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Student requesting teacher's visible profile
        if ($isStudent && isset($_GET['teacher'])) {
            $stmt = $db->prepare('SELECT id, name, email, phone, photo_url, visible_fields FROM teachers WHERE id = ?');
            $stmt->execute([$teacherId]);
            $teacher = $stmt->fetch();
            if (!$teacher) jsonResponse(['error' => 'Teacher not found'], 404);

            $visible = explode(',', $teacher['visible_fields'] ?? 'name');
            $profile = ['id' => $teacher['id']];
            foreach ($visible as $field) {
                $field = trim($field);
                if (isset($teacher[$field])) $profile[$field] = $teacher[$field];
            }
            if (in_array('photo', $visible) && $teacher['photo_url']) {
                $profile['photo_url'] = $teacher['photo_url'];
            }
            jsonResponse($profile);
            break;
        }

        // Get own profile
        if ($isStudent) {
            $stmt = $db->prepare('SELECT id, name, email, phone, photo_url FROM students WHERE id = ?');
            $stmt->execute([$studentId]);
        } else {
            $stmt = $db->prepare('SELECT id, name, email, phone, photo_url, visible_fields FROM teachers WHERE id = ?');
            $stmt->execute([$teacherId]);
        }
        $profile = $stmt->fetch();
        if (!$profile) jsonResponse(['error' => 'Profile not found'], 404);
        jsonResponse($profile);
        break;

    case 'PUT':
        $body = getBody();

        if ($isStudent) {
            $fields = [];
            $values = [];
            foreach (['name', 'email', 'phone'] as $f) {
                if (isset($body[$f])) {
                    $fields[] = "`$f` = ?";
                    $values[] = $body[$f];
                }
            }
            if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);
            $values[] = $studentId;
            $sql = 'UPDATE students SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $db->prepare($sql)->execute($values);
        } else {
            $fields = [];
            $values = [];
            foreach (['name', 'email', 'phone'] as $f) {
                if (isset($body[$f])) {
                    $fields[] = "`$f` = ?";
                    $values[] = $body[$f];
                }
            }
            if (isset($body['visible_fields'])) {
                $allowed = ['name', 'email', 'phone', 'photo'];
                $requested = array_filter(array_map('trim', explode(',', $body['visible_fields'])));
                $valid = array_intersect($requested, $allowed);
                $fields[] = "visible_fields = ?";
                $values[] = implode(',', $valid);
            }
            if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);
            $values[] = $teacherId;
            $sql = 'UPDATE teachers SET ' . implode(', ', $fields) . ' WHERE id = ?';
            $db->prepare($sql)->execute($values);
        }
        jsonResponse(['success' => true]);
        break;

    case 'POST':
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Photo file required'], 400);
        }

        $file = $_FILES['photo'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            jsonResponse(['error' => 'Only image files allowed (jpg, png, gif, webp)'], 400);
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            jsonResponse(['error' => 'Photo must be under 2MB'], 400);
        }

        $uploadDir = __DIR__ . '/../uploads/photos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $destPath = $uploadDir . $filename;
        move_uploaded_file($file['tmp_name'], $destPath);

        $photoUrl = 'uploads/photos/' . $filename;

        if ($isStudent) {
            $db->prepare('UPDATE students SET photo_url = ? WHERE id = ?')->execute([$photoUrl, $studentId]);
        } else {
            $db->prepare('UPDATE teachers SET photo_url = ? WHERE id = ?')->execute([$photoUrl, $teacherId]);
        }

        jsonResponse(['success' => true, 'photo_url' => $photoUrl]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
