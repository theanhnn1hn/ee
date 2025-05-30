<?php
/**
 * Voices Management API
 * Handle voice operations: list, delete, update
 */

require_once '../config.php';

header('Content-Type: application/json');
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch ($method) {
    case 'GET':
        handleGetVoices();
        break;
        
    case 'DELETE':
        handleDeleteVoice($input);
        break;
        
    case 'PUT':
        handleUpdateVoice($input);
        break;
        
    default:
        http_response_code(405);
        jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

/**
 * Get user's imported voices with filtering
 */
function handleGetVoices() {
    global $db, $LANGUAGES;
    
    $search = $_GET['search'] ?? '';
    $language = $_GET['language'] ?? '';
    $gender = $_GET['gender'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    // Build query with filters
    $whereConditions = ['uv.user_id = ?'];
    $params = [$_SESSION['user_id']];
    
    if ($search) {
        $whereConditions[] = '(v.name LIKE ? OR v.description LIKE ? OR uv.custom_name LIKE ?)';
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if ($language) {
        $whereConditions[] = '(v.language = ? OR uv.custom_language = ?)';
        $params[] = $language;
        $params[] = $language;
    }
    
    if ($gender) {
        $whereConditions[] = 'v.gender = ?';
        $params[] = $gender;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count
    $countQuery = "
        SELECT COUNT(*) 
        FROM user_voices uv 
        JOIN voices v ON v.voice_id = uv.voice_id 
        WHERE $whereClause
    ";
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $totalCount = $stmt->fetchColumn();
    
    // Get voices with pagination
    $query = "
        SELECT v.*, uv.custom_language, uv.custom_name, uv.usage_count, 
               uv.last_used, uv.import_date
        FROM user_voices uv 
        JOIN voices v ON v.voice_id = uv.voice_id 
        WHERE $whereClause
        ORDER BY uv.import_date DESC
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $voices = $stmt->fetchAll();
    
    // Enhance voice data with language info
    foreach ($voices as &$voice) {
        $voiceLang = $voice['custom_language'] ?: $voice['language'];
        $voice['language_info'] = null;
        
        foreach ($LANGUAGES as $name => $info) {
            if ($info['code'] === $voiceLang) {
                $voice['language_info'] = $info;
                break;
            }
        }
        
        // Parse analysis JSON if exists
        if ($voice['analysis']) {
            $voice['analysis'] = json_decode($voice['analysis'], true);
        }
    }
    
    jsonResponse([
        'success' => true,
        'voices' => $voices,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/**
 * Delete imported voice
 */
function handleDeleteVoice($input) {
    global $db;
    
    if (!isset($input['voice_id']) || empty($input['voice_id'])) {
        jsonResponse(['success' => false, 'message' => 'Missing voice_id'], 400);
    }
    
    $voiceId = $input['voice_id'];
    
    try {
        $db->beginTransaction();
        
        // Check if voice belongs to user
        $stmt = $db->prepare("
            SELECT uv.*, v.name 
            FROM user_voices uv 
            JOIN voices v ON v.voice_id = uv.voice_id 
            WHERE uv.user_id = ? AND uv.voice_id = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $voiceId]);
        $userVoice = $stmt->fetch();
        
        if (!$userVoice) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'Voice not found in your collection'], 404);
        }
        
        // Delete from user_voices (this removes the voice from user's collection)
        $stmt = $db->prepare("DELETE FROM user_voices WHERE user_id = ? AND voice_id = ?");
        $stmt->execute([$_SESSION['user_id'], $voiceId]);
        
        // Check if any other users have this voice imported
        $stmt = $db->prepare("SELECT COUNT(*) FROM user_voices WHERE voice_id = ?");
        $stmt->execute([$voiceId]);
        $otherUsers = $stmt->fetchColumn();
        
        // If no other users have this voice, we can remove it from voices table
        if ($otherUsers == 0) {
            $stmt = $db->prepare("DELETE FROM voices WHERE voice_id = ?");
            $stmt->execute([$voiceId]);
        }
        
        $db->commit();
        
        // Log activity
        logActivity('voice_deleted', [
            'voice_id' => $voiceId,
            'voice_name' => $userVoice['name']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Voice deleted successfully'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        
        logActivity('voice_delete_failed', [
            'voice_id' => $voiceId,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse([
            'success' => false,
            'message' => 'Delete failed: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Update voice settings (name, language)
 */
function handleUpdateVoice($input) {
    global $db;
    
    if (!isset($input['voice_id']) || empty($input['voice_id'])) {
        jsonResponse(['success' => false, 'message' => 'Missing voice_id'], 400);
    }
    
    $voiceId = $input['voice_id'];
    $customName = trim($input['custom_name'] ?? '');
    $customLanguage = $input['custom_language'] ?? null;
    
    try {
        // Check if voice belongs to user
        $stmt = $db->prepare("SELECT id FROM user_voices WHERE user_id = ? AND voice_id = ?");
        $stmt->execute([$_SESSION['user_id'], $voiceId]);
        
        if (!$stmt->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Voice not found in your collection'], 404);
        }
        
        // Update voice settings
        $stmt = $db->prepare("
            UPDATE user_voices 
            SET custom_name = ?, custom_language = ? 
            WHERE user_id = ? AND voice_id = ?
        ");
        
        $stmt->execute([
            $customName ?: null,
            $customLanguage,
            $_SESSION['user_id'],
            $voiceId
        ]);
        
        // Log activity
        logActivity('voice_updated', [
            'voice_id' => $voiceId,
            'custom_name' => $customName,
            'custom_language' => $customLanguage
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Voice updated successfully'
        ]);
        
    } catch (Exception $e) {
        logActivity('voice_update_failed', [
            'voice_id' => $voiceId,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse([
            'success' => false,
            'message' => 'Update failed: ' . $e->getMessage()
        ], 500);
    }
}

?>
