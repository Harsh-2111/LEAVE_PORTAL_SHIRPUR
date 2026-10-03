-- hostel_leave_schema.sql
CREATE DATABASE IF NOT EXISTS hostel_leave CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE hostel_leave;
-- Users table (login credentials moved here)
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  login_id VARCHAR(100) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,      -- For demo we store plaintext; in production use password_hash()
  role ENUM('student','teacher','hod','dean') NOT NULL,
  name VARCHAR(150) NOT NULL
);

-- Leave requests table
CREATE TABLE IF NOT EXISTS leave_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_name VARCHAR(200) NOT NULL,
  attendance DECIMAL(5,2) NOT NULL,
  year VARCHAR(50),
  student_id VARCHAR(20) NOT NULL,
  branch VARCHAR(100),
  batch VARCHAR(50),
  parent_email VARCHAR(150),
  parent_contact VARCHAR(20),
  leave_days INT,
  start_date DATE,
  end_date DATE,
  reason TEXT,
  teacher VARCHAR(150),
  hod_assigned VARCHAR(150),
  dean_assigned VARCHAR(150),
  teacher_approved TINYINT(1) DEFAULT 0,
  hod_approved TINYINT(1) DEFAULT 0,
  dean_approved TINYINT(1) DEFAULT 0,
  status VARCHAR(50) DEFAULT 'Pending',
  qr_code_data TEXT,
  timestamp BIGINT NOT NULL
);

-- sample users (demo credentials)
INSERT INTO users (login_id, password, role, name) VALUES
('student123','pass123','student','Demo Student'),
('dileep123','pass111','teacher','Dileep Kumar'),
('bagal123','pass111','teacher','Vivekanand Bagal'),
('hod1_id','hod1_pass','hod','HOD BTech/MBA'),
('hod2_id','hod2_pass','hod','HOD BPharm/Textile'),
('dean_id','dean_pass','dean','Dean of Academics');
