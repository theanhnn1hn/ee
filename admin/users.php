<?php
require_once '../config.php';
requireAdmin();

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);
    
    switch ($action) {
        case 'update_credits':
            $newBalance = intval($_POST['credits_balance'] ?? 0);
            $stmt = $db->prepare("UPDATE users SET credits_balance = ? WHERE id = ?");
            if ($stmt->execute([$newBalance, $userId])) {
                $success = "Credits updated successfully";
                logActivity('admin_update_credits', ['user_id' => $userId, 'new_balance' => $newBalance]);
            } else {
                $error = "Failed to update credits";
            }
            break;
            
        case 'update_status':
            $newStatus = $_POST['status'] ?? 'active';
            $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
            if ($stmt->execute([$newStatus, $userId])) {
                $success = "User status updated successfully";
                logActivity('admin_update_status', ['user_id' => $userId, 'new_status' => $newStatus]);
            } else {
                $error = "Failed to update status";
            }
            break;
            
        case 'reset_password':
            $newPassword = $_POST['new_password'] ?? '';
            if (strlen($newPassword) >= 6) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($stmt->execute([$hashedPassword, $userId])) {
                    $success = "Password reset successfully";
                    logActivity('admin_reset_password', ['user_id' => $userId]);
                } else {
                    $error = "Failed to reset password";
                }
            } else {
                $error = "Password must be at least 6 characters";
            }
            break;
            
        case 'delete_user':
            // Soft delete by setting status to suspended
            $stmt = $db->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'user'");
            if ($stmt->execute([$userId])) {
                $success = "User suspended successfully";
                logActivity('admin_suspend_user', ['user_id' => $userId]);
            } else {
                $error = "Failed to suspend user";
            }
            break;
    }
}

// Get users with pagination and search
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$whereConditions = ["role = 'user'"];
$params = [];

if ($search) {
    $whereConditions[] = "(username LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($status) {
    $whereConditions[] = "status = ?";
    $params[] = $status;
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE $whereClause");
$countStmt->execute($params);
$totalUsers = $countStmt->fetchColumn();
$totalPages = ceil($totalUsers / $limit);

// Get users
$stmt = $db->prepare("
    SELECT u.*, 
           COUNT(gh.id) as total_generations,
           SUM(gh.credits_used) as total_credits_spent,
           COUNT(uv.id) as imported_voices_count
    FROM users u
    LEFT JOIN generation_history gh ON gh.user_id = u.id
    LEFT JOIN user_voices uv ON uv.user_id = u.id
    WHERE $whereClause
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - User Management</title>
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
        
        .btn-primary {
            background: var(--primary-color);
            border: none;
            border-radius: 8px;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .table {
            font-size: 14px;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: var(--text-color);
            background: var(--bg-color);
        }
        
        .badge {
            font-size: 11px;
        }
        
        .search-form {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--border-color);
            border-radius: 8px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }
        
        .stats-mini {
            font-size: 12px;
            color: var(--text-light);
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
                    <a class="nav-link active" href="users.php">
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
                    <h2><i class="fas fa-users me-2"></i>User Management</h2>
                    <div class="d-flex gap-2">
                        <span class="badge bg-primary fs-6"><?= number_format($totalUsers) ?> Total Users</span>
                    </div>
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
                
                <!-- Search and Filters -->
                <div class="search-form">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Search Users</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Username or email..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="">All Statuses</option>
                                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i>Search
                                </button>
                                <a href="users.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i>Clear
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Users Table -->
                <div class="card">
                    <div class="card-body">
                        <?php if (empty($users)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                <h5>No Users Found</h5>
                                <p class="text-muted">No users match your search criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Contact</th>
                                            <th>Credits</th>
                                            <th>Activity</th>
                                            <th>Status</th>
                                            <th>Joined</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-3">
                                                            <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-medium"><?= htmlspecialchars($user['username']) ?></div>
                                                            <div class="stats-mini">ID: <?= $user['id'] ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div><?= htmlspecialchars($user['email']) ?></div>
                                                    <div class="stats-mini">
                                                        Last login: <?= $user['last_login'] ? date('M j, Y', strtotime($user['last_login'])) : 'Never' ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-medium"><?= formatCredits($user['credits_balance']) ?></div>
                                                    <div class="stats-mini">
                                                        Used: <?= formatCredits($user['total_credits_spent'] ?: 0) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div><?= number_format($user['total_generations'] ?: 0) ?> generations</div>
                                                    <div class="stats-mini"><?= number_format($user['imported_voices_count'] ?: 0) ?> voices</div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?= $user['status'] === 'active' ? 'success' : ($user['status'] === 'suspended' ? 'danger' : 'warning') ?>">
                                                        <?= ucfirst($user['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div><?= date('M j, Y', strtotime($user['created_at'])) ?></div>
                                                    <div class="stats-mini"><?= date('g:i A', strtotime($user['created_at'])) ?></div>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-primary" 
                                                                onclick="editUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>', <?= $user['credits_balance'] ?>, '<?= $user['status'] ?>')">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                onclick="resetPassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                            <i class="fas fa-key"></i>
                                                        </button>
                                                        <?php if ($user['status'] !== 'suspended'): ?>
                                                            <button class="btn btn-sm btn-outline-danger" 
                                                                    onclick="suspendUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                                <i class="fas fa-ban"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($totalPages > 1): ?>
                                <nav class="mt-4">
                                    <ul class="pagination justify-content-center">
                                        <?php if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>">
                                                    <i class="fas fa-chevron-left"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>">
                                                    <?= $i ?>
                                                </a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $totalPages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>">
                                                    <i class="fas fa-chevron-right"></i>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_credits">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit_username" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Credits Balance</label>
                            <input type="number" name="credits_balance" class="form-control" id="edit_credits" min="0" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" id="edit_status">
                                <option value="active">Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="pending">Pending</option>
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
    
    <!-- Reset Password Modal -->
    <div class="modal fade" id="resetPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" id="reset_user_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="reset_username" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control" 
                                   placeholder="Enter new password (min 6 characters)" minlength="6" required>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            The user will need to use this new password to login.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editUser(id, username, credits, status) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_username').value = username;
            document.getElementById('edit_credits').value = credits;
            document.getElementById('edit_status').value = status;
            
            // Update form action for status
            const form = document.querySelector('#editUserModal form');
            form.onsubmit = function(e) {
                // Update both credits and status
                const formData = new FormData(form);
                
                // First update credits
                fetch('users.php', {
                    method: 'POST',
                    body: formData
                }).then(() => {
                    // Then update status
                    const statusForm = new FormData();
                    statusForm.append('action', 'update_status');
                    statusForm.append('user_id', id);
                    statusForm.append('status', document.getElementById('edit_status').value);
                    
                    return fetch('users.php', {
                        method: 'POST',
                        body: statusForm
                    });
                }).then(() => {
                    location.reload();
                });
                
                e.preventDefault();
                return false;
            };
            
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        }
        
        function resetPassword(id, username) {
            document.getElementById('reset_user_id').value = id;
            document.getElementById('reset_username').value = username;
            new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
        }
        
        function suspendUser(id, username) {
            if (confirm(`Are you sure you want to suspend user "${username}"?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
