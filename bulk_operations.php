<?php
/**
 * Bulk Operations Page
 * Import/export and mass update tools for products
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('products.view');

$db = getDB();
$productsModule = new ProductsModule();
$productsEnhanced = new ProductsModuleEnhanced();

function getProductsColumnsMapBulk(Database $db) {
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

function hasProductColumnBulk(array $columnsMap, $columnName) {
    return isset($columnsMap[$columnName]);
}

function normalizeImportHeader($header) {
    $header = strtolower(trim((string)$header));
    $header = preg_replace('/[^a-z0-9_]+/', '_', $header);
    return trim($header, '_');
}

function parseCsvRows($tmpFile) {
    $handle = fopen($tmpFile, 'r');
    if (!$handle) {
        throw new Exception('Unable to read CSV file.');
    }

    $rows = [];
    $header = null;
    while (($data = fgetcsv($handle)) !== false) {
        if ($header === null) {
            $header = array_map('normalizeImportHeader', $data);
            continue;
        }
        if (count(array_filter($data, static function ($v) { return trim((string)$v) !== ''; })) === 0) {
            continue;
        }
        $row = [];
        foreach ($header as $index => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string)($data[$index] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function parseExcelRows($tmpFile) {
    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
        throw new Exception('Excel import requires phpoffice/phpspreadsheet.');
    }

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();
    $highestColumn = $sheet->getHighestDataColumn();
    $headerRow = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, true)[1] ?? [];
    $header = [];
    foreach ($headerRow as $column => $value) {
        $header[$column] = normalizeImportHeader($value);
    }

    $rows = [];
    for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
        $rowData = $sheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, true, true, true)[$rowNumber] ?? [];
        $row = [];
        foreach ($header as $column => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string)($rowData[$column] ?? ''));
        }
        if (count(array_filter($row, static function ($v) { return $v !== ''; })) === 0) {
            continue;
        }
        $rows[] = $row;
    }

    return $rows;
}

function parseSkuList($raw) {
    $parts = preg_split('/[\s,]+/', trim((string)$raw));
    $skus = [];
    foreach ($parts as $sku) {
        $sku = trim($sku);
        if ($sku === '') {
            continue;
        }
        $skus[] = $sku;
    }
    return array_values(array_unique($skus));
}

function getScopedProducts(Database $db, array $columnsMap, $scope, $categoryId, array $skuList) {
    $where = [];
    $params = [];

    if (hasProductColumnBulk($columnsMap, 'deleted_at')) {
        $where[] = "p.deleted_at IS NULL";
    }

    if ($scope === 'category' && $categoryId > 0) {
        $where[] = "p.category_id = ?";
        $params[] = $categoryId;
    } elseif ($scope === 'sku' && !empty($skuList)) {
        $placeholders = implode(',', array_fill(0, count($skuList), '?'));
        $where[] = "p.sku IN ({$placeholders})";
        $params = array_merge($params, $skuList);
    }

    $whereSql = empty($where) ? '1=1' : implode(' AND ', $where);
    return $db->fetchAll(
        "SELECT p.id, p.sku, p.name, p.cost_price, p.selling_price
         FROM products p
         WHERE {$whereSql}
         ORDER BY p.name",
        $params
    );
}

$columnsMap = getProductsColumnsMapBulk($db);
$categories = $db->fetchAll("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name");
$pageTitle = 'Bulk Product Operations';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $operation = $_POST['operation'] ?? '';

    try {
        if ($operation !== 'export') {
            requirePermission('products.edit');
        }

        if ($operation === 'import') {
            if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Import file is required.');
            }

            $tmpFile = $_FILES['import_file']['tmp_name'];
            $originalName = $_FILES['import_file']['name'] ?? '';
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if ($extension === 'csv') {
                $rows = parseCsvRows($tmpFile);
            } elseif (in_array($extension, ['xlsx', 'xls'], true)) {
                $rows = parseExcelRows($tmpFile);
            } else {
                throw new Exception('Unsupported import format. Use CSV, XLS, or XLSX.');
            }

            if (empty($rows)) {
                throw new Exception('No data rows found in import file.');
            }

            $required = ['sku', 'name', 'category', 'selling_price'];
            foreach ($required as $requiredField) {
                if (!array_key_exists($requiredField, $rows[0])) {
                    throw new Exception("Missing required column: {$requiredField}");
                }
            }

            $categoryMap = [];
            $categoryRows = $db->fetchAll("SELECT id, name FROM categories WHERE is_active = 1");
            foreach ($categoryRows as $categoryRow) {
                $categoryMap[strtolower(trim($categoryRow['name']))] = (int)$categoryRow['id'];
            }

            $supplierMap = [];
            $supplierRows = $db->fetchAll("SELECT id, name FROM suppliers WHERE is_active = 1");
            foreach ($supplierRows as $supplierRow) {
                $supplierMap[strtolower(trim($supplierRow['name']))] = (int)$supplierRow['id'];
            }

            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $line = $index + 2;
                $sku = trim($row['sku'] ?? '');
                $name = trim($row['name'] ?? '');
                $categoryName = strtolower(trim($row['category'] ?? ''));
                $sellingPrice = (float)($row['selling_price'] ?? 0);

                if ($sku === '' || $name === '' || $categoryName === '') {
                    $skipped++;
                    $errors[] = "Row {$line}: SKU, name, and category are required.";
                    continue;
                }
                if (!isset($categoryMap[$categoryName])) {
                    $skipped++;
                    $errors[] = "Row {$line}: Unknown category '{$row['category']}'.";
                    continue;
                }
                if ($sellingPrice < 0) {
                    $skipped++;
                    $errors[] = "Row {$line}: Selling price cannot be negative.";
                    continue;
                }

                $costPrice = isset($row['cost_price']) && $row['cost_price'] !== '' ? (float)$row['cost_price'] : 0;
                $status = strtolower(trim($row['status'] ?? 'active'));
                $isActive = ($status === 'discontinued' || $status === 'inactive') ? 0 : 1;
                $supplierName = strtolower(trim($row['supplier'] ?? ''));
                $markup = $productsEnhanced->calculateMarkup($costPrice, $sellingPrice);

                $data = [
                    'sku' => sanitize($sku),
                    'name' => sanitize($name),
                    'description' => sanitize($row['description'] ?? ''),
                    'category_id' => $categoryMap[$categoryName],
                    'supplier_id' => $supplierName !== '' && isset($supplierMap[$supplierName]) ? $supplierMap[$supplierName] : null,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'stock_quantity' => (int)($row['stock_quantity'] ?? 0),
                    'min_stock_level' => (int)($row['reorder_level'] ?? 0),
                    'max_stock_level' => isset($row['max_stock_level']) && $row['max_stock_level'] !== '' ? (int)$row['max_stock_level'] : null,
                    'barcode' => sanitize($row['barcode'] ?? ''),
                    'is_taxable' => isset($row['is_taxable']) ? (int)$row['is_taxable'] : 1,
                    'tax_rate' => isset($row['tax_rate']) ? (float)$row['tax_rate'] : 0,
                    'is_active' => $isActive
                ];

                if (hasProductColumnBulk($columnsMap, 'brand')) {
                    $data['brand'] = sanitize($row['brand'] ?? '') ?: null;
                }
                if (hasProductColumnBulk($columnsMap, 'model')) {
                    $data['model'] = sanitize($row['model'] ?? '') ?: null;
                }
                if (hasProductColumnBulk($columnsMap, 'location')) {
                    $data['location'] = sanitize($row['location'] ?? '') ?: null;
                }
                if (hasProductColumnBulk($columnsMap, 'warranty_period')) {
                    $data['warranty_period'] = isset($row['warranty_period']) && $row['warranty_period'] !== '' ? (int)$row['warranty_period'] : null;
                }
                if (hasProductColumnBulk($columnsMap, 'markup_percentage')) {
                    $data['markup_percentage'] = $markup;
                }

                try {
                    $existing = $productsModule->getProductBySKU($data['sku']);
                    if ($existing) {
                        $productsModule->updateProduct((int)$existing['id'], $data);
                        $updated++;
                    } else {
                        $productsModule->createProduct($data);
                        $created++;
                    }
                } catch (Exception $innerException) {
                    $skipped++;
                    $errors[] = "Row {$line}: " . $innerException->getMessage();
                }
            }

            $message = "Import completed. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}.";
            if (!empty($errors)) {
                $message .= ' First errors: ' . implode(' | ', array_slice($errors, 0, 5));
            }
            setFlashMessage('success', $message);
            redirect(getBaseUrl() . '/bulk_operations.php');
        }

        if ($operation === 'export') {
            $format = strtolower(trim($_POST['export_format'] ?? 'csv'));
            $where = hasProductColumnBulk($columnsMap, 'deleted_at') ? 'WHERE p.deleted_at IS NULL' : '';
            $rows = $db->fetchAll(
                "SELECT p.sku, p.name, p.brand, p.model, c.name AS category, s.name AS supplier,
                        p.cost_price, p.selling_price, p.stock_quantity, p.min_stock_level,
                        p.barcode, p.tax_rate, p.is_active, p.created_at
                 FROM products p
                 LEFT JOIN categories c ON c.id = p.category_id
                 LEFT JOIN suppliers s ON s.id = p.supplier_id
                 {$where}
                 ORDER BY p.name"
            );

            $headers = ['sku', 'name', 'brand', 'model', 'category', 'supplier', 'cost_price', 'selling_price', 'stock_quantity', 'reorder_level', 'barcode', 'tax_rate', 'status', 'created_at'];
            $filenameDate = date('Ymd_His');

            if ($format === 'xlsx') {
                if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                    throw new Exception('Excel export requires phpoffice/phpspreadsheet.');
                }

                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($headers as $index => $header) {
                    $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
                }
                $rowNumber = 2;
                foreach ($rows as $row) {
                    $values = [
                        $row['sku'],
                        $row['name'],
                        $row['brand'],
                        $row['model'],
                        $row['category'],
                        $row['supplier'],
                        $row['cost_price'],
                        $row['selling_price'],
                        $row['stock_quantity'],
                        $row['min_stock_level'],
                        $row['barcode'],
                        $row['tax_rate'],
                        (int)$row['is_active'] === 1 ? 'active' : 'discontinued',
                        $row['created_at']
                    ];
                    foreach ($values as $index => $value) {
                        $sheet->setCellValueByColumnAndRow($index + 1, $rowNumber, $value);
                    }
                    $rowNumber++;
                }

                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="products_export_' . $filenameDate . '.xlsx"');
                header('Cache-Control: max-age=0');
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $writer->save('php://output');
                exit;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="products_export_' . $filenameDate . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['sku'],
                    $row['name'],
                    $row['brand'],
                    $row['model'],
                    $row['category'],
                    $row['supplier'],
                    $row['cost_price'],
                    $row['selling_price'],
                    $row['stock_quantity'],
                    $row['min_stock_level'],
                    $row['barcode'],
                    $row['tax_rate'],
                    (int)$row['is_active'] === 1 ? 'active' : 'discontinued',
                    $row['created_at']
                ]);
            }
            fclose($output);
            exit;
        }

        if ($operation === 'bulk_price') {
            $scope = $_POST['scope'] ?? 'all';
            $categoryId = (int)($_POST['scope_category_id'] ?? 0);
            $skuList = parseSkuList($_POST['scope_skus'] ?? '');
            $priceType = $_POST['price_type'] ?? 'percentage';
            $priceField = $_POST['price_field'] ?? 'selling_price';
            $priceValue = (float)($_POST['price_value'] ?? 0);

            if (!in_array($priceType, ['percentage', 'fixed'], true)) {
                throw new Exception('Invalid price update type.');
            }
            if (!in_array($priceField, ['selling_price', 'cost_price'], true)) {
                throw new Exception('Invalid price field.');
            }

            $targets = getScopedProducts($db, $columnsMap, $scope, $categoryId, $skuList);
            if (empty($targets)) {
                throw new Exception('No products matched the selected scope.');
            }

            $updatedCount = 0;
            foreach ($targets as $target) {
                $currentPrice = (float)$target[$priceField];
                if ($priceType === 'percentage') {
                    $newPrice = $currentPrice + ($currentPrice * ($priceValue / 100));
                } else {
                    $newPrice = $currentPrice + $priceValue;
                }
                $newPrice = max(0, round($newPrice, 2));

                $updateData = [$priceField => $newPrice];
                $newCost = $priceField === 'cost_price' ? $newPrice : (float)$target['cost_price'];
                $newSelling = $priceField === 'selling_price' ? $newPrice : (float)$target['selling_price'];
                if (hasProductColumnBulk($columnsMap, 'markup_percentage')) {
                    $updateData['markup_percentage'] = $productsEnhanced->calculateMarkup($newCost, $newSelling);
                }

                $productsModule->updateProduct((int)$target['id'], $updateData);
                $updatedCount++;
            }

            setFlashMessage('success', "Price update completed for {$updatedCount} products.");
            redirect(getBaseUrl() . '/bulk_operations.php');
        }

        if ($operation === 'bulk_category') {
            $scope = $_POST['scope'] ?? 'all';
            $categoryId = (int)($_POST['scope_category_id'] ?? 0);
            $skuList = parseSkuList($_POST['scope_skus'] ?? '');
            $targetCategoryId = (int)($_POST['target_category_id'] ?? 0);

            if ($targetCategoryId <= 0) {
                throw new Exception('Target category is required.');
            }

            $targets = getScopedProducts($db, $columnsMap, $scope, $categoryId, $skuList);
            if (empty($targets)) {
                throw new Exception('No products matched the selected scope.');
            }

            $updatedCount = 0;
            foreach ($targets as $target) {
                $productsModule->updateProduct((int)$target['id'], ['category_id' => $targetCategoryId]);
                $updatedCount++;
            }

            setFlashMessage('success', "Category assignment completed for {$updatedCount} products.");
            redirect(getBaseUrl() . '/bulk_operations.php');
        }

        if ($operation === 'bulk_status') {
            $scope = $_POST['scope'] ?? 'all';
            $categoryId = (int)($_POST['scope_category_id'] ?? 0);
            $skuList = parseSkuList($_POST['scope_skus'] ?? '');
            $statusAction = $_POST['status_action'] ?? 'activate';
            $isActive = $statusAction === 'deactivate' ? 0 : 1;

            $targets = getScopedProducts($db, $columnsMap, $scope, $categoryId, $skuList);
            if (empty($targets)) {
                throw new Exception('No products matched the selected scope.');
            }

            $updatedCount = 0;
            foreach ($targets as $target) {
                $productsModule->updateProduct((int)$target['id'], ['is_active' => $isActive]);
                $updatedCount++;
            }

            setFlashMessage('success', ($isActive ? 'Activation' : 'Deactivation') . " completed for {$updatedCount} products.");
            redirect(getBaseUrl() . '/bulk_operations.php');
        }

        throw new Exception('Invalid operation.');
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        redirect(getBaseUrl() . '/bulk_operations.php');
    }
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Bulk Product Operations</h1>
    </div>
</div>

<div class="alert alert-info">
    <strong>Import format:</strong> include columns like `sku`, `name`, `category`, `selling_price`. Optional columns: `cost_price`, `stock_quantity`, `brand`, `model`, `supplier`, `status`, `barcode`, `tax_rate`, `reorder_level`, `location`, `warranty_period`.
</div>

<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Import CSV/Excel</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" class="bulk-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="operation" value="import">
                    <div class="mb-3">
                        <label class="form-label">Select file</label>
                        <input type="file" class="form-control" name="import_file" accept=".csv,.xlsx,.xls" required>
                    </div>
                    <div class="progress mb-3 d-none progress-wrap">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Start Import</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Export CSV/Excel</div>
            <div class="card-body">
                <form method="POST" class="bulk-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="operation" value="export">
                    <div class="mb-3">
                        <label class="form-label">Format</label>
                        <select class="form-select" name="export_format">
                            <option value="csv">CSV</option>
                            <option value="xlsx">Excel (XLSX)</option>
                        </select>
                    </div>
                    <div class="progress mb-3 d-none progress-wrap">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <button type="submit" class="btn btn-success">Download Export</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">Bulk Price Update</div>
            <div class="card-body">
                <form method="POST" class="bulk-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="operation" value="bulk_price">
                    <div class="mb-2">
                        <label class="form-label">Scope</label>
                        <select class="form-select scope-select" name="scope">
                            <option value="all">All Products</option>
                            <option value="category">By Category</option>
                            <option value="sku">By SKU List</option>
                        </select>
                    </div>
                    <div class="mb-2 scope-category d-none">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="scope_category_id">
                            <option value="0">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>"><?php echo escape($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2 scope-skus d-none">
                        <label class="form-label">SKU List</label>
                        <textarea class="form-control" name="scope_skus" rows="2" placeholder="SKU1, SKU2, SKU3"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Price Field</label>
                        <select class="form-select" name="price_field">
                            <option value="selling_price">Selling Price</option>
                            <option value="cost_price">Cost Price</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="price_type">
                                <option value="percentage">Percentage</option>
                                <option value="fixed">Fixed Amount</option>
                            </select>
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label">Value</label>
                            <input type="number" class="form-control" name="price_value" step="0.01" required>
                        </div>
                    </div>
                    <div class="progress mb-3 d-none progress-wrap">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <button type="submit" class="btn btn-warning">Apply Price Update</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">Bulk Category Assignment</div>
            <div class="card-body">
                <form method="POST" class="bulk-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="operation" value="bulk_category">
                    <div class="mb-2">
                        <label class="form-label">Scope</label>
                        <select class="form-select scope-select" name="scope">
                            <option value="all">All Products</option>
                            <option value="category">By Category</option>
                            <option value="sku">By SKU List</option>
                        </select>
                    </div>
                    <div class="mb-2 scope-category d-none">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="scope_category_id">
                            <option value="0">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>"><?php echo escape($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2 scope-skus d-none">
                        <label class="form-label">SKU List</label>
                        <textarea class="form-control" name="scope_skus" rows="2" placeholder="SKU1, SKU2, SKU3"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">New Category</label>
                        <select class="form-select" name="target_category_id" required>
                            <option value="0">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>"><?php echo escape($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="progress mb-3 d-none progress-wrap">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <button type="submit" class="btn btn-info">Assign Category</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header">Bulk Activate/Deactivate</div>
            <div class="card-body">
                <form method="POST" class="bulk-form">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="operation" value="bulk_status">
                    <div class="mb-2">
                        <label class="form-label">Scope</label>
                        <select class="form-select scope-select" name="scope">
                            <option value="all">All Products</option>
                            <option value="category">By Category</option>
                            <option value="sku">By SKU List</option>
                        </select>
                    </div>
                    <div class="mb-2 scope-category d-none">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="scope_category_id">
                            <option value="0">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo (int)$category['id']; ?>"><?php echo escape($category['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2 scope-skus d-none">
                        <label class="form-label">SKU List</label>
                        <textarea class="form-control" name="scope_skus" rows="2" placeholder="SKU1, SKU2, SKU3"></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Action</label>
                        <select class="form-select" name="status_action">
                            <option value="activate">Activate</option>
                            <option value="deactivate">Deactivate</option>
                        </select>
                    </div>
                    <div class="progress mb-3 d-none progress-wrap">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%">0%</div>
                    </div>
                    <button type="submit" class="btn btn-secondary">Apply Status</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function toggleScopeFields(form) {
    const scope = form.querySelector('.scope-select')?.value;
    const scopeCategory = form.querySelector('.scope-category');
    const scopeSkus = form.querySelector('.scope-skus');

    if (scopeCategory) {
        scopeCategory.classList.toggle('d-none', scope !== 'category');
    }
    if (scopeSkus) {
        scopeSkus.classList.toggle('d-none', scope !== 'sku');
    }
}

function attachProgress(form) {
    const progressWrap = form.querySelector('.progress-wrap');
    const progressBar = form.querySelector('.progress-bar');
    if (!progressWrap || !progressBar) return;

    progressWrap.classList.remove('d-none');
    let value = 0;
    const timer = setInterval(() => {
        value = Math.min(90, value + 10);
        progressBar.style.width = `${value}%`;
        progressBar.textContent = `${value}%`;
        if (value >= 90) {
            clearInterval(timer);
        }
    }, 200);
}

document.querySelectorAll('.scope-select').forEach((select) => {
    const form = select.closest('form');
    toggleScopeFields(form);
    select.addEventListener('change', () => toggleScopeFields(form));
});

document.querySelectorAll('.bulk-form').forEach((form) => {
    form.addEventListener('submit', () => attachProgress(form));
});
</script>

<?php include 'templates/footer.php'; ?>
