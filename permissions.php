<?php
/**
 * Permissions & Roles Management Page
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('users.view'); // Admin only typically

$pageTitle = 'Roles & Permissions';
$usersModule = new UsersModule();
$db = getDB();

$action = $_GET['action'] ?? 'roles';
$roleId = $_GET['role_id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'assign_permissions' && $roleId) {
            $permissionIds = $_POST['permissions'] ?? [];
            
            // Delete existing permissions
            $db->delete('role_permissions', 'role_id = ?', [$roleId]);
            
            // Insert new permissions
            foreach ($permissionIds as $permissionId) {
                $db->insert('role_permissions', [
                    'role_id' => $roleId,
                    'permission_id' => (int)$permissionId
                ]);
            }
            
            setFlashMessage('success', 'Permissions updated successfully.');
            redirect(getBaseUrl() . "/permissions.php?action=edit_role&role_id={$roleId}");
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get data
$roles = $usersModule->getRoles();
$allPermissions = $usersModule->getPermissions();
$role = null;
$rolePermissions = [];

if ($roleId) {
    $role = $db->fetchOne("SELECT * FROM roles WHERE id = ?", [$roleId]);
    if ($role) {
        $rolePermissions = $usersModule->getRolePermissions($roleId);
        $rolePermissionIds = array_column($rolePermissions, 'id');
    } else {
        $rolePermissionIds = [];
    }
} else {
    $rolePermissionIds = [];
}

// Group permissions by module
$permissionsByModule = [];
foreach ($allPermissions as $permission) {
    $module = $permission['module'];
    if (!isset($permissionsByModule[$module])) {
        $permissionsByModule[$module] = [];
    }
    $permissionsByModule[$module][] = $permission;
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Roles & Permissions</h1>
        <p class="text-muted">Manage user roles and their permissions</p>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Roles</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($roles as $r): ?>
                <a href="<?php echo getBaseUrl(); ?>/permissions.php?action=edit_role&role_id=<?php echo $r['id']; ?>" 
                   class="list-group-item list-group-item-action <?php echo $roleId == $r['id'] ? 'active' : ''; ?>">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1"><?php echo escape($r['name']); ?></h6>
                        <?php echo (!isset($r['is_active']) || (int)$r['is_active'] === 1) ? getStatusBadge('active') : getStatusBadge('inactive'); ?>
                    </div>
                    <small class="text-muted"><?php echo escape($r['description'] ?? ''); ?></small>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-8">
        <?php if ($action === 'edit_role' && $role): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Edit Permissions: <?php echo escape($role['name']); ?></h5>
            </div>
            <div class="card-body">
                <form method="POST" action="<?php echo getBaseUrl(); ?>/permissions.php?action=assign_permissions&role_id=<?php echo (int)$role['id']; ?>">
                    <?php echo csrfField(); ?>
                    
                    <?php foreach ($permissionsByModule as $module => $permissions): ?>
                    <div class="mb-4">
                        <h6 class="text-uppercase text-muted mb-3">
                            <i class="bi bi-folder"></i> <?php echo escape(ucfirst($module)); ?>
                        </h6>
                        
                        <div class="row">
                            <?php foreach ($permissions as $permission): ?>
                            <div class="col-md-6 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" 
                                           name="permissions[]" 
                                           value="<?php echo $permission['id']; ?>" 
                                           id="perm_<?php echo $permission['id']; ?>"
                                           <?php echo in_array($permission['id'], $rolePermissionIds ?? []) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="perm_<?php echo $permission['id']; ?>">
                                        <?php echo escape($permission['name']); ?>
                                        <?php if ($permission['description']): ?>
                                        <br><small class="text-muted"><?php echo escape($permission['description']); ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="<?php echo getBaseUrl(); ?>/permissions.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Permissions</button>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-body text-center text-muted">
                <i class="bi bi-info-circle" style="font-size: 3rem;"></i>
                <p class="mt-3">Select a role from the list to manage its permissions</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

