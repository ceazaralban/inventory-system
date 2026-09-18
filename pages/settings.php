<?php
require_once __DIR__ . '/../includes/init.php';
// requireRole(['admin','manager']);
redirectIfNotLoggedIn();
requirePageAccess('settings');

$database = new Database();
$db = $database->getConnection();
$is_admin = (($_SESSION['role'] ?? '') === 'admin');

$page_title = "Settings - Inventory System";

// Handle form submissions
if ($_POST) {

    // ✅ define action FIRST (fixes Undefined variable $action)
    $action = $_POST['action'] ?? '';

    if ($action === 'save_page_access') {
        if (!$is_admin) {
            http_response_code(403);
            die('Access denied');
        }

        $pages = ['dashboard','products','inventory','sales','cash_reconciliation','reports','settings'];

        // checkboxes arrays (if unchecked, it won’t exist)
        $allow_manager = $_POST['allow_manager'] ?? [];
        $allow_staff   = $_POST['allow_staff'] ?? [];

        $stmt = $db->prepare("
            INSERT INTO page_permissions (page_key, role, is_enabled)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)
        ");

        foreach ($pages as $p) {
            $mgr_enabled = isset($allow_manager[$p]) ? 1 : 0;
            $stf_enabled = isset($allow_staff[$p]) ? 1 : 0;

            $stmt->execute([$p, 'manager', $mgr_enabled]);
            $stmt->execute([$p, 'staff',   $stf_enabled]);
        }

        // If admin is changing permissions, refresh their session perms (optional)
        $_SESSION['page_perms'] = ['*' => 1];

        $success = "Page access updated successfully! (Other users may need logout/login to refresh.)";
    }

    if ($action === 'create_user') {
        if (!$is_admin) {
            http_response_code(403);
            die('Access denied');
        }

    $new_username = trim($_POST['new_username'] ?? '');
    $new_email    = trim($_POST['new_email'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $new_role     = trim($_POST['new_role'] ?? 'staff'); // admin/manager/staff
    $new_active   = isset($_POST['new_active']) ? 1 : 0;

    if ($new_username === '' || $new_email === '' || $new_password === '') {
            $error = "All fields are required for creating a user.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = "Valid email is required.";
        } elseif (strlen($new_password) < 6) {
            $error = "Password must be at least 6 characters.";
        } elseif (!in_array($new_role, ['admin','manager','staff'], true)) {
            $error = "Invalid role selected.";
        } else {
            try {
                // make sure username/email are unique
                $check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
                $check->execute([$new_username, $new_email]);
                if ($check->fetch()) {
                    throw new Exception("Username or email already exists.");
                }

                $hash = password_hash($new_password, PASSWORD_DEFAULT);

                $stmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, role, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$new_username, $new_email, $hash, $new_role, $new_active]);

                $success = "User created successfully!";
            } catch (Exception $e) {
                $error = "Failed to create user: " . $e->getMessage();
            }
        }
    }

    // ============================
    // ADMIN: Enable/Disable user
    // ============================
    if ($action === 'toggle_user_active') {
        if (!$is_admin) { http_response_code(403); die('Access denied'); }

        $target_id = (int)($_POST['target_user_id'] ?? 0);
        $new_active = (int)($_POST['new_active'] ?? 0);

        if ($target_id <= 0) {
            $error = "Invalid user.";
        } elseif ($target_id === (int)($_SESSION['user_id'] ?? 0) && $new_active === 0) {
            $error = "You cannot disable your own admin account.";
        } else {
            $stmt = $db->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$new_active, $target_id])) {
                $success = "User status updated.";
            } else {
                $error = "Failed to update user status.";
            }
        }
    }

    // ============================
    // ADMIN: Change user role
    // ============================
    if ($action === 'change_user_role') {
        if (!$is_admin) { http_response_code(403); die('Access denied'); }

        $target_id = (int)($_POST['target_user_id'] ?? 0);
        $new_role  = trim($_POST['new_role'] ?? 'staff');

        if ($target_id <= 0) {
            $error = "Invalid user.";
        } elseif (!in_array($new_role, ['admin','manager','staff'], true)) {
            $error = "Invalid role.";
        } elseif ($target_id === (int)($_SESSION['user_id'] ?? 0) && $new_role !== 'admin') {
            $error = "You cannot remove your own admin role.";
        } else {
            $stmt = $db->prepare("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$new_role, $target_id])) {
                $success = "User role updated.";
            } else {
                $error = "Failed to update user role.";
            }
        }
    }

    // ============================
    // ADMIN: Reset password (optional)
    // ============================
    if ($action === 'reset_user_password') {
        if (!$is_admin) { http_response_code(403); die('Access denied'); }

        $target_id = (int)($_POST['target_user_id'] ?? 0);
        $new_pass  = (string)($_POST['reset_password'] ?? '');

        if ($target_id <= 0) {
            $error = "Invalid user.";
        } elseif (strlen($new_pass) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt->execute([$hash, $target_id])) {
                $success = "Password reset successfully.";
            } else {
                $error = "Failed to reset password.";
            }
        }
    }

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($username)) {
            $error = "Username is required!";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Valid email is required!";
        } else {
            $query = "UPDATE users SET username = ?, email = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$username, $email, $_SESSION['user_id']])) {
                $_SESSION['username'] = $username;
                $success = "Profile updated successfully!";
            } else {
                $error = "Failed to update profile!";
            }
        }
    }
    
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Verify current password
        $query = "SELECT password_hash FROM users WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $user['password_hash'])) {
            $error = "Current password is incorrect!";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters long!";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match!";
        } else {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password_hash = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$new_password_hash, $_SESSION['user_id']])) {
                $success = "Password changed successfully!";
            } else {
                $error = "Failed to change password!";
            }
        }
    }
    
    if ($action === 'system_settings') {
        $company_name = trim($_POST['company_name'] ?? '');
        $currency = trim($_POST['currency'] ?? '$');
        $low_stock_threshold = intval($_POST['low_stock_threshold'] ?? 10);
        
        // In a real application, you'd save these to a settings table
        // For now, we'll just show a success message
        $success = "System settings updated successfully!";
    }
}

// Get current user data
$query = "SELECT username, email FROM users WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get system statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM products) as total_products,
        (SELECT COUNT(*) FROM sales) as total_sales,
        (SELECT COUNT(*) FROM purchases) as total_purchases,
        (SELECT SUM(total) FROM sales WHERE DATE(sale_date) = CURDATE()) as today_revenue
";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);
$page_keys = ['dashboard','products','inventory','sales','cash_reconciliation','reports','settings'];
$page_labels = [
    'dashboard'           => 'Dashboard',
    'products'            => 'Products',
    'inventory'           => 'Inventory',
    'sales'               => 'Sales',
    'cash_reconciliation' => 'Cash Reconciliation',
    'reports'             => 'Reports',
    'settings'            => 'Settings',
];

// defaults allow
$perm_manager = array_fill_keys($page_keys, 1);
$perm_staff   = array_fill_keys($page_keys, 1);

$stmt = $db->query("SELECT page_key, role, is_enabled FROM page_permissions WHERE role IN ('manager','staff')");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($r['role'] === 'manager') $perm_manager[$r['page_key']] = (int)$r['is_enabled'];
    if ($r['role'] === 'staff')   $perm_staff[$r['page_key']]   = (int)$r['is_enabled'];
}

$users_list = [];
if ($is_admin) {
    $users_list = $db->query("
        SELECT id, username, email, role, is_active, created_at, updated_at
        FROM users
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php include '../includes/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="main-content" id="mainContent">
                <!-- Top Navbar -->
                <nav class="navbar navbar-expand navbar-light bg-white border-bottom px-4 py-2 sticky-top shadow-sm">
                    <div class="container-fluid p-0">
                        <button class="btn btn-outline-secondary d-md-none me-2" id="sidebarToggleNav">
                            <i class="bi bi-list"></i>
                        </button>
                        <span class="navbar-brand mb-0 h6 text-secondary fw-bold">SYSTEM SETTINGS</span>
                        <div class="ms-auto d-flex align-items-center gap-3">
                            <span class="badge bg-light text-dark border px-3 py-2">
                                <i class="bi bi-person-circle me-1"></i>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'admin'); ?></strong>
                            </span>
                            <a href="../logout.php" class="btn btn-sm btn-outline-danger" title="Logout">
                                <i class="bi bi-power"></i>
                            </a>
                        </div>
                    </div>
                </nav>

                <!-- Settings Content -->
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h2>System Settings</h2>
                        </div>
                    </div>

                    <!-- Alerts -->
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- System Statistics -->
                        <div class="col-md-3">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">System Overview</h6>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Total Products</small>
                                        <strong class="h5"><?php echo $stats['total_products']; ?></strong>
                                    </div>
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Total Sales</small>
                                        <strong class="h5"><?php echo $stats['total_sales']; ?></strong>
                                    </div>
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Total Purchases</small>
                                        <strong class="h5"><?php echo $stats['total_purchases']; ?></strong>
                                    </div>
                                    <div class="mb-3">
                                        <small class="text-muted d-block">Today's Revenue</small>
                                        <strong class="h5">₱<?php echo number_format($stats['today_revenue'] ?? 0, 2); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Links -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Quick Actions</h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a href="products.php" class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-box me-1"></i>Manage Products
                                        </a>
                                        <a href="inventory.php" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-warehouse me-1"></i>View Inventory
                                        </a>
                                        <a href="reports.php" class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-graph-up me-1"></i>View Reports
                                        </a>
                                        <a href="../logout.php" class="btn btn-outline-danger btn-sm">
                                            <i class="bi bi-box-arrow-right me-1"></i>Logout
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Settings Forms -->
                        <div class="col-md-9">
                            <!-- Profile Settings -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0"><i class="bi bi-person me-2"></i>Profile Settings</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="update_profile">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Username *</label>
                                                    <input type="text" name="username" class="form-control" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Email Address *</label>
                                                    <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary">Update Profile</button>
                                    </form>
                                </div>
                            </div>

                            <!-- Change Password -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0"><i class="bi bi-shield-lock me-2"></i>Change Password</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="change_password">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Current Password *</label>
                                                    <input type="password" name="current_password" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">New Password *</label>
                                                    <input type="password" name="new_password" class="form-control" required minlength="6">
                                                    <small class="text-muted">Minimum 6 characters</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Confirm New Password *</label>
                                                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-warning">Change Password</button>
                                    </form>
                                </div>
                            </div>

                            <?php if ($is_admin): ?>
                            <div class="card mb-4">
                                <div class="card-header bg-dark text-white">
                                    <h6 class="card-title mb-0"><i class="bi bi-people me-2"></i>User Management (Admin Only)</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create_user">

                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Username *</label>
                                                <input type="text" name="new_username" class="form-control" required>
                                            </div>

                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Email *</label>
                                                <input type="email" name="new_email" class="form-control" required>
                                            </div>

                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Password *</label>
                                                <input type="password" name="new_password" class="form-control" required minlength="6">
                                            </div>

                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Role *</label>
                                                <select name="new_role" class="form-select" required>
                                                    <option value="staff">Staff</option>
                                                    <option value="manager">Manager</option>
                                                    <option value="admin">Admin</option>
                                                </select>
                                            </div>

                                            <div class="col-md-4 mb-3 d-flex align-items-end">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="new_active" id="new_active" checked>
                                                    <label class="form-check-label" for="new_active">Active</label>
                                                </div>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-dark">
                                            <i class="bi bi-person-plus me-1"></i>Create User
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <?php if ($is_admin): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h6 class="card-title mb-0"><i class="bi bi-list-check me-2"></i>Users List</h6>
                                        <small class="text-muted">Manage roles and access</small>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th>User</th>
                                                    <th>Email</th>
                                                    <th>Role</th>
                                                    <th>Status</th>
                                                    <th style="width:260px;">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($users_list)): ?>
                                                    <?php foreach ($users_list as $u): ?>
                                                        <?php
                                                            $is_me = ((int)$u['id'] === (int)($_SESSION['user_id'] ?? 0));
                                                            $active = ((int)($u['is_active'] ?? 0) === 1);
                                                        ?>
                                                        <tr>
                                                            <td><?php echo (int)$u['id']; ?></td>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($u['username']); ?></strong>
                                                                <?php if ($is_me): ?>
                                                                    <span class="badge bg-info ms-2">You</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($u['email']); ?></td>

                                                            <!-- Role change -->
                                                            <td>
                                                                <form method="POST" class="d-flex gap-2">
                                                                    <input type="hidden" name="action" value="change_user_role">
                                                                    <input type="hidden" name="target_user_id" value="<?php echo (int)$u['id']; ?>">
                                                                    <select name="new_role" class="form-select form-select-sm" <?php echo $is_me ? 'disabled' : ''; ?>>
                                                                        <option value="admin"   <?php echo ($u['role']==='admin')?'selected':''; ?>>Admin</option>
                                                                        <option value="manager" <?php echo ($u['role']==='manager')?'selected':''; ?>>Manager</option>
                                                                        <option value="staff"   <?php echo ($u['role']==='staff')?'selected':''; ?>>Staff</option>
                                                                    </select>
                                                                    <button class="btn btn-sm btn-outline-primary" type="submit" <?php echo $is_me ? 'disabled' : ''; ?>>
                                                                        Save
                                                                    </button>
                                                                </form>
                                                            </td>

                                                            <!-- Status -->
                                                            <td>
                                                                <?php if ($active): ?>
                                                                    <span class="badge bg-success">Active</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">Disabled</span>
                                                                <?php endif; ?>
                                                            </td>

                                                            <!-- Actions -->
                                                            <td>
                                                                <div class="d-flex flex-wrap gap-2">
                                                                    <!-- Enable/Disable -->
                                                                    <form method="POST" onsubmit="return confirm('Are you sure?');">
                                                                        <input type="hidden" name="action" value="toggle_user_active">
                                                                        <input type="hidden" name="target_user_id" value="<?php echo (int)$u['id']; ?>">
                                                                        <input type="hidden" name="new_active" value="<?php echo $active ? 0 : 1; ?>">
                                                                        <button class="btn btn-sm <?php echo $active ? 'btn-outline-danger' : 'btn-outline-success'; ?>"
                                                                                type="submit"
                                                                                <?php echo $is_me ? 'disabled' : ''; ?>>
                                                                            <?php echo $active ? 'Disable' : 'Enable'; ?>
                                                                        </button>
                                                                    </form>

                                                                    <!-- Reset password (optional) -->
                                                                    <button type="button"
                                                                            class="btn btn-sm btn-outline-secondary"
                                                                            data-bs-toggle="modal"
                                                                            data-bs-target="#resetPassModal"
                                                                            data-user-id="<?php echo (int)$u['id']; ?>"
                                                                            data-username="<?php echo htmlspecialchars($u['username']); ?>">
                                                                        Reset Password
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center text-muted py-3">No users found.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <small class="text-muted">Note: You cannot disable or remove your own admin privileges.</small>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php endif; ?>

                            <?php if ($is_admin): ?>
                            <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h6 class="card-title mb-0"><i class="bi bi-ui-checks me-2"></i>Page Access Control (Admin Only)</h6>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                <input type="hidden" name="action" value="save_page_access">

                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                    <thead>
                                        <tr>
                                        <th>Page</th>
                                        <th class="text-center">Manager Can View</th>
                                        <th class="text-center">Staff Can View</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($page_keys as $k): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($page_labels[$k] ?? $k); ?></strong></td>

                                            <td class="text-center">
                                            <input type="checkbox"
                                                    name="allow_manager[<?php echo htmlspecialchars($k); ?>]"
                                                    <?php echo ($perm_manager[$k] ?? 1) ? 'checked' : ''; ?>>
                                            </td>

                                            <td class="text-center">
                                            <input type="checkbox"
                                                    name="allow_staff[<?php echo htmlspecialchars($k); ?>]"
                                                    <?php echo ($perm_staff[$k] ?? 1) ? 'checked' : ''; ?>>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    </table>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>Save Page Access
                                </button>
                                <small class="text-muted ms-2">Tip: users may need logout/login for changes to apply.</small>
                                </form>
                            </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($is_admin): ?>
                            <!-- System Settings -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0"><i class="bi bi-gear me-2"></i>System Settings</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="system_settings">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Company Name</label>
                                                    <input type="text" name="company_name" class="form-control" placeholder="Your Company Name">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Currency Symbol</label>
                                                    <select name="currency" class="form-select">
                                                        <option value="$">PH (₱)</option>
                                                        <option value="$">USD ($)</option>
                                                        <option value="€">EUR (€)</option>
                                                        <option value="£">GBP (£)</option>
                                                        <option value="¥">JPY (¥)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="mb-3">
                                                    <label class="form-label">Low Stock Threshold</label>
                                                    <input type="number" name="low_stock_threshold" class="form-control" value="10" min="1">
                                                    <small class="text-muted">Default: 10 units</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">System Notification Email</label>
                                            <input type="email" name="notification_email" class="form-control" placeholder="alerts@yourcompany.com">
                                            <small class="text-muted">For low stock and system alerts</small>
                                        </div>
                                        <button type="submit" class="btn btn-success">Save System Settings</button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Local Bootstrap JS -->
    <script src="../assets/js/bootstrap.bundle.min.js"></script>

    <script>
    // Sidebar Toggle
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggleNav = document.getElementById('sidebarToggleNav');
        const sidebarToggleInner = document.getElementById('sidebarToggleInner');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        
        // Function to toggle sidebar
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            updateIcons();
        }
        
        // Function to close sidebar (for mobile)
        function closeSidebar() {
            if (window.innerWidth <= 768 && !sidebar.classList.contains('collapsed')) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                updateIcons();
            }
        }
        
        // Function to update all toggle icons
        function updateIcons() {
            const icons = document.querySelectorAll('.sidebar-toggle i');
            icons.forEach(icon => {
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('bi-list');
                    icon.classList.add('bi-chevron-right');
                } else {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-list');
                }
            });
        }
        
        // Navbar hamburger button click
        if (sidebarToggleNav && sidebar && mainContent) {
            sidebarToggleNav.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        // Sidebar inner hamburger button click
        if (sidebarToggleInner && sidebar && mainContent) {
            sidebarToggleInner.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        // Close sidebar when clicking outside on mobile
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                closeSidebar();
            });
        }
        
        // Close sidebar when clicking on main content on mobile
        mainContent.addEventListener('click', function() {
            closeSidebar();
        });
        
        // Don't close when clicking inside sidebar
        sidebar.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Auto-collapse on mobile
        function handleResize() {
            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
            updateIcons();
        }
        
        // Initial check
        handleResize();
        
        // Listen for resize events
        window.addEventListener('resize', handleResize);
        
        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        const resetModal = document.getElementById('resetPassModal');
            if (resetModal) {
                resetModal.addEventListener('show.bs.modal', function (event) {
                    const btn = event.relatedTarget;
                    const userId = btn.getAttribute('data-user-id');
                    const username = btn.getAttribute('data-username');

                    document.getElementById('reset_target_user_id').value = userId;
                    document.getElementById('reset_username_label').textContent = username;
                });
            }
    });
    </script>
<?php if ($is_admin): ?>
<div class="modal fade" id="resetPassModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Reset Password</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <input type="hidden" name="action" value="reset_user_password">
          <input type="hidden" name="target_user_id" id="reset_target_user_id">

          <div class="alert alert-warning">
            Reset password for: <strong id="reset_username_label"></strong>
          </div>

          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="reset_password" class="form-control" minlength="6" required>
            <small class="text-muted">Minimum 6 characters</small>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

</body>
</html>