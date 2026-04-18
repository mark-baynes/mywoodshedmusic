<?php
// My Woodshed Music — Data Import API (admin only)
// POST /api/import.php  — upload ZIP of CSVs to import

require_once __DIR__ . '/helpers.php';

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData || !($tokenData['is_admin'] ?? false)) {
    jsonResponse(['error' => 'Admin access required'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required'], 405);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'File upload required'], 400);
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if ($ext !== 'zip') {
    jsonResponse(['error' => 'Only ZIP files accepted'], 400);
}

$db = getDB();
$zip = new ZipArchive();
if ($zip->open($file['tmp_name']) !== true) {
    jsonResponse(['error' => 'Could not open ZIP file'], 400);
}

$results = [];

// Table import order matters for foreign keys
$importOrder = ['teachers', 'students', 'content', 'assignments', 'assignment_steps', 'progress',
                'toolbox_settings', 'student_toolbox_access', 'learning_profiles',
                'invite_codes', 'completion_notifications'];

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

foreach ($importOrder as $table) {
    $csvContent = $zip->getFromName("$table.csv");
    if ($csvContent === false) continue;

    $rows = parseCsv($csvContent);
    if (empty($rows)) {
        $results[$table] = 'empty';
        continue;
    }

    $imported = 0;
    $skipped = 0;
    $columns = array_keys($rows[0]);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $columnList = implode(',', array_map(function($c) { return "`$c`"; }, $columns));

    // Use INSERT IGNORE to skip duplicates
    $stmt = $db->prepare("INSERT IGNORE INTO `$table` ($columnList) VALUES ($placeholders)");

    foreach ($rows as $row) {
        try {
            $values = array_values($row);
            $stmt->execute($values);
            if ($stmt->rowCount() > 0) $imported++;
            else $skipped++;
        } catch (Exception $e) {
            $skipped++;
        }
    }

    $results[$table] = ['imported' => $imported, 'skipped' => $skipped];
}

$zip->close();
jsonResponse(['success' => true, 'results' => $results]);
