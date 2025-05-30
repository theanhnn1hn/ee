<?php
/**
 * Voice Library API
 * Handle voice library search and import (matches Python tool)
 */

require_once '../config.php';
require_once '../ElevenLabsAPI.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(['success' => false, 'message' => 'Method not allowed']);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'search':
        handleSearch($input);
        break;
        
    case 'import':
        handleImport($input);
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

/**
 * Search voice library (matches Python comprehensive search)
 */
function handleSearch($input) {
    $search = trim($input['search'] ?? '');
    $language = $input['language'] ?? '';
    $gender = $input['gender'] ?? '';
    $category = $input['category'] ?? '';
    $maxVoices = min(1000, max(100, intval($input['max_voices'] ?? 500)));
    
    if (empty($search)) {
        jsonResponse(['success' => false, 'message' => 'Search term is required'], 400);
    }
    
    try {
        // Initialize API
        $api = new ElevenLabsAPI();
        
        // Comprehensive voice search (matches Python logic)
        $allVoices = [];
        $pageSize = 100;
        $maxRequests = min(10, ceil($maxVoices / $pageSize));
        
        for ($request = 0; $request < $maxRequests; $request++) {
            try {
                $response = $api->getSharedVoices(
                    $pageSize,
                    $search,
                    $category ?: null,
                    $gender ?: null,
                    $language ?: null
                );
                
                $voices = $response['voices'] ?? [];
                
                if (empty($voices)) {
                    break; // No more voices
                }
                
                $allVoices = array_merge($allVoices, $voices);
                
                if (count($voices) < $pageSize) {
                    break; // Reached end
                }
                
                // Small delay between requests
                if ($request < $maxRequests - 1) {
                    usleep(500000); // 0.5 second
                }
                
            } catch (Exception $e) {
                // Log error but continue with what we have
                error_log("Library search request $request failed: " . $e->getMessage());
                break;
            }
        }
        
        // Filter for free tier voices (matches Python logic)
        $freeVoices = [];
        foreach ($allVoices as $voice) {
            $isFreeTier = false;
            
            // Method 1: Check available_for_tiers
            $availableForTiers = $voice['available_for_tiers'] ?? [];
            if (is_array($availableForTiers) && in_array('free', $availableForTiers)) {
                $isFreeTier = true;
            }
            
            // Method 2: Check category for default/premade voices
            elseif (empty($availableForTiers)) {
                $category = strtolower($voice['category'] ?? '');
                $sharing = $voice['sharing'] ?? [];
                
                if (in_array($category, ['premade', 'default'])) {
                    $isFreeTier = true;
                } elseif (in_array($category, ['community', 'generated']) && 
                         !($sharing['financial_rewards'] ?? false)) {
                    $isFreeTier = true;
                } elseif (!$category || $category === 'shared') {
                    // Assume accessible but mark for verification
                    $isFreeTier = true;
                    $voice['_needs_verification'] = true;
                }
            }
            
            if ($isFreeTier) {
                // Analyze voice (matches Python VoiceAnalyzer)
                $voice['analysis'] = analyzeVoice($voice);
                $voice['source'] = 'library';
                $freeVoices[] = $voice;
            }
        }
        
        // Remove duplicates by voice_id
        $uniqueVoices = [];
        foreach ($freeVoices as $voice) {
            $voiceId = $voice['voice_id'] ?? null;
            if ($voiceId && !isset($uniqueVoices[$voiceId])) {
                $uniqueVoices[$voiceId] = $voice;
            }
        }
        
        $finalVoices = array_values($uniqueVoices);
        
        // Sort by name
        usort($finalVoices, function($a, $b) {
            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });
        
        // Log search activity
        logActivity('library_search', [
            'search_term' => $search,
            'language' => $language,
            'gender' => $gender,
            'total_found' => count($allVoices),
            'free_tier_found' => count($finalVoices)
        ]);
        
        jsonResponse([
            'success' => true,
            'voices' => $finalVoices,
            'total_loaded' => count($allVoices),
            'free_tier_count' => count($finalVoices),
            'search_info' => [
                'term' => $search,
                'language' => $language,
                'gender' => $gender,
                'requests_made' => $request + 1
            ]
        ]);
        
    } catch (Exception $e) {
        logActivity('library_search_failed', [
            'search_term' => $search,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse([
            'success' => false,
            'message' => 'Search failed: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Import voice from library
 */
function handleImport($input) {
    global $db;
    
    $voiceId = $input['voice_id'] ?? '';
    $voiceName = $input['voice_name'] ?? '';
    $language = $input['language'] ?? '';
    
    if (!$voiceId || !$voiceName || !$language) {
        jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Check if user already has this voice
        $stmt = $db->prepare("SELECT id FROM user_voices WHERE user_id = ? AND voice_id = ?");
        $stmt->execute([$_SESSION['user_id'], $voiceId]);
        
        if ($stmt->fetch()) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'Voice already imported'], 400);
        }
        
        // Get or create voice in voices table
        $stmt = $db->prepare("SELECT * FROM voices WHERE voice_id = ?");
        $stmt->execute([$voiceId]);
        $existingVoice = $stmt->fetch();
        
        if (!$existingVoice) {
            // Fetch voice details from API for analysis
            try {
                $api = new ElevenLabsAPI();
                
                // Try to get voice details (this might fail for some library voices)
                $voiceDetails = [
                    'voice_id' => $voiceId,
                    'name' => $voiceName,
                    'description' => '',
                    'category' => 'community',
                    'language' => $language,
                    'gender' => 'unknown',
                    'age' => 'adult',
                    'quality' => 'community',
                    'source' => 'library',
                    'analysis' => json_encode(analyzeVoice(['name' => $voiceName, 'description' => ''])),
                    'is_available' => true
                ];
                
                // Insert into voices table
                $stmt = $db->prepare("
                    INSERT INTO voices (
                        voice_id, name, description, category, language, gender, 
                        age, quality, source, analysis, is_available
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $voiceDetails['voice_id'],
                    $voiceDetails['name'],
                    $voiceDetails['description'],
                    $voiceDetails['category'],
                    $voiceDetails['language'],
                    $voiceDetails['gender'],
                    $voiceDetails['age'],
                    $voiceDetails['quality'],
                    $voiceDetails['source'],
                    $voiceDetails['analysis'],
                    $voiceDetails['is_available']
                ]);
                
            } catch (Exception $e) {
                // If API call fails, create minimal voice record
                $stmt = $db->prepare("
                    INSERT INTO voices (
                        voice_id, name, language, source, is_available
                    ) VALUES (?, ?, ?, 'library', 1)
                ");
                $stmt->execute([$voiceId, $voiceName, $language]);
            }
        }
        
        // Import voice for user
        $stmt = $db->prepare("
            INSERT INTO user_voices (user_id, voice_id, custom_language, import_date) 
            VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->execute([$_SESSION['user_id'], $voiceId, $language]);
        
        $db->commit();
        
        // Log successful import
        logActivity('voice_imported', [
            'voice_id' => $voiceId,
            'voice_name' => $voiceName,
            'language' => $language,
            'source' => 'library'
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Voice imported successfully',
            'voice_id' => $voiceId,
            'voice_name' => $voiceName,
            'language' => $language
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        
        logActivity('voice_import_failed', [
            'voice_id' => $voiceId,
            'voice_name' => $voiceName,
            'error' => $e->getMessage()
        ]);
        
        jsonResponse([
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Analyze voice (simplified version of Python VoiceAnalyzer)
 */
function analyzeVoice($voice) {
    $name = strtolower($voice['name'] ?? '');
    $description = strtolower($voice['description'] ?? '');
    $category = strtolower($voice['category'] ?? '');
    $text = "$name $description";
    
    // Language detection
    $language = detectVoiceLanguage($text, $voice);
    
    // Gender detection
    $gender = 'unknown';
    $malePatterns = ['male', 'man', 'boy', 'masculine', 'adam', 'daniel', 'ethan', 'josh', 'antoni'];
    $femalePatterns = ['female', 'woman', 'girl', 'feminine', 'rachel', 'bella', 'elli', 'freya', 'sarah', 'emily'];
    
    foreach ($malePatterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            $gender = 'male';
            break;
        }
    }
    
    if ($gender === 'unknown') {
        foreach ($femalePatterns as $pattern) {
            if (strpos($text, $pattern) !== false) {
                $gender = 'female';
                break;
            }
        }
    }
    
    // Age detection
    $age = 'adult';
    if (strpos($text, 'young') !== false || strpos($text, 'teen') !== false || strpos($text, 'child') !== false) {
        $age = 'young';
    } elseif (strpos($text, 'old') !== false || strpos($text, 'elderly') !== false || strpos($text, 'senior') !== false) {
        $age = 'old';
    } elseif (strpos($text, 'middle') !== false) {
        $age = 'middle';
    }
    
    // Quality assessment
    $quality = 'community';
    if ($category === 'premade') {
        $quality = 'premium';
    } elseif ($category === 'professional') {
        $quality = 'professional';
    } elseif ($category === 'cloned') {
        $quality = 'cloned';
    } elseif ($category === 'generated') {
        $quality = 'generated';
    }
    
    return [
        'language' => $language,
        'gender' => $gender,
        'age' => $age,
        'quality' => $quality,
        'type' => ucfirst($category),
        'tags' => extractTags($voice)
    ];
}

/**
 * Detect voice language (simplified)
 */
function detectVoiceLanguage($text, $voice) {
    // Check for explicit language indicators in voice metadata
    $labels = $voice['labels'] ?? [];
    if (isset($labels['language'])) {
        return $labels['language'];
    }
    
    // Simple pattern matching
    if (preg_match('/\b(english|american|british|aussie)\b/', $text)) return 'en';
    if (preg_match('/\b(spanish|español|latino)\b/', $text)) return 'es';
    if (preg_match('/\b(french|français)\b/', $text)) return 'fr';
    if (preg_match('/\b(german|deutsch)\b/', $text)) return 'de';
    if (preg_match('/\b(italian|italiano)\b/', $text)) return 'it';
    if (preg_match('/\b(vietnamese|việt)\b/', $text)) return 'vi';
    if (preg_match('/\b(chinese|mandarin|cantonese)\b/', $text)) return 'zh';
    if (preg_match('/\b(japanese|japan)\b/', $text)) return 'ja';
    if (preg_match('/\b(korean|korea)\b/', $text)) return 'ko';
    if (preg_match('/\b(russian|русский)\b/', $text)) return 'ru';
    
    return 'en'; // Default
}

/**
 * Extract tags from voice metadata
 */
function extractTags($voice) {
    $tags = [];
    $description = strtolower($voice['description'] ?? '');
    
    $tagKeywords = ['narrator', 'character', 'news', 'commercial', 'audiobook', 'podcast', 'gaming', 'animation'];
    
    foreach ($tagKeywords as $keyword) {
        if (strpos($description, $keyword) !== false) {
            $tags[] = $keyword;
        }
    }
    
    return $tags;
}

?>
