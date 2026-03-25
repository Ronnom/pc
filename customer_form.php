<?php
/**
 * Customer Management - Registration/Edit Form
 * Complete form with validation, duplicate checking, and auto-generated customer ID
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('customers.manage');

$db = getDB();
$pageTitle = 'Customer Registration';
$editing = (int)($_GET['id'] ?? 0) > 0;
$customer = null;
$errors = [];
$success = '';

function customerFormHasColumn($db, $columnName) {
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

$hasCustomerType = customerFormHasColumn($db, 'customer_type');
$hasType = customerFormHasColumn($db, 'type');
$hasIsActive = customerFormHasColumn($db, 'is_active');
$hasStatus = customerFormHasColumn($db, 'status');

$typeColumn = $hasCustomerType ? 'customer_type' : ($hasType ? 'type' : null);
$statusColumn = $hasIsActive ? 'is_active' : ($hasStatus ? 'status' : null);
$typeExpr = $typeColumn ? "c.`{$typeColumn}`" : "'individual'";
$statusExpr = $statusColumn ? "c.`{$statusColumn}`" : "1";
$provinceColumn = customerFormHasColumn($db, 'province') ? 'province' : (customerFormHasColumn($db, 'state') ? 'state' : null);
$postalColumn = customerFormHasColumn($db, 'postal_code') ? 'postal_code' : (customerFormHasColumn($db, 'zip_code') ? 'zip_code' : null);
$hasLoyaltyPoints = customerFormHasColumn($db, 'loyalty_points');
$hasLoyaltyTier = customerFormHasColumn($db, 'loyalty_tier');

if ($editing) {
    $customer = $db->fetchOne(
        "SELECT c.*,
                {$typeExpr} AS customer_type,
                {$statusExpr} AS is_active" .
                ($provinceColumn ? ", c.`{$provinceColumn}` AS province" : ", '' AS province") .
                ($postalColumn ? ", c.`{$postalColumn}` AS postal_code" : ", '' AS postal_code") .
        " FROM customers c WHERE c.id = ?",
        [(int)$_GET['id']]
    );
    if (!$customer) {
        setFlashMessage('error', 'Customer not found');
        redirect('customers.php');
    }
    $pageTitle = 'Edit Customer';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'first_name' => trim($_POST['first_name'] ?? ''),
        'middle_name' => trim($_POST['middle_name'] ?? ''),
        'last_name' => trim($_POST['last_name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'province' => trim($_POST['province'] ?? ''),
        'postal_code' => trim($_POST['postal_code'] ?? ''),
        'country' => trim($_POST['country'] ?? 'USA'),
        'customer_type' => ($_POST['customer_type'] ?? 'individual'),
        'tax_id' => trim($_POST['tax_id'] ?? ''),
        'dob' => !empty($_POST['dob']) ? $_POST['dob'] : null,
        'notes' => trim($_POST['notes'] ?? '')
    ];

    // Validation
    if (empty($data['first_name'])) {
        $errors[] = 'First name is required';
    }
    if (empty($data['last_name'])) {
        $errors[] = 'Last name is required';
    }
    if (empty($data['phone'])) {
        $errors[] = 'Phone number is required';
    } elseif (!preg_match('/^[\d\-\+\s\(\)]+$/', $data['phone']) || strlen(preg_replace('/[^\d]/', '', $data['phone'])) < 10) {
        $errors[] = 'Invalid phone number format (minimum 10 digits)';
    }
    if (empty($data['email'])) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if ($data['customer_type'] === 'business' && empty($data['tax_id'])) {
        $errors[] = 'Tax ID is required for business customers';
    }

    // Duplicate check (email and phone)
    if (empty($errors)) {
        $dup_query = "SELECT id FROM customers WHERE (email = ? OR phone = ?)";
        $dup_params = [$data['email'], preg_replace('/[^\d]/', '', $data['phone'])];
        
        if ($editing) {
            $dup_query .= " AND id != ?";
            $dup_params[] = $customer['id'];
        }
        
        $existing = $db->fetchOne($dup_query, $dup_params);
        if ($existing) {
            $errors[] = 'A customer with this email or phone already exists';
        }
    }

    if (empty($errors)) {
        try {
            if ($editing) {
                // Update existing customer
                $updateSql = "UPDATE customers SET first_name=?, middle_name=?, last_name=?, email=?, phone=?, address=?, city=?";
                $updateParams = [
                    $data['first_name'],
                    $data['middle_name'],
                    $data['last_name'],
                    $data['email'],
                    preg_replace('/[^\d]/', '', $data['phone']),
                    $data['address'],
                    $data['city']
                ];

                if ($provinceColumn) {
                    $updateSql .= ", `{$provinceColumn}`=?";
                    $updateParams[] = $data['province'];
                }
                if ($postalColumn) {
                    $updateSql .= ", `{$postalColumn}`=?";
                    $updateParams[] = $data['postal_code'];
                }

                $updateSql .= ", country=?";
                if ($typeColumn) {
                    $updateSql .= ", `{$typeColumn}`=?";
                }
                $updateSql .= ", tax_id=?, dob=?, notes=?, updated_at=NOW() WHERE id=?";
                $updateParams[] = $data['country'];
                if ($typeColumn) {
                    $updateParams[] = $data['customer_type'];
                }
                $updateParams[] = $data['tax_id'];
                $updateParams[] = $data['dob'];
                $updateParams[] = $data['notes'];
                $updateParams[] = $customer['id'];

                $db->query($updateSql, $updateParams);
                logUserActivity('customer_update', 'Updated customer: ' . $data['first_name'] . ' ' . $data['last_name'], [
                    'customer_id' => $customer['id']
                ]);
                setFlashMessage('success', 'Customer updated successfully');
            } else {
                // Generate customer code: CUST-YYYY-XXXXX
                $year = date('Y');
                $seq = $db->fetchOne(
                    "SELECT LPAD(COUNT(*)+1,5,'0') as seq FROM customers WHERE YEAR(created_at) = ?",
                    [$year]
                );
                $customer_code = 'CUST-' . $year . '-' . ($seq['seq'] ?? '00001');

                $insertData = [
                    'customer_code' => $customer_code,
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => preg_replace('/[^\d]/', '', $data['phone']),
                    'address' => $data['address'],
                    'city' => $data['city'],
                    'country' => $data['country'],
                    'tax_id' => $data['tax_id'],
                    'dob' => $data['dob'],
                    'notes' => $data['notes'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
                if ($typeColumn) {
                    $insertData[$typeColumn] = $data['customer_type'];
                }
                if ($provinceColumn) {
                    $insertData[$provinceColumn] = $data['province'];
                }
                if ($postalColumn) {
                    $insertData[$postalColumn] = $data['postal_code'];
                }
                if ($statusColumn) {
                    $insertData[$statusColumn] = 1;
                }
                if ($hasLoyaltyPoints) {
                    $insertData['loyalty_points'] = 0;
                }
                if ($hasLoyaltyTier) {
                    $insertData['loyalty_tier'] = 'Bronze';
                }

                $db->insert('customers', $insertData);
                logUserActivity('customer_create', 'Created new customer: ' . $data['first_name'] . ' ' . $data['last_name'], [
                    'customer_code' => $customer_code
                ]);
                setFlashMessage('success', 'Customer registered successfully (ID: ' . $customer_code . ')');
            }
            redirect('customers.php');
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0"><?php echo $editing ? 'Edit' : 'Register'; ?> Customer</h1>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <strong>Errors:</strong>
    <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
        <li><?php echo escape($error); ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <!-- Name Section -->
            <div class="col-12">
                <h5>Personal Information</h5>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="first_name" value="<?php echo escape($customer['first_name'] ?? ''); ?>" required>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name" value="<?php echo escape($customer['middle_name'] ?? ''); ?>">
            </div>
            
            <div class="col-md-4">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="last_name" value="<?php echo escape($customer['last_name'] ?? ''); ?>" required>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">Date of Birth</label>
                <input type="date" class="form-control" name="dob" value="<?php echo escape($customer['dob'] ?? ''); ?>">
            </div>
            
            <!-- Contact Section -->
            <div class="col-12 mt-4">
                <h5>Contact Information</h5>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control" name="email" value="<?php echo escape($customer['email'] ?? ''); ?>" required>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                <input type="tel" class="form-control" name="phone" placeholder="+1 (555) 123-4567" value="<?php echo escape($customer['phone'] ?? ''); ?>" required>
            </div>
            
            <!-- Address Section -->
            <div class="col-12 mt-4">
                <h5>Address</h5>
            </div>
            
            <div class="col-12">
                <label class="form-label">Street Address</label>
                <input type="text" class="form-control" name="address" value="<?php echo escape($customer['address'] ?? ''); ?>">
            </div>
            
            <div class="col-md-6">
                <label class="form-label">City</label>
                <input type="text" class="form-control" name="city" value="<?php echo escape($customer['city'] ?? ''); ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Province/State</label>
                <input type="text" class="form-control" name="province" value="<?php echo escape($customer['province'] ?? ''); ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Postal Code</label>
                <input type="text" class="form-control" name="postal_code" value="<?php echo escape($customer['postal_code'] ?? ''); ?>">
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="country" value="<?php echo escape($customer['country'] ?? 'USA'); ?>">
            </div>
            
            <!-- Customer Type Section -->
            <div class="col-12 mt-4">
                <h5>Customer Type</h5>
            </div>
            
            <div class="col-md-6">
                <label class="form-label">Customer Type <span class="text-danger">*</span></label>
                <select class="form-select" name="customer_type" id="customerType" onchange="toggleBusinessFields()">
                    <option value="individual" <?php echo ($customer['customer_type'] ?? 'individual') === 'individual' ? 'selected' : ''; ?>>Individual</option>
                    <option value="business" <?php echo ($customer['customer_type'] ?? 'individual') === 'business' ? 'selected' : ''; ?>>Business</option>
                </select>
            </div>
            
            <div class="col-md-6" id="taxIdField" style="display: <?php echo ($customer['customer_type'] ?? 'individual') === 'business' ? 'block' : 'none'; ?>">
                <label class="form-label">Tax ID / Business Registration Number</label>
                <input type="text" class="form-control" name="tax_id" value="<?php echo escape($customer['tax_id'] ?? ''); ?>">
            </div>
            
            <!-- Notes Section -->
            <div class="col-12 mt-4">
                <h5>Additional Information</h5>
            </div>
            
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="4" placeholder="Add any additional notes..."><?php echo escape($customer['notes'] ?? ''); ?></textarea>
            </div>
            
            <!-- Form Actions -->
            <div class="col-12 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> <?php echo $editing ? 'Update' : 'Register'; ?> Customer
                </button>
                <a href="<?php echo getBaseUrl(); ?>/customers.php" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleBusinessFields() {
    const type = document.getElementById('customerType').value;
    const taxIdField = document.getElementById('taxIdField');
    taxIdField.style.display = type === 'business' ? 'block' : 'none';
}
</script>

<?php include 'templates/footer.php';
