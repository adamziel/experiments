-- Sample schema and data for testing binlog-json.php.
-- Run this against the MySQL container, then start binlog-json.php,
-- then run mutations.sql to see changes streamed as JSON.

USE testdb;

CREATE TABLE users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(200),
    balance    DECIMAL(10,2) DEFAULT 0.00,
    active     TINYINT(1) DEFAULT 1,
    metadata   JSON,
    bio        TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (name, email, balance, active, metadata, bio) VALUES
    ('Alice',   'alice@example.com',   250.00, 1, '{"role":"admin","tags":["a","b"]}',   'First user'),
    ('Bob',     'bob@example.com',     99.99,  1, '{"role":"editor"}',                   'Second user'),
    ('Charlie', 'charlie@example.com', 999.99, 0, NULL,                                  'Third user');

CREATE TABLE orders (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    product    VARCHAR(200),
    quantity   INT DEFAULT 1,
    price      DECIMAL(10,2),
    ordered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO orders (user_id, product, quantity, price) VALUES
    (1, 'Widget',  3, 12.50),
    (1, 'Gadget',  1, 89.99),
    (2, 'Widget', 10,  9.99);
