CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'librarian') NOT NULL DEFAULT 'librarian',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    photo_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    isbn VARCHAR(40) UNIQUE,
    title VARCHAR(180) NOT NULL,
    author VARCHAR(160) NOT NULL,
    category VARCHAR(100) NOT NULL,
    publisher VARCHAR(140),
    publication_year YEAR,
    shelf_location VARCHAR(60),
    total_copies INT NOT NULL DEFAULT 1,
    available_copies INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_no VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(140) NOT NULL,
    email VARCHAR(160),
    phone VARCHAR(40),
    address TEXT,
    status ENUM('active', 'blocked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    member_id INT NOT NULL,
    book_type VARCHAR(80) NOT NULL DEFAULT 'General',
    borrower_type ENUM('teacher', 'student') NOT NULL DEFAULT 'student',
    borrower_name VARCHAR(160) NULL,
    student_class VARCHAR(20) NULL,
    student_stream VARCHAR(60) NULL,
    issued_by INT NOT NULL,
    returned_by INT NULL,
    issue_date DATE NOT NULL,
    due_date DATE NOT NULL,
    borrowing_period INT NOT NULL DEFAULT 14,
    notes TEXT NULL,
    return_date DATE NULL,
    fine_amount DECIMAL(10, 2) NOT NULL DEFAULT 0,
    status ENUM('borrowed', 'returned', 'overdue') NOT NULL DEFAULT 'borrowed',
    activity_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES books(id),
    FOREIGN KEY (member_id) REFERENCES members(id),
    FOREIGN KEY (issued_by) REFERENCES users(id),
    FOREIGN KEY (returned_by) REFERENCES users(id)
);

CREATE TABLE fines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    member_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    status ENUM('unpaid', 'paid') NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (loan_id) REFERENCES loans(id),
    FOREIGN KEY (member_id) REFERENCES members(id)
);

CREATE TABLE settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
);

CREATE TABLE login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(160) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY login_attempt_key (email, ip_address)
);

INSERT INTO users (name, email, password_hash, role)
VALUES ('System Administrator', 'admin@library.test', '$2y$12$WJ8HC3pLZhcjpGq4IhqJAezZBqiWavwsBLoW5baBiGii6ORKf4gR.', 'admin');

INSERT INTO settings (setting_key, setting_value) VALUES
('library_name', 'Community Library'),
('school_name', 'Community Library'),
('school_logo', ''),
('school_motto', ''),
('loan_days', '14'),
('student_streams_s1_s4', 'A, B'),
('daily_fine_rate', '500');

INSERT INTO books (isbn, title, author, category, publisher, publication_year, shelf_location, total_copies, available_copies) VALUES
('9780131103627', 'The C Programming Language', 'Brian W. Kernighan, Dennis M. Ritchie', 'Programming', 'Prentice Hall', 1988, 'A1', 3, 3),
('9780132350884', 'Clean Code', 'Robert C. Martin', 'Programming', 'Prentice Hall', 2008, 'A2', 2, 2),
('9780140449136', 'The Odyssey', 'Homer', 'Literature', 'Penguin Classics', 2003, 'B1', 4, 4);
