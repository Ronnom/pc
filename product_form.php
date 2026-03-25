<?php
/**
 * Product Form Page
 * Comprehensive add/edit form with dynamic PC specs and image uploads
 */

require_once 'includes/init.php';
require_once 'includes/image_upload.php';
requireLogin();

$productsModule = new ProductsModule();
$productsEnhanced = new ProductsModuleEnhanced();
$suppliersModule = new SuppliersModule();
$db = getDB();

$action = $_GET['action'] ?? 'add';
$id = (int)($_GET['id'] ?? 0);

if ($action === 'generate_sku') {
    if (!hasPermission('products.create') && !hasPermission('products.edit')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit;
    }
    header('Content-Type: application/json');
    $categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $brand = trim($_GET['brand'] ?? '');
    try {
        $sku = $productsEnhanced->generateSKU($categoryId, $brand ?: null);
        echo json_encode(['success' => true, 'sku' => $sku]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'edit') {
    requirePermission('products.edit');
} else {
    requirePermission('products.create');
}

function getProductsColumnsMap(Database $db) {
    $columns = $db->fetchAll(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'products'"
    );

    $map = [];
    foreach ($columns as $column) {
        $map[$column['COLUMN_NAME']] = true;
    }
    return $map;
}

function productsColumnExists(array $map, $columnName) {
    return isset($map[$columnName]);
}

function productsTableExists(Database $db, $tableName) {
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS cnt
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$tableName]
    );
    return !empty($row['cnt']);
}

$productColumnsMap = getProductsColumnsMap($db);
$hasSerialTrackingTable = productsTableExists($db, 'product_serial_numbers');
$priceColumnName = productsColumnExists($productColumnsMap, 'sell_price') ? 'sell_price' : 'selling_price';
$categoriesHasSlug = tableColumnExists('categories', 'slug');
$categoriesHasSortOrder = tableColumnExists('categories', 'sort_order');

$categorySelect = $categoriesHasSlug
    ? "SELECT id, name, slug, parent_id"
    : "SELECT id, name, NULL AS slug, parent_id";
$categoryOrder = $categoriesHasSortOrder
    ? "COALESCE(parent_id, 0), sort_order, name"
    : "COALESCE(parent_id, 0), name";

$categories = $db->fetchAll(
    "{$categorySelect}
     FROM categories
     WHERE is_active = 1
     ORDER BY {$categoryOrder}"
);
$suppliers = $suppliersModule->getSuppliers([], 1000, 0);

$categoriesById = [];
foreach ($categories as $category) {
    $categoriesById[(int)$category['id']] = $category;
}

$allTemplateSpecs = [];
foreach ($categories as $category) {
    $categorySlug = $category['slug'] ?? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$category['name']), '-'));
    $template = $productsEnhanced->getComponentSpecsTemplate($categorySlug);
    if (!empty($template)) {
        $allTemplateSpecs[$categorySlug] = $template;
    }
}

$isEdit = $action === 'edit';
$product = null;
$existingSpecs = [];
$existingImages = [];
$templateSpecValues = [];
$customSpecRows = [];

if ($isEdit) {
    if ($id <= 0) {
        setFlashMessage('error', 'Invalid product ID.');
        redirect(getBaseUrl() . '/product_list.php');
    }

    $product = $productsEnhanced->getProduct($id, true);
    if (!$product) {
        setFlashMessage('error', 'Product not found.');
        redirect(getBaseUrl() . '/product_list.php');
    }

    $existingSpecs = $productsModule->getProductSpecifications($id);
    $existingImages = $productsModule->getProductImages($id);

    $currentCategorySlug = $categoriesById[(int)$product['category_id']]['slug'] ?? null;
    $templateKeys = array_keys($allTemplateSpecs[$currentCategorySlug] ?? []);
    $templateKeyMap = array_fill_keys($templateKeys, true);

    foreach ($existingSpecs as $spec) {
        $specKey = $spec['spec_key'];
        if (isset($templateKeyMap[$specKey])) {
            $templateSpecValues[$specKey] = $spec['spec_value'];
        } else {
            $customSpecRows[] = [
                'key' => $spec['spec_key'],
                'value' => $spec['spec_value'],
                'group' => $spec['spec_group'] ?? 'General'
            ];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    try {
        $postAction = $_POST['action'] ?? ($isEdit ? 'edit' : 'add');
        $isEditSubmit = $postAction === 'edit';
        $submitId = (int)($_POST['id'] ?? 0);
        $targetProductId = $isEditSubmit ? $submitId : 0;

        if ($isEditSubmit) {
            requirePermission('products.edit');
            if ($targetProductId <= 0) {
                throw new Exception('Invalid product ID.');
            }
            $originalProduct = $productsEnhanced->getProduct($targetProductId, true);
            if (!$originalProduct) {
                throw new Exception('Product not found.');
            }
        } else {
            requirePermission('products.create');
        }

        $categoryId = (int)($_POST['category_id'] ?? 0);
        $supplierId = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
        $brand = sanitize($_POST['brand'] ?? '');
        $model = sanitize($_POST['model'] ?? '');
        $serialNumber = array_key_exists('serial_number', $_POST)
            ? sanitize($_POST['serial_number'] ?? '')
            : null;
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);
        $markup = $productsEnhanced->calculateMarkup($costPrice, $sellingPrice);
        $isActive = ($_POST['status'] ?? 'active') === 'active' ? 1 : 0;
        $autoSku = isset($_POST['auto_generate_sku']);
        $sku = sanitize(trim($_POST['sku'] ?? ''));

        if ($categoryId <= 0) {
            throw new Exception('Category is required.');
        }
        if (empty(trim($_POST['name'] ?? ''))) {
            throw new Exception('Product name is required.');
        }
        if ($costPrice < 0 || $sellingPrice < 0) {
            throw new Exception('Prices cannot be negative.');
        }

        if ($autoSku || $sku === '') {
            $sku = $productsEnhanced->generateSKU($categoryId, $brand ?: null);
        }

        $maxStockRaw = array_key_exists('max_stock_level', $_POST)
            ? trim((string)($_POST['max_stock_level'] ?? ''))
            : '';
        $stockQuantity = (int)($_POST['stock_quantity'] ?? 0);

        $data = [
            'sku' => $sku,
            'name' => sanitize($_POST['name']),
            'category_id' => $categoryId,
            'supplier_id' => $supplierId,
            'cost_price' => $costPrice,
            'stock_quantity' => $stockQuantity,
            'is_active' => $isActive
        ];
        if (array_key_exists('description', $_POST)) {
            $data['description'] = sanitize($_POST['description'] ?? '');
        }

        $data[$priceColumnName] = $sellingPrice;

        if (productsColumnExists($productColumnsMap, 'brand')) {
            $data['brand'] = $brand ?: null;
        }
        if (productsColumnExists($productColumnsMap, 'model')) {
            $data['model'] = $model ?: null;
        }
        if ($serialNumber !== null && productsColumnExists($productColumnsMap, 'serial_number')) {
            $data['serial_number'] = $serialNumber ?: null;
        }
        if (array_key_exists('location', $_POST) && productsColumnExists($productColumnsMap, 'location')) {
            $data['location'] = sanitize($_POST['location'] ?? '') ?: null;
        }
        if (productsColumnExists($productColumnsMap, 'min_stock_level')) {
            $data['min_stock_level'] = (int)($_POST['min_stock_level'] ?? 0);
        }
        if (productsColumnExists($productColumnsMap, 'reorder_level')) {
            $data['reorder_level'] = (int)($_POST['min_stock_level'] ?? 0);
        }
        if (array_key_exists('max_stock_level', $_POST) && productsColumnExists($productColumnsMap, 'max_stock_level')) {
            $data['max_stock_level'] = $maxStockRaw !== '' ? (int)$maxStockRaw : null;
        }
        if (array_key_exists('barcode', $_POST) && productsColumnExists($productColumnsMap, 'barcode')) {
            $data['barcode'] = sanitize($_POST['barcode'] ?? '');
        }
        if (array_key_exists('is_taxable', $_POST) && productsColumnExists($productColumnsMap, 'is_taxable')) {
            $data['is_taxable'] = isset($_POST['is_taxable']) ? 1 : 0;
        }
        if (array_key_exists('tax_rate', $_POST) && productsColumnExists($productColumnsMap, 'tax_rate')) {
            $data['tax_rate'] = (float)($_POST['tax_rate'] ?? 0);
        }
        if (productsColumnExists($productColumnsMap, 'warranty_period')) {
            $warranty = trim((string)($_POST['warranty_period'] ?? ''));
            $data['warranty_period'] = $warranty !== '' ? (int)$warranty : null;
        }
        if (productsColumnExists($productColumnsMap, 'markup_percentage')) {
            $data['markup_percentage'] = $markup;
        }

        if ($isEditSubmit) {
            $productsModule->updateProduct($targetProductId, $data);
            $productId = $targetProductId;
        } else {
            if ($stockQuantity > 0) {
                if (!$hasSerialTrackingTable) {
                    throw new Exception('Serial tracking table is missing. Please run stock management schema updates.');
                }

                $serialInputs = $_POST['serial_numbers'] ?? [];
                if (!is_array($serialInputs)) {
                    $serialInputs = [];
                }

                $serialNumbers = [];
                foreach ($serialInputs as $serial) {
                    $serial = sanitize(trim((string)$serial));
                    if ($serial === '') {
                        continue;
                    }
                    $serialNumbers[] = $serial;
                }

                $serialNumbers = array_values($serialNumbers);
                if (count($serialNumbers) !== $stockQuantity) {
                    throw new Exception("You must provide exactly {$stockQuantity} serial numbers for the entered quantity.");
                }

                if (count(array_unique($serialNumbers)) !== count($serialNumbers)) {
                    throw new Exception('Serial numbers must be unique.');
                }

                $placeholders = implode(',', array_fill(0, count($serialNumbers), '?'));
                $existingSerials = $db->fetchAll(
                    "SELECT serial_number FROM product_serial_numbers WHERE serial_number IN ({$placeholders})",
                    $serialNumbers
                );
                if (!empty($existingSerials)) {
                    $duplicates = array_map(static function ($row) {
                        return $row['serial_number'];
                    }, $existingSerials);
                    throw new Exception('Serial numbers already exist: ' . implode(', ', $duplicates));
                }
            } else {
                $serialNumbers = [];
            }

            $productId = (int)$productsModule->createProduct($data);

            if (!empty($serialNumbers)) {
                foreach ($serialNumbers as $serialNumberValue) {
                    $db->insert('product_serial_numbers', [
                        'product_id' => $productId,
                        'serial_number' => $serialNumberValue,
                        'status' => 'in_stock'
                    ]);
                }
            }
        }

        $specRows = [];
        $specKeys = $_POST['spec_key'] ?? [];
        $specValues = $_POST['spec_value'] ?? [];
        $specGroups = $_POST['spec_group'] ?? [];
        foreach ($specKeys as $index => $specKey) {
            $key = sanitize(trim((string)$specKey));
            $value = sanitize(trim((string)($specValues[$index] ?? '')));
            $group = sanitize(trim((string)($specGroups[$index] ?? 'Technical')));
            if ($key === '' || $value === '') {
                continue;
            }
            $specRows[] = [
                'key' => $key,
                'value' => $value,
                'group' => $group,
                'sort_order' => $index
            ];
        }

        $customKeys = $_POST['custom_spec_key'] ?? [];
        $customValues = $_POST['custom_spec_value'] ?? [];
        $customGroups = $_POST['custom_spec_group'] ?? [];
        foreach ($customKeys as $index => $specKey) {
            $key = sanitize(trim((string)$specKey));
            $value = sanitize(trim((string)($customValues[$index] ?? '')));
            $group = sanitize(trim((string)($customGroups[$index] ?? 'General')));
            if ($key === '' || $value === '') {
                continue;
            }
            $specRows[] = [
                'key' => $key,
                'value' => $value,
                'group' => $group,
                'sort_order' => 500 + $index
            ];
        }
        $productsModule->saveProductSpecifications($productId, $specRows);

        $deletedImageIds = [];
        if ($isEditSubmit && isset($_POST['delete_image_ids']) && is_array($_POST['delete_image_ids'])) {
            foreach ($_POST['delete_image_ids'] as $imageId) {
                $deletedImageIds[(int)$imageId] = true;
            }

            if (!empty($deletedImageIds)) {
                $existingForDelete = $productsModule->getProductImages($productId);
                foreach ($existingForDelete as $image) {
                    if (!isset($deletedImageIds[(int)$image['id']])) {
                        continue;
                    }
                    $productsModule->deleteProductImage((int)$image['id']);
                    $absolutePath = APP_ROOT . '/' . ltrim($image['image_path'], '/');
                    if (is_file($absolutePath)) {
                        @unlink($absolutePath);
                    }
                }
            }
        }

        $newImageIds = [];
        if (!empty($_FILES['product_images'])) {
            $uploadedFiles = normalizeUploadFilesArray($_FILES['product_images']);
            foreach ($uploadedFiles as $index => $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $uploadResult = uploadProductImage($file);
                $imageId = (int)$productsModule->addProductImage(
                    $productId,
                    $uploadResult['path'],
                    $data['name'],
                    false
                );
                $newImageIds[$index] = $imageId;
            }
        }

        $primaryTarget = trim((string)($_POST['primary_image_target'] ?? ''));
        $primaryImageId = null;

        if (strpos($primaryTarget, 'existing:') === 0) {
            $candidateId = (int)substr($primaryTarget, 9);
            if ($candidateId > 0 && !isset($deletedImageIds[$candidateId])) {
                $primaryImageId = $candidateId;
            }
        } elseif (strpos($primaryTarget, 'new:') === 0) {
            $newIndex = (int)substr($primaryTarget, 4);
            if (isset($newImageIds[$newIndex])) {
                $primaryImageId = (int)$newImageIds[$newIndex];
            }
        }

        $allImages = $productsModule->getProductImages($productId);
        if ($primaryImageId === null && !empty($allImages)) {
            $primaryImageId = (int)$allImages[0]['id'];
        }

        if ($primaryImageId !== null) {
            $db->update('product_images', ['is_primary' => 0], 'product_id = ?', [$productId]);
            $db->update(
                'product_images',
                ['is_primary' => 1],
                'id = ? AND product_id = ?',
                [$primaryImageId, $productId]
            );
        }

        setFlashMessage('success', $isEditSubmit ? 'Product updated successfully.' : 'Product created successfully.');
        redirect(getBaseUrl() . '/product_view.php?id=' . $productId);
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

if (!$product) {
    $product = [
        'id' => 0,
        'sku' => '',
        'name' => '',
        'barcode' => '',
        'category_id' => '',
        'supplier_id' => '',
        'brand' => '',
        'model' => '',
        'serial_number' => '',
        'description' => '',
        'cost_price' => '0.00',
        'selling_price' => '0.00',
        'sell_price' => '0.00',
        'markup_percentage' => '0.00',
        'tax_rate' => '0.00',
        'is_taxable' => 1,
        'stock_quantity' => 0,
        'location' => '',
        'warranty_period' => '',
        'min_stock_level' => 0,
        'max_stock_level' => '',
        'is_active' => 1
    ];
}

if (!isset($product['selling_price']) && isset($product['sell_price'])) {
    $product['selling_price'] = $product['sell_price'];
}

$defaultComponentTemplates = [
    'cpu' => [
        'socket_type' => 'Socket Type',
        'cores_count' => 'Cores Count',
        'threads_count' => 'Threads Count',
        'base_clock_ghz' => 'Base Clock Speed (GHz)',
        'boost_clock_ghz' => 'Boost Clock Speed (GHz)',
        'tdp_watts' => 'TDP (Watts)',
        'cache_memory' => 'Cache Memory (L2/L3)'
    ],
    'gpu' => [
        'memory_size_gb' => 'Memory Size (GB)',
        'memory_type' => 'Memory Type',
        'cuda_stream_cores' => 'CUDA Cores / Stream Processors',
        'power_connectors' => 'Power Connectors',
        'ports' => 'Ports'
    ],
    'motherboard' => [
        'chipset' => 'Chipset',
        'socket_compatibility' => 'Socket Compatibility',
        'ram_slots_count' => 'RAM Slots Count',
        'max_ram_support' => 'Maximum RAM Support',
        'form_factor' => 'Form Factor',
        'pcie_slots' => 'PCIe Slots (Version / Count)'
    ],
    'ram' => [
        'memory_type' => 'Memory Type (DDR4/DDR5)',
        'speed_mhz' => 'Speed (MHz)',
        'capacity' => 'Capacity (Per Module / Total)',
        'latency' => 'Latency (CAS)',
        'voltage' => 'Voltage'
    ],
    'storage' => [
        'capacity' => 'Capacity (GB/TB)',
        'interface' => 'Interface',
        'form_factor' => 'Form Factor',
        'read_speed' => 'Read Speed',
        'write_speed' => 'Write Speed',
        'cache_memory' => 'Cache Memory'
    ],
    'psu' => [
        'wattage' => 'Wattage',
        'efficiency_rating' => 'Efficiency Rating',
        'modular_type' => 'Modular Type',
        'fan_size_mm' => 'Fan Size (mm)'
    ]
];

$templateSpecsForUi = $defaultComponentTemplates;
foreach ($allTemplateSpecs as $slug => $template) {
    if (!empty($template)) {
        $templateSpecsForUi[$slug] = $template;
    }
}

$pageTitle = $isEdit ? 'Edit Product' : 'Add Product';

include 'templates/header.php';
?>
<style>
.product-form .required::after { content: " *"; color: #dc2626; }
.product-form .section-title { font-size: .9rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--gray-600); }
.product-form .rich-editor { border: 1px solid var(--gray-300); border-radius: 10px; overflow: hidden; }
.product-form .rich-toolbar { border-bottom: 1px solid var(--gray-200); padding: .5rem; display: flex; gap: .25rem; flex-wrap: wrap; }
.product-form .rich-content { min-height: 140px; padding: .75rem; outline: none; background: #fff; }
.product-form .dropzone { border: 2px dashed var(--gray-300); border-radius: 10px; padding: 1rem; text-align: center; transition: .2s; cursor: pointer; }
.product-form .dropzone.dragover { border-color: var(--primary-color); background: #eff6ff; }
.product-form .sticky-actions { position: sticky; top: 1rem; }
.product-form-nav a { font-size: .82rem; }
</style>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <small class="text-muted">Create complete product records with pricing, inventory, and dynamic technical specs.</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-dark border" id="draftIndicator">Not saved</span>
                <a href="<?php echo getBaseUrl(); ?>/product_list.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>
</div>

<form method="POST" enctype="multipart/form-data" class="product-form" id="productForm">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="<?php echo $isEdit ? 'edit' : 'add'; ?>">
    <?php if ($isEdit): ?>
    <input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>">
    <?php endif; ?>
    <input type="hidden" name="status" id="status_value" value="<?php echo !empty($product['is_active']) ? 'active' : 'discontinued'; ?>">

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-4" id="section-core">
                <div class="card-header"><span class="section-title">Core Product Information</span></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label required">Product Name</label>
                            <input type="text" class="form-control" name="name" required value="<?php echo escape($product['name']); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label required">SKU</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="sku" name="sku" required value="<?php echo escape($product['sku']); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="generateSkuBtn">
                                    <i class="bi bi-magic"></i> Generate
                                </button>
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" id="auto_generate_sku" name="auto_generate_sku" <?php echo !$isEdit ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_generate_sku">Auto-generate SKU</label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label required">Category</label>
                            <select class="form-select" id="category_id" name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo (int)$category['id']; ?>" data-slug="<?php echo escape($category['slug']); ?>" data-name="<?php echo escape($category['name']); ?>" <?php echo (int)$product['category_id'] === (int)$category['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape($category['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Brand</label>
                            <input type="text" class="form-control" id="brand" name="brand" value="<?php echo escape($product['brand'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Model</label>
                            <input type="text" class="form-control" name="model" value="<?php echo escape($product['model'] ?? ''); ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Supplier</label>
                            <select class="form-select" name="supplier_id">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo (int)$supplier['id']; ?>" <?php echo (int)$product['supplier_id'] === (int)$supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo escape($supplier['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    
                </div>
            </div>

            <div class="card mb-4" id="section-pricing">
                <div class="card-header"><span class="section-title">Pricing</span></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label required">Cost Price</label>
                            <input type="number" class="form-control" id="cost_price" name="cost_price" step="0.01" min="0" required value="<?php echo escape((string)$product['cost_price']); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required">Selling Price</label>
                            <input type="number" class="form-control" id="selling_price" name="selling_price" step="0.01" min="0" required value="<?php echo escape((string)$product['selling_price']); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4" id="section-inventory">
                <div class="card-header"><span class="section-title">Inventory Management</span></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Stock Quantity</label>
                            <input type="number" class="form-control" name="stock_quantity" min="0" value="<?php echo (int)$product['stock_quantity']; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Re-order Level</label>
                            <input type="number" class="form-control" name="min_stock_level" min="0" value="<?php echo (int)$product['min_stock_level']; ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Warranty (Months)</label>
                            <input type="number" class="form-control" name="warranty_period" min="0" value="<?php echo escape((string)($product['warranty_period'] ?? '')); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-0 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" id="status_toggle" <?php echo !empty($product['is_active']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="status_toggle">Status: <span id="status_text"><?php echo !empty($product['is_active']) ? 'Active' : 'Inactive'; ?></span></label>
                            </div>
                        </div>
                    </div>
                    <?php if (!$isEdit): ?>
                    <hr>
                    <div id="serialNumbersSection">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Serial Numbers</h6>
                            <small class="text-muted">Required count: <span id="serialRequiredCount">0</span></small>
                        </div>
                        <p class="text-muted small mb-2">When quantity is greater than 0, enter or scan one unique serial number per unit.</p>
                        <div class="mb-3">
                            <label class="form-label">Scan/Paste Multiple Serials (one per line)</label>
                            <textarea class="form-control" id="serialBulkInput" rows="3" placeholder="SN-001&#10;SN-002&#10;SN-003"></textarea>
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="applySerialBulkBtn">
                                    <i class="bi bi-upc-scan"></i> Apply to Fields
                                </button>
                            </div>
                        </div>
                        <div id="serialNumbersContainer" class="row g-2"></div>
                        <div class="small text-danger mt-2" id="serialNumbersValidation"></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4" id="section-specs">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="section-title mb-0">Dynamic PC Component Specifications</span>
                    <span class="badge bg-primary-subtle text-primary-emphasis" id="specCategoryBadge">Not selected</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Fields update automatically based on selected category.</p>
                    <div id="componentSpecsContainer"></div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0" id="section-specs-custom">Custom Specifications</h6>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="addCustomSpecBtn">
                            <i class="bi bi-plus"></i> Add Row
                        </button>
                    </div>
                    <div id="customSpecsContainer" data-section="section-custom-specs">
                        <?php if (empty($customSpecRows)): ?>
                        <div class="row g-2 custom-spec-row mb-2">
                            <div class="col-md-4"><input type="text" class="form-control" name="custom_spec_key[]" placeholder="Spec name"></div>
                            <div class="col-md-4"><input type="text" class="form-control" name="custom_spec_value[]" placeholder="Spec value"></div>
                            <div class="col-md-3"><input type="text" class="form-control" name="custom_spec_group[]" placeholder="Group (General)"></div>
                            <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-custom-spec"><i class="bi bi-x"></i></button></div>
                        </div>
                        <?php else: ?>
                            <?php foreach ($customSpecRows as $row): ?>
                            <div class="row g-2 custom-spec-row mb-2">
                                <div class="col-md-4"><input type="text" class="form-control" name="custom_spec_key[]" value="<?php echo escape($row['key']); ?>" placeholder="Spec name"></div>
                                <div class="col-md-4"><input type="text" class="form-control" name="custom_spec_value[]" value="<?php echo escape($row['value']); ?>" placeholder="Spec value"></div>
                                <div class="col-md-3"><input type="text" class="form-control" name="custom_spec_group[]" value="<?php echo escape($row['group']); ?>" placeholder="Group (General)"></div>
                                <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-custom-spec"><i class="bi bi-x"></i></button></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="sticky-actions">
            <div class="card mb-4" id="section-media">
                <div class="card-header"><span class="section-title mb-0">Media</span></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Upload Images</label>
                        <div class="dropzone" id="imageDropzone">
                            <i class="bi bi-cloud-arrow-up d-block fs-4 mb-2"></i>
                            <div class="fw-semibold">Drag and drop images here</div>
                            <div class="small text-muted">JPG, PNG, GIF, WEBP (max 5MB each)</div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="browseImagesBtn">Browse Files</button>
                        </div>
                        <input type="file" class="d-none" id="product_images" name="product_images[]" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" multiple>
                        <div class="small text-danger mt-2" id="imageValidationMessage"></div>
                    </div>

                    <?php if (!empty($existingImages)): ?>
                    <h6 class="mb-2">Existing Images</h6>
                    <div class="row g-2 mb-3">
                        <?php foreach ($existingImages as $image): ?>
                        <div class="col-6">
                            <div class="border rounded p-2">
                                <img src="<?php echo getBaseUrl() . '/' . $image['image_path']; ?>" class="img-fluid rounded mb-2" alt="Product image">
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="primary_image_target" value="existing:<?php echo (int)$image['id']; ?>" <?php echo !empty($image['is_primary']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label small">Primary</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="delete_image_ids[]" value="<?php echo (int)$image['id']; ?>" id="del_<?php echo (int)$image['id']; ?>">
                                    <label class="form-check-label small" for="del_<?php echo (int)$image['id']; ?>">Delete</label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <h6 class="mb-2">New Upload Preview</h6>
                    <div id="newImagePreview" class="row g-2"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> <?php echo $isEdit ? 'Update Product' : 'Create Product'; ?>
                        </button>
                        <a href="<?php echo getBaseUrl(); ?>/product_list.php" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>
</form>

<script>
const templateSpecsByCategory = <?php echo json_encode($templateSpecsForUi ?? $allTemplateSpecs); ?>;
const existingTemplateValues = <?php echo json_encode($templateSpecValues); ?>;

function syncDescriptionField() {
    const editor = document.getElementById('description_editor');
    const field = document.getElementById('description');
    if (editor && field) {
        field.value = editor.innerHTML.trim();
    }
}

function calculateMarkup() {
    const cost = parseFloat(document.getElementById('cost_price').value || '0');
    const sell = parseFloat(document.getElementById('selling_price').value || '0');
    const field = document.getElementById('markup_percentage');
    if (!field) return;
    if (cost <= 0) {
        field.value = '0.00';
        return;
    }
    const markup = ((sell - cost) / cost) * 100;
    field.value = markup.toFixed(2);
} 

function getSelectedCategoryMeta() {
    const select = document.getElementById('category_id');
    const option = select.options[select.selectedIndex];
    return {
        id: option ? option.value : '',
        slug: option ? (option.getAttribute('data-slug') || '') : '',
        name: option ? (option.getAttribute('data-name') || option.textContent || '') : ''
    };
}

function resolveTemplateKey(slug, name) {
    const hay = `${slug} ${name}`.toLowerCase();
    if (hay.includes('cpu') || hay.includes('processor')) return 'cpu';
    if (hay.includes('gpu') || hay.includes('graphics')) return 'gpu';
    if (hay.includes('motherboard')) return 'motherboard';
    if (hay.includes('ram') || hay.includes('memory')) return 'ram';
    if (hay.includes('storage') || hay.includes('ssd') || hay.includes('hdd')) return 'storage';
    if (hay.includes('psu') || hay.includes('power supply')) return 'psu';
    return slug || '';
}

function renderTemplateSpecs() {
    const category = getSelectedCategoryMeta();
    const slug = resolveTemplateKey(category.slug, category.name);
    const container = document.getElementById('componentSpecsContainer');
    const badge = document.getElementById('specCategoryBadge');
    if (!container) return;
    if (badge) badge.textContent = category.name || 'Not selected';

    const template = templateSpecsByCategory[slug] || templateSpecsByCategory[category.slug] || null;
    if (!template) {
        container.innerHTML = '<p class="text-muted mb-0">No predefined component specs for this category. Use custom specs below.</p>';
        return;
    }

    let html = '<div class="row g-3">';
    Object.keys(template).forEach((key) => {
        const label = template[key];
        const value = existingTemplateValues[key] || '';
        html += `
            <div class="col-md-6">
                <label class="form-label">${label}</label>
                <input type="hidden" name="spec_key[]" value="${key}">
                <input type="hidden" name="spec_group[]" value="Technical">
                <input type="text" class="form-control" name="spec_value[]" value="${String(value).replace(/"/g, '&quot;')}" placeholder="Enter ${label.toLowerCase()}">
            </div>
        `;
    });
    html += '</div>';
    container.innerHTML = html;
}

async function generateSku() {
    const categoryId = getSelectedCategoryMeta().id;
    const brand = document.getElementById('brand').value;
    const params = new URLSearchParams({
        action: 'generate_sku',
        category_id: categoryId,
        brand: brand
    });
    try {
        const response = await fetch(`<?php echo getBaseUrl(); ?>/product_form.php?${params.toString()}`);
        const data = await response.json();
        if (data.success && data.sku) {
            document.getElementById('sku').value = data.sku;
        }
    } catch (error) {
    }
}

function updateTaxableState() {
    const taxableEl = document.getElementById('is_taxable');
    const taxRate = document.getElementById('tax_rate');
    if (!taxableEl || !taxRate) return;
    const taxable = taxableEl.checked;
    if (!taxable) {
        taxRate.value = '0.00';
    }
    taxRate.disabled = !taxable;
}

function updateStatusValue() {
    const active = document.getElementById('status_toggle').checked;
    document.getElementById('status_value').value = active ? 'active' : 'discontinued';
    document.getElementById('status_text').textContent = active ? 'Active' : 'Inactive';
}

function validateImageFiles(files) {
    const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const errors = [];
    for (const file of Array.from(files)) {
        if (!allowed.includes(file.type)) errors.push(`${file.name}: unsupported format`);
        if (file.size > 5 * 1024 * 1024) errors.push(`${file.name}: exceeds 5MB`);
    }
    return errors;
}

function renderImagePreview(files) {
    const preview = document.getElementById('newImagePreview');
    const validation = document.getElementById('imageValidationMessage');
    if (!preview) return;
    preview.innerHTML = '';
    if (validation) validation.textContent = '';

    const errors = validateImageFiles(files);
    if (errors.length > 0) {
        if (validation) validation.textContent = errors.join(' | ');
        return;
    }

    Array.from(files).forEach((file, index) => {
        const col = document.createElement('div');
        col.className = 'col-6';

        const wrapper = document.createElement('div');
        wrapper.className = 'border rounded p-2';

        const img = document.createElement('img');
        img.className = 'img-fluid rounded mb-2';
        img.alt = 'New upload';

        const radioDiv = document.createElement('div');
        radioDiv.className = 'form-check';
        radioDiv.innerHTML = `
            <input class="form-check-input" type="radio" name="primary_image_target" value="new:${index}">
            <label class="form-check-label small">Primary</label>
        `;

        wrapper.appendChild(img);
        wrapper.appendChild(radioDiv);
        col.appendChild(wrapper);
        preview.appendChild(col);

        const reader = new FileReader();
        reader.onload = (event) => {
            img.src = event.target.result;
        };
        reader.readAsDataURL(file);
    });
}

function addCustomSpecRow() {
    const container = document.getElementById('customSpecsContainer');
    const row = document.createElement('div');
    row.className = 'row g-2 custom-spec-row mb-2';
    row.innerHTML = `
        <div class="col-md-4"><input type="text" class="form-control" name="custom_spec_key[]" placeholder="Spec name"></div>
        <div class="col-md-4"><input type="text" class="form-control" name="custom_spec_value[]" placeholder="Spec value"></div>
        <div class="col-md-3"><input type="text" class="form-control" name="custom_spec_group[]" placeholder="Group (General)"></div>
        <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-custom-spec"><i class="bi bi-x"></i></button></div>
    `;
    container.appendChild(row);
}

function renderSerialNumberInputs() {
    const quantityEl = document.querySelector('input[name="stock_quantity"]');
    const container = document.getElementById('serialNumbersContainer');
    const requiredCountEl = document.getElementById('serialRequiredCount');
    if (!quantityEl || !container || !requiredCountEl) return;

    const qty = Math.max(0, parseInt(quantityEl.value || '0', 10));
    requiredCountEl.textContent = String(qty);

    const currentValues = Array.from(container.querySelectorAll('input[name="serial_numbers[]"]')).map((input) => input.value.trim());
    container.innerHTML = '';

    for (let i = 0; i < qty; i++) {
        const col = document.createElement('div');
        col.className = 'col-md-6';
        const value = currentValues[i] || '';
        col.innerHTML = `
            <label class="form-label small mb-1">Serial #${i + 1}</label>
            <input type="text" class="form-control form-control-sm" name="serial_numbers[]" value="${value.replace(/"/g, '&quot;')}" placeholder="Scan or type serial ${i + 1}">
        `;
        container.appendChild(col);
    }
}

function applyBulkSerials() {
    const bulkInput = document.getElementById('serialBulkInput');
    const fields = document.querySelectorAll('input[name="serial_numbers[]"]');
    if (!bulkInput || !fields.length) return;

    const lines = bulkInput.value
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '');

    fields.forEach((field, index) => {
        field.value = lines[index] || '';
    });
}

function validateSerialNumbersClient() {
    const quantityEl = document.querySelector('input[name="stock_quantity"]');
    const validationEl = document.getElementById('serialNumbersValidation');
    if (!quantityEl || !validationEl) return true;

    const qty = Math.max(0, parseInt(quantityEl.value || '0', 10));
    const values = Array.from(document.querySelectorAll('input[name="serial_numbers[]"]'))
        .map((input) => input.value.trim())
        .filter((value) => value !== '');

    validationEl.textContent = '';
    if (qty === 0) return true;

    if (values.length !== qty) {
        validationEl.textContent = `Please provide exactly ${qty} serial numbers.`;
        return false;
    }

    const uniqueCount = new Set(values).size;
    if (uniqueCount !== values.length) {
        validationEl.textContent = 'Serial numbers must be unique.';
        return false;
    }

    return true;
}

function setupDropzone() {
    const dropzone = document.getElementById('imageDropzone');
    const fileInput = document.getElementById('product_images');
    const browseBtn = document.getElementById('browseImagesBtn');
    if (!dropzone || !fileInput) return;

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.add('dragover');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropzone.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropzone.classList.remove('dragover');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        const files = event.dataTransfer.files;
        if (files?.length) {
            fileInput.files = files;
            renderImagePreview(files);
        }
    });

    dropzone.addEventListener('click', () => fileInput.click());
    browseBtn?.addEventListener('click', () => fileInput.click());
}

let draftTimer = null;
function queueDraftSaved() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(() => {
        const indicator = document.getElementById('draftIndicator');
        if (!indicator) return;
        indicator.className = 'badge bg-success-subtle text-success-emphasis border';
        indicator.textContent = `Saved ${new Date().toLocaleTimeString()}`;
    }, 400);
}

document.querySelectorAll('.rte-btn').forEach((button) => {
    button.addEventListener('click', () => {
        document.execCommand(button.dataset.command, false, null);
        syncDescriptionField();
        queueDraftSaved();
    });
});

document.getElementById('description_editor')?.addEventListener('input', () => {
    syncDescriptionField();
    queueDraftSaved();
});
document.getElementById('cost_price').addEventListener('input', calculateMarkup);
document.getElementById('selling_price').addEventListener('input', calculateMarkup);
document.getElementById('category_id').addEventListener('change', () => {
    renderTemplateSpecs();
    if (document.getElementById('auto_generate_sku').checked) {
        generateSku();
    }
});
document.getElementById('brand').addEventListener('input', () => {
    if (document.getElementById('auto_generate_sku').checked) {
        generateSku();
    }
});
document.getElementById('generateSkuBtn').addEventListener('click', generateSku);
document.getElementById('is_taxable')?.addEventListener('change', updateTaxableState);
document.getElementById('status_toggle').addEventListener('change', updateStatusValue);
document.getElementById('auto_generate_sku').addEventListener('change', (event) => {
    document.getElementById('sku').readOnly = event.target.checked;
    if (event.target.checked) {
        generateSku();
    }
});
document.getElementById('product_images').addEventListener('change', (event) => {
    renderImagePreview(event.target.files);
});
document.querySelector('input[name="stock_quantity"]')?.addEventListener('input', () => {
    renderSerialNumberInputs();
    queueDraftSaved();
});
document.getElementById('applySerialBulkBtn')?.addEventListener('click', () => {
    applyBulkSerials();
    queueDraftSaved();
});
document.getElementById('addCustomSpecBtn').addEventListener('click', addCustomSpecRow);
document.getElementById('customSpecsContainer').addEventListener('click', (event) => {
    const target = event.target.closest('.remove-custom-spec');
    if (target) {
        const row = target.closest('.custom-spec-row');
        if (row) {
            row.remove();
        }
    }
});
document.getElementById('productForm').addEventListener('input', queueDraftSaved);
document.getElementById('productForm').addEventListener('submit', (event) => {
    syncDescriptionField();
    if (!validateSerialNumbersClient()) {
        event.preventDefault();
    }
});

calculateMarkup();
renderTemplateSpecs();
updateTaxableState();
updateStatusValue();
setupDropzone();
renderSerialNumberInputs();
document.getElementById('sku').readOnly = document.getElementById('auto_generate_sku').checked;
</script>

<?php include 'templates/footer.php'; ?>
