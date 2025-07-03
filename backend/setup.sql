-- Create database if not exists
CREATE DATABASE IF NOT EXISTS aura_vesture;
USE aura_vesture;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('customer', 'admin', 'support') DEFAULT 'customer',
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User settings table
CREATE TABLE IF NOT EXISTS user_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_secret VARCHAR(32),
    login_notifications BOOLEAN DEFAULT TRUE,
    suspicious_activity_alerts BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_settings (user_id)
);

-- Support tickets table
CREATE TABLE IF NOT EXISTS support_tickets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    category ENUM('account_access', '2fa', 'security', 'password', 'other') NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    urgency ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    status ENUM('open', 'in_progress', 'pending', 'resolved', 'closed') DEFAULT 'open',
    assigned_to INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    INDEX idx_ticket_status (status),
    INDEX idx_ticket_urgency (urgency),
    INDEX idx_ticket_category (category)
);

-- Support ticket responses
CREATE TABLE IF NOT EXISTS ticket_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT,
    response TEXT NOT NULL,
    is_internal BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_ticket_responses (ticket_id)
);

-- Support ticket attachments
CREATE TABLE IF NOT EXISTS ticket_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    response_id INT,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_type VARCHAR(100) NOT NULL,
    file_size INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (response_id) REFERENCES ticket_responses(id),
    INDEX idx_ticket_attachments (ticket_id)
);

-- Two-factor backup codes
CREATE TABLE IF NOT EXISTS two_factor_backup_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(255) NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_backup_codes (user_id, used)
);

-- Password resets table
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    code VARCHAR(6) NOT NULL,
    used TINYINT(1) DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_token (token),
    INDEX idx_code (code)
);

-- User sessions table
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    last_activity TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_session_token (token),
    INDEX idx_session_user (user_id)
);

-- Activity logs for security monitoring
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_activity (user_id, action),
    INDEX idx_activity_date (created_at)
);

-- Products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    category VARCHAR(100),
    image_url VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category)
);

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    tracking_number VARCHAR(50) UNIQUE,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_tracking (tracking_number),
    INDEX idx_status (status)
);

-- Order items table
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    product_id INT,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Order status table for tracking
CREATE TABLE IF NOT EXISTS order_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    status VARCHAR(100) NOT NULL,
    location VARCHAR(255),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    INDEX idx_order_status (order_id, status)
);

-- Customer addresses
CREATE TABLE IF NOT EXISTS addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    type ENUM('billing', 'shipping') DEFAULT 'shipping',
    street_address VARCHAR(255) NOT NULL,
    city VARCHAR(100) NOT NULL,
    state VARCHAR(100) NOT NULL,
    postal_code VARCHAR(20) NOT NULL,
    country VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user_address (user_id, type)
);

-- Insert default admin user (password: admin123)
INSERT INTO users (email, password, role, first_name, last_name) 
VALUES (
    'admin@auravesture.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    'Admin',
    'User'
);

-- Insert default support user
INSERT INTO users (email, password, role, first_name, last_name)
VALUES (
    'support@auravesture.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'support',
    'Support',
    'Team'
);

-- Insert default settings for admin
INSERT INTO user_settings (user_id, two_factor_enabled, login_notifications, suspicious_activity_alerts)
SELECT id, TRUE, TRUE, TRUE
FROM users
WHERE email = 'admin@auravesture.com';

-- Insert sample products
INSERT INTO products (name, description, price, stock, category) VALUES
('Futuristic Jacket', 'A sleek and modern jacket with LED accents', 299.99, 50, 'Outerwear'),
('Neo Pants', 'Comfortable and stylish pants with smart fabric technology', 149.99, 30, 'Bottoms'),
('Tech Shirt', 'Breathable shirt with temperature regulation', 89.99, 100, 'Tops'),
('Smart Boots', 'Self-lacing boots with temperature control', 249.99, 25, 'Footwear'),
('Digital Watch', 'Smart watch with holographic display', 199.99, 40, 'Accessories');

-- Add support_manager role to users table
ALTER TABLE users MODIFY COLUMN role ENUM('customer', 'admin', 'support', 'support_manager') DEFAULT 'customer';

-- Add feedback-related fields to support_tickets table
ALTER TABLE support_tickets
ADD COLUMN feedback_received BOOLEAN DEFAULT FALSE,
ADD COLUMN feedback_date TIMESTAMP NULL,
ADD COLUMN first_response_at TIMESTAMP NULL;

-- Create ticket feedback table
CREATE TABLE IF NOT EXISTS ticket_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    user_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    satisfaction_response_time BOOLEAN DEFAULT FALSE,
    satisfaction_communication BOOLEAN DEFAULT FALSE,
    satisfaction_solution_quality BOOLEAN DEFAULT FALSE,
    satisfaction_professionalism BOOLEAN DEFAULT FALSE,
    satisfaction_overall_experience BOOLEAN DEFAULT FALSE,
    comments TEXT,
    follow_up_requested BOOLEAN DEFAULT FALSE,
    follow_up_completed BOOLEAN DEFAULT FALSE,
    follow_up_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_ticket_feedback (ticket_id),
    INDEX idx_feedback_rating (rating)
);

-- Create support tasks table
CREATE TABLE IF NOT EXISTS support_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT,
    assigned_to INT,
    type ENUM('feedback_follow_up', 'low_rating_review', 'escalation', 'general') NOT NULL,
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    description TEXT NOT NULL,
    notes TEXT,
    due_date TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES support_tickets(id),
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    INDEX idx_task_status (status),
    INDEX idx_task_priority (priority),
    INDEX idx_task_type (type)
);

-- Create support team performance metrics table
CREATE TABLE IF NOT EXISTS support_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    date DATE NOT NULL,
    tickets_handled INT DEFAULT 0,
    avg_response_time INT DEFAULT 0, -- in minutes
    avg_resolution_time INT DEFAULT 0, -- in minutes
    satisfaction_score DECIMAL(3,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY unique_user_date (user_id, date),
    INDEX idx_metrics_date (date)
);

-- Insert support manager user
INSERT INTO users (email, password, role, first_name, last_name)
VALUES (
    'manager@auravesture.com',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'support_manager',
    'Support',
    'Manager'
);

-- Create view for support performance reporting
CREATE OR REPLACE VIEW vw_support_performance AS
SELECT 
    u.id as user_id,
    u.first_name,
    u.last_name,
    COUNT(DISTINCT t.id) as total_tickets,
    AVG(CASE WHEN t.status = 'resolved' THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) END) as avg_resolution_time,
    AVG(CASE WHEN t.first_response_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_response_at) END) as avg_first_response_time,
    AVG(f.rating) as avg_satisfaction,
    COUNT(CASE WHEN f.rating <= 2 THEN 1 END) as low_ratings,
    COUNT(CASE WHEN f.rating >= 4 THEN 1 END) as high_ratings
FROM users u
LEFT JOIN support_tickets t ON t.assigned_to = u.id
LEFT JOIN ticket_feedback f ON t.id = f.ticket_id
WHERE u.role IN ('support', 'support_manager')
GROUP BY u.id;

-- Create trigger to update ticket metrics
DELIMITER //
CREATE TRIGGER after_ticket_update
AFTER UPDATE ON support_tickets
FOR EACH ROW
BEGIN
    IF NEW.status = 'resolved' AND OLD.status != 'resolved' THEN
        INSERT INTO support_metrics (user_id, date, tickets_handled)
        VALUES (NEW.assigned_to, CURDATE(), 1)
        ON DUPLICATE KEY UPDATE tickets_handled = tickets_handled + 1;
    END IF;
END//
DELIMITER ;

-- Create indexes for better performance
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_products_price ON products(price);
CREATE INDEX idx_orders_date ON orders(created_at);
CREATE INDEX idx_activity_logs_date ON activity_logs(created_at);
CREATE INDEX idx_sessions_activity ON user_sessions(last_activity);
CREATE INDEX idx_support_tickets_date ON support_tickets(created_at);
CREATE INDEX idx_ticket_responses_date ON ticket_responses(created_at);
CREATE INDEX idx_feedback_date ON ticket_feedback(created_at);
CREATE INDEX idx_tasks_due_date ON support_tasks(due_date);
CREATE INDEX idx_metrics_user_date ON support_metrics(user_id, date);
