-- ElevenLabs Web Tool Database Schema
-- Keeps all functionality from Python tool

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- Database: elevenlabs_web
CREATE DATABASE IF NOT EXISTS elevenlabs_web CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE elevenlabs_web;

-- =====================================================
-- USERS TABLE - Admin and regular users
-- =====================================================
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    status ENUM('active', 'suspended', 'pending') DEFAULT 'active',
    credits_balance INT DEFAULT 10000, -- Free credits for new users
    credits_used INT DEFAULT 0,
    max_credits INT DEFAULT 50000, -- Monthly limit
    last_credit_reset DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

-- =====================================================
-- API KEYS TABLE - Store ElevenLabs API keys
-- =====================================================
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_name VARCHAR(100) NOT NULL,
    api_key VARCHAR(255) NOT NULL,
    key_type ENUM('regular', 'premium') DEFAULT 'regular',
    monthly_limit INT DEFAULT 10000,
    credits_used INT DEFAULT 0,
    last_reset DATE DEFAULT NULL,
    status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    priority INT DEFAULT 1, -- Higher priority keys used first
    added_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================================================
-- VOICES TABLE - Store imported and cached voices
-- =====================================================
CREATE TABLE voices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voice_id VARCHAR(100) UNIQUE NOT NULL, -- ElevenLabs voice ID
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(50),
    language VARCHAR(10),
    gender ENUM('male', 'female', 'unknown') DEFAULT 'unknown',
    age ENUM('young', 'adult', 'middle', 'old') DEFAULT 'adult',
    quality ENUM('premium', 'professional', 'cloned', 'generated', 'community') DEFAULT 'community',
    source ENUM('imported', 'library', 'premade') DEFAULT 'library',
    preview_url TEXT NULL,
    analysis JSON, -- Store voice analysis data
    labels JSON, -- Store voice labels/tags
    imported_by INT NULL, -- User who imported this voice
    is_available BOOLEAN DEFAULT TRUE,
    use_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_voice_id (voice_id),
    INDEX idx_language (language),
    INDEX idx_category (category),
    INDEX idx_source (source)
);

-- =====================================================
-- USER_VOICES TABLE - User's imported voices
-- =====================================================
CREATE TABLE user_voices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    voice_id VARCHAR(100) NOT NULL,
    custom_language VARCHAR(10), -- User-selected language for this voice
    custom_name VARCHAR(255), -- User-given name
    import_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    usage_count INT DEFAULT 0,
    last_used TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_voice (user_id, voice_id)
);

-- =====================================================
-- GENERATION_HISTORY TABLE - Track all TTS generations
-- =====================================================
CREATE TABLE generation_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    voice_id VARCHAR(100) NOT NULL,
    text_input TEXT NOT NULL,
    model_used VARCHAR(50) NOT NULL,
    audio_format VARCHAR(50) NOT NULL,
    language_code VARCHAR(10),
    voice_settings JSON, -- Stability, similarity, style settings
    seed_value BIGINT NULL,
    credits_used INT NOT NULL,
    processing_time DECIMAL(8,3), -- In seconds
    audio_duration DECIMAL(8,3), -- In seconds
    file_size INT, -- In bytes
    output_filename VARCHAR(255),
    status ENUM('completed', 'failed', 'processing') DEFAULT 'processing',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, created_at),
    INDEX idx_status (status)
);

-- =====================================================
-- PROXIES TABLE - Store proxy configurations
-- =====================================================
CREATE TABLE proxies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    proxy_url VARCHAR(500) NOT NULL,
    proxy_type ENUM('http', 'https', 'socks4', 'socks5') DEFAULT 'http',
    username VARCHAR(100) NULL,
    password VARCHAR(100) NULL,
    status ENUM('active', 'inactive', 'error') DEFAULT 'active',
    last_test TIMESTAMP NULL,
    success_count INT DEFAULT 0,
    error_count INT DEFAULT 0,
    priority INT DEFAULT 1,
    added_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================================================
-- SETTINGS TABLE - Application settings
-- =====================================================
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    is_user_editable BOOLEAN DEFAULT FALSE,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- =====================================================
-- CACHE TABLE - For voice and data caching
-- =====================================================
CREATE TABLE cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) UNIQUE NOT NULL,
    cache_data LONGTEXT,
    cache_type ENUM('voices', 'api_response', 'settings') DEFAULT 'voices',
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key_type (cache_key, cache_type),
    INDEX idx_expires (expires_at)
);

-- =====================================================
-- ACTIVITY_LOGS TABLE - Track user activities
-- =====================================================
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created (created_at)
);

-- =====================================================
-- INSERT DEFAULT DATA
-- =====================================================

-- Default admin user (password: admin123)
INSERT INTO users (username, email, password, role, credits_balance, max_credits) VALUES 
('admin', 'admin@elevenlabs.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 999999, 999999);

-- Default settings
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_user_editable) VALUES
('app_name', 'ElevenLabs Professional Studio', 'string', 'Application name', TRUE),
('default_user_credits', '10000', 'integer', 'Default credits for new users', TRUE),
('max_user_credits', '50000', 'integer', 'Maximum monthly credits per user', TRUE),
('voice_cache_hours', '6', 'integer', 'Voice cache duration in hours', TRUE),
('max_text_length', '40000', 'integer', 'Maximum text length for TTS', TRUE),
('chunk_size', '2500', 'integer', 'Text chunk size for processing', TRUE),
('use_proxy', 'false', 'boolean', 'Enable proxy usage', TRUE),
('auto_language_detect', 'true', 'boolean', 'Enable auto language detection', TRUE),
('voices_per_page', '20', 'integer', 'Voices displayed per page', TRUE),
('preview_text', 'Hello, this is a preview of my voice. How do you like it?', 'string', 'Default preview text', TRUE);

-- Default models (matching Python tool)
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_user_editable) VALUES
('models', '{"Flash v2.5 (Fast & Cheap)": {"id": "eleven_flash_v2_5", "credits_per_char": 0.5, "languages": "32"}, "Turbo v2.5 (Balanced)": {"id": "eleven_turbo_v2_5", "credits_per_char": 0.5, "languages": "32"}, "Multilingual v2 (Premium)": {"id": "eleven_multilingual_v2", "credits_per_char": 1.0, "languages": "29"}}', 'json', 'Available TTS models', FALSE);

-- Audio formats (matching Python tool)
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_user_editable) VALUES
('audio_formats', '{"MP3 High Quality": "mp3_44100_128", "MP3 Standard": "mp3_22050_32", "PCM 16kHz": "pcm_16000", "PCM 44kHz": "pcm_44100"}', 'json', 'Available audio formats', FALSE);

-- Languages (matching Python tool)
INSERT INTO settings (setting_key, setting_value, setting_type, description, is_user_editable) VALUES
('languages', '{"Auto Detect": {"code": null, "flag": "🌐", "name": "Auto", "iso": null}, "English (US)": {"code": "en", "flag": "🇺🇸", "name": "English US", "iso": "en"}, "English (UK)": {"code": "en-gb", "flag": "🇬🇧", "name": "English UK", "iso": "en-gb"}, "Tiếng Việt": {"code": "vi", "flag": "🇻🇳", "name": "Vietnamese", "iso": "vi"}, "中文": {"code": "zh", "flag": "🇨🇳", "name": "Chinese", "iso": "zh"}, "日本語": {"code": "ja", "flag": "🇯🇵", "name": "Japanese", "iso": "ja"}, "한국어": {"code": "ko", "flag": "🇰🇷", "name": "Korean", "iso": "ko"}, "Español (ES)": {"code": "es", "flag": "🇪🇸", "name": "Spanish", "iso": "es"}, "Français (FR)": {"code": "fr", "flag": "🇫🇷", "name": "French", "iso": "fr"}, "Deutsch": {"code": "de", "flag": "🇩🇪", "name": "German", "iso": "de"}, "Italiano": {"code": "it", "flag": "🇮🇹", "name": "Italian", "iso": "it"}, "Português (BR)": {"code": "pt-br", "flag": "🇧🇷", "name": "Portuguese BR", "iso": "pt-br"}, "Русский": {"code": "ru", "flag": "🇷🇺", "name": "Russian", "iso": "ru"}}', 'json', 'Supported languages', FALSE);

COMMIT;