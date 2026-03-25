<?php
/**
 * Login Page
 * Enhanced with remember me and password visibility toggle
 */

require_once 'includes/init.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(getBaseUrl() . '/index.php');
}

$error = '';
$pageTitle = 'Login';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if (login($username, $password, $rememberMe)) {
            redirect(getBaseUrl() . '/index.php');
        } else {
            $error = 'Invalid username or password.';
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
                        <i class="bi bi-cpu" style="font-size: 3rem; color: var(--primary-color);"></i>
                        <h2 class="mt-3"><?php echo APP_NAME; ?></h2>
                        <p class="text-muted">Sign in to your account</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo escape($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" id="loginForm">
                        <?php echo csrfField(); ?>
                        
                        <div class="mb-3">
                            <label for="username" class="form-label">Username or Email</label>
                            <input type="text" class="form-control" id="username" name="username" required autofocus value="<?php echo escape($_POST['username'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="password" name="password" required>
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="bi bi-eye" id="togglePasswordIcon"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember_me" name="remember_me" value="1">
                            <label class="form-check-label" for="remember_me">
                                Remember me for 30 days
                            </label>
                        </div>
                        
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-box-arrow-in-right"></i> Sign In
                            </button>
                        </div>
                        
                        <div class="text-center">
                            <a href="<?php echo getBaseUrl(); ?>/forgot_password.php" class="text-decoration-none">
                                Forgot your password?
                            </a>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4">
                        <small class="text-muted">
                            Default credentials: admin / admin123<br>
                            <strong>Change immediately after first login!</strong>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
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
});
</script>

<?php include 'templates/footer.php'; ?>
