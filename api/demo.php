<?php
// My Woodshed Music — Demo Account API
// POST /api/demo.php?action=start_teacher  — create demo teacher + seed data, return token
// POST /api/demo.php?action=start_student  — create demo student login, return token
// POST /api/demo.php?action=cleanup        — remove all demo data for this session

require_once __DIR__ . '/helpers.php';

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {

    case 'start_teacher':
        cleanupDemo($db);

        $teacherId = 'demo_' . bin2hex(random_bytes(8));
        $hash = password_hash('demo_password_not_real', PASSWORD_DEFAULT);

        $db->prepare('INSERT INTO teachers (id, name, email, password_hash, approved, is_admin) VALUES (?, ?, ?, ?, 1, 0)')
           ->execute([$teacherId, 'Demo Teacher', 'demo@woodshed.demo', $hash]);

        try { $db->exec('ALTER TABLE teachers ADD COLUMN is_demo TINYINT(1) DEFAULT 0'); } catch (PDOException $e) {}
        $db->prepare('UPDATE teachers SET is_demo = 1 WHERE id = ?')->execute([$teacherId]);

        $contentIds = seedContent($db, $teacherId);
        $students = seedStudents($db, $teacherId);
        seedAssignments($db, $teacherId, $students, $contentIds);

        $token = createToken(['teacher_id' => $teacherId, 'role' => 'teacher', 'is_admin' => false, 'is_demo' => true]);
        jsonResponse([
            'token' => $token,
            'teacher' => [
                'id' => $teacherId,
                'name' => 'Demo Teacher',
                'email' => 'demo@woodshed.demo',
                'is_admin' => false,
                'is_demo' => true
            ]
        ]);
        break;

    case 'start_student':
        cleanupDemo($db);

        $teacherId = 'demo_' . bin2hex(random_bytes(8));
        $hash = password_hash('demo_password_not_real', PASSWORD_DEFAULT);

        $db->prepare('INSERT INTO teachers (id, name, email, password_hash, approved, is_admin) VALUES (?, ?, ?, ?, 1, 0)')
           ->execute([$teacherId, 'Mark Baynes', 'demo_teacher@woodshed.demo', $hash]);

        try { $db->exec('ALTER TABLE teachers ADD COLUMN is_demo TINYINT(1) DEFAULT 0'); } catch (PDOException $e) {}
        $db->prepare('UPDATE teachers SET is_demo = 1 WHERE id = ?')->execute([$teacherId]);

        $contentIds = seedContent($db, $teacherId);

        $studentId = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO students (id, teacher_id, name, level, notes, pin) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$studentId, $teacherId, 'Demo Student', 'Intermediate', 'Demo account — exploring the student experience', '0000']);

        seedStudentAssignment($db, $teacherId, $studentId, $contentIds);

        $token = createToken(['student_id' => $studentId, 'teacher_id' => $teacherId, 'role' => 'student', 'is_demo' => true]);
        jsonResponse([
            'token' => $token,
            'student' => [
                'id' => $studentId,
                'name' => 'Demo Student',
                'level' => 'Intermediate',
                'teacher_id' => $teacherId,
                'teacher_name' => 'Mark Baynes',
                'is_demo' => true
            ]
        ]);
        break;

    case 'cleanup':
        $count = cleanupDemo($db);
        jsonResponse(['success' => true, 'cleaned' => $count]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: start_teacher, start_student, cleanup'], 400);
}

// ============================================================
// SEED FUNCTIONS
// ============================================================

function cleanupDemo($db) {
    try { $db->exec('ALTER TABLE teachers ADD COLUMN is_demo TINYINT(1) DEFAULT 0'); } catch (PDOException $e) {}

    $stmt = $db->query("SELECT id FROM teachers WHERE is_demo = 1 OR id LIKE 'demo_%' OR email LIKE 'demo%@woodshed.demo'");
    $demoTeachers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;

    foreach ($demoTeachers as $tid) {
        $db->prepare('DELETE FROM teachers WHERE id = ?')->execute([$tid]);
        $count++;
    }

    $db->exec("DELETE FROM students WHERE id LIKE 'demo_%'");

    return $count;
}

function seedContent($db, $teacherId) {
    $items = [
        ['Major ii-V-I Voicings', 'Practice', 'Jazz', 'Learn the essential ii-V-I chord voicings in all 12 keys. Start with rootless voicings in the left hand, then add shell voicings.', ''],
        ['Autumn Leaves — Head & Changes', 'Play', 'Jazz', 'Learn the melody and chord changes to this essential jazz standard. Focus on smooth voice leading between chords.', 'https://www.youtube.com/watch?v=rsz6TE6t7JY'],
        ['Blues Scale Patterns', 'Practice', 'Foundation', 'Practice the blues scale in C, F, and Bb. Play ascending and descending, then try improvising simple phrases.', ''],
        ['Rhythm & Groove — Swing Feel', 'Listen', 'Jazz', 'Listen to these examples of swing feel. Pay attention to how the ride cymbal pattern affects the pianist comping.', 'https://www.youtube.com/watch?v=SBgQezOF8kY'],
        ['Pop Chord Progressions', 'Practice', 'Contemporary', 'Work through the I-V-vi-IV progression in C, G, D, and A major. Add different rhythmic patterns and inversions.', ''],
        ['Sight Reading — Level 2', 'Review', 'Foundation', 'Sight reading exercises focusing on treble clef, simple rhythms, and hand position changes within a 5-note range.', ''],
        ['Improvisation — Using Guide Tones', 'Watch', 'Jazz', 'Video lesson on using 3rds and 7ths as guide tones to navigate chord changes while improvising.', 'https://www.youtube.com/watch?v=Ud9CpGOG1GE'],
        ['Hanon Exercise No. 1', 'Practice', 'Foundation', 'Classic finger independence exercise. Start at 60bpm and gradually increase. Focus on evenness and relaxed hand position.', ''],
        ['Funk Piano — Clavinet Style', 'Play', 'Contemporary', 'Learn funky clavinet-style riffs. Focus on ghost notes, staccato touch, and rhythmic precision.', 'https://www.youtube.com/watch?v=V5DqBY1t0rk'],
        ['Ear Training — Interval Recognition', 'Review', 'Foundation', 'Practice identifying intervals by ear. Start with major/minor 2nds and 3rds, then progress to larger intervals.', ''],
    ];

    $contentIds = [];
    foreach ($items as $item) {
        $id = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO content (id, teacher_id, title, type, track, description, url) VALUES (?, ?, ?, ?, ?, ?, ?)')
           ->execute([$id, $teacherId, $item[0], $item[1], $item[2], $item[3], $item[4]]);
        $contentIds[] = $id;
    }

    return $contentIds;
}

function seedStudents($db, $teacherId) {
    $data = [
        ['Sarah Chen', 'Intermediate', 'Working on jazz standards and improvisation. Has a recital in June.', '1234'],
        ['Tom Wilson', 'Beginner', 'Adult learner, started 3 months ago. Keen on pop and contemporary styles.', '5678'],
        ['Mia Patel', 'Advanced', 'Preparing for university audition. Strong technique, needs more repertoire diversity.', '9012'],
    ];

    $students = [];
    foreach ($data as $s) {
        $id = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO students (id, teacher_id, name, level, notes, pin) VALUES (?, ?, ?, ?, ?, ?)')
           ->execute([$id, $teacherId, $s[0], $s[1], $s[2], $s[3]]);
        $students[] = ['id' => $id, 'name' => $s[0], 'level' => $s[1]];
    }

    return $students;
}

function seedAssignments($db, $teacherId, $students, $contentIds) {
    // Sarah (Intermediate) — jazz focus
    if (isset($students[0])) {
        $aId = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO assignments (id, teacher_id, student_id, week_label, sort_order) VALUES (?, ?, ?, ?, ?)')
           ->execute([$aId, $teacherId, $students[0]['id'], 'Week 12 — Jazz Voicings', 0]);

        $steps = [
            [$contentIds[0], 'Start with Dm7-G7-Cmaj7. Get all inversions smooth before moving keys.', 0],
            [$contentIds[1], 'Learn the A section melody by ear first, then check against the chart.', 1],
            [$contentIds[6], 'Watch this before your improvisation practice — apply guide tones to Autumn Leaves.', 2],
            [$contentIds[3], 'Listen for how the piano comps behind the soloist. Note the rhythmic variety.', 3],
        ];
        foreach ($steps as $s) {
            $sId = 'demo_' . bin2hex(random_bytes(8));
            $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)')
               ->execute([$sId, $aId, $s[0], $s[1], $s[2]]);
            if ($s[2] < 2) {
                $db->prepare('INSERT INTO progress (student_id, assignment_id, step_id, completed, practice_seconds, feedback) VALUES (?, ?, ?, 1, ?, ?)')
                   ->execute([$students[0]['id'], $aId, $sId, rand(300, 900), $s[2] === 0 ? 'good' : 'more_time']);
            }
        }
    }

    // Tom (Beginner) — foundations
    if (isset($students[1])) {
        $aId = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO assignments (id, teacher_id, student_id, week_label, sort_order) VALUES (?, ?, ?, ?, ?)')
           ->execute([$aId, $teacherId, $students[1]['id'], 'Week 4 — Getting Started', 0]);

        $steps = [
            [$contentIds[7], 'Slow and steady — 60bpm. Focus on keeping fingers curved and relaxed.', 0],
            [$contentIds[2], 'Just C blues scale this week. Hands separate first.', 1],
            [$contentIds[4], 'Try the I-V-vi-IV in C major. Use whole notes at first.', 2],
            [$contentIds[5], 'Do 2 exercises per day. No peeking ahead!', 3],
        ];
        foreach ($steps as $s) {
            $sId = 'demo_' . bin2hex(random_bytes(8));
            $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)')
               ->execute([$sId, $aId, $s[0], $s[1], $s[2]]);
            if ($s[2] < 1) {
                $db->prepare('INSERT INTO progress (student_id, assignment_id, step_id, completed, practice_seconds, feedback) VALUES (?, ?, ?, 1, ?, ?)')
                   ->execute([$students[1]['id'], $aId, $sId, rand(200, 500), 'good']);
            }
        }
    }

    // Mia (Advanced) — audition prep
    if (isset($students[2])) {
        $aId = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO assignments (id, teacher_id, student_id, week_label, sort_order) VALUES (?, ?, ?, ?, ?)')
           ->execute([$aId, $teacherId, $students[2]['id'], 'Week 18 — Audition Prep', 0]);

        $steps = [
            [$contentIds[0], 'All 12 keys this week — aim for memory, no chart.', 0],
            [$contentIds[1], 'Full head + 2 chorus solo. Record yourself and send it to me.', 1],
            [$contentIds[8], 'Add this to your repertoire diversity — contemporary groove piece.', 2],
            [$contentIds[9], 'Keep your ears sharp — 15 mins per day.', 3],
            [$contentIds[6], 'Review guide tone concept — apply it across your solo on Autumn Leaves.', 4],
        ];
        foreach ($steps as $s) {
            $sId = 'demo_' . bin2hex(random_bytes(8));
            $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)')
               ->execute([$sId, $aId, $s[0], $s[1], $s[2]]);
            if ($s[2] < 4) {
                $db->prepare('INSERT INTO progress (student_id, assignment_id, step_id, completed, practice_seconds, feedback) VALUES (?, ?, ?, 1, ?, ?)')
                   ->execute([$students[2]['id'], $aId, $sId, rand(600, 1800), $s[2] < 2 ? 'good' : '']);
            }
        }
    }
}

function seedStudentAssignment($db, $teacherId, $studentId, $contentIds) {
    $aId = 'demo_' . bin2hex(random_bytes(8));
    $db->prepare('INSERT INTO assignments (id, teacher_id, student_id, week_label, sort_order) VALUES (?, ?, ?, ?, ?)')
       ->execute([$aId, $teacherId, $studentId, 'Week 8 — Jazz & Foundations', 0]);

    $steps = [
        [$contentIds[0], 'Start with Dm7-G7-Cmaj7 in root position, then try inversions.', 0],
        [$contentIds[1], 'Learn the melody to Autumn Leaves — just the A section this week.', 1],
        [$contentIds[2], 'Practice the C blues scale hands together. Try improvising a 4-bar phrase.', 2],
        [$contentIds[7], 'Hanon at 72bpm — focus on even tone across all fingers.', 3],
        [$contentIds[3], 'Listen and pay attention to the swing feel in the piano comping.', 4],
    ];

    foreach ($steps as $s) {
        $sId = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)')
           ->execute([$sId, $aId, $s[0], $s[1], $s[2]]);
        if ($s[2] < 2) {
            $db->prepare('INSERT INTO progress (student_id, assignment_id, step_id, completed, practice_seconds, feedback) VALUES (?, ?, ?, 1, ?, ?)')
               ->execute([$studentId, $aId, $sId, rand(300, 900), $s[2] === 0 ? 'good' : 'more_time']);
        }
    }

    // Past assignment — all completed
    $aId2 = 'demo_' . bin2hex(random_bytes(8));
    $db->prepare('INSERT INTO assignments (id, teacher_id, student_id, week_label, sort_order) VALUES (?, ?, ?, ?, ?)')
       ->execute([$aId2, $teacherId, $studentId, 'Week 7 — Getting Started', 1]);

    $pastSteps = [
        [$contentIds[7], 'Start at 60bpm, work up to 80bpm by end of week.', 0],
        [$contentIds[5], 'Sight reading — do 2 exercises per day.', 1],
        [$contentIds[4], 'Pop progressions in C and G major.', 2],
    ];

    foreach ($pastSteps as $s) {
        $sId = 'demo_' . bin2hex(random_bytes(8));
        $db->prepare('INSERT INTO assignment_steps (id, assignment_id, content_id, notes, step_order) VALUES (?, ?, ?, ?, ?)')
           ->execute([$sId, $aId2, $s[0], $s[1], $s[2]]);
        $db->prepare('INSERT INTO progress (student_id, assignment_id, step_id, completed, practice_seconds, feedback) VALUES (?, ?, ?, 1, ?, ?)')
           ->execute([$studentId, $aId2, $sId, rand(400, 1200), 'good']);
    }
}
