<?php
// My Woodshed Music — Admin API (requires is_admin)
// GET    /api/admin.php?action=pending_teachers  — list unapproved teachers
// GET    /api/admin.php?action=all_teachers       — list all teachers
// POST   /api/admin.php?action=approve_teacher    — approve a teacher { teacherId }
// POST   /api/admin.php?action=reject_teacher     — delete unapproved teacher { teacherId }
// GET    /api/admin.php?action=invite_codes       — list invite codes
// POST   /api/admin.php?action=create_invite      — create invite code { label }
// DELETE /api/admin.php?action=delete_invite&id=X — delete invite code

require_once __DIR__ . '/helpers.php';

// Auth: must be admin
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$tokenData = null;
if (preg_match('/Bearer\s+(.+)/', $header, $matches)) {
    $tokenData = verifyToken($matches[1]);
}
if (!$tokenData || !($tokenData['is_admin'] ?? false)) {
    jsonResponse(['error' => 'Admin access required'], 403);
}

$db = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    case 'pending_teachers':
        $stmt = $db->query('SELECT id, name, email, approved, is_admin, created_at FROM teachers WHERE approved = 0 ORDER BY created_at DESC');
        jsonResponse($stmt->fetchAll());
        break;

    case 'all_teachers':
        $stmt = $db->query('SELECT id, name, email, approved, is_admin, created_at FROM teachers ORDER BY created_at DESC');
        jsonResponse($stmt->fetchAll());
        break;

    case 'approve_teacher':
        $body = getBody();
        $teacherId = $body['teacherId'] ?? '';
        if (!$teacherId) jsonResponse(['error' => 'teacherId required'], 400);
        $stmt = $db->prepare('UPDATE teachers SET approved = 1 WHERE id = ?');
        $stmt->execute([$teacherId]);
        jsonResponse(['success' => true]);
        break;

    case 'reject_teacher':
        $body = getBody();
        $teacherId = $body['teacherId'] ?? '';
        if (!$teacherId) jsonResponse(['error' => 'teacherId required'], 400);
        // Only delete if not approved
        $stmt = $db->prepare('DELETE FROM teachers WHERE id = ? AND approved = 0');
        $stmt->execute([$teacherId]);
        jsonResponse(['success' => true]);
        break;

    case 'invite_codes':
        $stmt = $db->query('SELECT ic.*, t.name as used_by_name FROM invite_codes ic LEFT JOIN teachers t ON t.id = ic.used_by ORDER BY ic.created_at DESC');
        jsonResponse($stmt->fetchAll());
        break;

    case 'create_invite':
        $body = getBody();
        $label = trim($body['label'] ?? '');
        $id = generateId();
        // Generate a readable 8-char code
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $stmt = $db->prepare('INSERT INTO invite_codes (id, code, label) VALUES (?, ?, ?)');
        $stmt->execute([$id, $code, $label]);
        jsonResponse(['id' => $id, 'code' => $code, 'label' => $label]);
        break;

    case 'delete_invite':
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Invite ID required'], 400);
        $db->prepare('DELETE FROM invite_codes WHERE id = ?')->execute([$id]);
        jsonResponse(['success' => true]);
        break;

    case 'get_settings':
        // Auto-create settings table
        try {
            $db->exec('CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )');
            $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('max_upload_mb', '10')");
        } catch (PDOException $e) {}
        $stmt = $db->query('SELECT setting_key, setting_value FROM settings');
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        jsonResponse($settings);
        break;

    case 'update_setting':
        $body = getBody();
        $key = trim($body['key'] ?? '');
        $value = trim($body['value'] ?? '');
        if (!$key) jsonResponse(['error' => 'Setting key required'], 400);
        $stmt = $db->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, $value]);
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['error' => 'Invalid admin action'], 400);
}
