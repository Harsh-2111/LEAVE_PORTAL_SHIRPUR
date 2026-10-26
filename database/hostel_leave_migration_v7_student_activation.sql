ALTER TABLE students
  ADD COLUMN student_email VARCHAR(255) NULL AFTER student_contact;

CREATE INDEX idx_students_student_email ON students (student_email);

CREATE TABLE student_activation_tokens (
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  activated_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uniq_student_activation_token_hash (token_hash),
  KEY idx_student_activation_expires (expires_at),
  CONSTRAINT fk_student_activation_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
