START TRANSACTION;

CREATE TABLE
  users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    user_role ENUM ('admin', 'manager', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE
  egg (
    egg_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_egg INT NOT NULL,
    status ENUM ('incubating', 'hatched', 'failed') NOT NULL,
    date_started_incubation TIMESTAMP NOT NULL,
    balut_count INT DEFAULT 0,
    failed_count INT DEFAULT 0,
    chick_count INT DEFAULT 0,
    batch_number INT NOT NULL UNIQUE,
    INDEX idx_egg_user (user_id),
    CONSTRAINT fk_egg_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

CREATE TABLE
  user_activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    log_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_log_date (log_date),
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE ON UPDATE CASCADE
  ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_general_ci;

COMMIT;