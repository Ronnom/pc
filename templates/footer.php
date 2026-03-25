<?php
/**
 * Footer Template
 */
?>
    </main>
    
    <!-- Footer -->
    <?php if (isLoggedIn()): ?>
    <footer class="bg-light mt-5 py-3">
        <div class="container-fluid text-center text-muted">
            <small>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?></small>
        </div>
    </footer>
    </div>
</div>
    <?php endif; ?>
    
    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery (optional, for easier DOM manipulation) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Custom JS -->
    <script src="<?php echo getBaseUrl(); ?>/assets/js/main.js"></script>
    
    <?php if (isset($additionalJS)): ?>
        <?php foreach ($additionalJS as $js): ?>
            <script src="<?php echo escape($js); ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>

