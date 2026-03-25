<?php
/**
 * Products Management Page
 * Redirects to product_list.php for the new interface
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('products.view');

redirect(getBaseUrl() . '/product_list.php');

$pageTitle = 'Products';
$productsModule = new ProductsModule();
$categoriesModule = new CategoriesModule();
$suppliersModule = new SuppliersModule();
$db = getDB();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'add' || $action === 'edit') {
            $data = [
                'sku' => sanitize($_POST['sku']),
                'name' => sanitize($_POST['name']),
                'description' => sanitize($_POST['description'] ?? ''),
                'category_id' => (int)$_POST['category_id'],
                'supplier_id' => !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null,
                'cost_price' => (float)$_POST['cost_price'],
                'selling_price' => (float)$_POST['selling_price'],
                'stock_quantity' => (int)($_POST['stock_quantity'] ?? 0),
                'min_stock_level' => (int)($_POST['min_stock_level'] ?? 0),
                'max_stock_level' => !empty($_POST['max_stock_level']) ? (int)$_POST['max_stock_level'] : null,
                'unit' => sanitize($_POST['unit'] ?? 'pcs'),
                'barcode' => sanitize($_POST['barcode'] ?? ''),
                'is_taxable' => isset($_POST['is_taxable']) ? 1 : 0,
                'tax_rate' => (float)($_POST['tax_rate'] ?? 0),
                'weight' => !empty($_POST['weight']) ? (float)$_POST['weight'] : null,
                'dimensions' => sanitize($_POST['dimensions'] ?? '')
            ];
            
            if ($action === 'add') {
                $productId = $productsModule->createProduct($data);
                setFlashMessage('success', 'Product created successfully.');
                redirect(getBaseUrl() . "/products.php?view={$productId}");
            } else {
                $productsModule->updateProduct($id, $data);
                setFlashMessage('success', 'Product updated successfully.');
                redirect(getBaseUrl() . "/products.php?view={$id}");
            }
        } elseif ($action === 'delete') {
            $productsModule->deleteProduct($id);
            setFlashMessage('success', 'Product deleted successfully.');
            redirect(getBaseUrl() . '/products.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get data for display
$product = null;
if ($id) {
    $product = $productsModule->getProduct($id);
}

$categories = $categoriesModule->getCategories();
$suppliers = $suppliersModule->getSuppliers(['search' => ''], 1000, 0);

// Pagination
$currentPage = (int)($_GET['page'] ?? 1);
$filters = [
    'search' => $_GET['search'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'low_stock' => isset($_GET['low_stock']) ? true : false
];

$pagination = getPagination($currentPage, 0); // Total count would be calculated in real implementation
$products = $productsModule->getProducts($filters, ITEMS_PER_PAGE, $pagination['offset']);

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Products</h1>
            <?php if (hasPermission('products.create')): ?>
            <a href="<?php echo getBaseUrl(); ?>/products.php?action=add" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Add Product
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" placeholder="Search products..." value="<?php echo escape($filters['search']); ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="category_id">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $filters['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo escape($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="low_stock" id="low_stock" <?php echo $filters['low_stock'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="low_stock">Low Stock</label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i> Filter</button>
                <a href="<?php echo getBaseUrl(); ?>/products.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body">
        <?php if (empty($products)): ?>
            <p class="text-muted text-center mb-0">No products found</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Stock</th>
                            <th>Cost Price</th>
                            <th>Selling Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $prod): ?>
                        <tr>
                            <td><?php echo escape($prod['sku']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/products.php?view=<?php echo $prod['id']; ?>">
                                    <?php echo escape($prod['name']); ?>
                                </a>
                            </td>
                            <td><?php echo escape($prod['category_name'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $stockClass = $prod['stock_quantity'] <= $prod['min_stock_level'] ? 'text-danger' : '';
                                echo "<span class='{$stockClass}'>" . $prod['stock_quantity'] . "</span>";
                                ?>
                            </td>
                            <td><?php echo formatCurrency($prod['cost_price']); ?></td>
                            <td><?php echo formatCurrency($prod['selling_price']); ?></td>
                            <td>
                                <a href="<?php echo getBaseUrl(); ?>/products.php?view=<?php echo $prod['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (hasPermission('products.edit')): ?>
                                <a href="<?php echo getBaseUrl(); ?>/products.php?action=edit&id=<?php echo $prod['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0"><?php echo $action === 'add' ? 'Add' : 'Edit'; ?> Product</h1>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="sku" class="form-label">SKU <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="sku" name="sku" required value="<?php echo escape($product['sku'] ?? ''); ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label for="name" class="form-label">Product Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" required value="<?php echo escape($product['name'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?php echo escape($product['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="category_id" class="form-label">Category <span class="text-danger">*</span></label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($product['category_id'] ?? '') == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo escape($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="supplier_id" class="form-label">Supplier</label>
                    <select class="form-select" id="supplier_id" name="supplier_id">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $supp): ?>
                        <option value="<?php echo $supp['id']; ?>" <?php echo ($product['supplier_id'] ?? '') == $supp['id'] ? 'selected' : ''; ?>>
                            <?php echo escape($supp['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="cost_price" class="form-label">Cost Price <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" class="form-control" id="cost_price" name="cost_price" required value="<?php echo $product['cost_price'] ?? '0.00'; ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="selling_price" class="form-label">Selling Price <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" class="form-control" id="selling_price" name="selling_price" required value="<?php echo $product['selling_price'] ?? '0.00'; ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="barcode" class="form-label">Barcode</label>
                    <input type="text" class="form-control" id="barcode" name="barcode" value="<?php echo escape($product['barcode'] ?? ''); ?>">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="stock_quantity" class="form-label">Stock Quantity</label>
                    <input type="number" class="form-control" id="stock_quantity" name="stock_quantity" value="<?php echo $product['stock_quantity'] ?? '0'; ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="min_stock_level" class="form-label">Min Stock Level</label>
                    <input type="number" class="form-control" id="min_stock_level" name="min_stock_level" value="<?php echo $product['min_stock_level'] ?? '0'; ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="max_stock_level" class="form-label">Max Stock Level</label>
                    <input type="number" class="form-control" id="max_stock_level" name="max_stock_level" value="<?php echo $product['max_stock_level'] ?? ''; ?>">
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                    <input type="number" step="0.01" class="form-control" id="tax_rate" name="tax_rate" value="<?php echo $product['tax_rate'] ?? '0.00'; ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="is_taxable" name="is_taxable" <?php echo ($product['is_taxable'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_taxable">Taxable</label>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <a href="<?php echo getBaseUrl(); ?>/products.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><?php echo $action === 'add' ? 'Create' : 'Update'; ?> Product</button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($action === 'view' && $product): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0"><?php echo escape($product['name']); ?></h1>
            <div>
                <?php if (hasPermission('products.edit')): ?>
                <a href="<?php echo getBaseUrl(); ?>/products.php?action=edit&id=<?php echo $product['id']; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Product Details</div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="200">SKU</th>
                        <td><?php echo escape($product['sku']); ?></td>
                    </tr>
                    <tr>
                        <th>Category</th>
                        <td><?php echo escape($product['category_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Supplier</th>
                        <td><?php echo escape($product['supplier_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Description</th>
                        <td><?php echo nl2br(escape($product['description'] ?? '-')); ?></td>
                    </tr>
                    <tr>
                        <th>Stock Quantity</th>
                        <td>
                            <span class="<?php echo $product['stock_quantity'] <= $product['min_stock_level'] ? 'text-danger fw-bold' : ''; ?>">
                                <?php echo $product['stock_quantity']; ?>
                            </span>
                            (Min: <?php echo $product['min_stock_level']; ?>)
                        </td>
                    </tr>
                    <tr>
                        <th>Cost Price</th>
                        <td><?php echo formatCurrency($product['cost_price']); ?></td>
                    </tr>
                    <tr>
                        <th>Selling Price</th>
                        <td><?php echo formatCurrency($product['selling_price']); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">Quick Actions</div>
            <div class="card-body">
                <a href="<?php echo getBaseUrl(); ?>/inventory.php?action=adjust&product_id=<?php echo $product['id']; ?>" class="btn btn-warning w-100 mb-2">
                    <i class="bi bi-arrow-left-right"></i> Adjust Stock
                </a>
                <a href="<?php echo getBaseUrl(); ?>/pos.php?product_id=<?php echo $product['id']; ?>" class="btn btn-success w-100 mb-2">
                    <i class="bi bi-cart-plus"></i> Add to POS
                </a>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include 'templates/footer.php'; ?>

