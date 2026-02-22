CREATE DATABASE db_duck_egg_allocation;
USE db_duck_egg_allocation;

CREATE TABLE table_admin (
  admin_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(50) NOT NULL,
  email VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE table_users (
  user_id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(50) NOT NULL,
  email VARCHAR(50),
  phone_number VARCHAR(15),
  password VARCHAR(255) NOT NULL,
  created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE egg_batching (
  batch_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  total_eggs INT NOT NULL,
  incubation_start_date DATE NOT NULL,
  expected_date DATE NOT NULL,
  status ENUM('incubating','balut','chick','failed') DEFAULT 'incubating',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES table_users(user_id)
);

CREATE TABLE egg_tracking (
  tracking_id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  incubation_day INT NOT NULL,
  note TEXT,
  recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (batch_id) REFERENCES egg_batching(batch_id)
);

CREATE TABLE egg_allocating (
  allocation_id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  balut_count INT DEFAULT 0,
  chick_count INT DEFAULT 0,
  failed_count INT DEFAULT 0,
  allocation_date DATE NOT NULL,
  FOREIGN KEY (batch_id) REFERENCES egg_batching(batch_id)
);