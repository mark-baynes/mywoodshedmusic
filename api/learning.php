<?php
// Learning profile API — student preferences, check-ins, teacher observations
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json');
    echo json_encode(['error' => "PHP Error: $errstr", 'file' => $errfile, 'line' => $errline]);
    exit;
});
set_exception_handler(function($e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Exception: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    exit;
});
require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();
$db = getDB();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($action) {

    // ===== STUDENT PREFERENCES =====
    case 'preferences':
        $studentId = $_GET['student_id'] ?? '';
        if (!$studentId) jsonResponse(['error' => 'student_id required'], 400);

        if ($method === 'GET') {
            $stmt = $db->prepare('SELECT * FROM student_preferences WHERE student_id = ?');
            $stmt->execute([$studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            jsonResponse($row ?: (object)[]);
        }

        if ($method === 'POST' || $method === 'PUT') {
            $body = getBody();
            $stmt = $db->prepare('INSERT INTO student_preferences (student_id, workload_pref, task_size_pref, stress_level, practice_time_pref, learning_style, free_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    workload_pref = VALUES(workload_pref),
                    task_size_pref = VALUES(task_size_pref),
                    stress_level = VALUES(stress_level),
                    practice_time_pref = VALUES(practice_time_pref),
                    learning_style = VALUES(learning_style),
                    free_notes = VALUES(free_notes)');
            $stmt->execute([
                $studentId,
                $body['workload_pref'] ?? 'moderate',
                $body['task_size_pref'] ?? 'mixed',
                $body['stress_level'] ?? 3,
                $body['practice_time_pref'] ?? 30,
                $body['learning_style'] ?? null,
                $body['free_notes'] ?? null
            ]);
            jsonResponse(['success' => true]);
        }
        break;

    // ===== WEEKLY CHECK-INS =====
    case 'checkins':
        $studentId = $_GET['student_id'] ?? '';
        if (!$studentId) jsonResponse(['error' => 'student_id required'], 400);

        if ($method === 'GET') {
            $stmt = $db->prepare('SELECT * FROM student_checkins WHERE student_id = ? ORDER BY created_at DESC LIMIT 20');
            $stmt->execute([$studentId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // camelCase
            $result = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'studentId' => $r['student_id'],
                    'weekLabel' => $r['week_label'],
                    'feltWorkload' => $r['felt_workload'],
                    'feltDifficulty' => $r['felt_difficulty'],
                    'feltEnjoyment' => (int)$r['felt_enjoyment'],
                    'freeComment' => $r['free_comment'],
                    'createdAt' => $r['created_at']
                ];
            }, $rows);
            jsonResponse($result);
        }

        if ($method === 'POST') {
            $body = getBody();
            $stmt = $db->prepare('INSERT INTO student_checkins (student_id, week_label, felt_workload, felt_difficulty, felt_enjoyment, free_comment) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $studentId,
                $body['week_label'] ?? null,
                $body['felt_workload'] ?? 'just_right',
                $body['felt_difficulty'] ?? 'just_right',
                $body['felt_enjoyment'] ?? 3,
                $body['free_comment'] ?? null
            ]);
            jsonResponse(['success' => true, 'id' => $db->lastInsertId()], 201);
        }
        break;

    // ===== TEACHER OBSERVATIONS =====
    case 'observations':
        $studentId = $_GET['student_id'] ?? '';
        if (!$studentId) jsonResponse(['error' => 'student_id required'], 400);

        if ($method === 'GET') {
            
            $stmt = $db->prepare('SELECT * FROM teacher_observations WHERE student_id = ? AND teacher_id = ? ORDER BY created_at DESC LIMIT 50');
            $stmt->execute([$studentId, $teacherId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'studentId' => $r['student_id'],
                    'note' => $r['note'],
                    'createdAt' => $r['created_at']
                ];
            }, $rows);
            jsonResponse($result);
        }

        if ($method === 'POST') {
            $body = getBody();
            if (empty($body['note'])) jsonResponse(['error' => 'note required'], 400);
            
            $stmt = $db->prepare('INSERT INTO teacher_observations (student_id, teacher_id, note) VALUES (?, ?, ?)');
            $stmt->execute([$studentId, $teacherId, $body['note']]);
            jsonResponse(['success' => true, 'id' => $db->lastInsertId()], 201);
        }

        if ($method === 'DELETE') {
            $id = $_GET['id'] ?? '';
            if (!$id) jsonResponse(['error' => 'id required'], 400);
            
            $stmt = $db->prepare('DELETE FROM teacher_observations WHERE id = ? AND teacher_id = ?');
            $stmt->execute([$id, $teacherId]);
            jsonResponse(['success' => true]);
        }
        break;

    case 'toolbox_access':
        $studentId = $_GET['student_id'] ?? '';
        if (!$studentId) jsonResponse(['error' => 'student_id required'], 400);

        // Auto-create table
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS student_toolbox_access (
                student_id VARCHAR(100) PRIMARY KEY,
                allowed_scales TEXT,
                allowed_chords TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )');
        } catch (Exception $e) {}

        if ($method === 'GET') {
            $stmt = $db->prepare('SELECT * FROM student_toolbox_access WHERE student_id = ?');
            $stmt->execute([$studentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                jsonResponse([
                    'allowed_scales' => $row['allowed_scales'] ? json_decode($row['allowed_scales'], true) : null,
                    'allowed_chords' => $row['allowed_chords'] ? json_decode($row['allowed_chords'], true) : null,
                ]);
            } else {
                jsonResponse((object)[]);
            }
        }

        if ($method === 'POST' || $method === 'PUT') {
            $body = getBody();
            $stmt = $db->prepare('INSERT INTO student_toolbox_access (student_id, allowed_scales, allowed_chords)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    allowed_scales = VALUES(allowed_scales),
                    allowed_chords = VALUES(allowed_chords)');
            $stmt->execute([
                $studentId,
                isset($body['allowed_scales']) ? json_encode($body['allowed_scales']) : null,
                isset($body['allowed_chords']) ? json_encode($body['allowed_chords']) : null,
            ]);
            jsonResponse(['success' => true]);
        }
        break;

    case 'debug':
        jsonResponse(['teacherId' => $teacherId, 'type' => gettype($teacherId), 'method' => $method]);
        break;

    default:
        jsonResponse(['error' => 'Unknown action. Use ?action=preferences|checkins|observations|toolbox_access'], 400);
}
