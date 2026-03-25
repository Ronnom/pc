<?php
/**
 * User Management Page
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('users.view');

$pageTitle = 'User Management';
$usersModule = new UsersModule();
$db = getDB();
$usersHasRoleId = tableColumnExists('users', 'role_id');
$usersHasFullName = tableColumnExists('users', 'full_name');

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'edit' && $id) {
            $firstName = sanitize($_POST['first_name'] ?? '');
            $lastName = sanitize($_POST['last_name'] ?? '');
            $data = [
                'email' => sanitize($_POST['email'] ?? '')
            ];

            if ($usersHasFullName) {
                $data['full_name'] = trim($firstName . ' ' . $lastName);
            } else {
                $data['first_name'] = $firstName;
                $data['last_name'] = $lastName;
            }

            if ($usersHasRoleId) {
                $data['role_id'] = (int)($_POST['role_id'] ?? 0);
            } elseif (tableColumnExists('users', 'is_admin')) {
                $data['is_admin'] = isset($_POST['is_admin']) ? 1 : 0;
            }
            
            if (!empty($_POST['password'])) {
                $passwordErrors = validatePasswordStrength($_POST['password']);
                if (!empty($passwordErrors)) {
                    throw new Exception(implode(' ', $passwordErrors));
                }
                $data['password'] = $_POST['password'];
            }
            
            $usersModule->updateUser($id, $data);
            setFlashMessage('success', 'User updated successfully.');
            redirect(getBaseUrl() . "/user_management.php?view={$id}");
        } elseif ($action === 'delete' && $id) {
            $usersModule->deleteUser($id);
            setFlashMessage('success', 'User deleted successfully.');
            redirect(getBaseUrl() . '/user_management.php');
        } elseif ($action === 'toggle_status' && $id) {
            $user = $usersModule->getUser($id);
            $newStatus = $user['is_active'] ? 0 : 1;
            $usersModule->updateUser($id, ['is_active' => $newStatus]);
            setFlashMessage('success', 'User status updated successfully.');
            redirect(getBaseUrl() . '/user_management.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get data for display
$user = null;
$userLogs = [];
if ($id) {
    $user = $usersModule->getUser($id);
    if ($user) {
        // Get user activity logs
        if (_logTableExists('user_logs')) {
            $userLogs = $db->fetchAll(
                "SELECT * FROM user_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
                [$id]
            );
        } elseif (_logTableExists('activity_logs')) {
            $userLogs = $db->fetchAll(
                "SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50",
                [$id]
            );
        } else {
            $userLogs = [];
        }
    }
}

// Pagination
$currentPage = (int)($_GET['page'] ?? 1);
$filters = [
    'search' => $_GET['search'] ?? '',
    'role_id' => $usersHasRoleId ? ($_GET['role_id'] ?? '') : ''
];

$pagination = getPagination($currentPage, 0);
$users = $usersModule->getUsers($filters, ITEMS_PER_PAGE, $pagination['offset']);
$roles = $usersModule->getRoles();

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">User Management</h1>
            <?php if (hasPermission('users.create')): ?>
            <a href="<?php echo getBaseUrl(); ?>/register.php" class="btn btn-primary">
                <i class="bi bi-person-plus"></i> Register New User
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-6">
                <input type="text" class="form-control" name="search" placeholder="Search users..." value="<?php echo escape($filters['search']); ?>">
            </div>
            <div class="col-md-4">
                <?php if ($usersHasRoleId && !empty($roles)): ?>
                <select class="form-select" name="role_id">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $role): ?>
                    <option value="<?php echo $role['id']; ?>" <?php echo $filters['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                        <?php echo escape($role['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text" class="form-control" value="Role filter unavailable in current schema" disabled>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-search"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($users)): ?>
            <p class="text-muted text-center mb-0">No users found</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $usr): ?>
                        <tr>
                            <td><?php echo escape($usr['username']); ?></td>
                            <td><?php echo escape(getUserDisplayName($usr)); ?></td>
                            <td><?php echo escape($usr['email']); ?></td>
                            <td><?php echo escape(getUserRoleLabel($usr, '-')); ?></td>
                            <td><?php echo $usr['is_active'] ? getStatusBadge('active') : getStatusBadge('inactive'); ?></td>
                            <td><?php echo !empty($usr['last_login']) ? formatDateTime($usr['last_login']) : 'Never'; ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/user_management.php?view=<?php echo $usr['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (hasPermission('users.edit')): ?>
                                <a href="<?php echo getBaseUrl(); ?>/user_management.php?action=edit&id=<?php echo $usr['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'view' && $user): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">User Details: <?php echo escape(getUserDisplayName($user)); ?></h1>
            <div>
                <?php if (hasPermission('users.edit')): ?>
                <a href="<?php echo getBaseUrl(); ?>/user_management.php?action=edit&id=<?php echo $user['id']; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
                <a href="<?php echo getBaseUrl(); ?>/user_management.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">User Information</div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="150">Username</th>
                        <td><?php echo escape($user['username']); ?></td>
                    </tr>
                    <tr>
                        <th>Email</th>
                        <td><?php echo escape($user['email']); ?></td>
                    </tr>
                    <tr>
                        <th>Name</th>
                        <td><?php echo escape(getUserDisplayName($user)); ?></td>
                    </tr>
                    <tr>
                        <th>Role</th>
                        <td><?php echo escape(getUserRoleLabel($user, '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo $user['is_active'] ? getStatusBadge('active') : getStatusBadge('inactive'); ?></td>
                    </tr>
                    <tr>
                        <th>Last Login</th>
                        <td><?php echo !empty($user['last_login']) ? formatDateTime($user['last_login']) : 'Never'; ?></td>
                    </tr>
                    <tr>
                        <th>Created</th>
                        <td><?php echo formatDateTime($user['created_at']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">Quick Actions</div>
            <div class="card-body">
                <?php if (hasPermission('users.edit')): ?>
                <form method="POST" action="" class="mb-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <button type="submit" class="btn btn-<?php echo $user['is_active'] ? 'warning' : 'success'; ?> w-100 mb-2">
                        <i class="bi bi-<?php echo $user['is_active'] ? 'x-circle' : 'check-circle'; ?>"></i>
                        <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?> User
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Activity Logs -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Activity Logs</h5>
            </div>
            <div class="card-body">
                <?php if (empty($userLogs)): ?>
                    <p class="text-muted text-center mb-0">No activity logs found</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Action</th>
                                    <th>Module</th>
                                    <th>Description</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userLogs as $log): ?>
                                <tr>
                                    <td><?php echo formatDateTime($log['created_at']); ?></td>
                                    <td><?php echo escape($log['action']); ?></td>
                                    <td><?php echo escape($log['module']); ?></td>
                                    <td><?php echo escape($log['description'] ?? '-'); ?></td>
                                    <td><?php echo escape($log['ip_address'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'edit' && $user): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Edit User: <?php echo escape(getUserDisplayName($user)); ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" value="<?php echo escape($user['username']); ?>" disabled>
                            <small class="form-text text-muted">Username cannot be changed</small>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?php echo escape($user['email']); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo escape($user['first_name'] ?? strtok((string)($user['full_name'] ?? ''), ' ')); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo escape($user['last_name'] ?? trim((string)preg_replace('/^\S+\s*/', '', (string)($user['full_name'] ?? '')))); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <?php if ($usersHasRoleId && !empty($roles)): ?>
                        <div class="col-md-6 mb-3">
                            <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="role_id" name="role_id" required>
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php echo $user['role_id'] == $role['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape($role['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php elseif (tableColumnExists('users', 'is_admin')): ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label d-block">Access Level</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" value="1" <?php echo !empty($user['is_admin']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_admin">Administrator</label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">New Password (leave blank to keep current)</label>
                        <input type="password" class="form-control" id="password" name="password">
                        <small class="form-text text-muted">If provided, password must meet strength requirements</small>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo getBaseUrl(); ?>/user_management.php?view=<?php echo $user['id']; ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>

