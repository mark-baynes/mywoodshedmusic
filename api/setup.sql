-- My Woodshed Music — Database Schema
-- Run this SQL to set up the database

CREATE DATABASE IF NOT EXISTS woodshed CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE woodshed;

-- Teachers
CREATE TABLE teachers (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Students
CREATE TABLE students (
    id VARCHAR(36) PRIMARY KEY,
    teacher_id VARCHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    level VARCHAR(255) DEFAULT '',
    notes TEXT,
    pin VARCHAR(6) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);

-- Content Library
CREATE TABLE content (
    id VARCHAR(36) PRIMARY KEY,
    teacher_id VARCHAR(36) NOT NULL,
    title VARCHAR(500) NOT NULL,
    type ENUM('Watch','Play','Practice','Listen','Review') DEFAULT 'Practice',
    track ENUM('Jazz','Contemporary','Foundation','Crossover') DEFAULT 'Foundation',
    description TEXT,
    url VARCHAR(2000) DEFAULT '',
    lesson_content MEDIUMTEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
);

-- Assignments (weekly practice paths)
CREATE TABLE assignments (
    id VARCHAR(36) PRIMARY KEY,
    teacher_id VARCHAR(36) NOT NULL,
    student_id VARCHAR(36) NOT NULL,
    week_label VARCHAR(255) NOT NULL,
    released TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Assignment Steps
CREATE TABLE assignment_steps (
    id VARCHAR(36) PRIMARY KEY,
    assignment_id VARCHAR(36) NOT NULL,
    content_id VARCHAR(36) NOT NULL,
    notes TEXT,
    step_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE
);

-- Student Progress
CREATE TABLE progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(36) NOT NULL,
    assignment_id VARCHAR(36) NOT NULL,
    step_id VARCHAR(36) NOT NULL,
    completed TINYINT(1) DEFAULT 0,
    feedback ENUM('good','more_time','struggled','') DEFAULT '',
    feedback_note TEXT,
    practice_seconds INT DEFAULT 0,
    audio_url VARCHAR(500) DEFAULT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_progress (student_id, assignment_id, step_id),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (step_id) REFERENCES assignment_steps(id) ON DELETE CASCADE
);

-- Index for common queries
CREATE INDEX idx_content_teacher ON content(teacher_id);
CREATE INDEX idx_assignments_student ON assignments(student_id);
CREATE INDEX idx_progress_student ON progress(student_id);
CREATE INDEX idx_progress_assignment ON progress(assignment_id);
