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


        // Check if all steps are now complete — send email notification to teacher
        try {
            $totalStmt = $db->prepare('SELECT COUNT(*) FROM assignment_steps WHERE assignment_id = ?');
            $totalStmt->execute([$assignmentId]);
            $totalSteps = (int)$totalStmt->fetchColumn();

            $doneStmt = $db->prepare('SELECT COUNT(*) FROM progress WHERE assignment_id = ? AND student_id = ? AND completed = 1');
            $doneStmt->execute([$assignmentId, $studentId]);
            $doneSteps = (int)$doneStmt->fetchColumn();

            if ($totalSteps > 0 && $doneSteps >= $totalSteps) {
                // Skip if we already sent a notification for this completion
                $alreadySent = $db->prepare('SELECT 1 FROM completion_notifications WHERE assignment_id = ? AND student_id = ?');
                $alreadySent->execute([$assignmentId, $studentId]);
                if (!$alreadySent->fetch()) {
                $db->prepare('INSERT IGNORE INTO completion_notifications (assignment_id, student_id) VALUES (?, ?)')->execute([$assignmentId, $studentId]);

                $infoStmt = $db->prepare('
                    SELECT t.email AS teacher_email, t.name AS teacher_name, s.name AS student_name, a.week_label
                    FROM assignments a
                    JOIN students s ON s.id = a.student_id
                    JOIN teachers t ON t.id = a.teacher_id
                    WHERE a.id = ?
                ');
                $infoStmt->execute([$assignmentId]);
                $info = $infoStmt->fetch();

                if ($info && $info['teacher_email']) {
                    $studentName = $info['student_name'] ?? 'A student';
                    $weekLabel = $info['week_label'] ?? 'their assignment';

                    $subject = "$studentName has completed all practice steps!";
                    $htmlBody = '
                    <div style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 500px; margin: 0 auto; padding: 20px;">
                        <div style="background: linear-gradient(135deg, #1e293b, #334155); border-radius: 12px; padding: 30px; color: #fff; text-align: center;">
                            <h2 style="margin: 0 0 8px 0; color: #fbbf24;">&#127926; Practice Complete!</h2>
                            <p style="margin: 0; font-size: 16px; color: #e2e8f0;">
                                <strong>' . htmlspecialchars($studentName) . '</strong> has finished all <strong>' . $totalSteps . '</strong> steps in <strong>' . htmlspecialchars($weekLabel) . '</strong>.
                            </p>
                        </div>
                        <div style="padding: 20px 0; text-align: center;">
                            <a href="https://www.jazzpiano.co.nz/mywoodshedmusic/" style="display: inline-block; background: #d97706; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600;">View in My Woodshed Music</a>
                        </div>
                        <p style="font-size: 12px; color: #94a3b8; text-align: center;">My Woodshed Music &mdash; jazzpiano.co.nz</p>
                    </div>';

                    $headers = "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    $headers .= "From: My Woodshed Music <noreply@jazzpiano.co.nz>\r\n";

                    @mail($info['teacher_email'], $subject, $htmlBody, $headers);
                }
                } // end duplicate check
            }
        } catch (Exception $e) {
            error_log('Notification email error: ' . $e->getMessage());
        }

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
