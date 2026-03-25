<?php
/**
 * User Profile Page
 */

require_once 'includes/init.php';
requireLogin();

$pageTitle = 'My Profile';
$error = '';
$success = '';

$usersModule = new UsersModule();
$user = getCurrentUser();
$usersHasFullName = tableColumnExists('users', 'full_name');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
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
        
        if (empty($firstName) || empty($lastName) || empty($data['email'])) {
            $error = 'Please fill in all required fields.';
        } elseif (!isValidEmail($data['email'])) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $usersModule->updateUser(getCurrentUserId(), $data);
                setFlashMessage('success', 'Profile updated successfully.');
                redirect(getBaseUrl() . '/profile.php');
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'Please fill in all password fields.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } else {
            try {
                changePassword(getCurrentUserId(), $currentPassword, $newPassword);
                setFlashMessage('success', 'Password changed successfully.');
                redirect(getBaseUrl() . '/profile.php');
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// Refresh user data
$user = getCurrentUser();

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">My Profile</h1>
        <p class="text-muted">Manage your account settings</p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo escape($error); ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Information -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person"></i> Profile Information</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_profile">
                    
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
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="<?php echo escape(getUserRoleLabel($user)); ?>" disabled>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Last Login</label>
                            <input type="text" class="form-control" value="<?php echo $user['last_login'] ? formatDateTime($user['last_login']) : 'Never'; ?>" disabled>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-shield-lock"></i> Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="changePasswordForm">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                            <button class="btn btn-outline-secondary" type="button" id="toggleCurrentPassword">
                                <i class="bi bi-eye" id="toggleCurrentPasswordIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                            <button class="btn btn-outline-secondary" type="button" id="toggleNewPassword">
                                <i class="bi bi-eye" id="toggleNewPasswordIcon"></i>
                            </button>
                        </div>
                        <small class="form-text text-muted">
                            Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters and contain:
                            <?php if (PASSWORD_REQUIRE_UPPERCASE): ?>uppercase, <?php endif; ?>
                            <?php if (PASSWORD_REQUIRE_LOWERCASE): ?>lowercase, <?php endif; ?>
                            <?php if (PASSWORD_REQUIRE_NUMBER): ?>number, <?php endif; ?>
                            <?php if (PASSWORD_REQUIRE_SPECIAL): ?>special character<?php endif; ?>
                        </small>
                        <small class="form-text text-danger">You cannot reuse your last <?php echo PASSWORD_HISTORY_COUNT; ?> passwords.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle"></i> Account Information</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th>Account Created</th>
                        <td><?php echo formatDateTime($user['created_at']); ?></td>
                    </tr>
                    <tr>
                        <th>Last Updated</th>
                        <td><?php echo formatDateTime($user['updated_at']); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo $user['is_active'] ? getStatusBadge('active') : getStatusBadge('inactive'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    function setupToggle(buttonId, inputId, iconId) {
        const button = document.getElementById(buttonId);
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        
        if (button && input && icon) {
            button.addEventListener('click', function() {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                
                if (type === 'password') {
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                } else {
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
            });
        }
    }
    
    setupToggle('toggleCurrentPassword', 'current_password', 'toggleCurrentPasswordIcon');
    setupToggle('toggleNewPassword', 'new_password', 'toggleNewPasswordIcon');
    
    // Password match validation
    const changePasswordForm = document.getElementById('changePasswordForm');
    if (changePasswordForm) {
        changePasswordForm.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match!');
                return false;
            }
        });
    }
});
</script>

<?php include 'templates/footer.php'; ?>

