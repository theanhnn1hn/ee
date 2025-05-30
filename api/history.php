<?php
/**
 * Generation History API
 * Get user's TTS generation history
 */

require_once '../config.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

$page = max(1, intval($_GET['page'] ?? 1));
$limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

try {
    // Get total count
    $countStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM generation_history 
        WHERE user_id = ?
    ");
    $countStmt->execute([$_SESSION['user_id']]);
    $totalCount = $countStmt->fetchColumn();
    
    // Get history with voice information
    $stmt = $db->prepare("
        SELECT 
            gh.*,
            v.name as voice_name,
            uv.custom_name as custom_voice_name
        FROM generation_history gh
        LEFT JOIN voices v ON v.voice_id = gh.voice_id
        LEFT JOIN user_voices uv ON uv.voice_id = gh.voice_id AND uv.user_id = gh.user_id
        WHERE gh.user_id = ?
        ORDER BY gh.created_at DESC
        LIMIT ? OFFSET ?
    ");
    
    $stmt->execute([$_SESSION['user_id'], $limit, $offset]);
    $history = $stmt->fetchAll();
    
    // Process history data
    foreach ($history as &$item) {
        // Parse voice settings JSON
        if ($item['voice_settings']) {
            $item['voice_settings'] = json_decode($item['voice_settings'], true);
        }
        
        // Use custom voice name if available
        $item['voice_display_name'] = $item['custom_voice_name'] ?: $item['voice_name'] ?: 'Unknown Voice';
        
        // Format file size
        if ($item['file_size']) {
            $item['file_size_formatted'] = formatFileSize($item['file_size']);
        }
        
        // Format duration
        if ($item['audio_duration']) {
            $item['audio_duration_formatted'] = formatDuration($item['audio_duration']);
        }
        
        // Check if audio file exists
        $item['audio_available'] = false;
        if ($item['output_filename'] && file_exists(AUDIO_PATH . $item['output_filename'])) {
            $item['audio_available'] = true;
            $item['audio_url'] = 'audio/' . $item['output_filename'];
        }
        
        // Truncate text for display
        if (strlen($item['text_input']) > 200) {
            $item['text_preview'] = substr($item['text_input'], 0, 200) . '...';
        } else {
            $item['text_preview'] = $item['text_input'];
        }
        
        // Status badge info
        $item['status_info'] = [
            'completed' => ['class' => 'success', 'icon' => 'check-circle', 'text' => 'Completed'],
            'failed' => ['class' => 'danger', 'icon' => 'exclamation-triangle', 'text' => 'Failed'],
            'processing' => ['class' => 'warning', 'icon' => 'clock', 'text' => 'Processing']
        ][$item['status']] ?? ['class' => 'secondary', 'icon' => 'question', 'text' => 'Unknown'];
    }
    
    // Calculate statistics
    $statsStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_generations,
            SUM(credits_used) as total_credits_used,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_generations,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_generations,
            SUM(audio_duration) as total_audio_duration,
            SUM(file_size) as total_file_size
        FROM generation_history 
        WHERE user_id = ?
    ");
    $statsStmt->execute([$_SESSION['user_id']]);
    $stats = $statsStmt->fetch();
    
    // Format stats
    $stats['total_audio_duration_formatted'] = formatDuration($stats['total_audio_duration'] ?? 0);
    $stats['total_file_size_formatted'] = formatFileSize($stats['total_file_size'] ?? 0);
    $stats['success_rate'] = $stats['total_generations'] > 0 
        ? round(($stats['successful_generations'] / $stats['total_generations']) * 100, 1) 
        : 0;
    
    jsonResponse([
        'success' => true,
        'history' => $history,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $totalCount,
            'pages' => ceil($totalCount / $limit)
        ],
        'statistics' => $stats
    ]);
    
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Failed to load history: ' . $e->getMessage()
    ], 500);
}

/**
 * Format duration in seconds to human readable format
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return round($seconds, 1) . 's';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return $minutes . 'm ' . round($secs) . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

?>
