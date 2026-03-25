<?php
/**
 * User Registration Page (Admin Only)
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('users.create');

$pageTitle = 'Register New User';
$error = '';
$success = '';

$usersModule = new UsersModule();
$roles = $usersModule->getRoles();
$usersHasRoleId = tableColumnExists('users', 'role_id');
$usersHasFullName = tableColumnExists('users', 'full_name');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    $data = [
        'username' => sanitize($_POST['username'] ?? ''),
        'email' => sanitize($_POST['email'] ?? ''),
        'password' => $_POST['password'] ?? '',
        'first_name' => sanitize($_POST['first_name'] ?? ''),
        'last_name' => sanitize($_POST['last_name'] ?? '')
    ];

    if ($usersHasRoleId) {
        $data['role_id'] = (int)($_POST['role_id'] ?? 0);
    }
    if ($usersHasFullName) {
        $data['full_name'] = trim($data['first_name'] . ' ' . $data['last_name']);
    }
    if (tableColumnExists('users', 'is_admin')) {
        $data['is_admin'] = isset($_POST['is_admin']) ? 1 : 0;
    }
    
    // Validation
    if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
        $error = 'Please fill in all required fields.';
    } elseif (!isValidEmail($data['email'])) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($data['first_name']) || empty($data['last_name'])) {
        $error = 'Please enter first and last name.';
    } elseif ($usersHasRoleId && ($data['role_id'] ?? 0) <= 0) {
        $error = 'Please select a role.';
    } else {
        // Validate password strength
        $passwordErrors = validatePasswordStrength($data['password']);
        if (!empty($passwordErrors)) {
            $error = implode(' ', $passwordErrors);
        } else {
            try {
                $userId = $usersModule->createUser($data);
                setFlashMessage('success', 'User registered successfully.');
                redirect(getBaseUrl() . '/user_management.php?view=' . $userId);
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Register New User</h1>
        <p class="text-muted">Create a new user account</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo escape($error); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="" id="registerForm">
                    <?php echo csrfField(); ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required value="<?php echo escape($_POST['username'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?php echo escape($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo escape($_POST['first_name'] ?? ''); ?>">
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo escape($_POST['last_name'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password" required>
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                            <small class="form-text text-muted">
                                Password must be at least <?php echo PASSWORD_MIN_LENGTH; ?> characters and contain:
                                <?php if (PASSWORD_REQUIRE_UPPERCASE): ?>uppercase, <?php endif; ?>
                                <?php if (PASSWORD_REQUIRE_LOWERCASE): ?>lowercase, <?php endif; ?>
                                <?php if (PASSWORD_REQUIRE_NUMBER): ?>number, <?php endif; ?>
                                <?php if (PASSWORD_REQUIRE_SPECIAL): ?>special character<?php endif; ?>
                            </small>
                            <div id="passwordStrength" class="mt-2"></div>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <?php if ($usersHasRoleId): ?>
                            <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                <option value="<?php echo $role['id']; ?>" <?php echo (isset($_POST['role_id']) && $_POST['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                    <?php echo escape($role['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php elseif (tableColumnExists('users', 'is_admin')): ?>
                            <label class="form-label d-block">Access Level</label>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="is_admin" name="is_admin" value="1" <?php echo isset($_POST['is_admin']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_admin">Administrator</label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo getBaseUrl(); ?>/user_management.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Register User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Password visibility toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    
    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        if (type === 'password') {
            toggleIcon.classList.remove('bi-eye-slash');
            toggleIcon.classList.add('bi-eye');
        } else {
            toggleIcon.classList.remove('bi-eye');
            toggleIcon.classList.add('bi-eye-slash');
        }
    });
    
    // Password strength indicator
    passwordInput.addEventListener('input', function() {
        const password = passwordInput.value;
        const strengthDiv = document.getElementById('passwordStrength');
        
        if (password.length === 0) {
            strengthDiv.innerHTML = '';
            return;
        }
        
        let strength = 0;
        let feedback = [];
        
        if (password.length >= <?php echo PASSWORD_MIN_LENGTH; ?>) strength++;
        else feedback.push('At least <?php echo PASSWORD_MIN_LENGTH; ?> characters');
        
        <?php if (PASSWORD_REQUIRE_UPPERCASE): ?>
        if (/[A-Z]/.test(password)) strength++;
        else feedback.push('Uppercase letter');
        <?php endif; ?>
        
        <?php if (PASSWORD_REQUIRE_LOWERCASE): ?>
        if (/[a-z]/.test(password)) strength++;
        else feedback.push('Lowercase letter');
        <?php endif; ?>
        
        <?php if (PASSWORD_REQUIRE_NUMBER): ?>
        if (/[0-9]/.test(password)) strength++;
        else feedback.push('Number');
        <?php endif; ?>
        
        <?php if (PASSWORD_REQUIRE_SPECIAL): ?>
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        else feedback.push('Special character');
        <?php endif; ?>
        
        const maxStrength = 1 + <?php echo (PASSWORD_REQUIRE_UPPERCASE ? 1 : 0) + (PASSWORD_REQUIRE_LOWERCASE ? 1 : 0) + (PASSWORD_REQUIRE_NUMBER ? 1 : 0) + (PASSWORD_REQUIRE_SPECIAL ? 1 : 0); ?>;
        const percentage = (strength / maxStrength) * 100;
        
        let color = 'danger';
        let text = 'Weak';
        if (percentage >= 75) {
            color = 'success';
            text = 'Strong';
        } else if (percentage >= 50) {
            color = 'warning';
            text = 'Medium';
        }
        
        strengthDiv.innerHTML = `
            <div class="progress" style="height: 5px;">
                <div class="progress-bar bg-${color}" role="progressbar" style="width: ${percentage}%"></div>
            </div>
            <small class="text-${color}">${text}</small>
            ${feedback.length > 0 ? '<br><small class="text-muted">Missing: ' + feedback.join(', ') + '</small>' : ''}
        `;
    });
});
</script>

<?php include 'templates/footer.php'; ?>

