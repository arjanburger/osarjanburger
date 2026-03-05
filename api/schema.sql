-- ArjanBurger OS - Database Schema
-- Database: arjanburger_os

CREATE DATABASE IF NOT EXISTS arjanburger_os CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE arjanburger_os;

-- ── OS Users (dashboard login) ──────────────────────────
CREATE TABLE os_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Clients ─────────────────────────────────────────────
CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(50),
    company VARCHAR(100),
    notes TEXT,
    visitor_id VARCHAR(100),
    source_page VARCHAR(100),
    status ENUM('lead', 'active', 'client', 'inactive') NOT NULL DEFAULT 'lead',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Landing Pages ───────────────────────────────────────
CREATE TABLE landing_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    url VARCHAR(500),
    client_id INT,
    status ENUM('draft', 'live', 'paused') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Tracking: Pageviews ─────────────────────────────────
CREATE TABLE tracking_pageviews (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    url VARCHAR(500),
    referrer VARCHAR(500),
    utm_json JSON,
    screen VARCHAR(20),
    viewport VARCHAR(20),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at)
) ENGINE=InnoDB;

-- ── Tracking: Conversions (CTA clicks) ──────────────────
CREATE TABLE tracking_conversions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    action VARCHAR(100),
    label VARCHAR(255),
    url VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at)
) ENGINE=InnoDB;

-- ── Tracking: Form submissions ──────────────────────────
CREATE TABLE tracking_forms (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    form_id VARCHAR(100),
    fields_json JSON,
    url VARCHAR(500),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at)
) ENGINE=InnoDB;

-- ── Tracking: Scroll depth ──────────────────────────────
CREATE TABLE tracking_scroll (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    depth INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at)
) ENGINE=InnoDB;

-- ── Tracking: Time on page ──────────────────────────────
CREATE TABLE tracking_time (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    seconds INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at)
) ENGINE=InnoDB;

-- ── Tracking: Form interactions (start/progress/abandon) ──
CREATE TABLE tracking_form_interactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    form_id VARCHAR(100),
    event ENUM('start', 'progress', 'abandon') NOT NULL,
    fields_json JSON,
    field_count INT DEFAULT 0,
    time_spent INT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at),
    INDEX idx_visitor_form (visitor_id, form_id)
) ENGINE=InnoDB;

-- ── Tracking: Video ────────────────────────────────────
CREATE TABLE tracking_video (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    page_slug VARCHAR(100) NOT NULL,
    visitor_id VARCHAR(100),
    event VARCHAR(50) NOT NULL,
    video_id VARCHAR(50),
    seconds_watched INT DEFAULT 0,
    duration INT DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_date (page_slug, created_at)
) ENGINE=InnoDB;

-- ── Visitor Aliases (cross-domain koppeling) ───────────
CREATE TABLE visitor_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    canonical_id VARCHAR(100) NOT NULL,
    alias_id VARCHAR(100) NOT NULL,
    source VARCHAR(50) DEFAULT 'cookie_sync',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (alias_id),
    INDEX (canonical_id)
) ENGINE=InnoDB;

-- ── Initial admin user ──────────────────────────────────
-- Wachtwoord: wijzig dit! Genereer hash met: php -r "echo password_hash('jouw_wachtwoord', PASSWORD_DEFAULT);"
-- INSERT INTO os_users (name, email, password_hash) VALUES ('Arjan', 'arjan@arjanburger.com', '$2y$10$...');
