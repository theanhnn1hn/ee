<?php
/**
 * ElevenLabs API Handler
 * Maintains exact functionality from Python tool
 */

require_once 'config.php';

class ElevenLabsAPI {
    private $baseUrl = ELEVENLABS_BASE_URL;
    private $timeout = REQUEST_TIMEOUT;
    private $maxRetries = MAX_RETRIES;
    private $useProxy = false;
    private $proxies = [];
    
    public function __construct() {
        $this->useProxy = getSetting('use_proxy', false);
        if ($this->useProxy) {
            $this->loadProxies();
        }
    }
    
    /**
     * Load active proxies from database
     */
    private function loadProxies() {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT proxy_url FROM proxies WHERE status = 'active' ORDER BY priority DESC");
        $stmt->execute();
        $this->proxies = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Make HTTP request with retry logic (matches Python)
     */
    private function makeRequest($method, $endpoint, $data = null, $apiKey = null, $estimatedCredits = 100) {
        $lastError = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            // Get best API key if not provided
            if (!$apiKey) {
                $keyData = getBestApiKey($estimatedCredits, false);
                if (!$keyData) {
                    throw new Exception("No API keys with sufficient credits available");
                }
                $apiKey = $keyData['api_key'];
                $keyId = $keyData['id'];
            }
            
            try {
                $result = $this->makeSingleRequest($method, $endpoint, $data, $apiKey);
                
                // Success - update API key usage
                if (isset($keyId)) {
                    updateApiKeyUsage($keyId, $estimatedCredits);
                }
                
                return $result;
                
            } catch (Exception $e) {
                $lastError = $e;
                $errorMsg = $e->getMessage();
                
                // Check if should retry (matches Python logic)
                $shouldRetry = false;
                
                if (strpos($errorMsg, 'Invalid API key') !== false || 
                    strpos($errorMsg, 'authentication failed') !== false) {
                    $shouldRetry = true;
                } elseif (strpos($errorMsg, 'Insufficient credits') !== false || 
                         strpos($errorMsg, '402') !== false) {
                    $shouldRetry = true;
                } elseif (strpos($errorMsg, 'Rate limited') !== false || 
                         strpos($errorMsg, '429') !== false) {
                    $shouldRetry = true;
                } elseif (strpos($errorMsg, 'voice_limit_reached') !== false) {
                    $shouldRetry = true;
                } elseif (strpos($errorMsg, 'voice_not_found') !== false) {
                    $shouldRetry = true;
                }
                
                if (!$shouldRetry || $attempt === $this->maxRetries) {
                    break;
                }
                
                // Reset API key for next attempt
                $apiKey = null;
                sleep(1); // Delay between retries
            }
        }
        
        throw new Exception("Request failed after {$this->maxRetries} attempts. Last error: " . $lastError->getMessage());
    }
    
    /**
     * Make single HTTP request
     */
    private function makeSingleRequest($method, $endpoint, $data, $apiKey) {
        $url = $this->baseUrl . $endpoint;
        $headers = [
            'xi-api-key: ' . $apiKey,
            'User-Agent: ElevenLabs-PHP-Client/2.0'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        // Set proxy if enabled
        if ($this->useProxy && !empty($this->proxies)) {
            $proxy = $this->proxies[array_rand($this->proxies)];
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }
        
        // Set method and data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                if (is_array($data)) {
                    $headers[] = 'Content-Type: application/json';
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL error: " . $error);
        }
        
        return $this->handleResponse($response, $httpCode);
    }
    
    /**
     * Handle API response (matches Python error handling)
     */
    private function handleResponse($response, $httpCode) {
        if ($httpCode === 200 || $httpCode === 201) {
            // Check if response is audio data
            if (substr($response, 0, 4) === "\x49\x44\x33" || // MP3
                substr($response, 0, 4) === "RIFF") { // WAV
                return $response; // Return binary audio data
            }
            
            $decoded = json_decode($response, true);
            return $decoded ?: $response;
        }
        
        // Handle errors (exact match from Python)
        switch ($httpCode) {
            case 400:
                $errorData = json_decode($response, true);
                if ($errorData && isset($errorData['detail'])) {
                    $detail = $errorData['detail'];
                    if (is_array($detail) && isset($detail['status'])) {
                        throw new Exception("API Error ({$detail['status']}): " . ($detail['message'] ?? 'Bad request'));
                    }
                    throw new Exception("Bad Request: " . (is_string($detail) ? $detail : json_encode($detail)));
                }
                throw new Exception("Bad Request: " . $response);
                
            case 401:
                throw new Exception("Invalid API key or authentication failed");
                
            case 402:
                throw new Exception("Insufficient credits");
                
            case 403:
                throw new Exception("Access denied. Check your subscription tier or API key permissions");
                
            case 404:
                throw new Exception("Requested resource not found");
                
            case 422:
                $errorData = json_decode($response, true);
                if ($errorData && isset($errorData['detail']) && is_array($errorData['detail'])) {
                    $errors = [];
                    foreach ($errorData['detail'] as $error) {
                        $field = end($error['loc'] ?? ['unknown']);
                        $message = $error['msg'] ?? 'Invalid value';
                        $errors[] = "$field: $message";
                    }
                    throw new Exception("Validation errors: " . implode('; ', $errors));
                }
                throw new Exception("Validation error: " . $response);
                
            case 429:
                $retryAfter = 60; // Default retry after
                throw new Exception("Rate limited. Retry after $retryAfter seconds");
                
            default:
                if ($httpCode >= 500) {
                    throw new Exception("Server error ($httpCode). ElevenLabs service may be temporarily unavailable");
                }
                throw new Exception("API Error $httpCode: " . $response);
        }
    }
    
    /**
     * Text-to-speech generation (matches Python logic)
     */
    public function textToSpeech($voiceId, $text, $modelId, $outputFormat, $language = null, $voiceSettings = null, $seed = null) {
        $estimatedCredits = estimateCredits($text, $this->getModelNameById($modelId));
        
        $data = [
            'text' => $text,
            'model_id' => $modelId,
            'voice_settings' => $voiceSettings ?: [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.0,
                'use_speaker_boost' => true
            ]
        ];
        
        if ($seed !== null) {
            $data['seed'] = $seed;
        }
        
        if ($language && in_array($modelId, ['eleven_turbo_v2_5', 'eleven_flash_v2_5'])) {
            $data['language_code'] = $language;
        }
        
        $endpoint = "/text-to-speech/$voiceId?output_format=$outputFormat";
        
        return $this->makeRequest('POST', $endpoint, $data, null, $estimatedCredits);
    }
    
    /**
     * Get user voices
     */
    public function getUserVoices() {
        return $this->makeRequest('GET', '/voices', null, null, 0);
    }
    
    /**
     * Get shared voices with pagination (matches Python)
     */
    public function getSharedVoices($pageSize = 100, $search = '', $category = null, $gender = null, $language = null) {
        $params = ['page_size' => $pageSize];
        
        if ($search) $params['search'] = $search;
        if ($category) $params['category'] = $category;
        if ($gender) $params['gender'] = $gender;
        if ($language) $params['language'] = $language;
        
        $queryString = http_build_query($params);
        $endpoint = "/shared-voices?" . $queryString;
        
        return $this->makeRequest('GET', $endpoint, null, null, 0);
    }
    
    /**
     * Preview voice
     */
    public function previewVoice($voiceId, $modelId = 'eleven_flash_v2_5', $language = null) {
        global $LANGUAGES;
        
        $previewTexts = [
            'vi' => "Xin chào, đây là bản xem trước giọng nói của tôi. Bạn thấy thế nào?",
            'en' => "Hello, this is a preview of my voice. How do you like it?",
            'zh' => "你好，这是我声音的预览。你觉得怎么样？",
            'ja' => "こんにちは、これは私の声のプレビューです。いかがですか？",
            'ko' => "안녕하세요, 이것은 제 목소리의 미리보기입니다. 어떠신가요?",
            'es' => "Hola, esta es una vista previa de mi voz. ¿Qué te parece?",
            'fr' => "Bonjour, ceci est un aperçu de ma voix. Qu'en pensez-vous?",
            'de' => "Hallo, das ist eine Vorschau meiner Stimme. Wie gefällt sie Ihnen?"
        ];
        
        $text = $previewTexts[$language] ?? $previewTexts['en'];
        
        $data = [
            'text' => $text,
            'model_id' => $modelId,
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
                'style' => 0.0,
                'use_speaker_boost' => true
            ]
        ];
        
        if ($language && in_array($modelId, ['eleven_turbo_v2_5', 'eleven_flash_v2_5'])) {
            $data['language'] = $language;
        }
        
        $endpoint = "/text-to-speech/$voiceId?output_format=mp3_22050_32";
        
        return $this->makeRequest('POST', $endpoint, $data, null, strlen($text));
    }
    
    /**
     * Delete voice by ID
     */
    public function deleteVoice($voiceId) {
        $endpoint = "/voices/$voiceId";
        return $this->makeRequest('DELETE', $endpoint, null, null, 0);
    }
    
    /**
     * Get subscription info
     */
    public function getSubscriptionInfo() {
        return $this->makeRequest('GET', '/user/subscription', null, null, 0);
    }
    
    /**
     * Helper: Get model name by ID
     */
    private function getModelNameById($modelId) {
        global $MODELS;
        foreach ($MODELS as $name => $info) {
            if ($info['id'] === $modelId) {
                return $name;
            }
        }
        return 'Flash v2.5 (Fast & Cheap)'; // Default
    }
    
    /**
     * Split text into chunks (matches Python logic)
     */
    public function splitTextSmart($text, $maxChunkSize = DEFAULT_CHUNK_SIZE) {
        if (strlen($text) <= $maxChunkSize) {
            return [$text];
        }
        
        // Split by sentences first
        $sentences = $this->extractSentences($text);
        $chunks = [];
        $currentChunk = '';
        
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (!$sentence) continue;
            
            if (strlen($sentence) > $maxChunkSize) {
                // Split long sentence
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                    $currentChunk = '';
                }
                $subChunks = $this->splitLongSentence($sentence, $maxChunkSize);
                $chunks = array_merge($chunks, $subChunks);
            } else {
                $testChunk = $currentChunk ? $currentChunk . ' ' . $sentence : $sentence;
                if (strlen($testChunk) <= $maxChunkSize) {
                    $currentChunk = $testChunk;
                } else {
                    if ($currentChunk) {
                        $chunks[] = $currentChunk;
                    }
                    $currentChunk = $sentence;
                }
            }
        }
        
        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
    
    /**
     * Extract sentences from text
     */
    private function extractSentences($text) {
        // Simple sentence splitting - can be enhanced
        $sentences = preg_split('/[.!?]+\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return $sentences;
    }
    
    /**
     * Split long sentence at word boundaries
     */
    private function splitLongSentence($sentence, $maxSize) {
        $words = explode(' ', $sentence);
        $chunks = [];
        $currentChunk = '';
        
        foreach ($words as $word) {
            $testChunk = $currentChunk ? $currentChunk . ' ' . $word : $word;
            if (strlen($testChunk) <= $maxSize) {
                $currentChunk = $testChunk;
            } else {
                if ($currentChunk) {
                    $chunks[] = $currentChunk;
                }
                $currentChunk = $word;
            }
        }
        
        if ($currentChunk) {
            $chunks[] = $currentChunk;
        }
        
        return $chunks;
    }
    
    /**
     * Detect text language (simplified version of Python logic)
     */
    public function detectLanguage($text) {
        $text = strtolower($text);
        
        // Vietnamese
        if (preg_match('/[áàảãạăắằẳẵặâấầẩẫậéèẻẽẹêếềểễệíìỉĩịóòỏõọôốồổỗộơớờởỡợúùủũụưứừửữựýỳỷỹỵđ]/', $text)) {
            return 'vi';
        }
        
        // Chinese
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return 'zh';
        }
        
        // Japanese
        if (preg_match('/[\x{3040}-\x{30ff}]/u', $text)) {
            return 'ja';
        }
        
        // Korean
        if (preg_match('/[\x{ac00}-\x{d7af}]/u', $text)) {
            return 'ko';
        }
        
        // Russian
        if (preg_match('/[\x{0400}-\x{04ff}]/u', $text)) {
            return 'ru';
        }
        
        // Spanish
        if (preg_match('/[ñáéíóúü]/', $text) || 
            preg_match('/\b(que|de|el|la|es|un|y|en|por|para)\b/', $text)) {
            return 'es';
        }
        
        // French
        if (preg_match('/[çàèéêëîïôùûüÿœ]/', $text) || 
            preg_match('/\b(que|de|le|la|est|un|et|en|pour|avec)\b/', $text)) {
            return 'fr';
        }
        
        // German
        if (preg_match('/[äöüß]/', $text) || 
            preg_match('/\b(der|die|das|und|ist|ein|mit|für)\b/', $text)) {
            return 'de';
        }
        
        // Default to English
        return 'en';
    }
}

?>
