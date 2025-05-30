<?php
require_once '../config.php';
requireAdmin();

// Get admin statistics
$stats = [
    'total_users' => $db->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
    'active_users' => $db->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND status = 'active'")->fetchColumn(),
    'total_generations' => $db->query("SELECT COUNT(*) FROM generation_history")->fetchColumn(),
    'total_credits_used' => $db->query("SELECT SUM(credits_used) FROM generation_history")->fetchColumn() ?: 0,
    'total_voices' => $db->query("SELECT COUNT(*) FROM voices")->fetchColumn(),
    'imported_voices' => $db->query("SELECT COUNT(*) FROM voices WHERE source = 'imported'")->fetchColumn(),
    'api_keys_count' => $db->query("SELECT COUNT(*) FROM api_keys WHERE status = 'active'")->fetchColumn(),
    'premium_keys_count' => $db->query("SELECT COUNT(*) FROM api_keys WHERE status = 'active' AND key_type = 'premium'")->fetchColumn()
];

// Recent activity
$recentActivity = $db->query("
    SELECT al.*, u.username 
    FROM activity_logs al 
    LEFT JOIN users u ON u.id = al.user_id 
    ORDER BY al.created_at DESC 
    LIMIT 10
")->fetchAll();

// Recent users
$recentUsers = $db->query("
    SELECT * FROM users 
    WHERE role = 'user' 
    ORDER BY created_at DESC 
    LIMIT 5
")->fetchAll();

// API key usage summary
$apiKeyUsage = $db->query("
    SELECT 
        key_name,
        key_type,
        credits_used,
        monthly_limit,
        ROUND((credits_used / monthly_limit) * 100, 1) as usage_percent
    FROM api_keys 
    WHERE status = 'active' 
    ORDER BY usage_percent DESC 
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Admin Dashboard</title>
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
        
        .stats-card {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary-color), var(--accent-color));
            color: white;
            margin-bottom: 20px;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stats-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .metric-card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        
        .metric-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .metric-label {
            color: var(--text-light);
            font-size: 13px;
        }
        
        .activity-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .badge {
            font-size: 11px;
        }
        
        .progress {
            height: 6px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .alert {
            border: none;
            border-radius: 8px;
        }
        
        .table {
            font-size: 14px;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--text-color);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
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
                    <a class="nav-link active" href="index.php">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a class="nav-link" href="users.php">
                        <i class="fas fa-users me-2"></i>Users
                    </a>
                    <a class="nav-link" href="api-keys.php">
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
                    <h2><i class="fas fa-tachometer-alt me-2"></i>Admin Dashboard</h2>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary" onclick="location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                        <a href="settings.php" class="btn btn-primary">
                            <i class="fas fa-cog me-1"></i>Settings
                        </a>
                    </div>
                </div>
                
                <!-- Statistics Overview -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="stats-number"><?= number_format($stats['total_users']) ?></div>
                            <div class="stats-label">Total Users</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="metric-card">
                            <div class="metric-number text-success"><?= number_format($stats['total_generations']) ?></div>
                            <div class="metric-label">Total Generations</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="metric-card">
                            <div class="metric-number text-info"><?= formatCredits($stats['total_credits_used']) ?></div>
                            <div class="metric-label">Credits Used</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="metric-card">
                            <div class="metric-number text-warning"><?= number_format($stats['api_keys_count']) ?></div>
                            <div class="metric-label">Active API Keys</div>
                        </div>
                    </div>
                </div>
                
                <!-- Detailed Stats -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number text-primary"><?= number_format($stats['active_users']) ?></div>
                            <div class="metric-label">Active Users</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number text-secondary"><?= number_format($stats['total_voices']) ?></div>
                            <div class="metric-label">Total Voices</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number text-success"><?= number_format($stats['imported_voices']) ?></div>
                            <div class="metric-label">Imported Voices</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="metric-card">
                            <div class="metric-number text-danger"><?= number_format($stats['premium_keys_count']) ?></div>
                            <div class="metric-label">Premium Keys</div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Recent Activity -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-chart-line me-2"></i>Recent Activity</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentActivity)): ?>
                                    <p class="text-muted">No recent activity</p>
                                <?php else: ?>
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <div class="activity-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div>
                                                    <strong><?= htmlspecialchars($activity['username'] ?: 'System') ?></strong>
                                                    <span class="text-muted"><?= htmlspecialchars($activity['action']) ?></span>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= date('M j, Y g:i A', strtotime($activity['created_at'])) ?>
                                                    </small>
                                                </div>
                                                <span class="badge bg-primary"><?= ucfirst(str_replace('_', ' ', $activity['action'])) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <div class="text-center mt-3">
                                    <a href="logs.php" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye me-1"></i>View All Logs
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- API Key Usage -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-key me-2"></i>API Key Usage</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($apiKeyUsage)): ?>
                                    <p class="text-muted">No API keys configured</p>
                                    <a href="api-keys.php" class="btn btn-primary btn-sm">
                                        <i class="fas fa-plus me-1"></i>Add API Keys
                                    </a>
                                <?php else: ?>
                                    <?php foreach ($apiKeyUsage as $key): ?>
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <span class="fw-medium"><?= htmlspecialchars($key['key_name']) ?></span>
                                                <div>
                                                    <span class="badge bg-<?= $key['key_type'] === 'premium' ? 'warning' : 'secondary' ?>">
                                                        <?= ucfirst($key['key_type']) ?>
                                                    </span>
                                                    <span class="badge bg-info"><?= $key['usage_percent'] ?>%</span>
                                                </div>
                                            </div>
                                            <div class="progress">
                                                <div class="progress-bar" style="width: <?= min(100, $key['usage_percent']) ?>%"></div>
                                            </div>
                                            <small class="text-muted">
                                                <?= formatCredits($key['credits_used']) ?> / <?= formatCredits($key['monthly_limit']) ?> credits
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <div class="text-center mt-3">
                                    <a href="api-keys.php" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-cog me-1"></i>Manage API Keys
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Users -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-users me-2"></i>Recent Users</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recentUsers)): ?>
                                    <p class="text-muted">No users registered yet</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Username</th>
                                                    <th>Email</th>
                                                    <th>Credits</th>
                                                    <th>Status</th>
                                                    <th>Registered</th>
                                                    <th>Last Login</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recentUsers as $user): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($user['username']) ?></strong>
                                                        </td>
                                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                                        <td>
                                                            <span class="badge bg-info"><?= formatCredits($user['credits_balance']) ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : 'warning' ?>">
                                                                <?= ucfirst($user['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                                        <td>
                                                            <?= $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never' ?>
                                                        </td>
                                                        <td>
                                                            <a href="users.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="text-center mt-3">
                                    <a href="users.php" class="btn btn-outline-primary">
                                        <i class="fas fa-users me-1"></i>Manage All Users
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
