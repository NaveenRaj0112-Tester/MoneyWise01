-- MoneyWise Database Schema
-- Run this via install.php (recommended) or: mysql -u root database.sql
-- Safe to re-run: tables are created if they don't exist, existing data is kept.
--
-- NOTE: This file assumes you have ALREADY selected/connected to the target
-- database (e.g. import it via phpMyAdmin into an existing database, or run
-- install.php which connects directly). It deliberately does NOT contain
-- CREATE DATABASE / USE statements — shared hosts (InfinityFree, etc.) do not
-- allow the MySQL user to create or switch databases.

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  gender ENUM('male','female') NOT NULL DEFAULT 'male',
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  password_changed_at DATETIME(6) NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  dash_balance_cleared TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type ENUM('income','expense') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  category VARCHAR(50) NOT NULL,
  notes TEXT NULL,
  tdate DATE NOT NULL,
  -- UPI / payment-tracking metadata (all optional — plain transactions leave these NULL/default)
  payee_name VARCHAR(120) NULL,
  upi_id VARCHAR(150) NULL,
  payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
  ttime TIME NULL,
  txn_ref VARCHAR(60) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'completed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_tx_user (user_id, tdate)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tx_id INT NOT NULL,
  product_name VARCHAR(120) NOT NULL,
  quantity DECIMAL(10,3) NOT NULL DEFAULT 1,
  unit VARCHAR(20) NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  total_price DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (tx_id) REFERENCES transactions(id) ON DELETE CASCADE,
  INDEX idx_ei_tx (tx_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  name VARCHAR(60) NOT NULL,
  type ENUM('income','expense') NOT NULL DEFAULT 'expense',
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_cat (user_id, name, type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  amount DECIMAL(12,2) NOT NULL,
  month TINYINT NOT NULL,
  year SMALLINT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salary_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  employee_name VARCHAR(100) NOT NULL,
  old_salary DECIMAL(12,2) NOT NULL,
  new_salary DECIMAL(12,2) NOT NULL,
  month TINYINT NOT NULL,
  year SMALLINT NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sh_emp (user_id, month, year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cleared_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  tx_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_tx (user_id, tx_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_clr_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  last_used_at DATETIME(6) NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_tok_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auth_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(16) NOT NULL,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  attempt_time DATETIME NOT NULL,
  INDEX idx_scope_email_ip (scope, email, ip, attempt_time),
  INDEX idx_scope_ip_time (scope, ip, attempt_time)
) ENGINE=InnoDB;

-- Event-based expense tracking. Each event belongs to one user; each event
-- can hold many event_expenses. The Events feature is a SEPARATE module: its
-- expenses live only in these tables and are NEVER written to `transactions`,
-- so event spending does not affect the Dashboard, overall reports, Statistics,
-- or Recent Transactions — it is viewed and reported only inside the Events module.
CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  event_name VARCHAR(120) NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  event_date DATE NULL,
  location VARCHAR(150) NULL,
  description TEXT NULL,
  budget DECIMAL(12,2) NULL,
  -- Tanglish -> Tamil: 1 = conversion tool enabled for this event, 0 = disabled.
  -- Per-event toggle so existing (English-only) events remain unaffected.
  enable_tanglish TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ev_user (user_id, event_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  expense_item VARCHAR(120) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  paid_to VARCHAR(120) NOT NULL,
  payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
  expense_date DATE NULL,
  expense_time TIME NULL,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ee_event (event_id),
  INDEX idx_ee_user (user_id, expense_date)
) ENGINE=InnoDB;

-- AI Financial Assistant chat storage. Each row belongs to one user and is only
-- ever read/written through backend functions that enforce WHERE user_id = ?.
-- Only the plain message text is stored (never API keys, tokens or credentials).
CREATE TABLE IF NOT EXISTS ai_conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(120) NOT NULL DEFAULT 'New chat',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ai_conv_user (user_id, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ai_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  user_id INT NOT NULL,
  role ENUM('user','assistant') NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_ai_msg_conv (conversation_id, id),
  INDEX idx_ai_msg_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS app_updates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT NOT NULL,
  admin_name VARCHAR(100) NOT NULL,
  version_from VARCHAR(20) NULL,
  version_to VARCHAR(20) NULL,
  files_replaced INT DEFAULT 0,
  files_added INT DEFAULT 0,
  files_skipped INT DEFAULT 0,
  files_failed INT DEFAULT 0,
  backup_path VARCHAR(500) NULL,
  status ENUM('applied','rolled_back','failed') DEFAULT 'applied',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_updates_admin (admin_user_id, created_at)
) ENGINE=InnoDB;
