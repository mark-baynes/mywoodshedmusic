<?php
// My Woodshed Music — Data Export API
// GET /api/export.php?scope=mine     — teacher's own data (CSV zip)
// GET /api/export.php?scope=all      — all data (admin only, CSV zip)

require_once __DIR__ . '/helpers.php';

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData) { http_response_code(401); echo 'Unauthorized'; exit; }

$scope = $_GET['scope'] ?? 'mine';
$isAdmin = !empty($tokenData['is_admin']);
$teacherId = $tokenData['teacher_id'] ?? null;

if ($scope === 'all' && !$isAdmin) {
    http_response_code(403); echo 'Admin access required'; exit;
}

$db = getDB();

function arrayToCsv($rows, $headers = null) {
    if (empty($rows)) return $headers ? implode(',', $headers) . "\n" : '';
    $output = fopen('php://temp', 'r+');
    $h = $headers ?: array_keys($rows[0]);
    fputcsv($output, $h);
    foreach ($rows as $row) {
        $line = [];
        foreach ($h as $col) $line[] = $row[$col] ?? '';
        fputcsv($output, $line);
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return $csv;
}

$zip = new ZipArchive();
$tmpFile = tempnam(sys_get_temp_dir(), 'woodshed_export_');
$zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($scope === 'all') {
    // Admin: export everything
    $tables = [
        'teachers' => 'SELECT id, name, email, approved, is_admin, created_at FROM teachers',
        'students' => 'SELECT * FROM students',
        'content' => 'SELECT * FROM content',
        'assignments' => 'SELECT * FROM assignments',
        'assignment_steps' => 'SELECT * FROM assignment_steps',
        'progress' => 'SELECT * FROM progress',
        'invite_codes' => 'SELECT * FROM invite_codes',
    ];
    // Optional tables that might not exist
    $optionalTables = [
        'toolbox_settings' => 'SELECT * FROM toolbox_settings',
        'student_toolbox_access' => 'SELECT * FROM student_toolbox_access',
        'learning_profiles' => 'SELECT * FROM learning_profiles',
        'completion_notifications' => 'SELECT * FROM completion_notifications',
    ];
    foreach ($tables as $name => $sql) {
        $rows = $db->query($sql)->fetchAll();
        $zip->addFromString("$name.csv", arrayToCsv($rows, empty($rows) ? null : null));
    }
    foreach ($optionalTables as $name => $sql) {
        try {
            $rows = $db->query($sql)->fetchAll();
            $zip->addFromString("$name.csv", arrayToCsv($rows));
        } catch (Exception $e) { /* table doesn't exist, skip */ }
    }
} else {
    // Teacher: export their own data
    if (!$teacherId) { http_response_code(400); echo 'No teacher ID'; exit; }

    // Students
    $stmt = $db->prepare('SELECT * FROM students WHERE teacher_id = ?');
    $stmt->execute([$teacherId]);
    $students = $stmt->fetchAll();
    $zip->addFromString('students.csv', arrayToCsv($students));

    // Content library
    $stmt = $db->prepare('SELECT * FROM content WHERE teacher_id = ?');
    $stmt->execute([$teacherId]);
    $zip->addFromString('content.csv', arrayToCsv($stmt->fetchAll()));

    // Assignments
    $stmt = $db->prepare('SELECT * FROM assignments WHERE teacher_id = ?');
    $stmt->execute([$teacherId]);
    $assignments = $stmt->fetchAll();
    $zip->addFromString('assignments.csv', arrayToCsv($assignments));

    // Assignment steps
    $assignmentIds = array_column($assignments, 'id');
    if (!empty($assignmentIds)) {
        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $stmt = $db->prepare("SELECT * FROM assignment_steps WHERE assignment_id IN ($placeholders)");
        $stmt->execute($assignmentIds);
        $zip->addFromString('assignment_steps.csv', arrayToCsv($stmt->fetchAll()));
    }

    // Progress for their students
    $studentIds = array_column($students, 'id');
    if (!empty($studentIds)) {
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $db->prepare("SELECT * FROM progress WHERE student_id IN ($placeholders)");
        $stmt->execute($studentIds);
        $zip->addFromString('progress.csv', arrayToCsv($stmt->fetchAll()));
    }

    // Toolbox settings
    try {
        $stmt = $db->prepare('SELECT * FROM toolbox_settings WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $rows = $stmt->fetchAll();
        if (!empty($rows)) $zip->addFromString('toolbox_settings.csv', arrayToCsv($rows));
    } catch (Exception $e) {}

    // Student toolbox access
    if (!empty($studentIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $stmt = $db->prepare("SELECT * FROM student_toolbox_access WHERE student_id IN ($placeholders)");
            $stmt->execute($studentIds);
            $rows = $stmt->fetchAll();
            if (!empty($rows)) $zip->addFromString('student_toolbox_access.csv', arrayToCsv($rows));
        } catch (Exception $e) {}
    }

    // Learning profiles
    if (!empty($studentIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $stmt = $db->prepare("SELECT * FROM learning_profiles WHERE student_id IN ($placeholders)");
            $stmt->execute($studentIds);
            $rows = $stmt->fetchAll();
            if (!empty($rows)) $zip->addFromString('learning_profiles.csv', arrayToCsv($rows));
        } catch (Exception $e) {}
    }
}

$zip->close();

$filename = $scope === 'all' ? 'woodshed_all_data_' . date('Y-m-d') . '.zip' : 'my_data_' . date('Y-m-d') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
readfile($tmpFile);
unlink($tmpFile);
exit;
