<?php
/**
 * TTS Generation API
 * Handles speech generation with exact Python tool logic
 */

require_once '../config.php';
require_once '../ElevenLabsAPI.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate input
$required = ['voice_id', 'text', 'model_id', 'output_format'];
foreach ($required as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        jsonResponse(['success' => false, 'message' => "Missing required field: $field"], 400);
    }
}

$voiceId = $input['voice_id'];
$text = trim($input['text']);
$modelId = $input['model_id'];
$outputFormat = $input['output_format'];
$language = $input['language'] ?? null;
$voiceSettings = $input['voice_settings'] ?? [
    'stability' => 0.5,
    'similarity_boost' => 0.75,
    'style' => 0.0,
    'use_speaker_boost' => true
];

// Validate text length
if (strlen($text) > MAX_TEXT_LENGTH) {
    jsonResponse(['success' => false, 'message' => 'Text too long. Maximum ' . MAX_TEXT_LENGTH . ' characters allowed'], 400);
}

// Estimate credits
$estimatedCredits = estimateCredits($text, array_search($modelId, array_column($MODELS, 'id')));

// Check user has enough credits
if (!hasEnoughCredits($_SESSION['user_id'], $estimatedCredits)) {
    $userCredits = $db->prepare("SELECT credits_balance FROM users WHERE id = ?");
    $userCredits->execute([$_SESSION['user_id']]);
    $balance = $userCredits->fetchColumn();
    
    jsonResponse([
        'success' => false, 
        'message' => "Insufficient credits. Required: " . formatCredits($estimatedCredits) . ", Available: " . formatCredits($balance)
    ], 400);
}

// Check voice exists in user's collection
$stmt = $db->prepare("
    SELECT v.*, uv.custom_language, uv.custom_name
    FROM user_voices uv 
    JOIN voices v ON v.voice_id = uv.voice_id 
    WHERE uv.user_id = ? AND uv.voice_id = ?
");
$stmt->execute([$_SESSION['user_id'], $voiceId]);
$voice = $stmt->fetch();

if (!$voice) {
    jsonResponse(['success' => false, 'message' => 'Voice not found in your collection'], 404);
}

try {
    $startTime = microtime(true);
    
    // Initialize ElevenLabs API
    $api = new ElevenLabsAPI();
    
    // Generate seed for consistency (matches Python logic)
    $seed = null;
    if (isset($input['seed']) && $input['seed']) {
        $seed = $input['seed'];
    } else {
        // Generate consistent seed based on voice and settings
        $seedComponents = [
            $voiceId,
            $modelId,
            substr($text, 0, 100),
            sprintf("s%.2f", $voiceSettings['stability']),
            sprintf("sim%.2f", $voiceSettings['similarity_boost']),
            sprintf("sty%.2f", $voiceSettings['style'])
        ];
        $seedString = implode('_', $seedComponents);
        $seed = abs(crc32($seedString)) % 4294967295;
    }
    
    // Optimize voice settings for consistency (matches Python)
    $optimizedSettings = [
        'stability' => max(0.6, $voiceSettings['stability']),
        'similarity_boost' => max(0.75, $voiceSettings['similarity_boost']),
        'style' => min(0.2, $voiceSettings['style']),
        'use_speaker_boost' => true
    ];
    
    // Use custom language if set for this voice
    $finalLanguage = $language;
    if ($voice['custom_language'] && $language === null) {
        $finalLanguage = $voice['custom_language'];
    }
    
    // Generate speech with chunking support
    $audioData = null;
    $sentenceData = [];
    
    if (strlen($text) <= DEFAULT_CHUNK_SIZE) {
        // Single chunk generation
        $audioData = $api->textToSpeech(
            $voiceId, 
            $text, 
            $modelId, 
            $outputFormat, 
            $finalLanguage, 
            $optimizedSettings, 
            $seed
        );
        
        // Store single sentence data for SRT
        $sentenceData = [[
            'text' => $text,
            'start_time' => 0.0,
            'end_time' => estimateAudioDuration($audioData, $outputFormat),
            'sequence' => 1
        ]];
        
    } else {
        // Multi-chunk generation (matches Python logic)
        $chunks = $api->splitTextSmart($text, DEFAULT_CHUNK_SIZE);
        $audioParts = [];
        $cumulativeDuration = 0.0;
        
        foreach ($chunks as $index => $chunk) {
            $chunkSeed = $seed + $index;
            
            $chunkAudio = $api->textToSpeech(
                $voiceId, 
                $chunk, 
                $modelId, 
                $outputFormat, 
                $finalLanguage, 
                $optimizedSettings, 
                $chunkSeed
            );
            
            $chunkDuration = estimateAudioDuration($chunkAudio, $outputFormat);
            
            $sentenceData[] = [
                'text' => trim($chunk),
                'start_time' => $cumulativeDuration,
                'end_time' => $cumulativeDuration + $chunkDuration,
                'sequence' => $index + 1
            ];
            
            $audioParts[] = $chunkAudio;
            $cumulativeDuration += $chunkDuration;
        }
        
        // Combine audio parts
        $audioData = combineAudioParts($audioParts, $outputFormat);
    }
    
    $processingTime = microtime(true) - $startTime;
    
    // Generate filename
    $voiceName = cleanFilename($voice['custom_name'] ?: $voice['name']);
    $textPreview = cleanFilename(substr($text, 0, 30));
    $timestamp = date('Y-m-d_H-i-s');
    $extension = strpos($outputFormat, 'mp3') !== false ? 'mp3' : 'wav';
    $filename = "{$voiceName}_{$textPreview}_{$timestamp}.{$extension}";
    
    // Save audio file
    $audioPath = AUDIO_PATH . $filename;
    if (!file_put_contents($audioPath, $audioData)) {
        throw new Exception('Failed to save audio file');
    }
    
    // Save generation record
    $stmt = $db->prepare("
        INSERT INTO generation_history (
            user_id, voice_id, text_input, model_used, audio_format, 
            language_code, voice_settings, seed_value, credits_used, 
            processing_time, audio_duration, file_size, output_filename, 
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
    ");
    
    $stmt->execute([
        $_SESSION['user_id'],
        $voiceId,
        $text,
        $modelId,
        $outputFormat,
        $finalLanguage,
        json_encode($optimizedSettings),
        $seed,
        $estimatedCredits,
        $processingTime,
        $sentenceData[count($sentenceData) - 1]['end_time'],
        strlen($audioData),
        $filename
    ]);
    
    $generationId = $db->lastInsertId();
    
    // Save sentence data for SRT export
    $sentenceDataFile = TEMP_PATH . "sentences_{$generationId}.json";
    file_put_contents($sentenceDataFile, json_encode([
        'generation_id' => $generationId,
        'total_duration' => $sentenceData[count($sentenceData) - 1]['end_time'],
        'sentences' => $sentenceData
    ]));
    
    // Deduct credits from user
    if (!deductCredits($_SESSION['user_id'], $estimatedCredits)) {
        throw new Exception('Failed to deduct credits');
    }
    
    // Update voice usage count
    $stmt = $db->prepare("
        UPDATE user_voices 
        SET usage_count = usage_count + 1, last_used = NOW() 
        WHERE user_id = ? AND voice_id = ?
    ");
    $stmt->execute([$_SESSION['user_id'], $voiceId]);
    
    // Log activity
    logActivity('tts_generation', [
        'voice_id' => $voiceId,
        'voice_name' => $voice['name'],
        'text_length' => strlen($text),
        'model' => $modelId,
        'credits_used' => $estimatedCredits,
        'processing_time' => round($processingTime, 2)
    ]);
    
    // Return success response
    jsonResponse([
        'success' => true,
        'message' => 'Speech generated successfully',
        'generation_id' => $generationId,
        'filename' => $filename,
        'audio_url' => 'audio/' . $filename,
        'credits_used' => $estimatedCredits,
        'processing_time' => round($processingTime, 2),
        'audio_duration' => round($sentenceData[count($sentenceData) - 1]['end_time'], 2),
        'file_size' => strlen($audioData),
        'chunks_count' => count($sentenceData),
        'seed_used' => $seed
    ]);
    
} catch (Exception $e) {
    // Save failed generation record
    $stmt = $db->prepare("
        INSERT INTO generation_history (
            user_id, voice_id, text_input, model_used, audio_format, 
            language_code, voice_settings, credits_used, status, error_message
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'failed', ?)
    ");
    
    $stmt->execute([
        $_SESSION['user_id'],
        $voiceId,
        $text,
        $modelId,
        $outputFormat,
        $finalLanguage,
        json_encode($voiceSettings),
        0, // No credits charged for failed generation
        $e->getMessage()
    ]);
    
    logActivity('tts_generation_failed', [
        'voice_id' => $voiceId,
        'error' => $e->getMessage(),
        'text_length' => strlen($text)
    ]);
    
    jsonResponse([
        'success' => false,
        'message' => 'Generation failed: ' . $e->getMessage()
    ], 500);
}

/**
 * Estimate audio duration based on file data and format
 */
function estimateAudioDuration($audioData, $format) {
    $fileSizeKB = strlen($audioData) / 1024;
    
    if (strpos($format, 'mp3') !== false) {
        // MP3: roughly 16KB per second for standard quality
        return max(0.5, $fileSizeKB / 16);
    } else {
        // PCM/WAV: roughly 1400KB per second for 44kHz
        return max(0.5, $fileSizeKB / 1400);
    }
}

/**
 * Combine audio parts (basic implementation)
 */
function combineAudioParts($audioParts, $format) {
    if (count($audioParts) === 1) {
        return $audioParts[0];
    }
    
    if (strpos($format, 'mp3') !== false) {
        // For MP3, concatenate with header from first file
        $combined = $audioParts[0];
        for ($i = 1; $i < count($audioParts); $i++) {
            // Skip ID3 header for subsequent files (simple approach)
            if (strlen($audioParts[$i]) > 100) {
                $combined .= substr($audioParts[$i], 32);
            } else {
                $combined .= $audioParts[$i];
            }
        }
        return $combined;
    } else {
        // For PCM/WAV, simple concatenation
        return implode('', $audioParts);
    }
}

?>
