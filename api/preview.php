<?php
/**
 * Voice Preview API
 * Generates short audio samples for voice preview
 */

require_once '../config.php';
require_once '../ElevenLabsAPI.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['voice_id']) || empty($input['voice_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing voice_id']);
    exit;
}

$voiceId = $input['voice_id'];
$language = $input['language'] ?? 'en';

try {
    // Initialize API
    $api = new ElevenLabsAPI();
    
    // Generate preview audio
    $audioData = $api->previewVoice($voiceId, 'eleven_flash_v2_5', $language);
    
    // Set appropriate headers for audio response
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . strlen($audioData));
    header('Content-Disposition: inline; filename="preview.mp3"');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Output audio data
    echo $audioData;
    
    // Log preview activity (don't charge credits for preview)
    logActivity('voice_preview', [
        'voice_id' => $voiceId,
        'language' => $language
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Preview failed: ' . $e->getMessage()
    ]);
    
    logActivity('voice_preview_failed', [
        'voice_id' => $voiceId,
        'error' => $e->getMessage()
    ]);
}

?>
