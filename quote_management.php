<?php
/**
 * Quote Management Dashboard
 * Manage, track, and monitor all quotations in the system
 */
require_once 'config/init.php';
requireLogin();
requirePermission('quotes.create');

$pageTitle = 'Quote Management';

$db = getDB();
$action = trim($_GET['action'] ?? '');
$errors = [];
$success = false;
$successMessage = '';

// Verify quote system setup
$quoteSetup = verifyQuoteSystemSetup();
if (!$quoteSetup['is_valid']) {
    echo '<div class="alert alert-danger">';
    echo '<h5>Quote System Issue</h5>';
    echo '<p>The quote system is not properly configured:</p>';
    echo '<ul>';
    foreach ($quoteSetup['issues'] as $issue) {
        echo '<li>' . escape($issue) . '</li>';
    }
    echo '</ul>';
    echo '</div>';
    include 'templates/footer.php';
    exit;
}

// Get statistics
$stats = getQuoteSystemStats();

// Handle cleanup action
if ($action === 'cleanup_expired') {
    requirePermission('admin');
    if (cleanupExpiredQuotes()) {
        $successMessage = 'Expired quotes have been marked.';
        $success = true;
    }
    header('Location: ' . getBaseUrl() . '/quote_management.php');
    exit;
}

// Duplicate functionality removed per requirements.

// Handle admin delete action (soft delete)
if ($action === 'delete_quote') {
    requirePermission('admin');
    $quoteId = (int)($_GET['id'] ?? 0);
    if ($quoteId <= 0) {
        header('Location: ' . getBaseUrl() . '/quote_management.php?error=' . urlencode('Invalid quote.'));
        exit;
    }
    if (tableColumnExists('quotes', 'deleted_at')) {
        $db->update('quotes', ['deleted_at' => date(DATETIME_FORMAT), 'status' => 'deleted'], 'id = ?', [$quoteId]);
    } else {
        $db->query('DELETE FROM quote_items WHERE quote_id = ?', [$quoteId]);
        $db->query('DELETE FROM quotes WHERE id = ?', [$quoteId]);
    }
    header('Location: ' . getBaseUrl() . '/quote_management.php?message=' . urlencode('Quote deleted.'));
    exit;
}

// Handle AJAX create quote
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $action === 'create_quote') {
    try {
        if (!hasPermission('quotes.create') && !hasPermission('quotes.edit')) {
            throw new Exception('You do not have permission to create quotes');
        }
        
        validateCSRF();
        
        $customerId = (int)($_POST['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new Exception('Customer is required');
        }
        
        $validUntil = trim((string)($_POST['valid_until'] ?? ''));
        if (!$validUntil) {
            throw new Exception('Valid until date is required');
        }
        
        $notes = trim((string)($_POST['notes'] ?? ''));
        $discountType = strtolower(trim((string)($_POST['discount_type'] ?? 'percent')));
        if (!in_array($discountType, ['percent', 'fixed'], true)) {
            $discountType = 'percent';
        }
        $discountValue = max(0, (float)($_POST['discount_value'] ?? 0));
        $reserveSerials = !empty($_POST['reserve_serials']) ? 1 : 0;
        $itemsJson = $_POST['items'] ?? '[]';
        $items = json_decode($itemsJson, true) ?: [];
        
        if (empty($items)) {
            throw new Exception('At least one product is required');
        }
        
        // Calculate totals
        $subtotal = 0;
        $taxAmount = 0;
        $discountAmount = 0;
        
        foreach ($items as $item) {
            $qty = max(1, (int)($item['quantity'] ?? 0));
            $price = max(0, (float)($item['unit_price'] ?? 0));
            $lineSubtotal = $price * $qty;
            $subtotal += $lineSubtotal;
        }

        $discountAmount = $discountType === 'percent'
            ? ($subtotal * ($discountValue / 100))
            : $discountValue;
        $discountAmount = min($discountAmount, $subtotal);
        $totalAmount = $subtotal - $discountAmount;
        
        // Generate quote number
        $today = date('Y-m-d');
        $datePrefix = date('Ymd');
        $lastQuote = $db->fetchOne(
            "SELECT quote_number FROM quotes WHERE quote_number LIKE ? ORDER BY id DESC LIMIT 1",
            ["Q-{$datePrefix}-%"]
        );
        $lastSeq = 0;
        if (!empty($lastQuote['quote_number'])) {
            $parts = explode('-', $lastQuote['quote_number']);
            $lastSeq = (int)end($parts);
        }
        $seq = $lastSeq + 1;
        $quoteNumber = 'Q-' . $datePrefix . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        
        // Insert quote
        $db->beginTransaction();
        try {
            $quoteData = [
                'quote_number' => $quoteNumber,
                'customer_id' => $customerId,
                'created_by' => getCurrentUserId(),
                'valid_until' => $validUntil,
                'status' => 'draft',
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'discount_amount' => $discountAmount,
                'total_amount' => $totalAmount,
                'notes' => $notes
            ];
            if (tableColumnExists('quotes', 'discount_type')) {
                $quoteData['discount_type'] = $discountType;
            }
            if (tableColumnExists('quotes', 'discount_value')) {
                $quoteData['discount_value'] = $discountValue;
            }
            if (tableColumnExists('quotes', 'reserve_serials')) {
                $quoteData['reserve_serials'] = $reserveSerials;
            }

            $quoteId = null;
            $insertAttempts = 0;
            while ($insertAttempts < 5 && $quoteId === null) {
                try {
                    $quoteData['quote_number'] = $quoteNumber;
                    $quoteId = $db->insert('quotes', $quoteData);
                } catch (Exception $e) {
                    if (stripos($e->getMessage(), 'Duplicate entry') !== false) {
                        $seq++;
                        $quoteNumber = 'Q-' . $datePrefix . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                        $insertAttempts++;
                        continue;
                    }
                    throw $e;
                }
            }
            if ($quoteId === null) {
                throw new Exception('Unable to generate a unique quote number. Please try again.');
            }
            
            // Insert quote items
            foreach ($items as $item) {
                $itemQty = max(1, (int)($item['quantity'] ?? 0));
                $itemPrice = max(0, (float)($item['unit_price'] ?? 0));
                $lineSubtotal = $itemPrice * $itemQty;
                $lineTaxAmount = 0;
                $lineDiscount = 0;
                if ($subtotal > 0 && $discountAmount > 0) {
                    $lineDiscount = ($lineSubtotal / $subtotal) * $discountAmount;
                }
                $lineTotal = $lineSubtotal - $lineDiscount;

                $quoteItemData = [
                    'quote_id' => $quoteId,
                    'product_id' => (int)($item['product_id'] ?? 0),
                    'quantity' => $itemQty,
                    'unit_price' => $itemPrice,
                    'discount_amount' => round($lineDiscount, 2),
                    'tax_amount' => round($lineTaxAmount, 2),
                    'line_total' => $lineTotal
                ];
                if (tableColumnExists('quote_items', 'is_backorder')) {
                    $quoteItemData['is_backorder'] = ((int)($item['stock_quantity'] ?? 0) <= 0) ? 1 : 0;
                }
                if (tableColumnExists('quote_items', 'sort_order')) {
                    $quoteItemData['sort_order'] = max(0, (int)($item['sort_order'] ?? 0));
                }

                $db->insert('quote_items', $quoteItemData);
            }
            
            $db->commit();
            
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Quote created successfully',
                'quote_number' => $quoteNumber,
                'quote_id' => $quoteId
            ]);
            exit;
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        error_log('Quote create error: ' . $e->getMessage());
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Get filter parameters
$statusFilter = trim($_GET['status'] ?? '');
$allowedStatuses = ['draft', 'sent', 'accepted', 'expired', 'converted'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

// Build query
$params = [];
$whereParts = [];
if (tableColumnExists('quotes', 'deleted_at')) {
    $whereParts[] = "q.deleted_at IS NULL";
}
if ($statusFilter) {
    $whereParts[] = 'q.status = ?';
    $params[] = $statusFilter;
}
$whereClause = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Get total count
$countResult = $db->fetchOne(
    "SELECT COUNT(*) as cnt FROM quotes q {$whereClause}",
    $params
);
$totalQuotes = (int)($countResult['cnt'] ?? 0);
$totalPages = ceil($totalQuotes / $perPage);

// Get quotes for current page
$quotes = $db->fetchAll(
    "SELECT q.*, 
            c.first_name, c.last_name, c.email,
            u.username as created_by_name,
            (SELECT COUNT(*) FROM quote_items WHERE quote_id = q.id) as item_count,
            (SELECT COUNT(*)
             FROM quote_items qi
             INNER JOIN products p ON p.id = qi.product_id
             WHERE qi.quote_id = q.id
               AND p.stock_quantity < qi.quantity) as stock_warning_count
     FROM quotes q
     LEFT JOIN customers c ON c.id = q.customer_id
     LEFT JOIN users u ON u.id = q.created_by
     {$whereClause}
     ORDER BY q.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$perPage, $offset])
);

$quoteDetailsMap = [];
foreach ($quotes as $quoteRow) {
    $quoteId = (int)($quoteRow['id'] ?? 0);
    if ($quoteId <= 0) {
        continue;
    }

    $lineItems = $db->fetchAll(
        "SELECT qi.quantity, qi.unit_price, qi.line_total,
                COALESCE(p.name, CONCAT('Product #', qi.product_id)) AS product_name
         FROM quote_items qi
         LEFT JOIN products p ON p.id = qi.product_id
         WHERE qi.quote_id = ?
         ORDER BY qi.id ASC",
        [$quoteId]
    );

    $audit = getQuoteAuditTrail($quoteId) ?? [];
    $timeline = [];
    if (!empty($quoteRow['created_at'])) {
        $timeline[] = ['label' => 'Created', 'timestamp' => $quoteRow['created_at']];
    }
    if (!empty($quoteRow['emailed_at'])) {
        $timeline[] = ['label' => 'Sent to customer', 'timestamp' => $quoteRow['emailed_at']];
    }
    if (!empty($quoteRow['updated_at']) && $quoteRow['updated_at'] !== $quoteRow['created_at']) {
        $timeline[] = ['label' => 'Updated', 'timestamp' => $quoteRow['updated_at']];
    }
    if (!empty($quoteRow['converted_at'])) {
        $timeline[] = ['label' => 'Converted', 'timestamp' => $quoteRow['converted_at']];
    }

    $auditLog = [];
    if (!empty($audit['created']['timestamp'])) {
        $auditLog[] = 'Created on ' . $audit['created']['timestamp'];
    }
    if (!empty($audit['sent']['timestamp'])) {
        $auditLog[] = 'Marked sent on ' . $audit['sent']['timestamp'];
    }
    if (!empty($audit['updated']['timestamp'])) {
        $auditLog[] = 'Last updated on ' . $audit['updated']['timestamp'];
    }
    if (!empty($audit['converted']['transaction_number'])) {
        $auditLog[] = 'Converted to ' . $audit['converted']['transaction_number'];
    }

    $quoteDetailsMap[$quoteId] = [
        'notes' => (string)($quoteRow['notes'] ?? ''),
        'line_items' => array_map(static function ($item) {
            return [
                'product_name' => (string)($item['product_name'] ?? 'Item'),
                'quantity' => (int)($item['quantity'] ?? 0),
                'unit_price' => (float)($item['unit_price'] ?? 0),
                'line_total' => (float)($item['line_total'] ?? 0),
            ];
        }, $lineItems),
        'timeline' => $timeline,
        'audit_log' => $auditLog,
    ];
}

include 'templates/header.php';

?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    .quote-dashboard{--card:#fff;--border:#e2e8f0;--muted:#64748b;--text:#0f172a;--accent:#4f46e5;--accent-dark:#4338ca;--shadow:0 10px 24px rgba(15,23,42,.06);font-family:'Inter',system-ui,sans-serif;color:var(--text)}
    .quote-dashboard .shell{max-width:1480px;margin:0 auto;padding:8px 0 24px}
    .quote-dashboard .saas-card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
    .quote-dashboard .metric-card{padding:18px}.quote-dashboard .metric-label{font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:6px}.quote-dashboard .metric-value{font-size:1.8rem;font-weight:700}
    .quote-dashboard .btn-primary-saas{background:var(--accent);border-color:var(--accent);color:#fff;border-radius:10px;padding:.72rem 1rem;font-weight:600}.quote-dashboard .btn-primary-saas:hover{background:var(--accent-dark);border-color:var(--accent-dark);color:#fff}
    .quote-dashboard .btn-outline-saas{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .quote-dashboard .chip-row{display:flex;gap:10px;flex-wrap:wrap}.quote-dashboard .chip-filter{display:inline-flex;align-items:center;padding:.55rem .85rem;border-radius:999px;border:1px solid #dbe1ea;background:#fff;color:#475569;text-decoration:none;font-weight:600}.quote-dashboard .chip-filter.active,.quote-dashboard .chip-filter:hover{background:#eef2ff;border-color:#a5b4fc;color:#4338ca}
    .quote-dashboard .layout{display:grid;grid-template-columns:minmax(0,2fr) minmax(300px,1fr);gap:20px}.quote-dashboard .sticky-card{position:sticky;top:88px}
    .quote-dashboard .table-shell{overflow:auto}.quote-dashboard .quote-table{margin:0;min-width:900px}.quote-dashboard .quote-table th{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;border-bottom:1px solid #e2e8f0;padding:1rem .85rem}
    .quote-dashboard .quote-table td{padding:1rem .85rem;border-bottom:1px solid #eef2f7;vertical-align:middle}.quote-dashboard .quote-row{cursor:pointer;transition:.15s ease}.quote-dashboard .quote-row:hover{background:#f8fafc}.quote-dashboard .quote-row.selected{background:#eef2ff;box-shadow:inset 3px 0 0 #4f46e5}
    .quote-dashboard .status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.32rem .7rem;font-size:.74rem;font-weight:700}.quote-dashboard .status-draft{background:#e5e7eb;color:#374151}.quote-dashboard .status-sent{background:#dbeafe;color:#1d4ed8}.quote-dashboard .status-accepted{background:#dcfce7;color:#166534}.quote-dashboard .status-expired{background:#fee2e2;color:#991b1b}.quote-dashboard .status-converted{background:#ede9fe;color:#6d28d9}
    .quote-dashboard .quote-number{font-weight:700}.quote-dashboard .quote-sub{color:var(--muted);font-size:.82rem}.quote-dashboard .detail-title{font-weight:700;font-size:1.1rem}.quote-dashboard .detail-block{padding:16px;border:1px solid #eef2f7;border-radius:10px;background:#fff}
    .quote-dashboard .detail-list{list-style:none;padding:0;margin:0}.quote-dashboard .detail-list li{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #eef2f7}.quote-dashboard .detail-list li:last-child{border-bottom:0}
    .quote-dashboard .timeline-list,.quote-dashboard .audit-list{list-style:none;padding:0;margin:0}.quote-dashboard .timeline-list li,.quote-dashboard .audit-list li{padding:10px 0;border-bottom:1px dashed #e2e8f0;font-size:.9rem}.quote-dashboard .timeline-list li:last-child,.quote-dashboard .audit-list li:last-child{border-bottom:0}
    .quote-dashboard .detail-actions .btn{width:100%;border-radius:10px;font-weight:600}.quote-dashboard .detail-footer{display:flex;gap:10px;flex-wrap:wrap}.quote-dashboard .detail-footer .btn{flex:1 1 160px}
    .quote-dashboard .empty-note{padding:40px 24px;text-align:center;color:var(--muted)}
    #newQuoteModal .modal-content{border:1px solid #e5e7eb;border-radius:12px}#newQuoteModal .modal-header{padding:14px 18px;border-bottom:1px solid #eef2f7}#newQuoteModal .modal-body{padding:16px 18px;background:#fcfdff}#newQuoteModal .modal-footer{padding:12px 18px;border-top:1px solid #eef2f7}
    #newQuoteModal .quote-block{background:#fff;border:1px solid #e9edf3;border-radius:10px;padding:12px;margin-bottom:12px}#newQuoteModal .quote-block .form-label{margin-bottom:6px;color:#374151}#quoteItems .quote-item-row{padding:10px 4px}#quoteItems .quote-item-row:last-child{border-bottom:0!important}#newQuoteModal .quote-summary{background:#fff;border:1px solid #e9edf3;border-radius:10px;padding:10px 12px}#newQuoteModal .quote-summary .label{color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.02em}#newQuoteModal .quote-summary .value{font-weight:700;font-size:1rem}
    @media (max-width:991px){.quote-dashboard .layout{grid-template-columns:1fr}.quote-dashboard .sticky-card{position:static}}
</style>
<div class="quote-dashboard">
<div class="shell">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h1 class="mb-1">Quote Management</h1>
            <p class="text-muted mb-0">Track quotes, monitor expiry, and convert approved work from one place.</p>
        </div>
        <button class="btn btn-primary-saas" data-bs-toggle="modal" data-bs-target="#newQuoteModal"><i class="fas fa-plus"></i> New Quote</button>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3"><div class="saas-card metric-card"><div class="metric-label">Total Quotes</div><div class="metric-value"><?php echo $stats['total_quotes']; ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="saas-card metric-card"><div class="metric-label">Draft Quotes</div><div class="metric-value"><?php echo $stats['draft_quotes']; ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="saas-card metric-card"><div class="metric-label">Sent Quotes</div><div class="metric-value"><?php echo $stats['sent_quotes']; ?></div></div></div>
        <div class="col-md-6 col-xl-3"><div class="saas-card metric-card"><div class="metric-label">Converted</div><div class="metric-value"><?php echo $stats['converted_quotes']; ?></div></div></div>
    </div>

    <div class="saas-card p-3 mb-3">
        <div class="chip-row">
            <a href="?" class="chip-filter <?php echo $statusFilter === '' ? 'active' : ''; ?>">All</a>
            <?php foreach ($allowedStatuses as $status): ?>
                <a href="?status=<?php echo urlencode($status); ?>" class="chip-filter <?php echo $statusFilter === $status ? 'active' : ''; ?>"><?php echo ucfirst($status); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="layout">
        <div>
            <div class="saas-card overflow-hidden">
                <div class="table-shell">
                    <table class="table quote-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>QUOTE</th>
                                <th>CUSTOMER</th>
                                <th>EXPIRES</th>
                                <th>STATUS</th>
                                <th class="text-end">AMOUNT</th>
                                <th class="text-end">ITEMS</th>
                                <th class="text-end">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($quotes)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No quotes found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($quotes as $q): ?>
                                <?php
                                $statusRaw = strtolower((string)($q['status'] ?? 'draft'));
                                $statusLabel = [
                                    'draft' => 'Draft',
                                    'sent' => 'Sent',
                                    'accepted' => 'Approved',
                                    'expired' => 'Expired',
                                    'converted' => 'Converted'
                                ][$statusRaw] ?? ucfirst(str_replace('_', ' ', $statusRaw));
                                $statusClass = [
                                    'draft' => 'secondary',
                                    'sent' => 'info',
                                    'accepted' => 'success',
                                    'expired' => 'danger',
                                    'converted' => 'primary'
                                ][$statusRaw] ?? 'secondary';
                                $validUntilTs = $q['valid_until'] ? strtotime($q['valid_until']) : null;
                                $createdTs = $q['created_at'] ? strtotime($q['created_at']) : null;
                                $daysLeft = $validUntilTs ? (int)floor(($validUntilTs - time()) / 86400) : null;
                                $daysLeft = $daysLeft !== null ? $daysLeft : null;
                                $totalWindow = 30;
                                if ($createdTs && $validUntilTs && $validUntilTs > $createdTs) {
                                    $totalWindow = max(1, (int)ceil(($validUntilTs - $createdTs) / 86400));
                                }
                                $remaining = $daysLeft !== null ? max(0, $daysLeft) : null;
                                $progress = $remaining !== null ? min(100, (int)round(($remaining / $totalWindow) * 100)) : 0;
                                $expiryText = $daysLeft === null
                                    ? 'No expiry'
                                    : ($daysLeft < 0 ? 'Expired' : 'Expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'));
                                $customerName = trim(($q['first_name'] ?? '') . ' ' . ($q['last_name'] ?? ''));
                                $dataPayload = [
                                    'id' => (int)$q['id'],
                                    'quote_number' => $q['quote_number'],
                                    'customer_name' => $customerName ?: 'Guest',
                                    'customer_email' => $q['email'] ?? '',
                                    'status' => $statusRaw,
                                    'status_label' => $statusLabel,
                                    'total_amount' => (float)$q['total_amount'],
                                    'valid_until' => $q['valid_until'],
                                    'created_at' => $q['created_at'],
                                    'emailed_at' => $q['emailed_at'] ?? null,
                                    'updated_at' => $q['updated_at'] ?? null,
                                    'converted_to_transaction_id' => $q['converted_to_transaction_id'] ?? null,
                                    'stock_warning_count' => (int)($q['stock_warning_count'] ?? 0),
                                    'created_by_name' => $q['created_by_name'] ?? 'User',
                                    'notes' => $quoteDetailsMap[(int)$q['id']]['notes'] ?? '',
                                    'line_items' => $quoteDetailsMap[(int)$q['id']]['line_items'] ?? [],
                                    'timeline' => $quoteDetailsMap[(int)$q['id']]['timeline'] ?? [],
                                    'audit_log' => $quoteDetailsMap[(int)$q['id']]['audit_log'] ?? []
                                ];
                                ?>
                                <tr class="quote-row" data-quote="<?php echo htmlspecialchars(json_encode($dataPayload), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td>
                                        <div class="quote-number"><?php echo escape($q['quote_number']); ?></div>
                                        <div class="quote-sub"><?php echo escape($q['created_by_name'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <div class="small"><?php echo escape($customerName ?: 'Guest'); ?></div>
                                        <?php if (!empty($q['email'])): ?>
                                            <div class="text-muted small"><?php echo escape($q['email']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small"><?php echo $expiryText; ?></div>
                                        <div class="progress progress-thin mt-1">
                                            <div class="progress-bar bg-<?php echo $daysLeft !== null && $daysLeft < 0 ? 'danger' : 'primary'; ?>"
                                                 style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                    </td>
                                    <td><span class="status-pill status-<?php echo escape($statusRaw); ?>"><?php echo $statusLabel; ?></span></td>
                                    <td class="text-end fw-semibold">$<?php echo number_format((float)$q['total_amount'], 2); ?></td>
                                    <td class="text-end">
                                        <span class="badge bg-light text-dark"><?php echo (int)$q['item_count']; ?></span>
                                        <?php if (!empty($q['stock_warning_count'])): ?>
                                            <i class="fas fa-exclamation-triangle text-warning ms-1" title="Stock below quoted quantity"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?php echo getBaseUrl(); ?>/quote.php?id=<?php echo (int)$q['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Quote"><i class="fas fa-eye"></i></a>
                                        <?php if ($q['status'] === 'converted' && $q['converted_to_transaction_id']): ?>
                                            <a href="<?php echo getBaseUrl(); ?>/receipt.php?transaction_id=<?php echo (int)$q['converted_to_transaction_id']; ?>" class="btn btn-sm btn-outline-success" title="View Transaction"><i class="fas fa-receipt"></i></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
            <nav class="mt-3" aria-label="Quote list pagination">
                <ul class="pagination justify-content-center mb-0">
                    <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo $statusFilter ? 'status=' . urlencode($statusFilter) . '&' : ''; ?>page=1">First</a></li>
                    <li class="page-item"><a class="page-link" href="?<?php echo $statusFilter ? 'status=' . urlencode($statusFilter) . '&' : ''; ?>page=<?php echo $page - 1; ?>">Previous</a></li>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo $statusFilter ? 'status=' . urlencode($statusFilter) . '&' : ''; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item"><a class="page-link" href="?<?php echo $statusFilter ? 'status=' . urlencode($statusFilter) . '&' : ''; ?>page=<?php echo $page + 1; ?>">Next</a></li>
                    <li class="page-item"><a class="page-link" href="?<?php echo $statusFilter ? 'status=' . urlencode($statusFilter) . '&' : ''; ?>page=<?php echo $totalPages; ?>">Last</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>

        <div>
            <div class="saas-card sticky-card">
                <div class="p-3 border-bottom"><div class="detail-title">Quote Details</div></div>
                <div class="p-3">
                    <div id="quoteActionEmpty" class="empty-note">Select a quote to view line items, conversion controls, communication timeline, and audit log.</div>
                    <div id="quoteActionPanel" class="d-none">
                        <div class="detail-block mb-3">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <div class="fw-bold" id="qa-quote-number">Quote</div>
                                    <div class="text-muted small" id="qa-customer">Customer</div>
                                </div>
                                <span id="qa-status" class="status-pill status-draft">Draft</span>
                            </div>
                            <div class="small text-muted" id="qa-summary-meta"></div>
                            <div class="small mt-2" id="qa-notes"></div>
                        </div>

                        <div class="detail-block mb-3">
                            <div class="fw-semibold mb-2">Line Items Summary</div>
                            <ul class="detail-list" id="qa-line-items"></ul>
                        </div>

                        <div class="detail-block mb-3 detail-actions">
                            <div class="fw-semibold mb-2">Actions</div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-primary-saas" id="qa-convert-sale">Convert to Order</button>
                                <button class="btn btn-outline-saas" id="qa-email">Send Quote</button>
                                <button class="btn btn-outline-saas" id="qa-convert-service">Convert to Service</button>
                                <a class="btn btn-outline-saas" id="qa-download" href="#" target="_blank">Open Quote PDF</a>
                            </div>
                        </div>

                        <div class="detail-block mb-3">
                            <div class="fw-semibold mb-2">Communication Timeline</div>
                            <ul class="timeline-list" id="qa-timeline"></ul>
                        </div>

                        <div class="detail-block mb-3">
                            <div class="fw-semibold mb-2">Audit Log</div>
                            <ul class="audit-list" id="qa-history"></ul>
                        </div>

                        <div class="detail-footer">
                            <?php if (hasPermission('admin')): ?>
                            <a href="?action=cleanup_expired" class="btn btn-outline-saas" onclick="return confirm('Mark expired quotes as expired?')">Cleanup Expired</a>
                            <?php endif; ?>
                            <button class="btn btn-primary-saas" data-bs-toggle="modal" data-bs-target="#newQuoteModal">New Quote</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- New Quote Modal -->
<div class="modal fade" id="newQuoteModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Quote</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quoteForm">
                    <div class="quote-block">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Customer *</label>
                            <input type="text" id="customerSearch" class="form-control form-control-sm" placeholder="Search customer name, phone...">
                            <div id="customerResults" class="list-group mt-1 d-none" style="max-height: 250px; overflow-y: auto;"></div>
                            <div id="selectedCustomer" class="small mt-2" data-customer-id="">No customer selected</div>
                            <button type="button" id="openQuickAddCustomer" class="btn btn-link btn-sm p-0 mt-1">
                                Quick add customer
                            </button>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Valid Until *</label>
                            <input type="date" id="validUntil" class="form-control form-control-sm" required>
                        </div>
                    </div>
                    </div>

                    <div class="quote-block">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label small fw-semibold mb-0">Products</label>
                            <button type="button" id="openProductPicker" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-grid-3x3-gap"></i> Product Picker
                            </button>
                        </div>
                        <div class="small text-muted mt-2">
                            Click <strong>Product Picker</strong> to browse by category and add items to this quote.
                        </div>
                    </div>

                    <div class="quote-block">
                        <label class="form-label small fw-semibold">Quote Items</label>
                        <div id="quoteItems" class="border rounded p-2" style="min-height: 100px; max-height: 300px; overflow-y: auto;">
                            <div class="text-muted small">No items added yet</div>
                        </div>
                    </div>

                    <div class="quote-block">
                    <div class="row g-3 mb-1">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Discount Type</label>
                            <select id="discountType" class="form-select form-select-sm">
                                <option value="percent">Percentage (%)</option>
                                <option value="fixed">Fixed Amount ($)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Discount Value</label>
                            <input type="number" id="discountValue" class="form-control form-control-sm" min="0" step="0.01" value="0">
                            <div class="small text-danger d-none" id="discountError">Discount exceeds subtotal. It has been capped.</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="reserveSerials">
                                <label class="form-check-label small fw-semibold" for="reserveSerials">Reserve Specific Serials</label>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div class="alert alert-warning py-2 small d-none" id="compatibilityWarning"></div>

                    <div class="quote-summary mb-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="label">Subtotal</div>
                            <div class="value"><span id="quoteSubtotal">0.00</span></div>
                        </div>
                        <div class="col-md-4">
                            <div class="label">Discount</div>
                            <div class="value"><span id="quoteDiscount">0.00</span></div>
                        </div>
                        <div class="col-md-4">
                            <div class="label">Grand Total</div>
                            <div class="value text-success"><span id="quoteTotal">0.00</span></div>
                        </div>
                    </div>
                    </div>

                    <div class="quote-block mb-0">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea id="quoteNotes" class="form-control form-control-sm" rows="2" placeholder="Optional notes for customer..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="saveQuoteBtn">Save Quote</button>
            </div>
        </div>
    </div>
</div>

<!-- Product Picker Modal -->
<div class="modal fade" id="productPickerModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Product Picker</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-3">
                        <label class="form-label small fw-semibold">Categories</label>
                        <div id="pickerCategories" class="list-group list-group-sm">
                            <button type="button" class="list-group-item list-group-item-action active" data-category-id="">All Products</button>
                            <?php
                            $pickerCategories = $db->fetchAll("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name");
                            foreach ($pickerCategories as $pickerCategory):
                            ?>
                            <button type="button" class="list-group-item list-group-item-action" data-category-id="<?php echo (int)$pickerCategory['id']; ?>">
                                <?php echo escape($pickerCategory['name']); ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-lg-9">
                        <div class="mb-2">
                            <input type="text" id="pickerSearch" class="form-control form-control-sm" placeholder="Search by product name or SKU">
                        </div>
                        <div id="productPickerGrid" class="row g-2"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal -->
<div class="modal fade" id="quickAddCustomer" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickCustomerForm">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">First Name *</label>
                        <input type="text" id="customerFirstName" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Last Name *</label>
                        <input type="text" id="customerLastName" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Phone</label>
                        <input type="text" id="customerPhone" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Email</label>
                        <input type="email" id="customerEmail" class="form-control form-control-sm">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="addCustomerBtn">Add Customer</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const baseUrl = "<?php echo getBaseUrl(); ?>";
    const csrf = "<?php echo generateCSRFToken(); ?>";
    const quoteModalEl = document.getElementById("newQuoteModal");
    const customerModalEl = document.getElementById("quickAddCustomer");
    const pickerModalEl = document.getElementById("productPickerModal");
    const quoteModal = new bootstrap.Modal(quoteModalEl);
    const customerModal = new bootstrap.Modal(customerModalEl);
    const pickerModal = new bootstrap.Modal(pickerModalEl);
    
    let quoteCart = [];
    let selectedCustomerId = null;
    let reopenQuoteAfterCustomerModal = false;
    let reopenQuoteAfterPickerModal = false;
    let pickerCategoryId = "";
    let pickerProductsMap = new Map();

    const el = (id) => document.getElementById(id);
    const fmt = (n) => Number(n || 0).toFixed(2);
    const quotePreview = document.createElement("div");
    quotePreview.className = "position-fixed d-none border rounded bg-white shadow-sm p-1";
    quotePreview.style.width = "120px";
    quotePreview.style.zIndex = "1080";
    document.body.appendChild(quotePreview);
    const toastContainer = document.createElement("div");
    toastContainer.id = "quoteToastContainer";
    toastContainer.className = "toast-container position-fixed top-0 end-0 p-3";
    toastContainer.style.zIndex = "1090";
    document.body.appendChild(toastContainer);

    const quoteRows = document.querySelectorAll(".quote-row");
    const quoteActionEmpty = el("quoteActionEmpty");
    const quoteActionPanel = el("quoteActionPanel");
    const qaQuoteNumber = el("qa-quote-number");
    const qaCustomer = el("qa-customer");
    const qaStatus = el("qa-status");
    const qaConvertSale = el("qa-convert-sale");
    const qaConvertService = el("qa-convert-service");
    const qaDownload = el("qa-download");
    const qaEmail = el("qa-email");
    const qaHistory = el("qa-history");
    const qaTimeline = el("qa-timeline");
    const qaLineItems = el("qa-line-items");
    const qaSummaryMeta = el("qa-summary-meta");
    const qaNotes = el("qa-notes");

    function formatDateLabel(raw) {
        if (!raw) return null;
        const d = new Date(raw.replace(' ', 'T'));
        if (isNaN(d.getTime())) return raw;
        return d.toLocaleString();
    }

    function setActionPanel(data) {
        if (!data || !quoteActionPanel) return;
        if (quoteActionEmpty) quoteActionEmpty.classList.add("d-none");
        quoteActionPanel.classList.remove("d-none");

        qaQuoteNumber.textContent = data.quote_number || "Quote";
        qaCustomer.textContent = data.customer_name || "Customer";
        qaStatus.textContent = data.status_label || data.status || "Draft";
        qaStatus.className = "status-pill status-" + (data.status || "draft");
        qaSummaryMeta.textContent = `Expires ${data.valid_until || "N/A"} | Amount $${fmt(data.total_amount)}`;
        qaNotes.textContent = data.notes ? `Notes: ${data.notes}` : "No internal notes for this quote.";

        const quoteId = data.id;
        qaDownload.href = `${baseUrl}/quote.php?id=${encodeURIComponent(String(quoteId))}`;
        if (data.customer_email) {
            qaEmail.href = `mailto:${data.customer_email}?subject=${encodeURIComponent(`Quote ${data.quote_number}`)}`;
            qaEmail.classList.remove("disabled");
        } else {
            qaEmail.href = "#";
            qaEmail.classList.add("disabled");
        }

        qaConvertSale.onclick = () => {
            window.location.href = `${baseUrl}/pos.php?mode=pos&quote_id=${encodeURIComponent(String(quoteId))}`;
        };
        qaConvertService.onclick = () => {
            window.location.href = `${baseUrl}/repairs.php?quote_id=${encodeURIComponent(String(quoteId))}`;
        };

        qaHistory.innerHTML = "";
        (data.audit_log || []).forEach((text) => {
            const li = document.createElement("li");
            li.textContent = text;
            qaHistory.appendChild(li);
        });
        if (!qaHistory.children.length) qaHistory.innerHTML = "<li>No audit events recorded yet.</li>";

        qaTimeline.innerHTML = "";
        (data.timeline || []).forEach((entry) => {
            const li = document.createElement("li");
            li.textContent = `${entry.label} | ${formatDateLabel(entry.timestamp) || "N/A"}`;
            qaTimeline.appendChild(li);
        });
        if (!qaTimeline.children.length) qaTimeline.innerHTML = "<li>No communication events recorded yet.</li>";

        qaLineItems.innerHTML = "";
        (data.line_items || []).forEach((item) => {
            const li = document.createElement("li");
            li.innerHTML = `<span>${item.product_name} x ${item.quantity}</span><strong>$${fmt(item.line_total)}</strong>`;
            qaLineItems.appendChild(li);
        });
        if (!qaLineItems.children.length) qaLineItems.innerHTML = "<li><span>No line items</span><strong>$0.00</strong></li>";
    }

    quoteRows.forEach((row) => {
        row.addEventListener("click", () => {
            quoteRows.forEach((r) => r.classList.remove("selected"));
            row.classList.add("selected");
            const payload = row.dataset.quote ? JSON.parse(row.dataset.quote) : null;
            setActionPanel(payload);
        });
    });
    if (quoteRows.length) {
        quoteRows[0].click();
    }

    function showToast(message) {
        const toastEl = document.createElement("div");
        toastEl.className = "toast align-items-center text-bg-dark border-0";
        toastEl.setAttribute("role", "alert");
        toastEl.setAttribute("aria-live", "assertive");
        toastEl.setAttribute("aria-atomic", "true");
        toastEl.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;
        toastContainer.appendChild(toastEl);
        if (window.bootstrap && bootstrap.Toast) {
            const instance = new bootstrap.Toast(toastEl, { delay: 1300 });
            toastEl.addEventListener("hidden.bs.toast", () => toastEl.remove());
            instance.show();
        } else {
            setTimeout(() => toastEl.remove(), 1300);
        }
    }

    // Set default valid until date (7 days from now)
    const defaultExpiry = new Date();
    defaultExpiry.setDate(defaultExpiry.getDate() + 7);
    el("validUntil").valueAsDate = defaultExpiry;

    // Customer search
    let customerTimer = null;
    el("customerSearch").addEventListener("keyup", (e) => {
        clearTimeout(customerTimer);
        customerTimer = setTimeout(() => searchCustomers(e.target.value), 300);
    });

    async function searchCustomers(q) {
        const resultBox = el("customerResults");
        if (!q.trim()) {
            resultBox.classList.add("d-none");
            resultBox.innerHTML = "";
            return;
        }
        try {
            const res = await fetch(`${baseUrl}/customers.php?ajax=1&action=search&search=${encodeURIComponent(q)}`);
            const data = await res.json();
            resultBox.innerHTML = "";
            if (!data.success || !data.customers || !data.customers.length) {
                resultBox.classList.add("d-none");
                return;
            }
            data.customers.forEach((c) => {
                const btn = document.createElement("button");
                btn.type = "button";
                btn.className = "list-group-item list-group-item-action";
                btn.textContent = `${c.first_name} ${c.last_name} (${c.phone || "no phone"})`;
                btn.addEventListener("click", (e) => {
                    e.preventDefault();
                    selectCustomer(c);
                });
                resultBox.appendChild(btn);
            });
            resultBox.classList.remove("d-none");
        } catch (e) {
            console.error("Customer search error:", e);
            resultBox.classList.add("d-none");
        }
    }

    function selectCustomer(c) {
        selectedCustomerId = c.id;
        el("customerSearch").value = `${c.first_name} ${c.last_name}`;
        el("selectedCustomer").textContent = `Selected: ${c.first_name} ${c.last_name}`;
        el("selectedCustomer").dataset.customerId = String(c.id);
        el("customerResults").classList.add("d-none");
    }

    // Modal handoff: avoid stacked modals/backdrops causing stuck gray screen.
    el("openQuickAddCustomer").addEventListener("click", (e) => {
        e.preventDefault();
        reopenQuoteAfterCustomerModal = true;
        quoteModal.hide();
    });

    customerModalEl.addEventListener("hidden.bs.modal", () => {
        if (reopenQuoteAfterCustomerModal) {
            reopenQuoteAfterCustomerModal = false;
            quoteModal.show();
        }
        // Safety cleanup for any stale backdrop/body class.
        document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
    });

    // Product picker modal handoff
    el("openProductPicker").addEventListener("click", (e) => {
        e.preventDefault();
        reopenQuoteAfterPickerModal = true;
        quoteModal.hide();
    });

    quoteModalEl.addEventListener("hidden.bs.modal", () => {
        if (reopenQuoteAfterCustomerModal) {
            customerModal.show();
            return;
        }
        if (reopenQuoteAfterPickerModal) {
            pickerModal.show();
            loadPickerProducts();
        }
    });

    pickerModalEl.addEventListener("hidden.bs.modal", () => {
        if (reopenQuoteAfterPickerModal) {
            reopenQuoteAfterPickerModal = false;
            quoteModal.show();
        }
        document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
    });

    // Product picker
    let pickerTimer = null;
    el("productPickerGrid").addEventListener("click", (event) => {
        const card = event.target.closest(".product-picker-card");
        if (!card) return;
        const productId = String(card.dataset.productId || "");
        let product = pickerProductsMap.get(productId);
        if (!product && card.dataset.productJson) {
            try {
                product = JSON.parse(card.dataset.productJson);
            } catch (_) {}
        }
        if (!product) return;
        const qtyInput = card.querySelector(".picker-qty");
        const qty = Number(qtyInput?.value || 1);
        try {
            addQuoteItem(product, qty);
        } catch (err) {
            alert("Unable to add product to quote. " + (err?.message || ""));
        }
        if (qtyInput) qtyInput.value = "1";
    });

    el("pickerSearch").addEventListener("keyup", () => {
        clearTimeout(pickerTimer);
        pickerTimer = setTimeout(loadPickerProducts, 250);
    });

    el("pickerCategories").addEventListener("click", (event) => {
        const btn = event.target.closest("[data-category-id]");
        if (!btn) return;
        pickerCategoryId = String(btn.dataset.categoryId || "");
        [...el("pickerCategories").querySelectorAll(".list-group-item")].forEach((node) => node.classList.remove("active"));
        btn.classList.add("active");
        loadPickerProducts();
    });

    async function loadPickerProducts() {
        const resultGrid = el("productPickerGrid");
        const q = el("pickerSearch").value.trim();
        try {
            const url = `${baseUrl}/product_list.php?ajax=1&action=search&search=${encodeURIComponent(q)}&category_id=${encodeURIComponent(pickerCategoryId)}`;
            const res = await fetch(url);
            if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
            const data = await res.json();
            resultGrid.innerHTML = "";
            pickerProductsMap = new Map();
            if (!data.success || !data.products || !data.products.length) {
                resultGrid.innerHTML = '<div class="col-12"><div class="text-muted small p-2">No products found</div></div>';
                return;
            }
            data.products.forEach((p) => {
                const normalizedId = p.id ?? p.product_id ?? null;
                const normalizedProduct = {
                    ...p,
                    id: normalizedId,
                    product_id: normalizedId
                };
                const productId = String(normalizedId ?? "");
                pickerProductsMap.set(productId, normalizedProduct);
                const div = document.createElement("div");
                div.className = "col-md-6 col-xl-4";
                const stock = Number(normalizedProduct.stock_quantity || 0);
                const isBackorder = stock <= 0;
                const stockBadge = isBackorder
                    ? '<span class="badge bg-warning text-dark">Backorder</span>'
                    : `<span class="badge bg-success-subtle text-success">In Stock: ${stock}</span>`;
                div.innerHTML = `
                    <div class="card h-100 product-picker-card" data-product-id="${productId}" data-product-json='${JSON.stringify(normalizedProduct).replace(/'/g, "&#39;")}' style="cursor:pointer;">
                        <div class="card-body p-2">
                            <div class="d-flex align-items-start gap-2">
                                <div style="width:44px;height:44px;flex-shrink:0;" class="border rounded overflow-hidden bg-light">
                                    ${normalizedProduct.image ? `<img src="${normalizedProduct.image}" alt="${normalizedProduct.name}" style="width:100%;height:100%;object-fit:cover;">` : ''}
                                </div>
                                <div class="flex-grow-1">
                                    <div class="small fw-semibold">${normalizedProduct.name}</div>
                                    <div class="small text-muted">${normalizedProduct.sku}</div>
                                    <div class="small text-muted">$${fmt(normalizedProduct.selling_price || normalizedProduct.sell_price || 0)} | ${stockBadge}</div>
                                </div>
                            </div>
                            <div class="mt-2 d-flex justify-content-end gap-2">
                                <input type="number" min="1" max="${Math.max(1, stock)}" value="1" class="form-control form-control-sm picker-qty" style="width:72px;">
                                <button type="button" class="btn btn-primary btn-sm picker-add-btn">Add</button>
                            </div>
                        </div>
                    </div>
                `;
                const qtyInput = div.querySelector(".picker-qty");
                const addBtn = div.querySelector(".picker-add-btn");
                qtyInput.addEventListener("click", (ev) => ev.stopPropagation());
                addBtn.addEventListener("click", (ev) => {
                    ev.preventDefault();
                    ev.stopPropagation();
                    try {
                        addQuoteItem(normalizedProduct, Number(qtyInput.value || 1));
                        qtyInput.value = "1";
                    } catch (err) {
                        alert("Unable to add product to quote. " + (err?.message || ""));
                    }
                });
                resultGrid.appendChild(div);
            });
        } catch (e) {
            console.error("Product search error:", e);
            resultGrid.innerHTML = '<div class="col-12"><div class="text-danger small p-2">Error loading products: ' + e.message + '</div></div>';
        }
    }

    function addQuoteItem(product, qty) {
        const normalizedProductId = Number(product.id ?? product.product_id ?? 0);
        if (!normalizedProductId) {
            throw new Error("Product ID missing in picker payload.");
        }
        let existing = quoteCart.find(x => Number(x.product_id) === normalizedProductId);
        const normalizedQty = Math.max(1, Number(qty || 1));
        const stock = Number(product.stock_quantity || 0);
        if (existing) {
            existing.quantity += normalizedQty;
        } else {
            quoteCart.push({
                product_id: normalizedProductId,
                name: product.name,
                sku: product.sku,
                image: product.image || null,
                category_name: product.category_name || "",
                brand: product.brand || "",
                unit_price: Number(product.selling_price || product.sell_price || 0),
                quantity: normalizedQty,
                stock_quantity: stock,
                is_backorder: stock <= 0,
                sort_order: quoteCart.length
            });
        }
        checkCompatibilityWarning();
        renderQuoteCart();
        showToast(`Added: ${product.name || "Product"}`);
    }

    function renderQuoteCart() {
        const container = el("quoteItems");
        container.innerHTML = "";
        if (!quoteCart.length) {
            container.innerHTML = '<div class="text-muted small">No items added yet</div>';
        } else {
            quoteCart.forEach((item, idx) => {
                const row = document.createElement("div");
                row.className = "d-flex justify-content-between align-items-center border-bottom py-2 quote-item-row";
                row.draggable = true;
                row.dataset.index = String(idx);
                const lineTotal = item.unit_price * item.quantity;
                row.innerHTML = `
                    <div class="flex-grow-1">
                        <div class="small fw-semibold ${item.is_backorder ? 'text-warning-emphasis' : ''}">
                            ${item.name} ${item.is_backorder ? '<span class="badge bg-warning text-dark ms-1">Backorder</span>' : ''}
                        </div>
                        <div class="small text-muted">${item.sku} @ $${fmt(item.unit_price)}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <input type="number" min="1" max="${Math.max(1, item.stock_quantity)}" value="${item.quantity}" 
                               class="form-control form-control-sm" style="width: 60px;">
                        <span class="small fw-semibold">$${fmt(lineTotal)}</span>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-btn">x</button>
                    </div>
                `;
                row.querySelector("input").addEventListener("change", (e) => {
                    item.quantity = Math.max(1, Number(e.target.value));
                    renderQuoteCart();
                });
                row.querySelector(".remove-btn").addEventListener("click", () => {
                    quoteCart.splice(idx, 1);
                    reindexSortOrder();
                    renderQuoteCart();
                });
                row.addEventListener("mouseenter", (ev) => {
                    if (!item.image) return;
                    quotePreview.innerHTML = `<img src="${item.image}" alt="${item.name}" class="img-fluid rounded">`;
                    quotePreview.classList.remove("d-none");
                    quotePreview.style.left = `${ev.clientX + 12}px`;
                    quotePreview.style.top = `${ev.clientY + 12}px`;
                });
                row.addEventListener("mousemove", (ev) => {
                    if (quotePreview.classList.contains("d-none")) return;
                    quotePreview.style.left = `${ev.clientX + 12}px`;
                    quotePreview.style.top = `${ev.clientY + 12}px`;
                });
                row.addEventListener("mouseleave", () => quotePreview.classList.add("d-none"));
                attachDnDHandlers(row);
                container.appendChild(row);
            });
        }
        calculateQuoteTotals();
    }

    function reindexSortOrder() {
        quoteCart.forEach((item, i) => {
            item.sort_order = i;
        });
    }

    function attachDnDHandlers(row) {
        row.addEventListener("dragstart", () => row.classList.add("opacity-50"));
        row.addEventListener("dragend", () => row.classList.remove("opacity-50"));
        row.addEventListener("dragover", (e) => e.preventDefault());
        row.addEventListener("drop", (e) => {
            e.preventDefault();
            const from = Number(document.querySelector(".quote-item-row.opacity-50")?.dataset.index ?? -1);
            const to = Number(row.dataset.index ?? -1);
            if (from < 0 || to < 0 || from === to) return;
            const [moved] = quoteCart.splice(from, 1);
            quoteCart.splice(to, 0, moved);
            reindexSortOrder();
            renderQuoteCart();
        });
    }

    function checkCompatibilityWarning() {
        const hasIntelCpu = quoteCart.some((i) => /cpu|processor/i.test(i.category_name || "") && /intel/i.test(i.brand || i.name || ""));
        const hasAmdMotherboard = quoteCart.some((i) => /motherboard/i.test(i.category_name || "") && /amd/i.test(i.brand || i.name || ""));
        const warning = el("compatibilityWarning");
        if (!warning) return;
        if (hasIntelCpu && hasAmdMotherboard) {
            warning.textContent = "Compatibility Warning: Intel CPU and AMD Motherboard may be incompatible.";
            warning.classList.remove("d-none");
        } else {
            warning.classList.add("d-none");
            warning.textContent = "";
        }
    }

    function calculateQuoteTotals() {
        const subtotal = quoteCart.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
        const discountTypeInput = el("discountType");
        const discountValueInput = el("discountValue");
        const discountType = discountTypeInput ? discountTypeInput.value : "percent";
        const discountValue = Math.max(0, Number(discountValueInput ? (discountValueInput.value || 0) : 0));
        let discount = discountType === "percent"
            ? subtotal * (discountValue / 100)
            : discountValue;
        const discountError = el("discountError");
        if (discount > subtotal) {
            discount = subtotal;
            if (discountError) discountError.classList.remove("d-none");
        } else if (discountError) {
            discountError.classList.add("d-none");
        }
        const total = subtotal - discount;
        
        if (el("quoteSubtotal")) el("quoteSubtotal").textContent = fmt(subtotal);
        if (el("quoteDiscount")) el("quoteDiscount").textContent = fmt(discount);
        if (el("quoteTotal")) el("quoteTotal").textContent = fmt(total);
    }
    if (el("discountType")) el("discountType").addEventListener("change", calculateQuoteTotals);
    if (el("discountValue")) el("discountValue").addEventListener("input", calculateQuoteTotals);

    // Quick add customer
    el("addCustomerBtn").addEventListener("click", async () => {
        const fname = el("customerFirstName").value.trim();
        const lname = el("customerLastName").value.trim();
        const phone = el("customerPhone").value.trim();
        const email = el("customerEmail").value.trim();

        if (!fname || !lname) {
            alert("First and last name are required");
            return;
        }

        if (phone && !/^\d{1,11}$/.test(phone)) {
            alert("Phone number must contain digits only and must not exceed 11 digits.");
            return;
        }

        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert("Please enter a complete email address such as name@gmail.com.");
            return;
        }

        el("addCustomerBtn").disabled = true;
        el("addCustomerBtn").textContent = "Adding...";

        try {
            const body = new URLSearchParams();
            body.append("csrf_token", csrf);
            body.append("first_name", fname);
            body.append("last_name", lname);
            body.append("phone", phone);
            body.append("email", email);
            body.append("is_active", "1");

            const res = await fetch(`${baseUrl}/customers.php?ajax=1&action=create`, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString()
            });

            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }

            const data = await res.json();
            
            if (data.success && data.customer) {
                selectCustomer(data.customer);
                customerModal.hide();
                el("customerFirstName").value = "";
                el("customerLastName").value = "";
                el("customerPhone").value = "";
                el("customerEmail").value = "";
                alert(`Customer ${fname} ${lname} added successfully!`);
            } else {
                throw new Error(data.message || "Could not create customer");
            }
        } catch (e) {
            console.error("Customer creation error:", e);
            alert("Error adding customer: " + e.message);
        } finally {
            el("addCustomerBtn").disabled = false;
            el("addCustomerBtn").textContent = "Add Customer";
        }
    });

    // Save quote
    el("saveQuoteBtn").addEventListener("click", async () => {
        if (!selectedCustomerId) {
            alert("Please select a customer");
            return;
        }
        if (!quoteCart.length) {
            alert("Please add at least one product");
            return;
        }

        const validUntil = el("validUntil").value;
        if (!validUntil) {
            alert("Please set a valid until date");
            return;
        }

        const notes = el("quoteNotes").value.trim();

        try {
            el("saveQuoteBtn").disabled = true;
            el("saveQuoteBtn").textContent = "Saving...";

            const res = await fetch(`${baseUrl}/quote_management.php?ajax=1&action=create_quote`, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    csrf_token: csrf,
                    customer_id: selectedCustomerId,
                    valid_until: validUntil,
                    notes: notes,
                    discount_type: el("discountType").value,
                    discount_value: el("discountValue").value || "0",
                    reserve_serials: el("reserveSerials").checked ? "1" : "0",
                    items: JSON.stringify(quoteCart)
                })
            });

            const text = await res.text();
            let data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (_) {
                data = null;
            }
            if (!res.ok) {
                const msg = data?.message || (text ? text.replace(/<[^>]+>/g, '').trim() : '') || `HTTP error! status: ${res.status}`;
                throw new Error(msg);
            }
            if (!data) throw new Error("Invalid server response.");
            if (data.success && data.quote_number) {
                alert(`Quote ${data.quote_number} created successfully!`);
                console.log("Quote created:", data);
                quoteModal.hide();
                // Reset form
                quoteCart = [];
                selectedCustomerId = null;
                el("customerSearch").value = "";
                el("selectedCustomer").textContent = "No customer selected";
                el("quoteNotes").value = "";
                el("pickerSearch").value = "";
                el("productPickerGrid").innerHTML = "";
                renderQuoteCart();
                // Reload page to show new quote
                setTimeout(() => location.reload(), 1500);
            } else {
                throw new Error(data.message || "Could not create quote");
            }
        } catch (e) {
            console.error("Quote save error:", e);
            alert("Error creating quote: " + e.message);
        } finally {
            el("saveQuoteBtn").disabled = false;
            el("saveQuoteBtn").textContent = "Save Quote";
        }
    });
});
</script>

<?php include 'templates/footer.php'; ?>







