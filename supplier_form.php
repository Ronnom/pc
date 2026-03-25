<?php
// supplier_form.php
// Supplier registration/edit form with validation and unique code
require_once 'config/init.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';
if (!has_permission('suppliers.create') && !has_permission('suppliers.edit')) {
    http_response_code(403);
    exit('Forbidden');
}
$pdo = get_db_connection();

function supplier_form_has_column($pdo, $table, $column) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function supplier_form_filter_data($pdo, $table, array $data) {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[$row['COLUMN_NAME']] = true;
    }

    $filtered = [];
    foreach ($data as $key => $value) {
        if (isset($columns[$key])) {
            $filtered[$key] = $value;
        }
    }
    return $filtered;
}

$suppliersHasSupplierCode = supplier_form_has_column($pdo, 'suppliers', 'supplier_code');
$suppliersHasContactPerson = supplier_form_has_column($pdo, 'suppliers', 'contact_person');
$suppliersHasContactName = supplier_form_has_column($pdo, 'suppliers', 'contact_name');
$suppliersHasStatus = supplier_form_has_column($pdo, 'suppliers', 'status');
$suppliersHasIsActive = supplier_form_has_column($pdo, 'suppliers', 'is_active');

$editing = isset($_GET['id']);
$supplier = null;
if ($editing) {
    if (!has_permission('suppliers.edit')) {
        http_response_code(403);
        exit('Forbidden');
    }
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) exit('Supplier not found.');
} else {
    if (!has_permission('suppliers.create')) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'company_name' => trim($_POST['company_name'] ?? ''),
        'contact_person' => trim($_POST['contact_person'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'phone2' => trim($_POST['phone2'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'zip_code' => trim($_POST['zip_code'] ?? ''),
        'country' => trim($_POST['country'] ?? ''),
        'payment_terms' => null,
        'lead_time_days' => 0,
    ];

    // Validation
    if (!$data['name']) $errors[] = 'Supplier name is required.';
    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if ($data['lead_time_days'] < 0) $errors[] = 'Lead time cannot be negative.';

    if (empty($errors)) {
        try {
            $payload = [
                'name' => $data['name'],
                'company_name' => $data['company_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'phone2' => $data['phone2'],
                'address' => $data['address'],
                'city' => $data['city'],
                'state' => $data['state'],
                'zip_code' => $data['zip_code'],
                'country' => $data['country'],
                'payment_terms' => null,
                'lead_time_days' => 0,
            ];

            if ($suppliersHasContactPerson) {
                $payload['contact_person'] = $data['contact_person'];
            } elseif ($suppliersHasContactName) {
                $payload['contact_name'] = $data['contact_person'];
            }

            if ($suppliersHasStatus) {
                $payload['status'] = $editing ? ($supplier['status'] ?? 1) : 1;
            } elseif ($suppliersHasIsActive) {
                $payload['is_active'] = $editing ? ($supplier['is_active'] ?? 1) : 1;
            }

            $payload = supplier_form_filter_data($pdo, 'suppliers', $payload);

            if ($editing) {
                if (!empty($payload)) {
                    $set = [];
                    $vals = [];
                    foreach ($payload as $col => $val) {
                        $set[] = "{$col} = ?";
                        $vals[] = $val;
                    }
                    $vals[] = $supplier['id'];
                    $sql = "UPDATE suppliers SET " . implode(', ', $set) . " WHERE id = ?";
                    $pdo->prepare($sql)->execute($vals);
                }
                if (function_exists('logUserActivity')) {
                    logUserActivity(getCurrentUserId(), 'update', 'suppliers', "Updated supplier: {$data['name']}");
                }
            } else {
                if ($suppliersHasSupplierCode) {
                    $year = date('Y');
                    $seq = $pdo->query("SELECT LPAD(COUNT(*)+1,4,'0') FROM suppliers WHERE YEAR(created_at) = $year")->fetchColumn();
                    $payload['supplier_code'] = "SUP-$year-$seq";
                }

                if (empty($payload)) {
                    throw new Exception('No compatible supplier fields found in current schema.');
                }

                $cols = array_keys($payload);
                $ph = array_fill(0, count($cols), '?');
                $vals = array_values($payload);
                $sql = "INSERT INTO suppliers (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $ph) . ")";
                $pdo->prepare($sql)->execute($vals);
                $supplier_id = $pdo->lastInsertId();
                if (function_exists('logUserActivity')) {
                    logUserActivity(getCurrentUserId(), 'create', 'suppliers', "Created supplier: {$data['name']}");
                }
            }
            header('Location: suppliers.php?success=1');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

include 'templates/header.php';
?>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h1 class="card-title"><?= $editing ? 'Edit' : 'Register' ?> Supplier</h1>
                    <div class="card-tools">
                        <a href="suppliers.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back to Suppliers
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <h4 class="mb-3">Basic Information</h4>
                                <div class="form-group">
                                    <label for="name">Supplier Name *</label>
                                    <input type="text" id="name" name="name" class="form-control" required
                                           value="<?= htmlspecialchars($supplier['name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="company_name">Company Name</label>
                                    <input type="text" id="company_name" name="company_name" class="form-control"
                                           value="<?= htmlspecialchars($supplier['company_name'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="contact_person">Contact Person</label>
                                    <input type="text" id="contact_person" name="contact_person" class="form-control"
                                           value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" name="email" class="form-control"
                                           value="<?= htmlspecialchars($supplier['email'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="phone">Phone</label>
                                    <input type="text" id="phone" name="phone" class="form-control"
                                           value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="phone2">Secondary Phone</label>
                                    <input type="text" id="phone2" name="phone2" class="form-control"
                                           value="<?= htmlspecialchars($supplier['phone2'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h4 class="mb-3">Address Details</h4>
                                <div class="form-group">
                                    <label for="address">Address</label>
                                    <textarea id="address" name="address" class="form-control" rows="3"><?= htmlspecialchars($supplier['address'] ?? '') ?></textarea>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="city">City</label>
                                            <input type="text" id="city" name="city" class="form-control"
                                                   value="<?= htmlspecialchars($supplier['city'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="state">State/Province</label>
                                            <input type="text" id="state" name="state" class="form-control"
                                                   value="<?= htmlspecialchars($supplier['state'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="zip_code">ZIP/Postal Code</label>
                                            <input type="text" id="zip_code" name="zip_code" class="form-control"
                                                   value="<?= htmlspecialchars($supplier['zip_code'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="country">Country</label>
                                            <input type="text" id="country" name="country" class="form-control"
                                                   value="<?= htmlspecialchars($supplier['country'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-2">
                            <div class="text-muted small">Fields marked * are required.</div>
                            <div class="d-flex gap-2">
                                <a href="suppliers.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> <?= $editing ? 'Update' : 'Create' ?> Supplier
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
include 'templates/footer.php';
