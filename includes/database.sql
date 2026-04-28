-- DocuGo Database Setup
-- Run this in phpMyAdmin or MySQL CLI

CREATE DATABASE IF NOT EXISTS docugo_db;
USE docugo_db;

-- Users table (students, alumni, registrar, admin)
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(20) UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'alumni', 'registrar', 'admin') DEFAULT 'student',
    course VARCHAR(100),
    year_graduated YEAR,
    contact_number VARCHAR(20),
    address TEXT,
    status ENUM('active', 'inactive', 'pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Document types table
CREATE TABLE IF NOT EXISTS document_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    fee DECIMAL(10,2) DEFAULT 0.00,
    processing_days INT DEFAULT 3,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Document requests table
CREATE TABLE IF NOT EXISTS document_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_code VARCHAR(20) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    document_type_id INT NOT NULL,
    purpose TEXT NOT NULL,
    copies INT DEFAULT 1,
    preferred_release_date DATE,
    release_mode ENUM('pickup', 'delivery') DEFAULT 'pickup',
    delivery_address TEXT,
    payment_status ENUM('unpaid', 'paid') DEFAULT 'unpaid',
    payment_reference VARCHAR(100),
    status ENUM('pending', 'processing', 'ready', 'released', 'cancelled') DEFAULT 'pending',
    remarks TEXT,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (document_type_id) REFERENCES document_types(id)
);

-- Graduate tracer table
CREATE TABLE IF NOT EXISTS graduate_tracer (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employment_status ENUM('employed', 'unemployed', 'self_employed', 'further_studies', 'not_looking') DEFAULT 'unemployed',
    employer_name VARCHAR(200),
    job_title VARCHAR(150),
    employment_sector ENUM('government', 'private', 'ngo', 'self', 'other'),
    degree_relevance ENUM('very_relevant', 'relevant', 'somewhat_relevant', 'not_relevant'),
    further_studies TINYINT(1) DEFAULT 0,
    school_further_studies VARCHAR(200),
    professional_license VARCHAR(200),
    date_submitted TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert default document types
INSERT INTO document_types (name, description, fee, processing_days) VALUES
('Transcript of Records', 'Official academic transcript', 100.00, 5),
('Certificate of Enrollment', 'Proof of enrollment for current students', 30.00, 1),
('Certificate of Graduation', 'Official certificate of graduation', 50.00, 3),
('Good Moral Certificate', 'Character certificate', 30.00, 2),
('Diploma (Replacement)', 'Replacement diploma', 500.00, 10),
('Authentication', 'Document authentication', 50.00, 3);

-- Insert default admin account (password: Admin@123)
INSERT INTO users (first_name, last_name, email, password, role, status) VALUES
('Admin', 'DocuGo', 'admin@adfc.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uDutS/G2', 'admin', 'active');
