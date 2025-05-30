<?php
require_once 'config.php';
requireLogin();

// Get user info
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

// Get user's imported voices
$stmt = $db->prepare("
    SELECT v.*, uv.custom_language, uv.custom_name, uv.usage_count, uv.last_used
    FROM user_voices uv 
    JOIN voices v ON v.voice_id = uv.voice_id 
    WHERE uv.user_id = ? 
    ORDER BY uv.import_date DESC
");
$stmt->execute([$_SESSION['user_id']]);
$userVoices = $stmt->fetchAll();

// Update session credits
$_SESSION['credits_balance'] = $user['credits_balance'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #667eea;
            --primary-dark: #5a6fd8;
            --secondary-color: #764ba2;
            --accent-color: #f093fb;
            --success-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #ef5350;
            --info-color: #29b6f6;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-color: #334155;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
        }
        
        body {
            background: var(--bg-color);
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-color);
        }
        
        .navbar {
            background: var(--primary-color);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 18px;
        }
        
        .sidebar {
            background: var(--card-bg);
            min-height: calc(100vh - 56px);
            border-right: 1px solid var(--border-color);
            position: sticky;
            top: 56px;
        }
        
        .sidebar .nav-link {
            color: var(--text-color);
            padding: 12px 20px;
            border-radius: 8px;
            margin: 4px 12px;
            transition: all 0.3s ease;
        }
        
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        .main-content {
            padding: 20px;
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            padding: 20px;
        }
        
        .stats-card {
            text-align: center;
            padding: 20px;
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-success {
            background: var(--success-color);
            border: none;
            border-radius: 8px;
        }
        
        .btn-danger {
            background: var(--danger-color);
            border: none;
            border-radius: 8px;
        }
        
        .btn-info {
            background: var(--info-color);
            border: none;
            border-radius: 8px;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .voice-card {
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .voice-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .voice-card.selected {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.05);
        }
        
        .voice-badges .badge {
            margin-right: 6px;
            margin-bottom: 6px;
            font-size: 11px;
            padding: 4px 8px;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .audio-controls {
            margin-top: 15px;
        }
        
        .text-stats {
            background: var(--bg-color);
            border-radius: 8px;
            padding: 12px;
            margin-top: 10px;
            font-size: 13px;
            color: var(--text-light);
        }
        
        .tab-content {
            padding-top: 20px;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: var(--text-light);
            font-weight: 500;
            padding: 12px 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .nav-tabs .nav-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            max-width: 300px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-microphone-alt me-2"></i><?= APP_NAME ?>
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-coins me-1"></i><?= formatCredits($user['credits_balance']) ?> Credits
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="#"><i class="fas fa-chart-line me-2"></i>Usage Stats</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 col-md-3 sidebar">
                <div class="nav flex-column nav-pills mt-3">
                    <a class="nav-link active" data-bs-toggle="tab" href="#tts-tab">
                        <i class="fas fa-microphone me-2"></i>Text to Speech
                    </a>
                    <a class="nav-link" data-bs-toggle="tab" href="#library-tab">
                        <i class="fas fa-search me-2"></i>Voice Library
                    </a>
                    <a class="nav-link" data-bs-toggle="tab" href="#history-tab">
                        <i class="fas fa-history me-2"></i>History
                    </a>
                    <a class="nav-link" data-bs-toggle="tab" href="#settings-tab">
                        <i class="fas fa-cog me-2"></i>Settings
                    </a>
                </div>
                
                <div class="mt-4 mx-3">
                    <div class="card">
                        <div class="card-body stats-card">
                            <div class="stats-number text-primary"><?= count($userVoices) ?></div>
                            <small class="text-muted">Imported Voices</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 main-content">
                <div class="tab-content">
                    <!-- Text to Speech Tab -->
                    <div class="tab-pane fade show active" id="tts-tab">
                        <div class="row">
                            <!-- Voice Selection -->
                            <div class="col-lg-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5><i class="fas fa-users me-2"></i>Select Voice</h5>
                                    </div>
                                    <div class="card-body">
                                        <!-- Voice Filters -->
                                        <div class="mb-3">
                                            <input type="text" id="voice-search" class="form-control" 
                                                   placeholder="🔍 Search voices..." onkeyup="filterVoices()">
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <select id="language-filter" class="form-select form-select-sm" onchange="filterVoices()">
                                                    <option value="">All Languages</option>
                                                    <?php foreach ($LANGUAGES as $name => $info): ?>
                                                        <?php if ($info['code']): ?>
                                                            <option value="<?= $info['code'] ?>"><?= $info['flag'] ?> <?= $name ?></option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <select id="gender-filter" class="form-select form-select-sm" onchange="filterVoices()">
                                                    <option value="">All Genders</option>
                                                    <option value="male">👨 Male</option>
                                                    <option value="female">👩 Female</option>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Voices List -->
                                        <div id="voices-container" style="max-height: 400px; overflow-y: auto;">
                                            <?php if (empty($userVoices)): ?>
                                                <div class="empty-state">
                                                    <i class="fas fa-microphone-slash"></i>
                                                    <h6>No Voices Imported</h6>
                                                    <p>Go to Voice Library to import voices</p>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($userVoices as $voice): ?>
                                                    <div class="voice-card" data-voice-id="<?= $voice['voice_id'] ?>" 
                                                         data-language="<?= $voice['custom_language'] ?: $voice['language'] ?>"
                                                         data-gender="<?= $voice['gender'] ?>"
                                                         data-name="<?= strtolower($voice['custom_name'] ?: $voice['name']) ?>"
                                                         onclick="selectVoice(this)">
                                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                                            <h6 class="mb-1"><?= htmlspecialchars($voice['custom_name'] ?: $voice['name']) ?></h6>
                                                            <button class="btn btn-sm btn-outline-primary" onclick="previewVoice('<?= $voice['voice_id'] ?>', event)">
                                                                <i class="fas fa-play"></i>
                                                            </button>
                                                        </div>
                                                        
                                                        <div class="voice-badges">
                                                            <?php 
                                                            $langInfo = null;
                                                            $voiceLang = $voice['custom_language'] ?: $voice['language'];
                                                            foreach ($LANGUAGES as $name => $info) {
                                                                if ($info['code'] === $voiceLang) {
                                                                    $langInfo = $info;
                                                                    break;
                                                                }
                                                            }
                                                            ?>
                                                            <span class="badge bg-primary"><?= $langInfo['flag'] ?? '🌐' ?> <?= $langInfo['name'] ?? 'Unknown' ?></span>
                                                            <span class="badge bg-secondary"><?= $voice['gender'] === 'male' ? '👨' : ($voice['gender'] === 'female' ? '👩' : '👤') ?> <?= ucfirst($voice['gender']) ?></span>
                                                            <span class="badge bg-info"><?= ucfirst($voice['quality']) ?></span>
                                                            <?php if ($voice['source'] === 'imported'): ?>
                                                                <span class="badge bg-success">📥 Imported</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <?php if ($voice['description']): ?>
                                                            <p class="text-muted small mb-2"><?= htmlspecialchars(substr($voice['description'], 0, 100)) ?>...</p>
                                                        <?php endif; ?>
                                                        
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <small class="text-muted">Used <?= $voice['usage_count'] ?> times</small>
                                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteVoice('<?= $voice['voice_id'] ?>', event)">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Text Input & Generation -->
                            <div class="col-lg-8">
                                <div class="card">
                                    <div class="card-header">
                                        <h5><i class="fas fa-edit me-2"></i>Text to Speech Generation</h5>
                                    </div>
                                    <div class="card-body">
                                        <!-- Text Input -->
                                        <div class="mb-3">
                                            <label class="form-label">Text Input</label>
                                            <div class="d-flex gap-2 mb-2">
                                                <button class="btn btn-sm btn-outline-secondary" onclick="loadTextFile()">
                                                    <i class="fas fa-file-text me-1"></i>Load Text
                                                </button>
                                                <button class="btn btn-sm btn-outline-info" onclick="loadSRTFile()">
                                                    <i class="fas fa-file-video me-1"></i>Load SRT
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning" onclick="clearText()">
                                                    <i class="fas fa-trash me-1"></i>Clear
                                                </button>
                                            </div>
                                            <textarea id="text-input" class="form-control" rows="8" 
                                                      placeholder="Enter your text here, or use the import buttons above to load from files.

🎬 SRT Import: Convert subtitle files to speech text
📄 Text Import: Load plain text files  
📋 Paste: Quick paste from clipboard

You can also right-click for more options."
                                                      onkeyup="updateTextStats()"></textarea>
                                            <div id="text-stats" class="text-stats">
                                                <div class="row">
                                                    <div class="col-6">
                                                        <span id="char-count">0 characters | 0 words</span>
                                                    </div>
                                                    <div class="col-6 text-end">
                                                        <span id="credit-estimate">Estimated cost: 0 credits</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Generation Settings -->
                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Model</label>
                                                <select id="model-select" class="form-select" onchange="updateTextStats()">
                                                    <?php foreach ($MODELS as $name => $info): ?>
                                                        <option value="<?= $info['id'] ?>" data-credits="<?= $info['credits_per_char'] ?>">
                                                            <?= $name ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Format</label>
                                                <select id="format-select" class="form-select">
                                                    <?php foreach ($AUDIO_FORMATS as $name => $value): ?>
                                                        <option value="<?= $value ?>"><?= $name ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Language</label>
                                                <select id="tts-language-select" class="form-select">
                                                    <?php foreach ($LANGUAGES as $name => $info): ?>
                                                        <option value="<?= $info['code'] ?>"><?= $info['flag'] ?> <?= $name ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        
                                        <!-- Voice Settings -->
                                        <div class="row mb-3">
                                            <div class="col-md-4">
                                                <label class="form-label">Stability: <span id="stability-value">0.50</span></label>
                                                <input type="range" id="stability-slider" class="form-range" min="0" max="1" step="0.01" value="0.5" oninput="updateSliderValue('stability')">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Similarity: <span id="similarity-value">0.75</span></label>
                                                <input type="range" id="similarity-slider" class="form-range" min="0" max="1" step="0.01" value="0.75" oninput="updateSliderValue('similarity')">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Style: <span id="style-value">0.00</span></label>
                                                <input type="range" id="style-slider" class="form-range" min="0" max="1" step="0.01" value="0.0" oninput="updateSliderValue('style')">
                                            </div>
                                        </div>
                                        
                                        <!-- Generation Controls -->
                                        <div class="d-flex gap-2 mb-3">
                                            <button id="generate-btn" class="btn btn-primary" onclick="generateSpeech()">
                                                <i class="fas fa-microphone me-2"></i>Generate Speech
                                            </button>
                                            <button id="preview-btn" class="btn btn-info" onclick="previewSelected()" disabled>
                                                <i class="fas fa-play me-2"></i>Preview Voice
                                            </button>
                                        </div>
                                        
                                        <!-- Progress -->
                                        <div id="generation-progress" class="progress mb-3" style="display: none;">
                                            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
                                        </div>
                                        
                                        <!-- Audio Controls -->
                                        <div id="audio-controls" class="audio-controls" style="display: none;">
                                            <div class="d-flex gap-2 mb-2">
                                                <button id="play-btn" class="btn btn-success" onclick="playAudio()">
                                                    <i class="fas fa-play me-2"></i>Play
                                                </button>
                                                <button id="pause-btn" class="btn btn-warning" onclick="pauseAudio()">
                                                    <i class="fas fa-pause me-2"></i>Pause
                                                </button>
                                                <button id="save-btn" class="btn btn-secondary" onclick="saveAudio()">
                                                    <i class="fas fa-download me-2"></i>Save
                                                </button>
                                                <button id="export-srt-btn" class="btn btn-info" onclick="exportSRT()">
                                                    <i class="fas fa-file-video me-2"></i>Export SRT
                                                </button>
                                            </div>
                                            <audio id="audio-player" controls class="w-100" style="display: none;"></audio>
                                        </div>
                                        
                                        <div id="generation-status" class="text-muted"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Voice Library Tab -->
                    <div class="tab-pane fade" id="library-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-search me-2"></i>Voice Library Search & Import</h5>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">Search community voices and import them with language selection for Text-to-Speech</p>
                                
                                <!-- Search Controls -->
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <input type="text" id="library-search" class="form-control" 
                                               placeholder="Search by name, keywords, description...">
                                    </div>
                                    <div class="col-md-2">
                                        <select id="library-language" class="form-select">
                                            <option value="">All Languages</option>
                                            <option value="en">English</option>
                                            <option value="es">Spanish</option>
                                            <option value="fr">French</option>
                                            <option value="de">German</option>
                                            <option value="vi">Vietnamese</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <select id="library-gender" class="form-select">
                                            <option value="">All Genders</option>
                                            <option value="male">Male</option>
                                            <option value="female">Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-primary w-100" onclick="searchLibrary()">
                                            <i class="fas fa-search me-1"></i>Search
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Search Results -->
                                <div id="library-results">
                                    <div class="empty-state">
                                        <i class="fas fa-search"></i>
                                        <h6>Voice Library Search</h6>
                                        <p>Enter search terms to find voices from the community library</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- History Tab -->
                    <div class="tab-pane fade" id="history-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-history me-2"></i>Generation History</h5>
                            </div>
                            <div class="card-body">
                                <div id="history-content">Loading history...</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Settings Tab -->
                    <div class="tab-pane fade" id="settings-tab">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-cog me-2"></i>Settings</h5>
                            </div>
                            <div class="card-body">
                                <h6>Account Information</h6>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" readonly>
                                    </div>
                                </div>
                                
                                <h6>Credits Information</h6>
                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <div class="stats-card bg-light">
                                            <div class="stats-number text-primary"><?= formatCredits($user['credits_balance']) ?></div>
                                            <small>Available Credits</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="stats-card bg-light">
                                            <div class="stats-number text-info"><?= formatCredits($user['credits_used']) ?></div>
                                            <small>Used Credits</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="stats-card bg-light">
                                            <div class="stats-number text-success"><?= formatCredits($user['max_credits']) ?></div>
                                            <small>Monthly Limit</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="loading-content">
            <div class="spinner-border text-primary mb-3" role="status"></div>
            <h6 id="loading-text">Processing...</h6>
            <p id="loading-detail" class="text-muted small mb-0"></p>
        </div>
    </div>
    
    <!-- File inputs (hidden) -->
    <input type="file" id="text-file-input" accept=".txt" style="display: none;" onchange="handleTextFile(this)">
    <input type="file" id="srt-file-input" accept=".srt" style="display: none;" onchange="handleSRTFile(this)">
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global variables
        let selectedVoice = null;
        let currentAudio = null;
        let generationData = null;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateTextStats();
            loadHistory();
        });
        
        // Voice selection functions (matching Python tool logic)
        function selectVoice(element) {
            // Remove selection from all voices
            document.querySelectorAll('.voice-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Select this voice
            element.classList.add('selected');
            selectedVoice = {
                voice_id: element.dataset.voiceId,
                name: element.dataset.name,
                language: element.dataset.language,
                gender: element.dataset.gender
            };
            
            // Enable preview button
            document.getElementById('preview-btn').disabled = false;
            
            console.log('Selected voice:', selectedVoice);
        }
        
        // Text statistics (matches Python tool)
        function updateTextStats() {
            const text = document.getElementById('text-input').value;
            const charCount = text.length;
            const wordCount = text.trim() ? text.trim().split(/\s+/).length : 0;
            
            // Calculate credits based on selected model
            const modelSelect = document.getElementById('model-select');
            const creditsPerChar = parseFloat(modelSelect.selectedOptions[0].dataset.credits || 0.5);
            const estimatedCredits = Math.ceil(charCount * creditsPerChar);
            
            document.getElementById('char-count').textContent = `${charCount.toLocaleString()} characters | ${wordCount.toLocaleString()} words`;
            document.getElementById('credit-estimate').textContent = `Estimated cost: ${estimatedCredits.toLocaleString()} credits`;
        }
        
        // Slider updates
        function updateSliderValue(type) {
            const slider = document.getElementById(type + '-slider');
            const value = parseFloat(slider.value);
            document.getElementById(type + '-value').textContent = value.toFixed(2);
        }
        
        // Voice filtering (matches Python tool logic)
        function filterVoices() {
            const searchTerm = document.getElementById('voice-search').value.toLowerCase();
            const languageFilter = document.getElementById('language-filter').value;
            const genderFilter = document.getElementById('gender-filter').value;
            
            document.querySelectorAll('.voice-card').forEach(card => {
                const name = card.dataset.name;
                const language = card.dataset.language;
                const gender = card.dataset.gender;
                
                const matchesSearch = !searchTerm || name.includes(searchTerm);
                const matchesLanguage = !languageFilter || language === languageFilter;
                const matchesGender = !genderFilter || gender === genderFilter;
                
                card.style.display = (matchesSearch && matchesLanguage && matchesGender) ? 'block' : 'none';
            });
        }
        
        // File loading functions
        function loadTextFile() {
            document.getElementById('text-file-input').click();
        }
        
        function loadSRTFile() {
            document.getElementById('srt-file-input').click();
        }
        
        function handleTextFile(input) {
            const file = input.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('text-input').value = e.target.result;
                    updateTextStats();
                };
                reader.readAsText(file);
            }
        }
        
        function handleSRTFile(input) {
            const file = input.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const srtContent = e.target.result;
                    const parsedText = parseSRTContent(srtContent);
                    document.getElementById('text-input').value = parsedText;
                    updateTextStats();
                };
                reader.readAsText(file);
            }
        }
        
        function parseSRTContent(srtContent) {
            // Simple SRT parsing (matches Python logic)
            const blocks = srtContent.split(/\n\s*\n/);
            const sentences = [];
            
            blocks.forEach(block => {
                const lines = block.trim().split('\n');
                if (lines.length >= 3) {
                    // Skip sequence number and timestamp, get text
                    const text = lines.slice(2).join(' ').trim();
                    if (text) {
                        sentences.push(text);
                    }
                }
            });
            
            return sentences.join('\n\n');
        }
        
        function clearText() {
            if (confirm('Clear all text?')) {
                document.getElementById('text-input').value = '';
                updateTextStats();
            }
        }
        
        // Voice operations
        function previewVoice(voiceId, event) {
            event.stopPropagation();
            
            const language = document.getElementById('tts-language-select').value;
            
            showLoading('Generating preview...', 'Please wait...');
            
            fetch('api/preview.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    voice_id: voiceId,
                    language: language
                })
            })
            .then(response => response.blob())
            .then(blob => {
                hideLoading();
                const audio = new Audio(URL.createObjectURL(blob));
                audio.play();
            })
            .catch(error => {
                hideLoading();
                console.error('Preview error:', error);
                alert('Preview failed: ' + error.message);
            });
        }
        
        function previewSelected() {
            if (!selectedVoice) {
                alert('Please select a voice first');
                return;
            }
            previewVoice(selectedVoice.voice_id, { stopPropagation: () => {} });
        }
        
        function deleteVoice(voiceId, event) {
            event.stopPropagation();
            
            if (!confirm('Are you sure you want to delete this voice?')) {
                return;
            }
            
            fetch('api/voices.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    voice_id: voiceId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload(); // Refresh to update voices list
                } else {
                    alert('Delete failed: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                alert('Delete failed: ' + error.message);
            });
        }
        
        // Main TTS generation (matches Python tool logic)
        function generateSpeech() {
            if (!selectedVoice) {
                alert('Please select a voice first');
                return;
            }
            
            const text = document.getElementById('text-input').value.trim();
            if (!text) {
                alert('Please enter text to convert');
                return;
            }
            
            // Get settings
            const modelId = document.getElementById('model-select').value;
            const outputFormat = document.getElementById('format-select').value;
            const language = document.getElementById('tts-language-select').value;
            
            const voiceSettings = {
                stability: parseFloat(document.getElementById('stability-slider').value),
                similarity_boost: parseFloat(document.getElementById('similarity-slider').value),
                style: parseFloat(document.getElementById('style-slider').value),
                use_speaker_boost: true
            };
            
            // Estimate credits
            const modelSelect = document.getElementById('model-select');
            const creditsPerChar = parseFloat(modelSelect.selectedOptions[0].dataset.credits || 0.5);
            const estimatedCredits = Math.ceil(text.length * creditsPerChar);
            
            // Check if user has enough credits
            const userCredits = <?= $user['credits_balance'] ?>;
            if (userCredits < estimatedCredits) {
                alert(`Insufficient credits. Required: ${estimatedCredits.toLocaleString()}, Available: ${userCredits.toLocaleString()}`);
                return;
            }
            
            showLoading('Generating speech...', 'This may take a few moments...');
            showProgress(0.1);
            
            // Disable generate button
            document.getElementById('generate-btn').disabled = true;
            
            const requestData = {
                voice_id: selectedVoice.voice_id,
                text: text,
                model_id: modelId,
                output_format: outputFormat,
                language: language === 'null' ? null : language,
                voice_settings: voiceSettings
            };
            
            fetch('api/generate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideLoading();
                hideProgress();
                document.getElementById('generate-btn').disabled = false;
                
                if (data.success) {
                    // Store generation data
                    generationData = data;
                    
                    // Show audio controls
                    document.getElementById('audio-controls').style.display = 'block';
                    document.getElementById('generation-status').textContent = `✅ Speech generated successfully! Credits used: ${data.credits_used}`;
                    
                    // Update user credits in UI
                    document.querySelector('.navbar .nav-link').innerHTML = 
                        `<i class="fas fa-coins me-1"></i>${(userCredits - data.credits_used).toLocaleString()} Credits`;
                    
                    // Load audio
                    const audioPlayer = document.getElementById('audio-player');
                    audioPlayer.src = data.audio_url;
                    audioPlayer.style.display = 'block';
                    
                } else {
                    document.getElementById('generation-status').textContent = `❌ Generation failed: ${data.message}`;
                }
            })
            .catch(error => {
                hideLoading();
                hideProgress();
                document.getElementById('generate-btn').disabled = false;
                console.error('Generation error:', error);
                document.getElementById('generation-status').textContent = `❌ Generation failed: ${error.message}`;
            });
        }
        
        // Audio controls
        function playAudio() {
            const audioPlayer = document.getElementById('audio-player');
            audioPlayer.play();
        }
        
        function pauseAudio() {
            const audioPlayer = document.getElementById('audio-player');
            audioPlayer.pause();
        }
        
        function saveAudio() {
            if (!generationData || !generationData.audio_url) {
                alert('No audio to save');
                return;
            }
            
            // Create download link
            const link = document.createElement('a');
            link.href = generationData.audio_url;
            link.download = generationData.filename || 'generated_audio.mp3';
            link.click();
        }
        
        function exportSRT() {
            if (!generationData || !generationData.generation_id) {
                alert('No audio to export');
                return;
            }
            
            // Request SRT export
            fetch('api/export-srt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    generation_id: generationData.generation_id
                })
            })
            .then(response => response.blob())
            .then(blob => {
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = (generationData.filename || 'generated_audio').replace(/\.[^.]+$/, '.srt');
                link.click();
            })
            .catch(error => {
                console.error('SRT export error:', error);
                alert('SRT export failed: ' + error.message);
            });
        }
        
        // Voice library functions
        function searchLibrary() {
            const search = document.getElementById('library-search').value;
            const language = document.getElementById('library-language').value;
            const gender = document.getElementById('library-gender').value;
            
            if (!search.trim()) {
                alert('Please enter search terms');
                return;
            }
            
            showLoading('Searching voices...', 'Loading community voices...');
            
            fetch('api/library.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'search',
                    search: search,
                    language: language,
                    gender: gender
                })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                displayLibraryResults(data.voices || []);
            })
            .catch(error => {
                hideLoading();
                console.error('Library search error:', error);
                alert('Search failed: ' + error.message);
            });
        }
        
        function displayLibraryResults(voices) {
            const container = document.getElementById('library-results');
            
            if (voices.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h6>No voices found</h6>
                        <p>Try adjusting your search criteria</p>
                    </div>
                `;
                return;
            }
            
            let html = `<div class="row">`;
            
            voices.forEach(voice => {
                html += `
                    <div class="col-md-6 mb-3">
                        <div class="voice-card">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="mb-1">${voice.name}</h6>
                                <button class="btn btn-sm btn-outline-primary" onclick="previewLibraryVoice('${voice.voice_id}')">
                                    <i class="fas fa-play"></i>
                                </button>
                            </div>
                            
                            <div class="voice-badges mb-2">
                                <span class="badge bg-primary">${voice.language || 'Unknown'}</span>
                                <span class="badge bg-secondary">${voice.gender || 'Unknown'}</span>
                                <span class="badge bg-info">${voice.category || 'Community'}</span>
                            </div>
                            
                            <p class="text-muted small mb-2">${(voice.description || '').substring(0, 100)}...</p>
                            
                            <button class="btn btn-success btn-sm" onclick="importVoice('${voice.voice_id}', '${voice.name}')">
                                <i class="fas fa-download me-1"></i>Import Voice
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += `</div>`;
            container.innerHTML = html;
        }
        
        function previewLibraryVoice(voiceId) {
            const language = document.getElementById('library-language').value || 'en';
            
            fetch('api/preview.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    voice_id: voiceId,
                    language: language
                })
            })
            .then(response => response.blob())
            .then(blob => {
                const audio = new Audio(URL.createObjectURL(blob));
                audio.play();
            })
            .catch(error => {
                console.error('Preview error:', error);
                alert('Preview failed: ' + error.message);
            });
        }
        
        function importVoice(voiceId, voiceName) {
            // Show language selection modal
            const languages = <?= json_encode($LANGUAGES) ?>;
            let languageOptions = '';
            
            Object.entries(languages).forEach(([name, info]) => {
                if (info.code) {
                    languageOptions += `<option value="${info.code}">${info.flag} ${name}</option>`;
                }
            });
            
            const modalHtml = `
                <div class="modal fade" id="importModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">📥 Import Voice: ${voiceName}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">🌐 Select Language for this Voice</label>
                                    <select id="import-language" class="form-select">
                                        ${languageOptions}
                                    </select>
                                </div>
                                <p class="text-muted">This voice will be imported to your Text-to-Speech collection.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-success" onclick="confirmImportVoice('${voiceId}', '${voiceName}')">
                                    📥 Import Voice
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHtml);
            const modal = new bootstrap.Modal(document.getElementById('importModal'));
            modal.show();
            
            // Remove modal when closed
            document.getElementById('importModal').addEventListener('hidden.bs.modal', function() {
                this.remove();
            });
        }
        
        function confirmImportVoice(voiceId, voiceName) {
            const language = document.getElementById('import-language').value;
            
            bootstrap.Modal.getInstance(document.getElementById('importModal')).hide();
            
            showLoading('Importing voice...', 'Adding to your collection...');
            
            fetch('api/library.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'import',
                    voice_id: voiceId,
                    voice_name: voiceName,
                    language: language
                })
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    alert('✅ Voice imported successfully! You can now use it in Text-to-Speech.');
                    // Optionally refresh the page to show new voice
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert('Import failed: ' + data.message);
                }
            })
            .catch(error => {
                hideLoading();
                console.error('Import error:', error);
                alert('Import failed: ' + error.message);
            });
        }
        
        // History loading
        function loadHistory() {
            fetch('api/history.php')
            .then(response => response.json())
            .then(data => {
                displayHistory(data.history || []);
            })
            .catch(error => {
                console.error('History error:', error);
                document.getElementById('history-content').innerHTML = 'Failed to load history';
            });
        }
        
        function displayHistory(history) {
            const container = document.getElementById('history-content');
            
            if (history.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-history"></i>
                        <h6>No generation history</h6>
                        <p>Your generated speech files will appear here</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            history.forEach(item => {
                html += `
                    <div class="card mb-2">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-4">
                                    <h6 class="mb-1">${item.voice_name || 'Unknown Voice'}</h6>
                                    <small class="text-muted">${new Date(item.created_at).toLocaleDateString()}</small>
                                </div>
                                <div class="col-md-4">
                                    <span class="badge bg-primary me-1">${item.model_used}</span>
                                    <span class="badge bg-info">${item.credits_used} credits</span>
                                </div>
                                <div class="col-md-4 text-end">
                                    ${item.output_filename ? `
                                        <a href="audio/${item.output_filename}" class="btn btn-sm btn-outline-primary" download>
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                            <p class="text-muted small mb-0 mt-2">${(item.text_input || '').substring(0, 100)}...</p>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Utility functions
        function showLoading(title, detail) {
            document.getElementById('loading-text').textContent = title;
            document.getElementById('loading-detail').textContent = detail;
            document.getElementById('loading-overlay').style.display = 'flex';
        }
        
        function hideLoading() {
            document.getElementById('loading-overlay').style.display = 'none';
        }
        
        function showProgress(value) {
            const progressContainer = document.getElementById('generation-progress');
            const progressBar = progressContainer.querySelector('.progress-bar');
            progressContainer.style.display = 'block';
            progressBar.style.width = (value * 100) + '%';
        }
        
        function hideProgress() {
            document.getElementById('generation-progress').style.display = 'none';
        }
    </script>
</body>
</html>
    </script>
</body>
</html>
