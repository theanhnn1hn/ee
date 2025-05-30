<?php
require_once '../config.php';
requireAdmin();

// Handle API key actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_key':
            $keyName = trim($_POST['key_name'] ?? '');
            $apiKey = trim($_POST['api_key'] ?? '');
            $keyType = $_POST['key_type'] ?? 'regular';
            $monthlyLimit = intval($_POST['monthly_limit'] ?? 10000);
            $priority = intval($_POST['priority'] ?? 1);
            
            if ($keyName && $apiKey) {
                // Check if key already exists
                $stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key = ?");
                $stmt->execute([$apiKey]);
                
                if ($stmt->fetch()) {
                    $error = "This API key already exists";
                } else {
                    // Test API key validity
                    try {
                        $testCh = curl_init();
                        curl_setopt_array($testCh, [
                            CURLOPT_URL => ELEVENLABS_BASE_URL . '/user',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_TIMEOUT => 10,
                            CURLOPT_HTTPHEADER => ['xi-api-key: ' . $apiKey],
                            CURLOPT_SSL_VERIFYPEER => false
                        ]);
                        
                        $response = curl_exec($testCh);
                        $httpCode = curl_getinfo($testCh, CURLINFO_HTTP_CODE);
                        curl_close($testCh);
                        
                        if ($httpCode === 200) {
                            // API key is valid, add to database
                            $stmt = $db->prepare("
                                INSERT INTO api_keys (key_name, api_key, key_type, monthly_limit, priority, added_by, last_reset) 
                                VALUES (?, ?, ?, ?, ?, ?, CURDATE())
                            ");
                            
                            if ($stmt->execute([$keyName, $apiKey, $keyType, $monthlyLimit, $priority, $_SESSION['user_id']])) {
                                $success = "API key added successfully";
                                logActivity('admin_add_api_key', ['key_name' => $keyName, 'key_type' => $keyType]);
                            } else {
                                $error = "Failed to add API key";
                            }
                        } else {
                            $error = "Invalid API key - could not authenticate with ElevenLabs";
                        }
                    } catch (Exception $e) {
                        $error = "Could not validate API key: " . $e->getMessage();
                    }
                }
            } else {
                $error = "Please fill in all required fields";
            }
            break;
            
        case 'update_key':
            $keyId = intval($_POST['key_id'] ?? 0);
            $keyName = trim($_POST['key_name'] ?? '');
            $monthlyLimit = intval($_POST['monthly_limit'] ?? 10000);
            $priority = intval($_POST['priority'] ?? 1);
            $status = $_POST['status'] ?? 'active';
            
            if ($keyId && $keyName) {
                $stmt = $db->prepare("
                    UPDATE api_keys 
                    SET key_name = ?, monthly_limit = ?, priority = ?, status = ? 
                    WHERE id = ?
                ");
                
                if ($stmt->execute([$keyName, $monthlyLimit, $priority, $status, $keyId])) {
                    $success = "API key updated successfully";
                    logActivity('admin_update_api_key', ['key_id' => $keyId, 'key_name' => $keyName]);
                } else {
                    $error = "Failed to update API key";
                }
            } else {
                $error = "Invalid input";
            }
            break;
            
        case 'delete_key':
            $keyId = intval($_POST['key_id'] ?? 0);
            
            if ($keyId) {
                $stmt = $db->prepare("SELECT key_name FROM api_keys WHERE id = ?");
                $stmt->execute([$keyId]);
                $keyData = $stmt->fetch();
                
                if ($keyData) {
                    $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ?");
                    if ($stmt->execute([$keyId])) {
                        $success = "API key deleted successfully";
                        logActivity('admin_delete_api_key', ['key_id' => $keyId, 'key_name' => $keyData['key_name']]);
                    } else {
                        $error = "Failed to delete API key";
                    }
                } else {
                    $error = "API key not found";
                }
            }
            break;
            
        case 'reset_usage':
            $keyId = intval($_POST['key_id'] ?? 0);
            
            if ($keyId) {
                $stmt = $db->prepare("UPDATE api_keys SET credits_used = 0, last_reset = CURDATE() WHERE id = ?");
                if ($stmt->execute([$keyId])) {
                    $success = "Usage reset successfully";
                    logActivity('admin_reset_api_usage', ['key_id' => $keyId]);
                } else {
                    $error = "Failed to reset usage";
                }
            }
            break;
    }
}

// Get API keys with usage statistics
$stmt = $db->prepare("
    SELECT ak.*, u.username as added_by_name,
           ROUND((ak.credits_used / ak.monthly_limit) * 100, 1) as usage_percent,
           (ak.monthly_limit - ak.credits_used) as remaining_credits
    FROM api_keys ak
    LEFT JOIN users u ON u.id = ak.added_by
    ORDER BY ak.priority DESC, ak.created_at DESC
");
$stmt->execute();
$apiKeys = $stmt->fetchAll();

// Calculate total statistics
$totalKeys = count($apiKeys);
$activeKeys = count(array_filter($apiKeys, fn($k) => $k['status'] === 'active'));
$premiumKeys = count(array_filter($apiKeys, fn($k) => $k['key_type'] === 'premium' && $k['status'] === 'active'));
$totalCreditsUsed = array_sum(array_column($apiKeys, 'credits_used'));
$totalCreditsAvailable = array_sum(array_column($apiKeys, 'monthly_limit')) - $totalCreditsUsed;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - API Keys Management</title>
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
            --border-color: #e2e8f0;
        }
        
        body {
            background: var(--bg-color);
            font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-color);
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
        
        .stats-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        
        .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .api-key-card {
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .api-key-card.premium {
            border-color: var(--warning-color);
            background: rgba(241, 196, 15, 0.05);
        }
        
        .api-key-card.inactive {
            opacity: 0.6;
            background: #f8f9fa;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .api-key-masked {
            font-family: monospace;
            background: var(--bg-color);
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            font-size: 13px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-shield-alt me-2"></i><?= APP_NAME ?> - Admin
            </a>
            
            <div class="navbar-nav ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="fas fa-user-shield me-1"></i><?= htmlspecialchars($_SESSION['username']) ?>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="../dashboard.php"><i class="fas fa-user me-2"></i>User View</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
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
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users me-2"></i>Users
                    </a>
                    <a class="nav-link active" href="api-keys.php">
                        <i class="fas fa-key me-2"></i>API Keys
                    </a>
                    <a class="nav-link" href="voices.php">
                        <i class="fas fa-microphone me-2"></i>Voices
                    </a>
                    <a class="nav-link" href="generations.php">
                        <i class="fas fa-history me-2"></i>Generations
                    </a>
                    <a class="nav-link" href="settings.php">
                        <i class="fas fa-cog me-2"></i>Settings
                    </a>
                    <a class="nav-link" href="logs.php">
                        <i class="fas fa-file-alt me-2"></i>Activity Logs
                    </a>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 main-content">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-key me-2"></i>API Keys Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addKeyModal">
                        <i class="fas fa-plus me-1"></i>Add API Key
                    </button>
                </div>
                
                <!-- Alerts -->
                <?php if (isset($success)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="stats-number text-primary"><?= number_format($totalKeys) ?></div>
                            <small class="text-muted">Total Keys</small>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="stats-number text-success"><?= number_format($activeKeys) ?></div>
                            <small class="text-muted">Active Keys</small>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="stats-number text-warning"><?= number_format($premiumKeys) ?></div>
                            <small class="text-muted">Premium Keys</small>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="stats-number text-info"><?= formatCredits($totalCreditsAvailable) ?></div>
                            <small class="text-muted">Available Credits</small>
                        </div>
                    </div>
                </div>
                
                <!-- API Keys List -->
                <?php if (empty($apiKeys)): ?>
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-key fa-3x text-muted mb-3"></i>
                            <h5>No API Keys Configured</h5>
                            <p class="text-muted">Add your ElevenLabs API keys to enable text-to-speech functionality.</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addKeyModal">
                                <i class="fas fa-plus me-1"></i>Add Your First API Key
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($apiKeys as $key): ?>
                        <div class="api-key-card <?= $key['key_type'] === 'premium' ? 'premium' : '' ?> <?= $key['status'] !== 'active' ? 'inactive' : '' ?>">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-2">
                                        <h5 class="mb-0 me-2"><?= htmlspecialchars($key['key_name']) ?></h5>
                                        <span class="badge bg-<?= $key['key_type'] === 'premium' ? 'warning' : 'secondary' ?> me-2">
                                            <?= ucfirst($key['key_type']) ?>
                                        </span>
                                        <span class="badge bg-<?= $key['status'] === 'active' ? 'success' : 'danger' ?>">
                                            <?= ucfirst($key['status']) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="api-key-masked mb-2">
                                        <?= substr($key['api_key'], 0, 8) ?>••••••••••••••••••••••••••••••••••••••••••••••••••••••••
                                    </div>
                                    
                                    <small class="text-muted">
                                        Priority: <?= $key['priority'] ?> | 
                                        Added: <?= date('M j, Y', strtotime($key['created_at'])) ?> |
                                        Reset: <?= $key['last_reset'] ? date('M j, Y', strtotime($key['last_reset'])) : 'Never' ?>
                                        <?php if ($key['added_by_name']): ?>
                                            | By: <?= htmlspecialchars($key['added_by_name']) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        <div class="d-flex justify-content-between mb-1">
                                            <small>Usage</small>
                                            <small><?= $key['usage_percent'] ?>%</small>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar <?= $key['usage_percent'] > 80 ? 'bg-danger' : ($key['usage_percent'] > 60 ? 'bg-warning' : 'bg-success') ?>" 
                                                 style="width: <?= min(100, $key['usage_percent']) ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="text-center">
                                        <strong><?= formatCredits($key['credits_used']) ?></strong> / 
                                        <?= formatCredits($key['monthly_limit']) ?> credits
                                    </div>
                                </div>
                                
                                <div class="col-md-2 text-end">
                                    <div class="btn-group-vertical">
                                        <button class="btn btn-sm btn-outline-primary mb-1" 
                                                onclick="editKey(<?= $key['id'] ?>, '<?= htmlspecialchars($key['key_name']) ?>', <?= $key['monthly_limit'] ?>, <?= $key['priority'] ?>, '<?= $key['status'] ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-success mb-1" 
                                                onclick="resetUsage(<?= $key['id'] ?>, '<?= htmlspecialchars($key['key_name']) ?>')">
                                            <i class="fas fa-sync"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" 
                                                onclick="deleteKey(<?= $key['id'] ?>, '<?= htmlspecialchars($key['key_name']) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add API Key Modal -->
    <div class="modal fade" id="addKeyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add API Key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_key">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Key Name</label>
                                    <input type="text" name="key_name" class="form-control" 
                                           placeholder="e.g., Main API Key" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Key Type</label>
                                    <select name="key_type" class="form-select" required>
                                        <option value="regular">Regular (Free Tier)</option>
                                        <option value="premium">Premium (Paid Tier)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">API Key</label>
                            <input type="text" name="api_key" class="form-control" 
                                   placeholder="sk-..." required>
                            <small class="text-muted">Your ElevenLabs API key starting with "sk-"</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Monthly Limit (Credits)</label>
                                    <input type="number" name="monthly_limit" class="form-control" 
                                           value="10000" min="1000" max="1000000" required>
                                    <small class="text-muted">Regular: ~10,000 | Premium: 100,000+</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Priority</label>
                                    <input type="number" name="priority" class="form-control" 
                                           value="1" min="1" max="100" required>
                                    <small class="text-muted">Higher priority keys are used first</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            The API key will be validated against ElevenLabs servers before adding.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add API Key</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit API Key Modal -->
    <div class="modal fade" id="editKeyModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit API Key</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_key">
                        <input type="hidden" name="key_id" id="edit_key_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Key Name</label>
                            <input type="text" name="key_name" class="form-control" id="edit_key_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Monthly Limit</label>
                                    <input type="number" name="monthly_limit" class="form-control" 
                                           id="edit_monthly_limit" min="1000" max="1000000" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Priority</label>
                                    <input type="number" name="priority" class="form-control" 
                                           id="edit_priority" min="1" max="100" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="edit_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="expired">Expired</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editKey(id, name, monthlyLimit, priority, status) {
            document.getElementById('edit_key_id').value = id;
            document.getElementById('edit_key_name').value = name;
            document.getElementById('edit_monthly_limit').value = monthlyLimit;
            document.getElementById('edit_priority').value = priority;
            document.getElementById('edit_status').value = status;
            
            new bootstrap.Modal(document.getElementById('editKeyModal')).show();
        }
        
        function resetUsage(id, name) {
            if (confirm(`Reset usage for "${name}"? This will set credits used back to 0.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reset_usage">
                    <input type="hidden" name="key_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function deleteKey(id, name) {
            if (confirm(`Are you sure you want to delete the API key "${name}"? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_key">
                    <input type="hidden" name="key_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
