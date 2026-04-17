<?php
require_once __DIR__ . '/helpers.php';
$db = getDB();

try {
    // Student learning preferences (filled by student)
    $db->exec('CREATE TABLE IF NOT EXISTS student_preferences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        workload_pref ENUM("light","moderate","heavy") DEFAULT "moderate",
        task_size_pref ENUM("few_big","mixed","many_small") DEFAULT "mixed",
        stress_level INT DEFAULT 3,
        practice_time_pref INT DEFAULT 30,
        learning_style TEXT,
        free_notes TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY (student_id)
    )');
    echo "Created student_preferences table.\n";

    // Weekly check-ins (filled by student after practice)
    $db->exec('CREATE TABLE IF NOT EXISTS student_checkins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        week_label VARCHAR(100),
        felt_workload ENUM("too_little","just_right","too_much") DEFAULT "just_right",
        felt_difficulty ENUM("too_easy","just_right","too_hard") DEFAULT "just_right",
        felt_enjoyment INT DEFAULT 3,
        free_comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (student_id)
    )');
    echo "Created student_checkins table.\n";

    // Teacher observations (filled by teacher during lessons)
    $db->exec('CREATE TABLE IF NOT EXISTS teacher_observations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        teacher_id INT NOT NULL,
        note TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (student_id),
        INDEX (teacher_id)
    )');
    echo "Created teacher_observations table.\n";

    echo "\nAll tables created successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
