CREATE TABLE IF NOT EXISTS items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category VARCHAR(255) NOT NULL,
    status VARCHAR(100) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    description TEXT NULL,
    INDEX idx_items_name (name),
    INDEX idx_items_category (category)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;