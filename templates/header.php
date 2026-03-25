<?php
/**
 * Header Template
 */
if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__) . '/includes/init.php';
}

require_once APP_ROOT . '/includes/navigation.php';

$currentUser = getCurrentUser();
$currentPage = basename($_SERVER['PHP_SELF']);
$currentUserName = getUserDisplayName($currentUser);
$currentUserRole = getUserRoleLabel($currentUser);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? escape($pageTitle) . ' - ' : ''; ?><?php echo APP_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo getBaseUrl(); ?>/assets/css/style.css">

    <?php if (isset($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link rel="stylesheet" href="<?php echo escape($css); ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <script src="<?php echo getBaseUrl(); ?>/assets/js/sidebar.js" defer></script>
</head>
<body>
<?php if (isLoggedIn()): ?>
<?php
$settingsUrl = file_exists(APP_ROOT . '/settings.php')
    ? (getBaseUrl() . '/settings.php')
    : (getBaseUrl() . '/permissions.php?tab=settings');

$sidebarMenu = NavigationHelper::renderNavigation();
?>

<div class="app-shell d-flex">
    <aside class="app-sidebar d-none d-lg-flex flex-column" role="complementary">
        <div class="sidebar-brand">
            <a href="<?php echo getBaseUrl(); ?>/index.php" aria-label="Go to Dashboard">
                <i class="bi bi-cpu-fill" aria-hidden="true"></i>
                <span><?php echo APP_NAME; ?></span>
            </a>
        </div>
        <div class="sidebar-scroll">
            <?php echo $sidebarMenu; ?>
        </div>

        <!-- User Info Section -->
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user__avatar">
                    <i class="bi bi-person-circle" aria-hidden="true"></i>
                </div>
                <div class="sidebar-user__info">
                    <div class="sidebar-user__name">
                        <?php echo escape($currentUserName); ?>
                    </div>
                    <div class="sidebar-user__role">
                        <?php echo escape($currentUserRole); ?>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <div class="app-content flex-grow-1">
        <header class="app-topbar d-flex align-items-center justify-content-between" role="banner">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-secondary d-lg-none"
                        type="button"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#mobileSidebar"
                        aria-controls="mobileSidebar"
                        aria-label="Toggle navigation menu">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="topbar-breadcrumb">
                    <h1 class="topbar-title"><?php echo isset($pageTitle) ? escape($pageTitle) : 'Dashboard'; ?></h1>
                </div>
            </div>

            <div class="d-flex align-items-center gap-2">
                <!-- Quick Actions -->
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            aria-label="Quick actions">
                        <i class="bi bi-plus-circle" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?php echo getBaseUrl(); ?>/product_form.php?action=add"><i class="bi bi-box"></i> Add Product</a></li>
                        <li><a class="dropdown-item" href="<?php echo getBaseUrl(); ?>/customers.php?action=add"><i class="bi bi-person-plus"></i> Add Customer</a></li>
                        <li><a class="dropdown-item" href="<?php echo getBaseUrl(); ?>/pos.php"><i class="bi bi-cart"></i> New Sale</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo getBaseUrl(); ?>/purchase_orders.php?action=add"><i class="bi bi-file-earmark-plus"></i> New Purchase Order</a></li>
                    </ul>
                </div>

                <!-- User Menu -->
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-2"
                            data-bs-toggle="dropdown"
                            type="button"
                            aria-expanded="false"
                            aria-label="User menu">
                        <div class="user-avatar-sm">
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                        </div>
                        <span class="d-none d-md-inline">
                            <?php echo escape($currentUserName); ?>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?php echo getBaseUrl(); ?>/profile.php"><i class="bi bi-person"></i> Profile</a></li>
                        <li><a class="dropdown-item" href="<?php echo $settingsUrl; ?>"><i class="bi bi-gear"></i> Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo getBaseUrl(); ?>/logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Mobile Sidebar -->
        <div class="offcanvas offcanvas-start d-lg-none"
             tabindex="-1"
             id="mobileSidebar"
             aria-labelledby="mobileSidebarLabel">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="mobileSidebarLabel">
                    <i class="bi bi-cpu-fill" aria-hidden="true"></i>
                    <?php echo APP_NAME; ?>
                </h5>
                <button type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="offcanvas"
                        aria-label="Close navigation"></button>
            </div>
            <div class="offcanvas-body p-0">
                <div class="sidebar-scroll">
                    <?php echo $sidebarMenu; ?>
                </div>

                <!-- Mobile User Info -->
                <div class="sidebar-footer sidebar-footer--mobile">
                    <div class="sidebar-user">
                        <div class="sidebar-user__avatar">
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                        </div>
                        <div class="sidebar-user__info">
                            <div class="sidebar-user__name">
                                <?php echo escape($currentUserName); ?>
                            </div>
                            <div class="sidebar-user__role">
                                <?php echo escape($currentUserRole); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
<?php endif; ?>

<?php
$flash = getFlashMessage();
if ($flash):
?>
<div class="<?php echo isLoggedIn() ? 'container-fluid px-3 mt-3' : 'container mt-3'; ?>">
    <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
        <?php echo escape($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<main class="<?php echo isLoggedIn() ? 'container-fluid py-3' : ''; ?>">
