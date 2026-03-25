<?php
/**
 * Reset Password Page
 */

require_once 'includes/init.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(getBaseUrl() . '/index.php');
}

$pageTitle = 'Reset Password';
$error = '';
$token = $_GET['token'] ?? '';

if (empty($token)) {
    setFlashMessage('error', 'Invalid reset token.');
    redirect(getBaseUrl() . '/login.php');
}

// Verify token
$resetToken = verifyPasswordResetToken($token);
if (!$resetToken) {
    setFlashMessage('error', 'Invalid or expired reset token.');
    redirect(getBaseUrl() . '/login.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = 'Please fill in all fields.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            resetPassword($token, $newPassword);
            setFlashMessage('success', 'Password has been reset successfully. Please login with your new password.');
            redirect(getBaseUrl() . '/login.php');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

include 'templates/header.php';
?>

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-shield-lock" style="font-size: 3rem; color: var(--primary-color);"></i>
                        <h2 class="mt-3">Reset Password</h2>
                        <p class="text-muted">Enter your new password</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo escape($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="resetForm">
                        <?php echo csrfField(); ?>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
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
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> Reset Password
                            </button>
                        </div>
                        
                        <div class="text-center">
                            <a href="<?php echo getBaseUrl(); ?>/login.php" class="text-decoration-none">
                                <i class="bi bi-arrow-left"></i> Back to Login
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('new_password');
    const toggleIcon = document.getElementById('togglePasswordIcon');
    
    togglePassword.addEventListener('click', function() {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        // Toggle icon
        if (type === 'password') {
            toggleIcon.classList.remove('bi-eye-slash');
            toggleIcon.classList.add('bi-eye');
        } else {
            toggleIcon.classList.remove('bi-eye');
            toggleIcon.classList.add('bi-eye-slash');
        }
    });
    
    // Password match validation
    const confirmPassword = document.getElementById('confirm_password');
    const resetForm = document.getElementById('resetForm');
    
    resetForm.addEventListener('submit', function(e) {
        if (passwordInput.value !== confirmPassword.value) {
            e.preventDefault();
            alert('Passwords do not match!');
            return false;
        }
    });
});
</script>

<?php include 'templates/footer.php'; ?>

