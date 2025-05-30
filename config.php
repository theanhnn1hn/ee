<?php
/**
 * ElevenLabs Web Tool Configuration
 * Maintains all settings and connections from Python tool
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// DATABASE CONFIGURATION
// =====================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'elevenlabs_web');
define('DB_USER', 'root');
define('DB_PASS', '');

// Database connection
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch(PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}

// =====================================================
// APPLICATION SETTINGS (from Python tool)
// =====================================================
define('APP_NAME', 'ElevenLabs Professional Studio');
define('APP_VERSION', '2.0');
define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('TEMP_PATH', __DIR__ . '/temp/');
define('AUDIO_PATH', __DIR__ . '/audio/');

// Create directories if not exist
foreach ([UPLOAD_PATH, TEMP_PATH, AUDIO_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// =====================================================
// ELEVENLABS API SETTINGS (matching Python tool)
// =====================================================
define('ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io/v1');
define('DEFAULT_CHUNK_SIZE', 2500);
define('MAX_TEXT_LENGTH', 40000);
define('REQUEST_TIMEOUT', 60);
define('MAX_RETRIES', 3);
define('CREDIT_BUFFER', 500);

// Models (exact match from Python)
$MODELS = [
    "Flash v2.5 (Fast & Cheap)" => [
        "id" => "eleven_flash_v2_5", 
        "credits_per_char" => 0.5, 
        "languages" => "32"
    ],
    "Turbo v2.5 (Balanced)" => [
        "id" => "eleven_turbo_v2_5", 
        "credits_per_char" => 0.5, 
        "languages" => "32"
    ],
    "Multilingual v2 (Premium)" => [
        "id" => "eleven_multilingual_v2", 
        "credits_per_char" => 1.0, 
        "languages" => "29"
    ]
];

// Audio formats (exact match from Python)
$AUDIO_FORMATS = [
    "MP3 High Quality" => "mp3_44100_128",
    "MP3 Standard" => "mp3_22050_32",
    "PCM 16kHz" => "pcm_16000",
    "PCM 44kHz" => "pcm_44100"
];

// Languages (subset from Python tool)
$LANGUAGES = [
    "Auto Detect" => ["code" => null, "flag" => "🌐", "name" => "Auto", "iso" => null],
    "English (US)" => ["code" => "en", "flag" => "🇺🇸", "name" => "English US", "iso" => "en"],
    "English (UK)" => ["code" => "en-gb", "flag" => "🇬🇧", "name" => "English UK", "iso" => "en-gb"],
    "Tiếng Việt" => ["code" => "vi", "flag" => "🇻🇳", "name" => "Vietnamese", "iso" => "vi"],
    "中文" => ["code" => "zh", "flag" => "🇨🇳", "name" => "Chinese", "iso" => "zh"],
    "日本語" => ["code" => "ja", "flag" => "🇯🇵", "name" => "Japanese", "iso" => "ja"],
    "한국어" => ["code" => "ko", "flag" => "🇰🇷", "name" => "Korean", "iso" => "ko"],
    "Español (ES)" => ["code" => "es", "flag" => "🇪🇸", "name" => "Spanish", "iso" => "es"],
    "Français (FR)" => ["code" => "fr", "flag" => "🇫🇷", "name" => "French", "iso" => "fr"],
    "Deutsch" => ["code" => "de", "flag" => "🇩🇪", "name" => "German", "iso" => "de"],
    "Italiano" => ["code" => "it", "flag" => "🇮🇹", "name" => "Italian", "iso" => "it"],
    "Português (BR)" => ["code" => "pt-br", "flag" => "🇧🇷", "name" => "Portuguese BR", "iso" => "pt-br"],
    "Русский" => ["code" => "ru", "flag" => "🇷🇺", "name" => "Russian", "iso" => "ru"]
];

// =====================================================
// UTILITY FUNCTIONS
// =====================================================

/**
 * Get setting value from database
 */
function getSetting($key, $default = null) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    if (!$result) {
        return $default;
    }
    
    $value = $result['setting_value'];
    $type = $result['setting_type'];
    
    switch ($type) {
        case 'integer':
            return (int)$value;
        case 'boolean':
            return $value === 'true' || $value === '1';
        case 'json':
            return json_decode($value, true);
        default:
            return $value;
    }
}

/**
 * Set setting value in database
 */
function setSetting($key, $value, $type = 'string', $userId = null) {
    $db = Database::getInstance()->getConnection();
    
    if ($type === 'json') {
        $value = json_encode($value);
    } elseif ($type === 'boolean') {
        $value = $value ? 'true' : 'false';
    }
    
    $stmt = $db->prepare("
        INSERT INTO settings (setting_key, setting_value, setting_type, updated_by) 
        VALUES (?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        setting_value = VALUES(setting_value), 
        setting_type = VALUES(setting_type),
        updated_by = VALUES(updated_by)
    ");
    
    return $stmt->execute([$key, $value, $type, $userId]);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Redirect to login if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Require admin privileges
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: dashboard.php');
        exit;
    }
}

/**
 * Log user activity
 */
function logActivity($action, $details = [], $userId = null) {
    if (!$userId) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
        VALUES (?, ?, ?, ?, ?)
    ");
    
    return $stmt->execute([
        $userId,
        $action,
        json_encode($details),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

/**
 * Format credits number
 */
function formatCredits($credits) {
    return number_format($credits);
}

/**
 * Estimate credits for text
 */
function estimateCredits($text, $model = 'Flash v2.5 (Fast & Cheap)') {
    global $MODELS;
    $charCount = strlen($text);
    $creditsPerChar = $MODELS[$model]['credits_per_char'] ?? 1.0;
    return (int)($charCount * $creditsPerChar);
}

/**
 * Check user has enough credits
 */
function hasEnoughCredits($userId, $requiredCredits) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT credits_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    return $user && $user['credits_balance'] >= $requiredCredits;
}

/**
 * Deduct credits from user
 */
function deductCredits($userId, $credits) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        UPDATE users 
        SET credits_balance = credits_balance - ?, credits_used = credits_used + ? 
        WHERE id = ? AND credits_balance >= ?
    ");
    
    return $stmt->execute([$credits, $credits, $userId, $credits]);
}

/**
 * Clean text for filename
 */
function cleanFilename($text, $maxLength = 30) {
    $clean = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $text);
    return substr($clean, 0, $maxLength);
}

/**
 * Generate secure random string
 */
function generateRandomString($length = 16) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Format file size
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Send JSON response
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get best available API key (matches Python logic)
 */
function getBestApiKey($estimatedCredits, $premium = false) {
    $db = Database::getInstance()->getConnection();
    
    $keyType = $premium ? 'premium' : 'regular';
    
    $stmt = $db->prepare("
        SELECT api_key, id, monthly_limit, credits_used 
        FROM api_keys 
        WHERE key_type = ? AND status = 'active' 
        AND (monthly_limit - credits_used) >= ?
        ORDER BY priority DESC, (monthly_limit - credits_used) DESC
        LIMIT 1
    ");
    
    $stmt->execute([$keyType, $estimatedCredits + CREDIT_BUFFER]);
    return $stmt->fetch();
}

/**
 * Update API key usage
 */
function updateApiKeyUsage($keyId, $creditsUsed) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        UPDATE api_keys 
        SET credits_used = credits_used + ? 
        WHERE id = ?
    ");
    
    return $stmt->execute([$creditsUsed, $keyId]);
}

// =====================================================
// GLOBAL VARIABLES
// =====================================================
$db = Database::getInstance()->getConnection();

// Load models, formats, and languages from database if available
$dbModels = getSetting('models');
if ($dbModels) {
    $MODELS = $dbModels;
}

$dbFormats = getSetting('audio_formats');
if ($dbFormats) {
    $AUDIO_FORMATS = $dbFormats;
}

$dbLanguages = getSetting('languages');
if ($dbLanguages) {
    $LANGUAGES = $dbLanguages;
}

// Set timezone
date_default_timezone_set('UTC');

// CORS headers for AJAX requests
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    exit(0);
}

?>
