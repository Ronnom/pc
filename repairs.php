<?php
/**
 * Service / Repair Module
 * Repair request intake, diagnosis, parts usage with stock deduction, labor, status, history.
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('repairs.view');

$db = getDB();
$pageTitle = 'Service & Repair';

// Resolve warranty table name (prefer plural `warranties` if present)
$warranty_table = getWarrantyTableName();

function srTableExists($db, $tableName) {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?",
        [$tableName]
    );
    return (int)($row['cnt'] ?? 0) > 0;
}

function srColumnIsNullable($db, $tableName, $columnName) {
    $row = $db->fetchOne(
        "SELECT IS_NULLABLE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$tableName, $columnName]
    );
    return strtoupper((string)($row['IS_NULLABLE'] ?? '')) === 'YES';
}

function srColumnType($db, $tableName, $columnName) {
    $row = $db->fetchOne(
        "SELECT COLUMN_TYPE
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?",
        [$tableName, $columnName]
    );
    return (string)($row['COLUMN_TYPE'] ?? '');
}

if (!srTableExists($db, 'repairs')) {
    setFlashMessage('error', 'Repairs table is missing. Run database schema updates first.');
    include 'templates/header.php';
    echo '<div class="alert alert-danger">Service/Repair module is unavailable because required tables are missing.</div>';
    include 'templates/footer.php';
    exit;
}
$hasRepairParts = srTableExists($db, 'repair_parts');

$statusPipeline = ['received', 'diagnosing', 'repairing', 'ready'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'create_repair') {
            requirePermission('repairs.create');

            $customerId = (int)($_POST['customer_id'] ?? 0);
            $preferredContact = trim((string)($_POST['preferred_contact'] ?? ''));
            $productId = (int)($_POST['product_id'] ?? 0);
            $productIdValue = $productId > 0 ? $productId : null;
            $deviceType = trim((string)($_POST['device_type'] ?? ''));
            $brand = trim((string)($_POST['brand'] ?? ''));
            $model = trim((string)($_POST['model'] ?? ''));
            $serial = trim((string)($_POST['serial_number'] ?? ''));
            $imei = trim((string)($_POST['imei'] ?? ''));
            $requestDate = trim((string)($_POST['request_date'] ?? date('Y-m-d')));
            $preInspection = trim((string)($_POST['pre_repair_inspection'] ?? ''));
            $reportedIssue = trim((string)($_POST['customer_reported_issue'] ?? ''));
            $techDiagnosis = trim((string)($_POST['technician_diagnosis'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));
            $initialQuote = (float)($_POST['initial_quote'] ?? 0);
            $warrantyStatus = trim((string)($_POST['warranty_status'] ?? 'billable'));
            $chargerIncluded = !empty($_POST['charger_included']);
            $externalDamage = !empty($_POST['external_damage']);
            $checkinLine = 'Check-in: Charger Included: ' . ($chargerIncluded ? 'Yes' : 'No') . '; External Damage: ' . ($externalDamage ? 'Yes' : 'No');
            if ($preInspection !== '') {
                $preInspection .= "\n" . $checkinLine;
            } else {
                $preInspection = $checkinLine;
            }

            if ($serial === '') {
                throw new Exception('Serial number is required.');
            }
            if ($productIdValue === null && !srColumnIsNullable($db, 'repairs', 'product_id')) {
                $colType = srColumnType($db, 'repairs', 'product_id');
                if ($colType !== '') {
                    try {
                        $db->query("ALTER TABLE repairs MODIFY COLUMN product_id {$colType} NULL");
                    } catch (Exception $e) {
                        // If alter fails, continue; insert will surface the real error.
                    }
                }
            }
            if ($deviceType === '') {
                $deviceType = 'other';
            }
            if (!in_array($preferredContact, ['sms','email'], true)) {
                $preferredContact = '';
            }

            $warrantyId = null;
            if ($serial !== '' && $warranty_table !== null && $productIdValue !== null) {
                $warranty = $db->fetchOne(
                    "SELECT id FROM " . $warranty_table . "
                     WHERE product_id = ?
                         AND serial_number = ?
                         AND status IN ('active','claimed')
                     ORDER BY id DESC
                     LIMIT 1",
                    [$productIdValue, $serial]
                );
                $warrantyId = $warranty['id'] ?? null;
            }

            $db->insert('repairs', [
                'customer_id' => $customerId > 0 ? $customerId : null,
                'preferred_contact' => $preferredContact !== '' ? $preferredContact : null,
                'product_id' => $productIdValue,
                'device_type' => $deviceType,
                'brand' => $brand !== '' ? $brand : null,
                'model' => $model !== '' ? $model : null,
                'serial_number' => $serial !== '' ? $serial : null,
                'imei' => $imei !== '' ? $imei : null,
                'pre_repair_inspection' => $preInspection !== '' ? $preInspection : null,
                'customer_reported_issue' => $reportedIssue !== '' ? $reportedIssue : null,
                'technician_diagnosis' => null,
                'warranty_status' => in_array($warrantyStatus, ['billable','warranty','internal'], true) ? $warrantyStatus : 'billable',
                'initial_quote' => max(0, $initialQuote),
                'warranty_id' => $warrantyId,
                'request_date' => $requestDate,
                'diagnosis' => null,
                'labor_charge' => 0,
                'status' => 'received',
                'notes' => $notes !== '' ? $notes : null
            ]);

            $repairId = (int)$db->fetchOne("SELECT LAST_INSERT_ID() as id")['id'];

            // Accessories log
            if (srTableExists($db, 'repair_accessories')) {
                $accessories = $_POST['accessories'] ?? [];
                if (is_array($accessories)) {
                    foreach ($accessories as $name => $present) {
                        $db->insert('repair_accessories', [
                            'repair_id' => $repairId,
                            'accessory_name' => (string)$name,
                            'present' => !empty($present) ? 1 : 0
                        ]);
                    }
                }
            }

            // Labor tracking
            // QC defaults
            if (srTableExists($db, 'repair_qc')) {
                $db->insert('repair_qc', [
                    'repair_id' => $repairId,
                    'power_tested' => 0,
                    'stress_test_passed' => 0,
                    'exterior_cleaned' => 0,
                    'wifi_tested' => 0,
                    'ports_tested' => 0,
                    'stress_test_minutes' => 0,
                    'cleaning_done' => 0
                ]);
            }

            setFlashMessage('success', 'Repair request registered.');
            redirect(getBaseUrl() . '/repairs.php');
        }

        if ($action === 'update_repair') {
            requirePermission('repairs.edit');
            $repairId = (int)($_POST['repair_id'] ?? 0);
            $status = trim((string)($_POST['status'] ?? 'received'));
            $diagnosis = trim((string)($_POST['diagnosis'] ?? ''));
            $reportedIssue = trim((string)($_POST['customer_reported_issue'] ?? ''));
            $techDiagnosis = trim((string)($_POST['technician_diagnosis'] ?? ''));
            $warrantyStatus = trim((string)($_POST['warranty_status'] ?? 'billable'));
            $labor = (float)($_POST['labor_charge'] ?? 0);
            $notes = trim((string)($_POST['notes'] ?? ''));
            $totalQuote = (float)($_POST['total_quote'] ?? 0);
            $chargerIncluded = !empty($_POST['charger_included']);
            $externalDamage = !empty($_POST['external_damage']);
            $checkinLine = 'Check-in: Charger Included: ' . ($chargerIncluded ? 'Yes' : 'No') . '; External Damage: ' . ($externalDamage ? 'Yes' : 'No');
            if ($notes === '') {
                $notes = $checkinLine;
            } elseif (stripos($notes, 'Check-in:') === false) {
                $notes .= "\n" . $checkinLine;
            }

            if ($repairId <= 0) {
                throw new Exception('Invalid repair ID.');
            }
            if (!in_array($status, $statusPipeline, true)) {
                throw new Exception('Invalid repair status.');
            }

            $db->query(
                "UPDATE repairs
                 SET status = ?, diagnosis = ?, labor_charge = ?, notes = ?, updated_at = NOW()
                 WHERE id = ?",
                [$status, $diagnosis !== '' ? $diagnosis : null, max(0, $labor), $notes !== '' ? $notes : null, $repairId]
            );

            if (tableColumnExists('repairs', 'customer_reported_issue')) {
                $db->query("UPDATE repairs SET customer_reported_issue = ?, technician_diagnosis = ?, warranty_status = ? WHERE id = ?",
                    [$reportedIssue !== '' ? $reportedIssue : null,
                     $techDiagnosis !== '' ? $techDiagnosis : null,
                     in_array($warrantyStatus, ['billable','warranty','internal'], true) ? $warrantyStatus : 'billable',
                     $repairId]
                );
            }
            if (tableColumnExists('repairs', 'initial_quote')) {
                $db->query("UPDATE repairs SET initial_quote = ? WHERE id = ?", [max(0, $totalQuote), $repairId]);
            }

            setFlashMessage('success', 'Repair updated.');
            redirect(getBaseUrl() . '/repairs.php');
        }

        if ($action === 'add_part') {
            requirePermission('repairs.complete');

            if (!srTableExists($db, 'repair_parts')) {
                throw new Exception('repair_parts table is missing.');
            }

            $repairId = (int)($_POST['repair_id'] ?? 0);
            $partProductId = (int)($_POST['part_product_id'] ?? 0);
            $qty = max(1, (int)($_POST['quantity'] ?? 1));

            if ($repairId <= 0 || $partProductId <= 0) {
                throw new Exception('Repair and part product are required.');
            }

            $part = $db->fetchOne("SELECT id, name, cost_price, stock_quantity FROM products WHERE id = ?", [$partProductId]);
            if (!$part) {
                throw new Exception('Part product not found.');
            }
            if ((int)$part['stock_quantity'] < $qty) {
                throw new Exception('Insufficient stock for selected part.');
            }

            $unitCost = (float)($part['cost_price'] ?? 0);
            $totalCost = $unitCost * $qty;

            $db->beginTransaction();
            try {
                $db->insert('repair_parts', [
                    'repair_id' => $repairId,
                    'product_id' => $partProductId,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost
                ]);

                $db->query(
                    "UPDATE products
                     SET stock_quantity = stock_quantity - ?
                     WHERE id = ?",
                    [$qty, $partProductId]
                );

                if (srTableExists($db, 'stock_movements')) {
                    $db->insert('stock_movements', [
                        'product_id' => $partProductId,
                        'movement_type' => 'out',
                        'quantity' => $qty,
                        'reference_type' => 'repair',
                        'reference_id' => $repairId,
                        'notes' => 'Part used in repair #' . $repairId,
                        'created_by' => getCurrentUserId()
                    ]);
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }

            setFlashMessage('success', 'Part added and inventory deducted.');
            redirect(getBaseUrl() . '/repairs.php');
        }

        if ($action === 'collect_payment') {
            requirePermission('sales.create');

            $repairId = (int)($_POST['repair_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            if ($repairId <= 0) {
                throw new Exception('Invalid repair ID.');
            }

            $repair = $db->fetchOne(
                "SELECT r.*, CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
                 FROM repairs r
                 LEFT JOIN customers c ON c.id = r.customer_id
                 WHERE r.id = ? LIMIT 1",
                [$repairId]
            );
            if (!$repair) {
                throw new Exception('Repair not found.');
            }
            if (($repair['status'] ?? '') !== 'ready') {
                throw new Exception('Only Ready repairs can be paid.');
            }
            if ($amount <= 0) {
                $amount = (float)($repair['initial_quote'] ?? 0);
            }
            if ($amount < 0) {
                $amount = 0;
            }

            $legacyServiceSku = 'REPAIR-SERVICE';
            $serviceSku = 'SERVICE-FEE';
            $serviceName = 'Service Fee';
            $serviceDescription = 'Service fee charge';
            $service = $db->fetchOne("SELECT id FROM products WHERE sku = ? LIMIT 1", [$serviceSku]);
            if (!$service) {
                $legacyService = $db->fetchOne("SELECT id FROM products WHERE sku = ? LIMIT 1", [$legacyServiceSku]);
                if ($legacyService) {
                    $db->query(
                        "UPDATE products SET sku = ?, name = ?, description = ?, is_active = 0 WHERE id = ?",
                        [$serviceSku, $serviceName, $serviceDescription, (int)$legacyService['id']]
                    );
                } else {
                    $db->insert('products', [
                        'sku' => $serviceSku,
                        'name' => $serviceName,
                        'description' => $serviceDescription,
                        'cost_price' => 0.00,
                        'sell_price' => 0.00,
                        'stock_quantity' => 0,
                        'is_active' => 0
                    ]);
                }
                $service = $db->fetchOne("SELECT id FROM products WHERE sku = ? LIMIT 1", [$serviceSku]);
            }
            $serviceId = (int)($service['id'] ?? 0);
            if ($serviceId <= 0) {
                throw new Exception('Unable to create service product for repairs.');
            }

            $db->beginTransaction();
            try {
                $transactionNumber = generateTransactionNumber();
                $db->insert('transactions', [
                    'transaction_number' => $transactionNumber,
                    'customer_id' => $repair['customer_id'] ?: null,
                    'user_id' => getCurrentUserId(),
                    'transaction_date' => date('Y-m-d H:i:s'),
                    'subtotal' => $amount,
                    'tax_amount' => 0.00,
                    'discount_amount' => 0.00,
                    'total_amount' => $amount,
                    'status' => 'completed',
                    'payment_status' => 'paid',
                    'notes' => 'Repair payment for repair #' . $repairId
                ]);
                $txId = (int)$db->fetchOne("SELECT LAST_INSERT_ID() AS id")['id'];

                $db->insert('transaction_items', [
                    'transaction_id' => $txId,
                    'product_id' => $serviceId,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'discount_amount' => 0.00,
                    'tax_amount' => 0.00,
                    'subtotal' => $amount,
                    'total' => $amount
                ]);

                $note = trim((string)($repair['notes'] ?? ''));
                $note = ($note !== '' ? $note . "\n" : '') . 'Paid via transaction ' . $transactionNumber;
                $db->query("UPDATE repairs SET status = 'completed', notes = ? WHERE id = ?", [$note, $repairId]);

                $resolvedWarrantyId = !empty($repair['warranty_id']) ? (int)$repair['warranty_id'] : null;
                if ($resolvedWarrantyId === null && $warranty_table !== null) {
                    $repairSerial = trim((string)($repair['serial_number'] ?? ''));
                    if ($repairSerial !== '') {
                        $warrantyRow = $db->fetchOne(
                            "SELECT id FROM {$warranty_table} WHERE serial_number = ? ORDER BY id DESC LIMIT 1",
                            [$repairSerial]
                        );
                        if (!empty($warrantyRow['id'])) {
                            $resolvedWarrantyId = (int)$warrantyRow['id'];
                        }
                    }
                }

                if (!empty($resolvedWarrantyId) && $warranty_table !== null) {
                    $db->query(
                        "UPDATE {$warranty_table}
                         SET status = 'completed'
                         WHERE id = ?",
                        [$resolvedWarrantyId]
                    );
                    if (srTableExists($db, 'warranty_claims')) {
                        $db->query(
                            "UPDATE warranty_claims
                             SET status = 'completed'
                             WHERE warranty_id = ?
                               AND status <> 'completed'",
                            [$resolvedWarrantyId]
                        );
                    }
                }

                $db->commit();
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }

            setFlashMessage('success', 'Payment recorded and repair completed.');
            redirect(getBaseUrl() . '/repairs.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        redirect(getBaseUrl() . '/repairs.php');
    }
}

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => in_array($_GET['status'] ?? '', $statusPipeline, true) ? $_GET['status'] : '',
    'customer' => (int)($_GET['customer'] ?? 0),
    'product' => (int)($_GET['product'] ?? 0),
    'page' => max(1, (int)($_GET['page'] ?? 1)),
    'per_page' => min(100, max(10, (int)($_GET['per_page'] ?? 100)))
];

$where = [];
$whereParams = [];

if ($filters['q'] !== '') {
    $like = '%' . $filters['q'] . '%';
    $where[] = "(r.serial_number LIKE ? OR p.name LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
    $whereParams[] = $like;
    $whereParams[] = $like;
    $whereParams[] = $like;
    $whereParams[] = $like;
}
if ($filters['status'] !== '') {
    $where[] = "r.status = ?";
    $whereParams[] = $filters['status'];
}
if ($filters['customer'] > 0) {
    $where[] = "r.customer_id = ?";
    $whereParams[] = $filters['customer'];
}
if ($filters['product'] > 0) {
    $where[] = "r.product_id = ?";
    $whereParams[] = $filters['product'];
}

$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
$offset = ($filters['page'] - 1) * $filters['per_page'];

$partsJoin = $hasRepairParts ? "LEFT JOIN (
        SELECT repair_id, SUM(total_cost) AS parts_cost
        FROM repair_parts
        GROUP BY repair_id
     ) parts ON parts.repair_id = r.id" : "";
$partsSelect = $hasRepairParts ? "COALESCE(parts.parts_cost, 0) AS parts_cost" : "0 AS parts_cost";

$repairs = $db->fetchAll(
    "SELECT r.*,
            p.name AS product_name, p.sku,
            CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name,
            {$partsSelect}
     FROM repairs r
     LEFT JOIN products p ON p.id = r.product_id
     LEFT JOIN customers c ON c.id = r.customer_id
     {$partsJoin}
     {$whereSql}
     ORDER BY r.created_at DESC
     LIMIT " . (int)$filters['per_page'] . " OFFSET " . (int)$offset,
    $whereParams
);

$readyRepairs = $db->fetchAll(
    "SELECT r.id, r.customer_id, r.initial_quote, r.serial_number, r.model, r.customer_reported_issue,
            CONCAT(COALESCE(c.first_name,''), ' ', COALESCE(c.last_name,'')) AS customer_name
     FROM repairs r
     LEFT JOIN customers c ON c.id = r.customer_id
     WHERE r.status = 'ready'
     ORDER BY r.updated_at DESC
     LIMIT 100"
);

$products = $db->fetchAll("SELECT id, name, sku, stock_quantity FROM products ORDER BY name ASC LIMIT 400");
$customers = $db->fetchAll("SELECT id, first_name, last_name FROM customers ORDER BY first_name ASC, last_name ASC LIMIT 400");

include 'templates/header.php';
?>
<?php
// Build grouped status columns for job board (4 columns)
$boardStatuses = ['received', 'diagnosing', 'repairing', 'ready'];
$repairsByStatus = [];
foreach ($boardStatuses as $s) { $repairsByStatus[$s] = []; }
foreach ($repairs as $r) {
    if (isset($repairsByStatus[$r['status']])) {
        $repairsByStatus[$r['status']][] = $r;
    }
}

// Load parts per repair for sidebar (if table exists)
$partsByRepair = [];
if ($hasRepairParts && !empty($repairs)) {
    $ids = array_map(static function ($r) { return (int)$r['id']; }, $repairs);
    $ids = array_values(array_filter($ids));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $partsRows = $db->fetchAll(
            "SELECT rp.repair_id, p.name, p.sku, rp.quantity, rp.unit_cost, rp.total_cost
             FROM repair_parts rp
             LEFT JOIN products p ON p.id = rp.product_id
             WHERE rp.repair_id IN ({$placeholders})
             ORDER BY rp.id ASC",
            $ids
        );
        foreach ($partsRows as $row) {
            $partsByRepair[(int)$row['repair_id']][] = $row;
        }
    }
}
?>

<style>
    .repair-dashboard { --bg:#f8fafc; --card:#ffffff; --border:#e5e7eb; --text:#0f172a; --muted:#6b7280; --accent:#2563eb; --accent-soft:#dbeafe; }
    .repair-dashboard { background: var(--bg); padding: 16px; border-radius: 12px; }
    .repair-header { display:flex; align-items:center; gap:12px; }
    .repair-header .search { flex: 1; }
    .repair-kanban { display:grid; grid-template-columns: repeat(4, minmax(240px, 1fr)); gap:12px; }
    .kanban-col { background: var(--card); border:1px solid var(--border); border-radius:12px; padding:10px; min-height: 460px; }
    .kanban-title { font-size:12px; text-transform: uppercase; letter-spacing:.06em; color: var(--muted); display:flex; justify-content:space-between; align-items:center; }
    .kanban-empty { border:1px dashed #cbd5e1; border-radius:10px; padding:16px; text-align:center; color:#94a3b8; font-size:12px; }
    .job-card { border:1px solid var(--border); border-radius:12px; padding:12px; background:#fff; cursor:pointer; transition:all .15s ease; box-shadow:0 1px 3px rgba(15,23,42,.04); }
    .job-card:hover { border-color:#cbd5e1; box-shadow:0 8px 18px rgba(15,23,42,.08); transform:translateY(-1px); }
    .job-card.selected { border-color: var(--accent); box-shadow:0 0 0 2px rgba(37,99,235,.15); }
    .job-title { font-weight:700; font-size:14px; }
    .job-sub { color:var(--muted); font-size:12px; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    .badge-urgency { font-size:11px; border-radius:999px; padding:2px 8px; }
    .urg-low { background:#dcfce7; color:#166534; }
    .urg-med { background:#fef3c7; color:#92400e; }
    .urg-high { background:#fee2e2; color:#991b1b; }
    .thumb { width:36px; height:36px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; color:#64748b; }
    .meta-row { display:flex; gap:10px; align-items:center; color:#64748b; font-size:12px; }
    .meta-row i { font-size:12px; }
    .quick-actions .btn { font-size:11px; padding:4px 8px; }
    .serial-missing { color:#b45309; background:#fef3c7; border-radius:6px; padding:2px 6px; display:inline-block; }
    .sidebar { background:#fff; border:1px solid var(--border); border-radius:12px; padding:12px; position:sticky; top:84px; }
    .timeline { border-left:2px solid #e2e8f0; padding-left:12px; }
    .timeline-item { margin-bottom:10px; }
    .timeline-item .time { font-size:12px; color:var(--muted); }
    .checklist label { font-size:12px; color:var(--muted); }
    .action-bar .btn { min-height:42px; font-weight:600; }
    .stepper { display:flex; gap:8px; align-items:center; }
    .step { flex:1; height:6px; border-radius:999px; background:#e2e8f0; position:relative; }
    .step.active { background:var(--accent); }
    .parts-table { width:100%; font-size:12px; }
    .parts-table th { color:#6b7280; font-weight:600; text-transform:uppercase; font-size:11px; }
    @media (max-width: 1200px) { .repair-kanban { grid-template-columns: 1fr 1fr; } }
    @media (max-width: 992px) { .repair-kanban { grid-template-columns: 1fr; } .sidebar { position:static; } }
</style>

<div class="repair-dashboard">
    <div class="repair-header mb-3">
        <div class="search">
            <form method="GET">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q" value="<?php echo escape($filters['q']); ?>" placeholder="Search by Customer Name, Phone, or Repair ID">
                </div>
            </form>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newIntakeModal">+ New Intake</button>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Ready for Payment</strong>
            <span class="badge bg-light text-dark"><?php echo count($readyRepairs); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Customer</th>
                            <th>Device</th>
                            <th>Serial</th>
                            <th>Issue</th>
                            <th class="text-end">Quote</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($readyRepairs)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No ready repairs.</td></tr>
                    <?php else: ?>
                        <?php foreach ($readyRepairs as $rr): ?>
                            <tr>
                                <td><?php echo escape(trim((string)($rr['customer_name'] ?? 'Guest'))); ?></td>
                                <td><?php echo escape($rr['model'] ?? ''); ?></td>
                                <td><?php echo escape($rr['serial_number'] ?? ''); ?></td>
                                <td><?php echo escape($rr['customer_reported_issue'] ?? ''); ?></td>
                                <td class="text-end"><?php echo number_format((float)($rr['initial_quote'] ?? 0), 2); ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="collect_payment">
                                        <input type="hidden" name="repair_id" value="<?php echo (int)$rr['id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo (float)($rr['initial_quote'] ?? 0); ?>">
                                        <button type="submit" class="btn btn-sm btn-success">Collect Payment</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="repair-kanban">
                <?php foreach ($boardStatuses as $status): ?>
                    <div class="kanban-col">
                        <div class="kanban-title mb-2">
                            <span><?php echo ucfirst($status); ?></span>
                            <span class="badge bg-light text-dark"><?php echo count($repairsByStatus[$status]); ?></span>
                        </div>
                        <div class="d-grid gap-2">
                            <?php if (empty($repairsByStatus[$status])): ?>
                                <div class="kanban-empty">Drag and Drop here</div>
                            <?php else: ?>
                                <?php foreach ($repairsByStatus[$status] as $r): ?>
                                    <?php
                                    $deviceIcon = 'bi-pc-display-horizontal';
                                    if (stripos((string)$r['product_name'], 'laptop') !== false) $deviceIcon = 'bi-laptop';
                                    if (stripos((string)$r['product_name'], 'gpu') !== false || stripos((string)$r['product_name'], 'graphics') !== false) $deviceIcon = 'bi-gpu-card';
                                    $serialMissing = empty($r['serial_number']);
                                    ?>
                                    <div class="job-card"
                                         data-repair-id="<?php echo (int)$r['id']; ?>"
                                         data-customer="<?php echo escape(trim((string)($r['customer_name'] ?? 'Guest'))); ?>"
                                         data-product="<?php echo escape($r['product_name'] ?? ''); ?>"
                                         data-model="<?php echo escape($r['model'] ?? ''); ?>"
                                         data-sku="<?php echo escape($r['sku'] ?? ''); ?>"
                                         data-serial="<?php echo escape($r['serial_number'] ?? ''); ?>"
                                         data-status="<?php echo escape($r['status']); ?>"
                                         data-request-date="<?php echo escape($r['request_date'] ?? ''); ?>"
                                         data-diagnosis="<?php echo escape($r['diagnosis'] ?? ''); ?>"
                                         data-reported="<?php echo escape($r['customer_reported_issue'] ?? ''); ?>"
                                         data-techdiag="<?php echo escape($r['technician_diagnosis'] ?? ''); ?>"
                                         data-warranty="<?php echo escape($r['warranty_status'] ?? 'billable'); ?>"
                                         data-notes="<?php echo escape($r['notes'] ?? ''); ?>"
                                         data-quote="<?php echo escape((string)($r['initial_quote'] ?? 0)); ?>">
                                        <div class="d-flex gap-2 align-items-start">
                                            <div class="thumb"><i class="bi <?php echo $deviceIcon; ?>"></i></div>
                                            <div>
                                                <div class="job-title"><?php echo escape(trim((string)($r['customer_name'] ?? 'Guest'))); ?></div>
                                                <div class="job-sub">Device: <?php echo escape(($r['model'] ?? '') !== '' ? $r['model'] : ($r['product_name'] ?? '')); ?></div>
                                                <div class="small mono <?php echo $serialMissing ? 'serial-missing' : ''; ?>">
                                                    Serial #<?php echo escape($r['serial_number'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="small text-muted mt-1">
                                                    Issue: <?php echo escape($r['customer_reported_issue'] ?? '—'); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="sidebar">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <div class="small text-muted">Selected Job</div>
                        <div class="fw-semibold" id="jobTitle">No job selected</div>
                        <div class="small mono" id="jobSerial">Serial #—</div>
                    </div>
                    <span class="badge bg-light text-dark" id="jobStatus">—</span>
                </div>

                <form method="POST" id="repairUpdateForm" class="mb-3">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_repair">
                    <input type="hidden" name="repair_id" id="jobRepairId" value="">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label small">Status</label>
                            <select class="form-select form-select-sm" name="status" id="jobStatusSelect">
                                <?php foreach ($statusPipeline as $s): ?>
                                    <option value="<?php echo $s; ?>"><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Total Quote</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="total_quote" id="jobTotalQuoteInput" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Customer Reported Issue</label>
                            <input type="text" class="form-control form-control-sm" name="customer_reported_issue" id="jobReportedInput" placeholder="Reported issue">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Check-In Checklist</label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="charger_included" id="jobChargerIncluded">
                                    Charger Included?
                                </label>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="external_damage" id="jobExternalDamage">
                                    External Damage Noted?
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Notes</label>
                            <input type="text" class="form-control form-control-sm" name="notes" id="jobNotesInput" placeholder="Notes">
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-outline-primary w-100">Save Update</button>
                        </div>
                    </div>
                </form>

                <div class="action-bar d-grid gap-2">
                    <button class="btn btn-success" type="button" id="sendReadySms" disabled>Send Ready SMS</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New Intake Modal -->
<div class="modal fade" id="newIntakeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">New Intake</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" class="row g-2" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_repair">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Customer (Optional)</label>
                            <select class="form-select" name="customer_id">
                                <option value="0">Walk-in / Unknown</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo escape(trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Preferred Contact</label>
                            <select class="form-select" name="preferred_contact">
                                <option value="">Select</option>
                                <option value="sms">SMS</option>
                                <option value="email">Email</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Request Date</label>
                            <input type="date" class="form-control" name="request_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label">Device Type</label>
                            <select class="form-select" name="device_type">
                                <option value="desktop">Desktop</option>
                                <option value="laptop">Laptop</option>
                                <option value="gpu">GPU</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-4"><label class="form-label">Brand</label><input type="text" class="form-control" name="brand"></div>
                        <div class="col-4"><label class="form-label">Model</label><input type="text" class="form-control" name="model"></div>
                        <div class="col-6">
                            <label class="form-label">Serial Number *</label>
                            <input type="text" class="form-control" name="serial_number" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label">IMEI (Optional)</label>
                            <input type="text" class="form-control" name="imei">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Check-In Checklist</label>
                            <div class="d-flex flex-wrap gap-3">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="charger_included" value="1">
                                    Charger Included?
                                </label>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="external_damage" value="1">
                                    External Damage Noted?
                                </label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Total Quote</label>
                            <input type="number" class="form-control" name="initial_quote" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Accessories Left Behind</label>
                            <div class="d-flex flex-wrap gap-2">
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="accessories[power_cable]" value="1"> Power Cable</label>
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="accessories[case]" value="1"> Case</label>
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="accessories[box]" value="1"> Box</label>
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="accessories[adapter]" value="1"> Adapter</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Customer Reported Issue</label>
                            <textarea class="form-control" name="customer_reported_issue" rows="2" required></textarea>
                        </div>
                    </div>
                    <div class="col-12 mt-2">
                        <button type="submit" class="btn btn-primary w-100">Create Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const cards = document.querySelectorAll(".job-card");
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

    function selectCard(card) {
        cards.forEach(c => c.classList.remove("selected"));
        card.classList.add("selected");
        const model = card.dataset.model || card.dataset.product || "";
        setText("jobTitle", `${card.dataset.customer || "Guest"} - ${model}`);
        const serialVal = card.dataset.serial || "N/A";
        const serialEl = document.getElementById("jobSerial");
        if (serialEl) {
            serialEl.textContent = `Serial #${serialVal}`;
            serialEl.classList.toggle("serial-missing", serialVal === "N/A");
        }
        setText("jobStatus", (card.dataset.status || "").toUpperCase());
        const repairIdInput = document.getElementById("jobRepairId");
        if (repairIdInput) repairIdInput.value = card.dataset.repairId || "";
        const statusSelect = document.getElementById("jobStatusSelect");
        const statusValue = card.dataset.status || "received";
        if (statusSelect) statusSelect.value = statusValue;
        const quoteInput = document.getElementById("jobTotalQuoteInput");
        if (quoteInput) quoteInput.value = card.dataset.quote || "0";
        const reportedInput = document.getElementById("jobReportedInput");
        if (reportedInput) reportedInput.value = card.dataset.reported || "";
        const notesInput = document.getElementById("jobNotesInput");
        if (notesInput) notesInput.value = card.dataset.notes || "";
        const sendSmsBtn = document.getElementById("sendReadySms");
        if (sendSmsBtn) sendSmsBtn.disabled = statusValue !== "ready";
    }

    if (cards.length) {
        selectCard(cards[0]);
        cards.forEach(card => card.addEventListener("click", () => selectCard(card)));
    }

    const statusSelect = document.getElementById("jobStatusSelect");
    const sendSmsBtn = document.getElementById("sendReadySms");
    if (statusSelect && sendSmsBtn) {
        statusSelect.addEventListener("change", () => {
            sendSmsBtn.disabled = statusSelect.value !== "ready";
        });
    }
    if (sendSmsBtn) {
        sendSmsBtn.addEventListener("click", () => {
            alert("Ready SMS sent.");
        });
    }
});
</script>

<?php include 'templates/footer.php'; ?>
