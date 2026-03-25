<?php
/**
 * Utility Functions
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not allowed');
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return CURRENCY_SYMBOL . number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date, $format = DATE_FORMAT) {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    return date($format, strtotime($date));
}

/**
 * Format datetime
 */
function formatDateTime($datetime, $format = DATETIME_FORMAT) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    return date($format, strtotime($datetime));
}

/**
 * Generate unique code/number
 */
function generateUniqueCode($prefix = '', $length = 8) {
    $random = strtoupper(substr(md5(uniqid(rand(), true)), 0, $length));
    return $prefix . $random;
}

/**
 * Generate transaction number
 */
function generateTransactionNumber() {
    return 'TXN-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
}

/**
 * Generate PO number
 */
function generatePONumber() {
    return 'PO-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
}

/**
 * Generate adjustment number
 */
function generateAdjustmentNumber() {
    return 'ADJ-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
}

/**
 * Generate return number
 */
function generateReturnNumber() {
    return 'RET-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
}

/**
 * Redirect to URL
 */
function redirect($url) {
    header("Location: " . $url);
    exit();
}

/**
 * Get current URL
 */
function getCurrentUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

/**
 * Get base URL
 */
function getBaseUrl() {
    return APP_URL;
}

/**
 * Flash message (store in session)
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

/**
 * Get and clear flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $message;
    }
    return null;
}

/**
 * Pagination helper
 */
function getPagination($currentPage, $totalItems, $itemsPerPage = ITEMS_PER_PAGE) {
    $totalPages = ceil($totalItems / $itemsPerPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $itemsPerPage;
    
    return [
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'total_items' => $totalItems,
        'items_per_page' => $itemsPerPage,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages
    ];
}

/**
 * Calculate tax amount
 */
function calculateTax($amount, $taxRate) {
    return round($amount * ($taxRate / 100), 2);
}

/**
 * Calculate discount amount
 */
function calculateDiscount($amount, $discountPercent) {
    return round($amount * ($discountPercent / 100), 2);
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status) {
    $badges = [
        'active' => 'success',
        'inactive' => 'secondary',
        'pending' => 'warning',
        'completed' => 'success',
        'cancelled' => 'danger',
        'refunded' => 'info',
        'draft' => 'secondary',
        'sent' => 'info',
        'confirmed' => 'primary',
        'received' => 'success',
        'approved' => 'success',
        'rejected' => 'danger'
    ];
    
    $class = $badges[strtolower($status)] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . ucfirst($status) . '</span>';
}

/**
 * Render pagination component
 */
function renderPagination($pagination, $baseUrl = '', $queryParams = []) {
    if ($pagination['total_pages'] <= 1) {
        return '';
    }

    $html = '<nav aria-label="Page navigation" class="d-flex justify-content-between align-items-center">';
    $html .= '<div class="pagination-info text-muted">';
    $html .= 'Showing ' . number_format(($pagination['current_page'] - 1) * $pagination['items_per_page'] + 1) . ' to ' . number_format(min($pagination['current_page'] * $pagination['items_per_page'], $pagination['total_items'])) . ' of ' . number_format($pagination['total_items']) . ' entries';
    $html .= '</div>';

    $html .= '<ul class="pagination pagination-sm mb-0">';

    // Previous button
    $prevDisabled = !$pagination['has_prev'] ? 'disabled' : '';
    $prevUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $pagination['current_page'] - 1]));
    $html .= '<li class="page-item ' . $prevDisabled . '">';
    $html .= '<a class="page-link" href="' . ($prevDisabled ? '#' : $prevUrl) . '" ' . ($prevDisabled ? 'tabindex="-1" aria-disabled="true"' : '') . '>';
    $html .= '<i class="bi bi-chevron-left"></i> Previous';
    $html .= '</a></li>';

    // Page numbers
    $startPage = max(1, $pagination['current_page'] - 2);
    $endPage = min($pagination['total_pages'], $pagination['current_page'] + 2);

    // Show first page if not in range
    if ($startPage > 1) {
        $url = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => 1]));
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">1</a></li>';
        if ($startPage > 2) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }

    // Page range
    for ($i = $startPage; $i <= $endPage; $i++) {
        $active = $i == $pagination['current_page'] ? 'active' : '';
        $url = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $i]));
        $html .= '<li class="page-item ' . $active . '">';
        $html .= '<a class="page-link" href="' . $url . '">' . $i . '</a>';
        $html .= '</li>';
    }

    // Show last page if not in range
    if ($endPage < $pagination['total_pages']) {
        if ($endPage < $pagination['total_pages'] - 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $url = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $pagination['total_pages']]));
        $html .= '<li class="page-item"><a class="page-link" href="' . $url . '">' . $pagination['total_pages'] . '</a></li>';
    }

    // Next button
    $nextDisabled = !$pagination['has_next'] ? 'disabled' : '';
    $nextUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['page' => $pagination['current_page'] + 1]));
    $html .= '<li class="page-item ' . $nextDisabled . '">';
    $html .= '<a class="page-link" href="' . ($nextDisabled ? '#' : $nextUrl) . '" ' . ($nextDisabled ? 'tabindex="-1" aria-disabled="true"' : '') . '>';
    $html .= 'Next <i class="bi bi-chevron-right"></i>';
    $html .= '</a></li>';

    $html .= '</ul></nav>';

    return $html;
}

/**
 * Render enhanced table with sorting and actions
 */
function renderTable($headers, $rows, $sortableColumns = [], $currentSort = '', $currentOrder = 'ASC', $baseUrl = '', $queryParams = []) {
    $html = '<div class="table-responsive">';
    $html .= '<table class="table table-hover">';

    // Table header
    $html .= '<thead>';
    $html .= '<tr>';
    foreach ($headers as $key => $header) {
        $headerLabel = $header;
        if (is_array($header)) {
            $headerLabel = $header['label'] ?? $header['title'] ?? reset($header);
        } elseif (is_object($header)) {
            $headerLabel = method_exists($header, '__toString') ? (string)$header : ($header->label ?? $header->title ?? '');
        }
        if (is_array($headerLabel) || is_object($headerLabel)) {
            $headerLabel = '';
        }
        $headerLabel = (string)$headerLabel;

        $sortable = in_array($key, $sortableColumns);
        $sortIcon = '';
        $sortUrl = '';

        if ($sortable) {
            $sortOrder = ($currentSort === $key && $currentOrder === 'ASC') ? 'DESC' : 'ASC';
            $sortIcon = $currentSort === $key ? ($currentOrder === 'ASC' ? ' ↑' : ' ↓') : '';
            $sortUrl = $baseUrl . '?' . http_build_query(array_merge($queryParams, ['order_by' => $key, 'order_dir' => $sortOrder]));
            $html .= '<th><a href="' . $sortUrl . '" class="text-decoration-none text-dark fw-semibold">' . $headerLabel . $sortIcon . '</a></th>';
        } else {
            $html .= '<th class="fw-semibold">' . $headerLabel . '</th>';
        }
    }
    $html .= '</tr>';
    $html .= '</thead>';

    // Table body
    $html .= '<tbody>';
    if (empty($rows)) {
        $colspan = count($headers);
        $html .= '<tr><td colspan="' . $colspan . '" class="text-center py-5 text-muted">';
        $html .= '<div class="empty-state">';
        $html .= '<i class="bi bi-table d-block mb-3"></i>';
        $html .= '<h5>No data found</h5>';
        $html .= '<p class="mb-0">There are no records to display at this time.</p>';
        $html .= '</div>';
        $html .= '</td></tr>';
    } else {
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $key => $header) {
                $value = $row[$key] ?? '';
                $html .= '<td>' . $value . '</td>';
            }
            $html .= '</tr>';
        }
    }
    $html .= '</tbody>';

    $html .= '</table>';
    $html .= '</div>';

    return $html;
}

/**
 * Render stat card component
 */
function renderStatCard($title, $value, $subtitle, $icon, $color = 'primary', $link = null, $trend = null) {
    $colorClasses = [
        'primary' => 'border-primary text-primary',
        'success' => 'border-success text-success',
        'danger' => 'border-danger text-danger',
        'warning' => 'border-warning text-warning',
        'info' => 'border-info text-info',
        'secondary' => 'border-secondary text-secondary'
    ];

    $bgClasses = [
        'primary' => 'bg-primary',
        'success' => 'bg-success',
        'danger' => 'bg-danger',
        'warning' => 'bg-warning',
        'info' => 'bg-info',
        'secondary' => 'bg-secondary'
    ];

    $cardClass = $colorClasses[$color] ?? $colorClasses['primary'];
    $iconBgClass = $bgClasses[$color] ?? $bgClasses['primary'];

    $cardTag = $link ? 'a href="' . $link . '" class="text-decoration-none"' : 'div';
    $cardClasses = 'col-md-6 col-lg-4 col-xl-3 mb-3';
    $innerClasses = 'card h-100 ' . ($link ? 'card-hover' : '') . ' ' . $cardClass;

    $html = '<div class="' . $cardClasses . '">';
    $html .= '<' . $cardTag . ' class="' . $innerClasses . '">';
    $html .= '<div class="card-body text-center">';

    // Icon
    $html .= '<div class="' . $iconBgClass . ' text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px;">';
    $html .= '<i class="' . $icon . ' fs-5"></i>';
    $html .= '</div>';

    // Value
    $html .= '<div class="display-6 fw-bold mb-1">' . $value . '</div>';

    // Title
    $html .= '<div class="fw-medium text-dark mb-1">' . $title . '</div>';

    // Subtitle
    $html .= '<div class="text-muted small">' . $subtitle . '</div>';

    // Trend (if provided)
    if ($trend) {
        $trendClass = $trend['direction'] === 'up' ? 'text-success' : ($trend['direction'] === 'down' ? 'text-danger' : 'text-muted');
        $trendIcon = $trend['direction'] === 'up' ? 'bi-arrow-up' : ($trend['direction'] === 'down' ? 'bi-arrow-down' : 'bi-dash');
        $html .= '<div class="mt-2 ' . $trendClass . '">';
        $html .= '<i class="bi ' . $trendIcon . ' me-1"></i>';
        $html .= '<small>' . $trend['value'] . '</small>';
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '</' . $cardTag . '>';
    $html .= '</div>';

    return $html;
}

/**
 * Check whether a database table exists (cached).
 */
function _logTableExists($tableName) {
    static $tableCache = [];
    $tableName = trim((string)$tableName);
    if ($tableName === '') {
        return false;
    }
    if (isset($tableCache[$tableName])) {
        return $tableCache[$tableName];
    }

    try {
        $db = getDB();
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$tableName]
        );
        $tableCache[$tableName] = !empty($row['cnt']);
    } catch (Exception $e) {
        $tableCache[$tableName] = false;
    }

    return $tableCache[$tableName];
}

/**
 * Check whether a table column exists (cached).
 */
function tableColumnExists($tableName, $columnName) {
    static $columnCache = [];

    $tableName = trim((string)$tableName);
    $columnName = trim((string)$columnName);
    if ($tableName === '' || $columnName === '') {
        return false;
    }

    $cacheKey = $tableName . '.' . $columnName;
    if (isset($columnCache[$cacheKey])) {
        return $columnCache[$cacheKey];
    }

    try {
        $db = getDB();
        $row = $db->fetchOne(
            "SELECT COUNT(*) AS cnt
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?",
            [$tableName, $columnName]
        );
        $columnCache[$cacheKey] = !empty($row['cnt']);
    } catch (Exception $e) {
        $columnCache[$cacheKey] = false;
    }

    return $columnCache[$cacheKey];
}

/**
 * Resolve runtime product sell-price column name.
 */
function getProductPriceColumnName() {
    if (tableColumnExists('products', 'sell_price')) {
        return 'sell_price';
    }
    return 'selling_price';
}

/**
 * Resolve runtime product low-stock threshold column name.
 * Returns null when the schema has no threshold column.
 */
function getProductThresholdColumnName() {
    if (tableColumnExists('products', 'min_stock_level')) {
        return 'min_stock_level';
    }
    if (tableColumnExists('products', 'reorder_level')) {
        return 'reorder_level';
    }
    return null;
}

/**
 * Read product price from a row regardless of active schema.
 */
function getProductPriceValue(array $product, $default = 0.0) {
    if (isset($product['sell_price'])) {
        return (float)$product['sell_price'];
    }
    if (isset($product['selling_price'])) {
        return (float)$product['selling_price'];
    }
    return (float)$default;
}

/**
 * Resolve threshold value for stock checks from a product row.
 */
function getProductThresholdValue(array $product, $fallback = 5) {
    if (isset($product['min_stock_level'])) {
        return (int)$product['min_stock_level'];
    }
    if (isset($product['reorder_level'])) {
        return (int)$product['reorder_level'];
    }
    return (int)$fallback;
}

/**
 * Check if a product row should be considered low stock.
 */
function isProductLowStock(array $product, $fallback = 5) {
    $stock = (int)($product['stock_quantity'] ?? 0);
    return $stock <= getProductThresholdValue($product, $fallback);
}

/**
 * Resolve display name for a user row across schema variants.
 */
function getUserDisplayName($user, $default = 'User') {
    if (!is_array($user) || empty($user)) {
        return $default;
    }

    $fullName = trim((string)($user['full_name'] ?? ''));
    if ($fullName !== '') {
        return $fullName;
    }

    $firstLast = trim(
        (string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? '')
    );
    if ($firstLast !== '') {
        return $firstLast;
    }

    if (!empty($user['username'])) {
        return (string)$user['username'];
    }

    return $default;
}

/**
 * Resolve user role label across schema variants.
 */
function getUserRoleLabel($user, $default = 'User') {
    if (!is_array($user) || empty($user)) {
        return $default;
    }

    if (!empty($user['role_name'])) {
        return (string)$user['role_name'];
    }
    if (!empty($user['role'])) {
        return (string)$user['role'];
    }
    if (isset($user['is_admin']) && (int)$user['is_admin'] === 1) {
        return 'Administrator';
    }

    return $default;
}

/**
 * Resolve the warranty table name for runtime compatibility.
 * Prefers `warranties` if present, otherwise falls back to `warranty`.
 * Returns null when neither table exists.
 */
function getWarrantyTableName() {
    if (_logTableExists('warranties')) {
        return 'warranties';
    }
    if (_logTableExists('warranty')) {
        return 'warranty';
    }
    return null;
}

/**
 * Log user activity.
 *
 * Supported signatures:
 * 1) logUserActivity($userId, $action, $module, $description[, $entityId[, $meta]])
 * 2) logUserActivity($action, $description[, $context[, $entityId]])
 */
function logUserActivity(...$args) {
    try {
        if (empty($args)) {
            return false;
        }

        $userId = 0;
        $action = 'activity';
        $module = 'system';
        $description = '';
        $entityId = null;
        $meta = null;

        // Legacy format: user_id first
        if (is_numeric($args[0])) {
            $userId = (int)$args[0];
            $action = (string)($args[1] ?? $action);
            $module = (string)($args[2] ?? $module);
            $description = (string)($args[3] ?? '');
            $entityId = isset($args[4]) && is_numeric($args[4]) ? (int)$args[4] : null;
            $meta = isset($args[5]) && is_array($args[5]) ? $args[5] : null;
        } else {
            // Newer format: action first
            $action = (string)$args[0];
            $description = (string)($args[1] ?? '');
            $context = isset($args[2]) && is_array($args[2]) ? $args[2] : [];
            $entityId = isset($args[3]) && is_numeric($args[3]) ? (int)$args[3] : null;

            if (isset($context['user_id']) && is_numeric($context['user_id'])) {
                $userId = (int)$context['user_id'];
            } elseif (function_exists('getCurrentUserId')) {
                $userId = (int)getCurrentUserId();
            }

            if (!empty($context['module'])) {
                $module = (string)$context['module'];
            } elseif (!empty($context['entity'])) {
                $module = (string)$context['entity'];
            }

            if ($entityId === null && isset($context['entity_id']) && is_numeric($context['entity_id'])) {
                $entityId = (int)$context['entity_id'];
            }

            $meta = !empty($context) ? $context : null;
        }

        // Both user_logs and activity_logs are user-linked; skip if user is unknown.
        if ($userId <= 0) {
            return false;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        if (is_array($meta) && !empty($meta)) {
            $metaJson = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($metaJson !== false) {
                $description = trim($description . ' | meta: ' . $metaJson);
            }
        }

        $db = getDB();

        // Primary target table used by this codebase.
        if (_logTableExists('user_logs')) {
            $db->insert('user_logs', [
                'user_id' => $userId,
                'action' => $action,
                'module' => $module,
                'description' => $description,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent
            ]);
            return true;
        }

        // Secondary fallback for alternate schema.
        if (_logTableExists('activity_logs')) {
            $db->insert('activity_logs', [
                'user_id' => $userId,
                'entity' => $module,
                'entity_id' => $entityId,
                'action' => $action,
                'details' => $description
            ]);
            return true;
        }
    } catch (Exception $e) {
        error_log('logUserActivity failed: ' . $e->getMessage());
    }

    return false;
}

/**
 * Render loading spinner
 */
function renderLoadingSpinner($size = 'md', $text = 'Loading...') {
    $sizeClasses = [
        'sm' => 'w-4 h-4',
        'md' => 'w-6 h-6',
        'lg' => 'w-8 h-8'
    ];

    $sizeClass = $sizeClasses[$size] ?? $sizeClasses['md'];

    $html = '<div class="d-flex align-items-center justify-content-center p-4">';
    $html .= '<div class="loading-spinner me-2" style="width: 1rem; height: 1rem;"></div>';
    $html .= '<span class="text-muted">' . $text . '</span>';
    $html .= '</div>';

    return $html;
}

/**
 * Render empty state component
 */
function renderEmptyState($icon, $title, $message, $actionButton = null) {
    $html = '<div class="empty-state">';
    $html .= '<i class="bi ' . $icon . '"></i>';
    $html .= '<h3>' . $title . '</h3>';
    $html .= '<p>' . $message . '</p>';

    if ($actionButton) {
        $html .= '<div class="mt-4">';
        $html .= '<a href="' . ($actionButton['url'] ?? '#') . '" class="btn btn-primary">';
        $html .= '<i class="bi ' . ($actionButton['icon'] ?? 'bi-plus') . ' me-2"></i>';
        $html .= $actionButton['text'] ?? 'Add New';
        $html .= '</a>';
        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
}

