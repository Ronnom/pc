<?php
/**
 * Forgot Password Page
 */

require_once 'includes/init.php';

// Redirect if already logged in
if (isLoggedIn()) {
    redirect(getBaseUrl() . '/index.php');
}

$pageTitle = 'Forgot Password';
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    $email = sanitize($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } elseif (!isValidEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $result = generatePasswordResetToken($email);
        
        if ($result) {
            // In production, send email here
            // For now, we'll show the reset link (remove in production!)
            $resetLink = getBaseUrl() . '/reset_password.php?token=' . $result['token'];
            
            $message = 'Password reset link has been generated. ';
            $message .= 'In production, this would be sent to your email. ';
            $message .= '<br><br><strong>Reset Link (for testing):</strong><br>';
            $message .= '<a href="' . $resetLink . '">' . $resetLink . '</a>';
        } else {
            // Don't reveal if email exists
            $message = 'If an account with that email exists, a password reset link has been sent.';
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
                        <i class="bi bi-key" style="font-size: 3rem; color: var(--primary-color);"></i>
                        <h2 class="mt-3">Forgot Password</h2>
                        <p class="text-muted">Enter your email to receive a password reset link</p>
                    </div>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo escape($error); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($message): ?>
                        <div class="alert alert-info"><?php echo $message; ?></div>
                    <?php else: ?>
                        <form method="POST" action="">
                            <?php echo csrfField(); ?>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" required autofocus>
                            </div>
                            
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-send"></i> Send Reset Link
                                </button>
                            </div>
                            
                            <div class="text-center">
                                <a href="<?php echo getBaseUrl(); ?>/login.php" class="text-decoration-none">
                                    <i class="bi bi-arrow-left"></i> Back to Login
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

