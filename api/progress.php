<?php
// My Woodshed Music — Progress API
// GET  /api/progress.php?student_id=X                    — all progress for a student
// GET  /api/progress.php?assignment_id=X                 — progress for an assignment
// POST /api/progress.php                                 — mark step complete with feedback

require_once __DIR__ . '/helpers.php';

// Progress can be written by students or teachers
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData) jsonResponse(['error' => 'Unauthorized'], 401);

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $studentId = $_GET['student_id'] ?? '';
        $assignmentId = $_GET['assignment_id'] ?? '';

        if ($assignmentId) {
            $stmt = $db->prepare('SELECT * FROM progress WHERE assignment_id = ?');
            $stmt->execute([$assignmentId]);
        } elseif ($studentId) {
            $stmt = $db->prepare('SELECT * FROM progress WHERE student_id = ?');
            $stmt->execute([$studentId]);
        } else {
            jsonResponse(['error' => 'student_id or assignment_id required'], 400);
        }

        $rows = $stmt->fetchAll();
        // Camel case for frontend
        foreach ($rows as &$r) {
            $r['studentId'] = $r['student_id'];
            $r['assignmentId'] = $r['assignment_id'];
            $r['stepId'] = $r['step_id'];
            $r['feedbackNote'] = $r['feedback_note'];
            $r['completedAt'] = $r['completed_at'];
            $r['practiceSeconds'] = (int)($r['practice_seconds'] ?? 0);
        }
        jsonResponse($rows);
        break;

    case 'POST':
        $body = getBody();
        $studentId = $body['studentId'] ?? '';
        $assignmentId = $body['assignmentId'] ?? '';
        $stepId = $body['stepId'] ?? '';

        if (!$studentId || !$assignmentId || !$stepId) {
            jsonResponse(['error' => 'studentId, assignmentId, and stepId required'], 400);
        }

        $stmt = $db->prepare('
            INSERT INTO progress (student_id, assignment_id, step_id, completed, feedback, feedback_note, practice_seconds)
            VALUES (?, ?, ?, 1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE completed = 1, feedback = VALUES(feedback), feedback_note = VALUES(feedback_note), practice_seconds = GREATEST(practice_seconds, VALUES(practice_seconds)), completed_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            $studentId, $assignmentId, $stepId,
            $body['feedback'] ?? '',
            $body['feedbackNote'] ?? '',
            intval($body['practiceSeconds'] ?? 0)
        ]);

        jsonResponse(['success' => true, 'studentId' => $studentId, 'assignmentId' => $assignmentId, 'stepId' => $stepId]);
        break;


    case 'PUT':
        $body = getBody();
        $studentId = $body['studentId'] ?? '';
        $assignmentId = $body['assignmentId'] ?? '';
        $stepId = $body['stepId'] ?? '';
        $practiceSeconds = intval($body['practiceSeconds'] ?? 0);

        if (!$studentId || !$assignmentId || !$stepId) {
            jsonResponse(['error' => 'studentId, assignmentId, and stepId required'], 400);
        }

        $stmt = $db->prepare('
            INSERT INTO progress (student_id, assignment_id, step_id, completed, practice_seconds)
            VALUES (?, ?, ?, 0, ?)
            ON DUPLICATE KEY UPDATE practice_seconds = GREATEST(practice_seconds, VALUES(practice_seconds))
        ');
        $stmt->execute([$studentId, $assignmentId, $stepId, $practiceSeconds]);

        jsonResponse(['success' => true, 'practiceSeconds' => $practiceSeconds]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
