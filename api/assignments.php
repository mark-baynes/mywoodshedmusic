<?php
// My Woodshed Music — Assignments API
// GET    /api/assignments.php                — list all assignments (teacher or student token)
// GET    /api/assignments.php?student_id=X   — assignments for a student
// POST   /api/assignments.php                — create assignment with steps (teacher only)
// PUT    /api/assignments.php?id=X           — update assignment (teacher only)
// DELETE /api/assignments.php?id=X           — remove assignment (teacher only)

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
$studentIdFromToken = $tokenData['student_id'] ?? null;

// Students can only GET
$method = $_SERVER['REQUEST_METHOD'];
if ($isStudent && $method !== 'GET') {
    jsonResponse(['error' => 'Students can only read assignments'], 403);
}

// For teacher-only actions, require teacher role
if (!$isStudent && !$teacherId) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = getDB();

// Auto-migrate: add sort_order column
try { $db->exec('ALTER TABLE assignments ADD COLUMN sort_order INT DEFAULT 0'); } catch (PDOException $e) {}

// Helper to load steps for an assignment
function loadSteps($db, $assignmentId) {
    $stmt = $db->prepare('
        SELECT s.id, s.content_id as contentId, s.notes, s.step_order as `order`,
               c.title as content_title, c.type as content_type, c.track as content_track,
               c.description as content_description, c.url as content_url, c.lesson_content as content_lesson_content
        FROM assignment_steps s
        LEFT JOIN content c ON c.id = s.content_id
        WHERE s.assignment_id = ?
        ORDER BY s.step_order
    ');
    $stmt->execute([$assignmentId]);
    return $stmt->fetchAll();
}

// Helper to load progress for an assignment
function loadProgress($db, $assignmentId) {
    $stmt = $db->prepare('SELECT * FROM progress WHERE assignment_id = ?');
    $stmt->execute([$assignmentId]);
    return $stmt->fetchAll();
}

switch ($method) {

    case 'GET':
        if ($isStudent) {
            // Student token: return assignments for this student only
            $stmt = $db->prepare('SELECT a.* FROM assignments a WHERE a.student_id = ? ORDER BY a.sort_order ASC, a.created_at DESC');
            $stmt->execute([$studentIdFromToken]);
        } else {
            // Teacher token: original behaviour
            $studentId = $_GET['student_id'] ?? '';

            if ($studentId) {
                $stmt = $db->prepare('SELECT a.* FROM assignments a JOIN students st ON st.id = a.student_id WHERE a.student_id = ? AND st.teacher_id = ? ORDER BY a.sort_order ASC, a.created_at DESC');
                $stmt->execute([$studentId, $teacherId]);
            } else {
                $stmt = $db->prepare('SELECT a.*, s.name as student_name FROM assignments a JOIN students s ON s.id = a.student_id WHERE a.teacher_id = ? ORDER BY a.sort_order ASC, a.created_at DESC');
                $stmt->execute([$teacherId]);
            }
        }

        $assignments = $stmt->fetchAll();
        foreach ($assignments as &$a) {
            $a['steps'] = loadSteps($db, $a['id']);
            $a['progress'] = loadProgress($db, $a['id']);
            // Camel case for frontend
            $a['studentId'] = $a['student_id'];
            $a['weekLabel'] = $a['week_label'];
            $a['createdAt'] = $a['created_at'];
            $a['sortOrder'] = (int)($a['sort_order'] ?? 0);
        }

        jsonResponse($assignments);
        break;

    case 'POST':
        $body = getBody();
        $studentId = $body['studentId'] ?? '';
        $weekLabel = trim($body['weekLabel'] ?? 'This Week');
        $steps = $body['steps'] ?? [];

        if (!$studentId || empty($steps)) {
            jsonResponse(['error' => 'Student ID and at least one step required'], 400);
        }

        // Verify student belongs to teacher
        $check = $db->prepare('SELECT id FROM students WHERE id = ? AND teacher_id = ?');
        $check->execute([$studentId, $teacherId]);
        if (!$check->fetch()) jsonResponse(['error' => 'Student not found'], 404);

        $assignmentId = generateId();
        // Get next sort_order
        $maxOrder = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM assignments WHERE teacher_id = ? AND student_id = ?');
        $maxOrder->execute([$teacherId, $studentId]);
        $nextOrder = (int)$maxOrder->fetch()['next_order'];
        $stmt = $db->prepare('INSERT INTO assignments (id, teacher_id, student_id, week_label, sort_order) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$assignmentId, $teacherId, $studentId, $weekLabel, $nextOrder]);

        // Insert steps
        $stepStmt = $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)');
        foreach ($steps as $i => $step) {
            $stepId = $step['id'] ?? generateId();
            $stepStmt->execute([$stepId, $assignmentId, $step['contentId'], $step['notes'] ?? '', $i + 1]);
        }

        // Return full assignment
        $result = [
            'id' => $assignmentId,
            'studentId' => $studentId,
            'weekLabel' => $weekLabel,
            'steps' => loadSteps($db, $assignmentId),
            'progress' => [],
            'createdAt' => date('Y-m-d H:i:s')
        ];

        jsonResponse($result, 201);
        break;

    case 'PUT':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Assignment ID required'], 400);

        $body = getBody();

        // Reorder: swap sort_order values
        if (isset($body['reorder'])) {
            $order = $body['reorder']; // array of assignment IDs in desired order
            foreach ($order as $i => $aId) {
                $db->prepare('UPDATE assignments SET sort_order = ? WHERE id = ? AND teacher_id = ?')->execute([$i, $aId, $teacherId]);
            }
            jsonResponse(['success' => true]);
            break;
        }

        // Update week label
        if (isset($body['weekLabel'])) {
            $stmt = $db->prepare('UPDATE assignments SET week_label = ? WHERE id = ? AND teacher_id = ?');
            $stmt->execute([$body['weekLabel'], $id, $teacherId]);
        }

        // Replace steps if provided
        if (isset($body['steps'])) {
            $db->prepare('DELETE FROM assignment_steps WHERE assignment_id = ?')->execute([$id]);
            $stepStmt = $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)');
            foreach ($body['steps'] as $i => $step) {
                $stepId = $step['id'] ?? generateId();
                $stepStmt->execute([$stepId, $id, $step['contentId'], $step['notes'] ?? '', $i + 1]);
            }
        }

        jsonResponse(['success' => true, 'steps' => loadSteps($db, $id)]);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Assignment ID required'], 400);

        $stmt = $db->prepare('DELETE FROM assignments WHERE id = ? AND teacher_id = ?');
        $stmt->execute([$id, $teacherId]);

        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
