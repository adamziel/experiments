-- Run these AFTER starting binlog-json.php to watch changes appear as JSON.
-- Each block is a different kind of mutation.

USE testdb;

-- Simple insert
INSERT INTO users (name, email, balance, metadata)
VALUES ('Diana', 'diana@example.com', 500.00, '{"role":"viewer","nested":{"x":true}}');

-- Multi-row insert
INSERT INTO orders (user_id, product, quantity, price) VALUES
    (3, 'Doohickey', 2, 45.00),
    (3, 'Thingamajig', 1, 120.00);

-- Update with before/after image
UPDATE users SET balance = balance + 100, metadata = '{"role":"admin","promoted":true}' WHERE name = 'Bob';

-- Update multiple rows
UPDATE orders SET price = price * 1.10 WHERE product = 'Widget';

-- Delete
DELETE FROM orders WHERE product = 'Thingamajig';

-- DDL (shows up as a query event)
ALTER TABLE users ADD COLUMN last_login DATETIME NULL;

-- Transaction with multiple statements
START TRANSACTION;
INSERT INTO users (name, email, balance) VALUES ('Eve', 'eve@example.com', 0.00);
UPDATE users SET balance = balance - 50 WHERE name = 'Alice';
COMMIT;

-- Delete a row
DELETE FROM users WHERE name = 'Diana';
