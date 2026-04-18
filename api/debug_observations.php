<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();

// Show table structure
$cols = $db->query("DESCRIBE teacher_observations")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>TABLE STRUCTURE:\n";
foreach ($cols as $c) echo $c['Field'] . " | " . $c['Type'] . " | " . $c['Null'] . "\n";

// Show all rows
$rows = $db->query("SELECT * FROM teacher_observations ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo "\nALL ROWS:\n";
foreach ($rows as $r) {
    echo "id=" . $r['id'] . " student_id=" . $r['student_id'] . " teacher_id=[" . $r['teacher_id'] . "] note=[" . substr($r['note'], 0, 50) . "] created=" . $r['created_at'] . "\n";
}
echo "</pre>";
