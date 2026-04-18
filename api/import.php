<?php
// My Woodshed Music — Data Import API
// POST /api/import.php          — teacher imports their own data (ZIP of CSVs)
// POST /api/import.php?scope=all — admin imports all data (ZIP of CSVs)

require_once __DIR__ . '/helpers.php';

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData || !isset($tokenData['teacher_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$scope = $_GET['scope'] ?? 'mine';
$isAdmin = !empty($tokenData['is_admin']);
$teacherId = $tokenData['teacher_id'];

if ($scope === 'all' && !$isAdmin) {
    jsonResponse(['error' => 'Admin access required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required'], 405);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'File upload required'], 400);
}

$file = $_FILES['file'];
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'zip') {
    jsonResponse(['error' => 'Only ZIP files accepted'], 400);
}

$db = getDB();
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    jsonResponse(['error' => 'Could not open ZIP file'], 400);
}

$results = [];

function parseCsv($csvString) {
    $rows = [];
    $lines = str_getcsv($csvString, "\n");
    if (count($lines) < 2) return $rows;
    $headers = str_getcsv($lines[0]);
    for ($i = 1; $i < count($lines); $i++) {
        $values = str_getcsv($lines[$i]);
        if (count($values) === count($headers)) {
            $rows[] = array_combine($headers, $values);
        }
    }
    return $rows;
}

// Tables teachers can import (scoped to their own data)
$teacherTables = ['students', 'content', 'assignments', 'assignment_steps', 'progress',
                  'toolbox_settings', 'student_toolbox_access', 'learning_profiles'];

// Tables only admin can import
$adminOnlyTables = ['teachers', 'invite_codes', 'completion_notifications'];

$importOrder = $scope === 'all'
    ? array_merge($adminOnlyTables, $teacherTables)
    : $teacherTables;

// For teacher imports, get their student IDs and assignment IDs to validate ownership
$ownStudentIds = [];
$ownAssignmentIds = [];
if ($scope !== 'all') {
    $stmt = $db->prepare('SELECT id FROM students WHERE teacher_id = ?');
    $stmt->execute([$teacherId]);
    $ownStudentIds = array_column($stmt->fetchAll(), 'id');

    $stmt = $db->prepare('SELECT id FROM assignments WHERE teacher_id = ?');
    $stmt->execute([$teacherId]);
    $ownAssignmentIds = array_column($stmt->fetchAll(), 'id');
}

foreach ($importOrder as $table) {
    $csvContent = $zip->getFromName("$table.csv");
    if ($csvContent === false) continue;

    $rows = parseCsv($csvContent);
    if (empty($rows)) { $results[$table] = 'empty'; continue; }

    // For teacher scope, enforce ownership
    if ($scope !== 'all') {
        $filteredRows = [];
        foreach ($rows as $row) {
            switch ($table) {
                case 'students':
                case 'content':
                case 'assignments':
                    // Must belong to this teacher
                    if (($row['teacher_id'] ?? '') === $teacherId) $filteredRows[] = $row;
                    break;
                case 'assignment_steps':
                    // Must belong to one of their assignments
                    if (in_array($row['assignment_id'] ?? '', $ownAssignmentIds)) $filteredRows[] = $row;
                    break;
                case 'progress':
                case 'learning_profiles':
                case 'student_toolbox_access':
                    // Must belong to one of their students
                    if (in_array($row['student_id'] ?? '', $ownStudentIds)) $filteredRows[] = $row;
                    break;
                case 'toolbox_settings':
                    if (($row['teacher_id'] ?? '') === $teacherId) $filteredRows[] = $row;
                    break;
                default:
                    $filteredRows[] = $row;
            }
        }
        $rows = $filteredRows;
        // Also add any new student/assignment IDs from the import for subsequent tables
        if ($table === 'students') {
            $ownStudentIds = array_merge($ownStudentIds, array_column($rows, 'id'));
        }
        if ($table === 'assignments') {
            $ownAssignmentIds = array_merge($ownAssignmentIds, array_column($rows, 'id'));
        }
    }

    if (empty($rows)) { $results[$table] = 'no matching records'; continue; }

    $imported = 0;
    $skipped = 0;
    $columns = array_keys($rows[0]);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $columnList = implode(',', array_map(function($c) { return "`$c`"; }, $columns));

    $stmt = $db->prepare("INSERT IGNORE INTO `$table` ($columnList) VALUES ($placeholders)");

    foreach ($rows as $row) {
        try {
            $stmt->execute(array_values($row));
            if ($stmt->rowCount() > 0) $imported++;
            else $skipped++;
        } catch (Exception $e) {
            $skipped++;
        }
    }

    $results[$table] = ['imported' => $imported, 'skipped' => $skipped];
}

// Extract uploaded files (PDFs and audio) from ZIP
$baseDir = __DIR__ . '/../';
$fileCount = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    // Only extract files from uploads/ directories
    if (strpos($name, 'uploads/') === 0) {
        $destPath = $baseDir . $name;
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);
        // Don't overwrite existing files
        if (!file_exists($destPath)) {
            $fileData = $zip->getFromIndex($i);
            if ($fileData !== false) {
                file_put_contents($destPath, $fileData);
                $fileCount++;
            }
        }
    }
}
if ($fileCount > 0) {
    $results['uploaded_files'] = $fileCount . ' files restored';
}

$zip->close();
jsonResponse(['success' => true, 'results' => $results]);
