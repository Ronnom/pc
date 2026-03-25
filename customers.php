<?php
/**
 * Customer Management - Database List
 * List all customers with search, filter, sort, pagination, and quick actions
 */

require_once 'includes/init.php';
requireLogin();

$db = getDB();
$pageTitle = 'Customer Database';

function customerHasColumn($db, $columnName) {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'customers'
           AND COLUMN_NAME = ?",
        [$columnName]
    );
    return (int)($row['cnt'] ?? 0) > 0;
}

$hasCustomerCode = customerHasColumn($db, 'customer_code');
$hasCustomerType = customerHasColumn($db, 'customer_type');
$hasType = customerHasColumn($db, 'type');
$hasIsActive = customerHasColumn($db, 'is_active');
$hasStatus = customerHasColumn($db, 'status');
$hasLoyaltyPoints = customerHasColumn($db, 'loyalty_points');
$hasLoyaltyTier = customerHasColumn($db, 'loyalty_tier');
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
$ajaxAction = trim($_GET['action'] ?? $_POST['action'] ?? '');

if ($isAjax && in_array($ajaxAction, ['search', 'create'], true)) {
    if (!hasPermission('customers.view') && !hasPermission('customers.edit') && !hasPermission('quotes.create')) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
} else {
    requirePermission('customers.view');
}

$customerCodeSelect = $hasCustomerCode ? "c.customer_code" : "CAST(c.id AS CHAR)";
$customerTypeSelect = $hasCustomerType ? "c.customer_type" : ($hasType ? "c.type" : "'individual'");
$statusSelect = $hasIsActive ? "c.is_active" : ($hasStatus ? "c.status" : "1");
$loyaltyPointsSelect = $hasLoyaltyPoints ? "c.loyalty_points" : "0";
$loyaltyTierSelect = $hasLoyaltyTier ? "c.loyalty_tier" : "'None'";

// Search/filter parameters
$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'type' => trim($_GET['type'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
    'date_from' => trim($_GET['date_from'] ?? ''),
    'date_to' => trim($_GET['date_to'] ?? ''),
    'sort' => trim($_GET['sort'] ?? 'name'),
    'order' => trim($_GET['order'] ?? 'asc'),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, (int)($_GET['per_page'] ?? 10)))
];

// Build WHERE clause
$where_parts = [];
$where_params = [];

if (!empty($filters['q'])) {
    $searchFields = ["c.first_name LIKE ?", "c.last_name LIKE ?", "c.email LIKE ?", "c.phone LIKE ?"];
    $search = '%' . $filters['q'] . '%';
    if ($hasCustomerCode) {
        array_unshift($searchFields, "c.customer_code LIKE ?");
        $where_params[] = $search;
    }
    $where_parts[] = "(" . implode(" OR ", $searchFields) . ")";
    $where_params[] = $search;
    $where_params[] = $search;
    $where_params[] = $search;
    $where_params[] = $search;
}

if (!empty($filters['type'])) {
    if ($hasCustomerType) {
        $where_parts[] = "c.customer_type = ?";
        $where_params[] = $filters['type'];
    } elseif ($hasType) {
        $where_parts[] = "c.type = ?";
        $where_params[] = $filters['type'];
    }
}

if (!empty($filters['status'])) {
    if ($hasIsActive) {
        $where_parts[] = "c.is_active = ?";
        $where_params[] = ($filters['status'] === 'active' ? 1 : 0);
    } elseif ($hasStatus) {
        $where_parts[] = "c.status = ?";
        $where_params[] = ($filters['status'] === 'active' ? 1 : 0);
    }
}

if (!empty($filters['date_from'])) {
    $where_parts[] = "EXISTS (
        SELECT 1
        FROM transactions tx_from
        WHERE tx_from.customer_id = c.id
          AND tx_from.status = 'completed'
          AND DATE(tx_from.transaction_date) >= ?
    )";
    $where_params[] = $filters['date_from'];
}

if (!empty($filters['date_to'])) {
    $where_parts[] = "EXISTS (
        SELECT 1
        FROM transactions tx_to
        WHERE tx_to.customer_id = c.id
          AND tx_to.status = 'completed'
          AND DATE(tx_to.transaction_date) <= ?
    )";
    $where_params[] = $filters['date_to'];
}

$where_sql = !empty($where_parts) ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

// Count total
$count_row = $db->fetchOne(
    "SELECT COUNT(DISTINCT c.id) as count FROM customers c {$where_sql}",
    $where_params
);
$total_count = (int)($count_row['count'] ?? 0);

// Sorting
$sort_map = [
    'name' => 'c.last_name, c.first_name',
    'spent' => 'total_spent',
    'last_purchase' => 'last_purchase',
    'created' => 'c.created_at'
];
$order_by = $sort_map[$filters['sort']] ?? 'c.last_name, c.first_name';
$order_dir = strtolower($filters['order']) === 'desc' ? 'DESC' : 'ASC';

// Pagination
$offset = ($filters['page'] - 1) * $filters['per_page'];
$total_pages = ceil($total_count / $filters['per_page']);

// Fetch customers with stats
$customers = $db->fetchAll(
    "SELECT c.id, {$customerCodeSelect} as customer_code, c.first_name, c.last_name, c.email, c.phone,
            {$customerTypeSelect} as customer_type, {$statusSelect} as is_active, c.created_at,
            {$loyaltyPointsSelect} as loyalty_points, {$loyaltyTierSelect} as loyalty_tier,
            COALESCE(SUM(CASE WHEN t.status = 'completed' THEN t.total_amount ELSE 0 END), 0) as total_spent,
            MAX(CASE WHEN t.status = 'completed' THEN t.transaction_date ELSE NULL END) as last_purchase,
            COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.id ELSE NULL END) as num_transactions
     FROM customers c
     LEFT JOIN transactions t ON c.id = t.customer_id
     {$where_sql}
     GROUP BY c.id
     ORDER BY {$order_by} {$order_dir}
     LIMIT " . (int)$filters['per_page'] . " OFFSET " . (int)$offset,
    $where_params
);
$showing_from = $total_count > 0 ? ($offset + 1) : 0;
$showing_to = min($offset + $filters['per_page'], $total_count);
$canManageCustomers = hasPermission('customers.manage');
$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);

function formatUsdAmount($amount) {
    return '$' . number_format((float)$amount, 2);
}

function formatLastPurchaseDisplay($dateValue) {
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '' || $dateValue === '0000-00-00' || $dateValue === '0000-00-00 00:00:00') {
        return ['label' => '-', 'subtext' => 'No purchases yet'];
    }

    $timestamp = strtotime($dateValue);
    if ($timestamp === false) {
        return ['label' => '-', 'subtext' => null];
    }

    $daysAgo = (int) floor((time() - $timestamp) / 86400);
    if ($daysAgo <= 0) {
        $subtext = 'Today';
    } elseif ($daysAgo === 1) {
        $subtext = '1 day ago';
    } else {
        $subtext = number_format($daysAgo) . ' days ago';
    }

    return [
        'label' => date('Y-m-d', $timestamp),
        'subtext' => $subtext
    ];
}

// Handle AJAX requests
if ($isAjax) {
    
    if ($ajaxAction === 'search') {
        try {
            if (!hasPermission('customers.view') && !hasPermission('customers.edit') && !hasPermission('quotes.create')) {
                throw new Exception('You do not have permission to search customers');
            }
            
            $searchTerm = trim($_GET['search'] ?? '');

            $searchWhere = [
                "first_name LIKE ?",
                "last_name LIKE ?",
                "CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,'')) LIKE ?",
                "email LIKE ?",
                "phone LIKE ?"
            ];
            $params = [
                "%$searchTerm%",
                "%$searchTerm%",
                "%$searchTerm%",
                "%$searchTerm%",
                "%$searchTerm%"
            ];

            $activeFilter = '';
            if ($hasIsActive) {
                $activeFilter = 'is_active = 1 AND ';
            } elseif (customerHasColumn($db, 'deleted_at')) {
                $activeFilter = 'deleted_at IS NULL AND ';
            }

            $results = $db->fetchAll(
                "SELECT id, first_name, last_name, email, phone
                 FROM customers
                 WHERE {$activeFilter}(" . implode(' OR ', $searchWhere) . ")
                 ORDER BY first_name, last_name
                 LIMIT 20",
                $params
            );
            
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'customers' => $results]);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
    
    if ($ajaxAction === 'create') {
        // Allow create if user can view OR edit customers (more flexible than requiring specific 'create' permission)
        try {
            if (!hasPermission('customers.view') && !hasPermission('customers.edit') && !hasPermission('quotes.create')) {
                throw new Exception('You do not have permission to create customers');
            }
            
            validateCSRF();
            
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            if (!$firstName || !$lastName) {
                throw new Exception('First and last name are required');
            }

            if ($phone !== '') {
                $normalizedPhone = preg_replace('/\D+/', '', $phone);
                if ($normalizedPhone === '' || strlen($normalizedPhone) > 11) {
                    throw new Exception('Phone number must contain digits only and must not exceed 11 digits.');
                }
                $phone = $normalizedPhone;
            }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a complete email address such as name@gmail.com.');
            }
            
            $insertData = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone ?: null,
                'email' => $email ?: null
            ];
            if ($hasIsActive) {
                $insertData['is_active'] = 1;
            }

            $customerId = $db->insert('customers', $insertData);
            
            $customer = $db->fetchOne(
                "SELECT id, first_name, last_name, email, phone FROM customers WHERE id = ?",
                [$customerId]
            );
            
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'customer' => $customer]);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}

include 'templates/header.php';
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    .customer-dashboard{--card:#fff;--border:#e2e8f0;--muted:#64748b;--text:#0f172a;--accent:#4f46e5;--accent-dark:#4338ca;--accent-soft:#eef2ff;--shadow:0 10px 24px rgba(15,23,42,.06);font-family:'Inter',system-ui,sans-serif;color:var(--text)}
    .customer-dashboard .shell{max-width:1480px;margin:0 auto;padding:8px 0 24px}
    .customer-dashboard .saas-card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
    .customer-dashboard .page-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px}
    .customer-dashboard .page-head h1{margin:0;font-size:1.8rem;font-weight:700;letter-spacing:-.02em}
    .customer-dashboard .page-head p{margin:6px 0 0;color:var(--muted)}
    .customer-dashboard .btn-primary-saas{background:var(--accent);border:1px solid var(--accent);color:#fff;border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .customer-dashboard .btn-primary-saas:hover{background:var(--accent-dark);border-color:var(--accent-dark);color:#fff}
    .customer-dashboard .btn-outline-saas{background:#fff;border:1px solid #cbd5e1;color:#334155;border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .customer-dashboard .btn-outline-saas:hover{background:#f8fafc;color:#1e293b}
    .customer-dashboard .filter-card{padding:20px;margin-bottom:20px}
    .customer-dashboard .filter-grid{display:grid;grid-template-columns:minmax(260px,1.8fr) minmax(170px,.9fr) minmax(160px,.8fr) minmax(150px,.75fr) minmax(150px,.75fr) auto auto;gap:14px;align-items:end}
    .customer-dashboard .search-wrap{position:relative}
    .customer-dashboard .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8}
    .customer-dashboard .search-wrap .form-control{padding-left:40px}
    .customer-dashboard .field-group{display:flex;flex-direction:column}
    .customer-dashboard .form-label{font-size:.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);margin-bottom:8px}
    .customer-dashboard .form-control,.customer-dashboard .form-select{border-radius:10px;border:1px solid #dbe1ea;padding:.72rem .9rem;box-shadow:none;min-height:44px}
    .customer-dashboard .form-control:focus,.customer-dashboard .form-select:focus{border-color:#a5b4fc;box-shadow:0 0 0 .18rem rgba(79,70,229,.12)}
    .customer-dashboard .filter-actions{display:flex;gap:12px;align-items:end}
    .customer-dashboard .table-card{overflow:hidden}
    .customer-dashboard .table-topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding:18px 20px;border-bottom:1px solid #eef2f7}
    .customer-dashboard .result-copy{color:var(--muted);font-size:.95rem}
    .customer-dashboard .table-shell{overflow:auto}
    .customer-dashboard .customer-table{margin:0;min-width:1280px}
    .customer-dashboard .customer-table thead th{padding:1rem .9rem;border-bottom:1px solid #e2e8f0;font-size:.74rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);background:#fff}
    .customer-dashboard .customer-table tbody td{padding:1rem .9rem;border-bottom:1px solid #eef2f7;vertical-align:middle}
    .customer-dashboard .customer-table tbody tr{transition:.15s ease}
    .customer-dashboard .customer-table tbody tr:hover{background:#f8fafc}
    .customer-dashboard .name-link{font-weight:700;color:#0f172a;text-decoration:none}
    .customer-dashboard .name-link:hover{color:#4338ca}
    .customer-dashboard .primary-text{font-weight:700;color:#0f172a}
    .customer-dashboard .secondary-text{font-size:.82rem;color:var(--muted);margin-top:2px}
    .customer-dashboard .money,.customer-dashboard .numeric{text-align:right;white-space:nowrap}
    .customer-dashboard .loyalty-chip,.customer-dashboard .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.34rem .72rem;font-size:.74rem;font-weight:700}
    .customer-dashboard .loyalty-chip{background:#eef2ff;color:#3730a3}
    .customer-dashboard .status-active{background:#dcfce7;color:#166534}
    .customer-dashboard .status-inactive{background:#e5e7eb;color:#374151}
    .customer-dashboard .action-cell{white-space:nowrap;text-align:center}
    .customer-dashboard .icon-btn{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:10px;border:1px solid #dbe1ea;background:#fff;color:#334155;text-decoration:none;transition:.15s ease}
    .customer-dashboard .icon-btn:hover{background:var(--accent-soft);border-color:#a5b4fc;color:var(--accent-dark)}
    .customer-dashboard .pagination-bar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;padding:18px 20px;border-top:1px solid #eef2f7}
    .customer-dashboard .per-page-form{display:flex;align-items:center;gap:10px;color:var(--muted);flex-wrap:wrap}
    .customer-dashboard .per-page-form .form-select{width:auto;min-width:140px;padding:.55rem 2rem .55rem .8rem}
    .customer-dashboard .pagination-wrap{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .customer-dashboard .pagination-modern{display:flex;align-items:center;gap:8px}
    .customer-dashboard .pagination-modern a,.customer-dashboard .pagination-modern span{min-width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;padding:0 .85rem;border:1px solid #dbe1ea;background:#fff;color:#334155;text-decoration:none;font-weight:600}
    .customer-dashboard .pagination-modern a:hover{border-color:#a5b4fc;color:#4338ca;background:#eef2ff}
    .customer-dashboard .pagination-modern .active{background:#4f46e5;border-color:#4f46e5;color:#fff}
    .customer-dashboard .pagination-modern .disabled{opacity:.45;pointer-events:none}
    .customer-dashboard .muted-copy{color:var(--muted);font-size:.95rem}
    .customer-dashboard .empty-state{padding:56px 24px;text-align:center}
    .customer-dashboard .empty-illustration{width:84px;height:84px;border-radius:24px;background:linear-gradient(135deg,#eef2ff,#e0f2fe);display:inline-flex;align-items:center;justify-content:center;color:#4f46e5;font-size:2rem;margin-bottom:18px}
    .customer-dashboard .empty-state h3{margin-bottom:8px;font-size:1.15rem}
    .customer-dashboard .empty-state p{color:var(--muted);margin-bottom:18px}
    .customer-dashboard .skeleton-wrap{display:none;padding:20px}
    .customer-dashboard.loading .skeleton-wrap{display:block}
    .customer-dashboard.loading .results-content{display:none}
    .customer-dashboard .skeleton-line{height:14px;border-radius:999px;background:linear-gradient(90deg,#e5e7eb 25%,#f1f5f9 50%,#e5e7eb 75%);background-size:200% 100%;animation:customerShimmer 1.4s infinite}
    .customer-dashboard .skeleton-line + .skeleton-line{margin-top:12px}
    .customer-dashboard .skeleton-line.short{width:42%}
    .customer-dashboard .skeleton-line.med{width:68%}
    @keyframes customerShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
    @media (max-width:1279px){.customer-dashboard .filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.customer-dashboard .filter-actions{grid-column:span 3;justify-content:flex-end}}
    @media (max-width:991px){.customer-dashboard .filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.customer-dashboard .filter-actions{grid-column:span 2}}
    @media (max-width:575px){.customer-dashboard .filter-grid{grid-template-columns:1fr}.customer-dashboard .page-head{align-items:stretch}.customer-dashboard .page-head a{width:100%}.customer-dashboard .filter-actions{grid-column:auto;flex-direction:column}.customer-dashboard .filter-actions .btn{width:100%}.customer-dashboard .table-topbar,.customer-dashboard .pagination-bar{align-items:flex-start}.customer-dashboard .pagination-wrap{width:100%}}
</style>

<div class="customer-dashboard loading" id="customerDashboard">
    <div class="shell">
        <div class="page-head">
            <div>
                <h1>Customer Database</h1>
                <p>Search and manage customer records, purchase history, and loyalty data.</p>
            </div>
            <?php if ($canManageCustomers): ?>
                <a href="<?php echo getBaseUrl(); ?>/customer_form.php" class="btn btn-primary-saas">
                    <i class="bi bi-plus-lg me-1"></i> Add Customer
                </a>
            <?php endif; ?>
        </div>

        <div class="saas-card filter-card">
            <form method="GET" id="customerFilterForm">
                <div class="filter-grid">
                    <div class="field-group">
                        <label class="form-label">Search</label>
                        <div class="search-wrap">
                            <i class="bi bi-search"></i>
                            <input type="text" class="form-control" name="q" placeholder="Search by name, email, or phone..." value="<?php echo escape($filters['q']); ?>">
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="form-label">Customer Type</label>
                        <select class="form-select" name="type">
                            <option value="">All Types</option>
                            <option value="individual" <?php echo $filters['type'] === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="business" <?php echo $filters['type'] === 'business' ? 'selected' : ''; ?>>Business</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filters['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="form-label">Last Purchase From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo escape($filters['date_from']); ?>" placeholder="mm/dd/yyyy">
                    </div>
                    <div class="field-group">
                        <label class="form-label">Last Purchase To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo escape($filters['date_to']); ?>" placeholder="mm/dd/yyyy">
                    </div>
                    <div class="filter-actions">
                        <input type="hidden" name="per_page" value="<?php echo (int)$filters['per_page']; ?>">
                        <button type="submit" class="btn btn-primary-saas">
                            <i class="bi bi-search me-1"></i> Search
                        </button>
                        <a href="<?php echo getBaseUrl(); ?>/customers.php" class="btn btn-outline-saas">Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="saas-card table-card">
            <div class="skeleton-wrap">
                <div class="skeleton-line med"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line short"></div>
            </div>
            <div class="results-content">
                <div class="table-topbar">
                    <div class="result-copy">Showing <?php echo number_format($showing_from); ?>-<?php echo number_format($showing_to); ?> of <?php echo number_format($total_count); ?> customers</div>
                </div>
                <?php if (empty($customers)): ?>
                    <div class="empty-state">
                        <div class="empty-illustration"><i class="bi bi-people"></i></div>
                        <h3>No customers match these filters</h3>
                        <p>Try clearing the search, widening the date range, or resetting the customer filters.</p>
                        <a href="<?php echo getBaseUrl(); ?>/customers.php" class="btn btn-outline-saas">Clear Filters</a>
                    </div>
                <?php else: ?>
                    <div class="table-shell">
                        <table class="table customer-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>NAME</th>
                                    <th>CONTACT</th>
                                    <th>TYPE</th>
                                    <th class="text-end">TOTAL SPENT</th>
                                    <th class="text-end">TRANSACTIONS</th>
                                    <th>LAST PURCHASE</th>
                                    <th>LOYALTY</th>
                                    <th>STATUS</th>
                                    <th class="text-center">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <?php
                                    $fullName = trim(((string)$customer['first_name']) . ' ' . ((string)$customer['last_name']));
                                    if ($fullName === '') {
                                        $fullName = 'Unnamed Customer';
                                    }
                                    $customerType = ucfirst(str_replace('_', ' ', (string)($customer['customer_type'] ?? 'individual')));
                                    $statusKey = !empty($customer['is_active']) ? 'active' : 'inactive';
                                    $loyaltyTier = trim((string)($customer['loyalty_tier'] ?? 'None'));
                                    $loyaltyPoints = (int)($customer['loyalty_points'] ?? 0);
                                    $loyaltyText = $loyaltyTier !== '' && strcasecmp($loyaltyTier, 'none') !== 0
                                        ? $loyaltyTier
                                        : ($loyaltyPoints > 0 ? number_format($loyaltyPoints) . ' pts' : 'None');
                                    $lastPurchase = formatLastPurchaseDisplay($customer['last_purchase'] ?? null);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo getBaseUrl(); ?>/customer_profile.php?id=<?php echo (int)$customer['id']; ?>" class="name-link"><?php echo escape($fullName); ?></a>
                                            <div class="secondary-text"><?php echo escape((string)$customer['customer_code']); ?></div>
                                        </td>
                                        <td>
                                            <div class="primary-text"><?php echo escape((string)($customer['email'] ?: ($customer['phone'] ?: 'No contact info'))); ?></div>
                                            <?php if (!empty($customer['email']) && !empty($customer['phone'])): ?>
                                                <div class="secondary-text"><?php echo escape((string)$customer['phone']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo escape($customerType); ?></td>
                                        <td class="money"><?php echo escape(formatUsdAmount($customer['total_spent'])); ?></td>
                                        <td class="numeric"><?php echo number_format((int)$customer['num_transactions']); ?></td>
                                        <td>
                                            <div class="primary-text"><?php echo escape($lastPurchase['label']); ?></div>
                                            <?php if (!empty($lastPurchase['subtext'])): ?>
                                                <div class="secondary-text"><?php echo escape($lastPurchase['subtext']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="loyalty-chip"><?php echo escape($loyaltyText); ?></span></td>
                                        <td><span class="status-pill status-<?php echo $statusKey; ?>"><?php echo ucfirst($statusKey); ?></span></td>
                                        <td class="action-cell">
                                            <a href="<?php echo getBaseUrl(); ?>/customer_profile.php?id=<?php echo (int)$customer['id']; ?>" class="icon-btn" title="View customer" data-bs-toggle="tooltip">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <?php if ($canManageCustomers): ?>
                                                <a href="<?php echo getBaseUrl(); ?>/customer_form.php?id=<?php echo (int)$customer['id']; ?>" class="icon-btn ms-1" title="Edit customer" data-bs-toggle="tooltip">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination-bar">
                        <form method="GET" class="per-page-form">
                            <?php foreach ($filters as $key => $value): ?>
                                <?php if ($key !== 'per_page' && $key !== 'page' && $value !== '' && $value !== null): ?>
                                    <input type="hidden" name="<?php echo escape($key); ?>" value="<?php echo escape((string)$value); ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <label for="customerPerPage" class="mb-0">Rows</label>
                            <select id="customerPerPage" name="per_page" class="form-select" onchange="this.form.submit()">
                                <?php foreach ([10, 25, 50, 100] as $perPage): ?>
                                <option value="<?php echo $perPage; ?>" <?php echo (int)$filters['per_page'] === $perPage ? 'selected' : ''; ?>><?php echo $perPage; ?> per page</option>
                                <?php endforeach; ?>
                            </select>
                        </form>

                        <div class="pagination-wrap">
                            <div class="muted-copy">Showing <?php echo number_format($showing_from); ?>-<?php echo number_format($showing_to); ?> of <?php echo number_format($total_count); ?> results</div>
                            <div class="pagination-modern">
                                <?php $prevUrl = getBaseUrl() . '/customers.php?' . http_build_query(array_merge($queryWithoutPage, ['page' => max(1, $filters['page'] - 1)])); ?>
                                <?php $nextUrl = getBaseUrl() . '/customers.php?' . http_build_query(array_merge($queryWithoutPage, ['page' => min(max(1, $total_pages), $filters['page'] + 1)])); ?>
                                <a href="<?php echo $prevUrl; ?>" class="<?php echo $filters['page'] <= 1 ? 'disabled' : ''; ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                                <?php for ($i = max(1, $filters['page'] - 1); $i <= min(max(1, $total_pages), $filters['page'] + 1); $i++): ?>
                                    <?php $pageUrl = getBaseUrl() . '/customers.php?' . http_build_query(array_merge($queryWithoutPage, ['page' => $i])); ?>
                                    <?php if ($i === (int)$filters['page']): ?>
                                        <span class="active"><?php echo number_format($i); ?></span>
                                    <?php else: ?>
                                        <a href="<?php echo $pageUrl; ?>"><?php echo number_format($i); ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <a href="<?php echo $nextUrl; ?>" class="<?php echo $filters['page'] >= $total_pages ? 'disabled' : ''; ?>" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
window.addEventListener('load', function () {
    const dashboard = document.getElementById('customerDashboard');
    if (dashboard) {
        dashboard.classList.remove('loading');
    }
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
});
</script>

<?php include 'templates/footer.php'; ?>
