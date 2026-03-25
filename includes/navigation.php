<?php
/**
 * Navigation Helper Class
 * Handles navigation rendering and state management
 */

if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

class NavigationHelper {
    private static $config = null;
    private static $currentPage = null;

    /**
     * Load navigation configuration
     */
    private static function loadConfig() {
        if (self::$config === null) {
            self::$config = require APP_ROOT . '/config/navigation.php';
        }
        return self::$config;
    }

    /**
     * Get current page
     */
    private static function getCurrentPage() {
        if (self::$currentPage === null) {
            self::$currentPage = basename($_SERVER['PHP_SELF']);
        }
        return self::$currentPage;
    }

    /**
     * Check if user has permission for navigation item
     */
    private static function hasPermission($permission) {
        if (function_exists('isAdmin') && isAdmin()) {
            return true;
        }

        if ($permission === null) {
            return true; // No permission required
        }

        if (!function_exists('hasPermission')) {
            return false;
        }

        if (is_array($permission)) {
            foreach ($permission as $perm) {
                if (hasPermission($perm)) {
                    return true;
                }
            }
            return false;
        }

        return hasPermission($permission);
    }

    /**
     * Check if navigation item is active
     */
    private static function isActive($item) {
        $currentPage = self::getCurrentPage();

        // Check direct active pages
        if (isset($item['active_pages']) && in_array($currentPage, $item['active_pages'], true)) {
            return true;
        }

        // Check children for active state
        if (isset($item['children'])) {
            foreach ($item['children'] as $child) {
                if (isset($child['active_pages']) && in_array($currentPage, $child['active_pages'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Render navigation menu
     */
    public static function renderNavigation() {
        $config = self::loadConfig();
        $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $isAdmin = function_exists('isAdmin') ? isAdmin() : false;
        $isTechnician = function_exists('isTechnician') ? isTechnician() : false;

        ob_start();
        ?>

        <nav class="sidebar-menu" aria-label="Primary navigation" role="navigation">
            <?php if ($isTechnician && !$isAdmin): ?>
                <?php
                $repairsActive = in_array(self::getCurrentPage(), ['repairs.php'], true);
                ?>
                <a class="sidebar-item sidebar-item--main <?php echo $repairsActive ? 'sidebar-item--active' : ''; ?>"
                   href="<?php echo getBaseUrl() . '/repairs.php'; ?>"
                   aria-current="<?php echo $repairsActive ? 'page' : 'false'; ?>">
                    <i class="bi bi-tools" aria-hidden="true"></i>
                    <span class="sidebar-item__text">Service & Repairs</span>
                </a>
            <?php else: ?>
            <!-- Dashboard Link -->
            <?php
            $dashboard = $config['dashboard'];
            $dashboardActive = self::isActive($dashboard);
            ?>
            <a class="sidebar-item sidebar-item--main <?php echo $dashboardActive ? 'sidebar-item--active' : ''; ?>"
               href="<?php echo getBaseUrl() . $dashboard['href']; ?>"
               aria-current="<?php echo $dashboardActive ? 'page' : 'false'; ?>">
                <i class="bi <?php echo $dashboard['icon']; ?>" aria-hidden="true"></i>
                <span class="sidebar-item__text"><?php echo escape($dashboard['label']); ?></span>
            </a>

            <!-- Navigation Groups -->
            <?php foreach ($config['groups'] as $groupId => $group): ?>
                <div class="sidebar-group" data-group="<?php echo $groupId; ?>">
                    <p class="sidebar-group__label"><?php echo escape($group['label']); ?></p>

                    <?php foreach ($group['items'] as $itemId => $item): ?>
                        <?php if (!self::hasPermission($item['permission'])) continue; ?>

                        <?php
                        $itemActive = self::isActive($item);
                        $hasChildren = !empty($item['children']);
                        $itemIdAttr = "sidebar-{$groupId}-{$itemId}";
                        ?>

                        <div class="sidebar-section <?php echo $itemActive ? 'sidebar-section--active sidebar-section--open' : ''; ?>"
                             data-section="<?php echo $itemIdAttr; ?>">

                            <?php if ($hasChildren): ?>
                                <button type="button"
                                        class="sidebar-item sidebar-item--parent"
                                        aria-expanded="<?php echo $itemActive ? 'true' : 'false'; ?>"
                                        aria-controls="<?php echo $itemIdAttr; ?>-children"
                                        data-section-toggle>
                                    <span class="sidebar-item__main">
                                        <i class="bi <?php echo $item['icon']; ?>" aria-hidden="true"></i>
                                        <span class="sidebar-item__text"><?php echo escape($item['label']); ?></span>
                                    </span>
                                    <i class="bi bi-chevron-down sidebar-item__chevron" aria-hidden="true"></i>
                                </button>

                                <div id="<?php echo $itemIdAttr; ?>-children"
                                     class="sidebar-children <?php echo $itemActive ? 'sidebar-children--expanded' : ''; ?>">
                                    <ul class="sidebar-children__list" role="list">
                                        <?php foreach ($item['children'] as $child): ?>
                                            <li>
                                                <?php
                                                $childActive = isset($child['active_pages']) && in_array(self::getCurrentPage(), $child['active_pages'], true);
                                                ?>
                                                <a class="sidebar-item sidebar-item--child <?php echo $childActive ? 'sidebar-item--active' : ''; ?>"
                                                   href="<?php echo getBaseUrl() . $child['href']; ?>"
                                                   aria-current="<?php echo $childActive ? 'page' : 'false'; ?>">
                                                    <i class="bi <?php echo $child['icon']; ?>" aria-hidden="true"></i>
                                                    <span class="sidebar-item__text"><?php echo escape($child['label']); ?></span>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <a class="sidebar-item sidebar-item--main <?php echo $itemActive ? 'sidebar-item--active' : ''; ?>"
                                   href="<?php echo getBaseUrl() . ($item['href'] ?? '#'); ?>"
                                   aria-current="<?php echo $itemActive ? 'page' : 'false'; ?>">
                                    <i class="bi <?php echo $item['icon']; ?>" aria-hidden="true"></i>
                                    <span class="sidebar-item__text"><?php echo escape($item['label']); ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </nav>

        <?php
        return ob_get_clean();
    }

    /**
     * Get navigation configuration for external use
     */
    public static function getConfig() {
        return self::loadConfig();
    }

    /**
     * Check if current page is active in navigation
     */
    public static function isCurrentPageActive() {
        $config = self::loadConfig();
        $currentPage = self::getCurrentPage();

        // Check dashboard
        if (in_array($currentPage, $config['dashboard']['active_pages'], true)) {
            return true;
        }

        // Check all navigation items
        foreach ($config['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if (self::isActive($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
