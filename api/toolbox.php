<?php
// Toolbox settings API — teacher can customise scales, chords, note names
// GET  /api/toolbox.php              — get custom toolbox settings
// POST /api/toolbox.php              — save custom toolbox settings

require_once __DIR__ . '/helpers.php';

$teacherId = requireAuth();
$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Ensure table exists (auto-migrate)
try {
    $db->exec('CREATE TABLE IF NOT EXISTS toolbox_settings (
        teacher_id VARCHAR(100) PRIMARY KEY,
        enharmonic_map TEXT,
        scale_types TEXT,
        chord_types TEXT,
        note_names TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )');
} catch (Exception $e) {
    // Table already exists
}

switch ($method) {
    case 'GET':
        $stmt = $db->prepare('SELECT * FROM toolbox_settings WHERE teacher_id = ?');
        $stmt->execute([$teacherId]);
        $row = $stmt->fetch();
        if ($row) {
            jsonResponse([
                'enharmonic_map' => $row['enharmonic_map'] ? json_decode($row['enharmonic_map'], true) : null,
                'scale_types' => $row['scale_types'] ? json_decode($row['scale_types'], true) : null,
                'chord_types' => $row['chord_types'] ? json_decode($row['chord_types'], true) : null,
                'note_names' => $row['note_names'] ? json_decode($row['note_names'], true) : null,
            ]);
        } else {
            jsonResponse((object)[]);
        }
        break;

    case 'POST':
        $body = getBody();
        $stmt = $db->prepare('INSERT INTO toolbox_settings (teacher_id, enharmonic_map, scale_types, chord_types, note_names)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                enharmonic_map = COALESCE(VALUES(enharmonic_map), enharmonic_map),
                scale_types = COALESCE(VALUES(scale_types), scale_types),
                chord_types = COALESCE(VALUES(chord_types), chord_types),
                note_names = COALESCE(VALUES(note_names), note_names)');
        $stmt->execute([
            $teacherId,
            isset($body['enharmonic_map']) ? json_encode($body['enharmonic_map']) : null,
            isset($body['scale_types']) ? json_encode($body['scale_types']) : null,
            isset($body['chord_types']) ? json_encode($body['chord_types']) : null,
            isset($body['note_names']) ? json_encode($body['note_names']) : null,
        ]);
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
