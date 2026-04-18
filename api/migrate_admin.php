<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();
try {
    // Add approved and is_admin columns to teachers
    $db->exec('ALTER TABLE teachers ADD COLUMN approved TINYINT(1) NOT NULL DEFAULT 0');
    echo "Added 'approved' column.\n";
} catch (Exception $e) {
    echo "approved column: " . $e->getMessage() . "\n";
}
try {
    $db->exec('ALTER TABLE teachers ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0');
    echo "Added 'is_admin' column.\n";
} catch (Exception $e) {
    echo "is_admin column: " . $e->getMessage() . "\n";
}

// Create invite_codes table
try {
    $db->exec('CREATE TABLE IF NOT EXISTS invite_codes (
        id VARCHAR(100) PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        label VARCHAR(200) DEFAULT "",
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        used_by VARCHAR(100) DEFAULT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL
    )');
    echo "Created invite_codes table.\n";
} catch (Exception $e) {
    echo "invite_codes table: " . $e->getMessage() . "\n";
}

// Mark the first teacher (Mark) as approved + admin
// Find by email
$stmt = $db->prepare('UPDATE teachers SET approved = 1, is_admin = 1 WHERE email = ?');
$stmt->execute(['markbaynes@gmail.com']);
$affected = $stmt->rowCount();
echo "Set markbaynes@gmail.com as approved admin: $affected rows updated.\n";

// Also approve any existing teachers so they aren't locked out
$db->exec('UPDATE teachers SET approved = 1 WHERE approved = 0');
echo "Approved all existing teachers.\n";

echo "\nDone! Migration complete.";
