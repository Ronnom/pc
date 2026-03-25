<?php
/**
 * Product View Page
 * Complete product details with stock indicator, sales history, image gallery
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('products.view');

$pageTitle = 'Product Details';
$productsModule = new ProductsModule();
$productsEnhanced = new ProductsModuleEnhanced();
$db = getDB();

$id = $_GET['id'] ?? null;

if (!$id) {
    setFlashMessage('error', 'Product ID is required.');
    redirect(getBaseUrl() . '/product_list.php');
}

$product = $productsEnhanced->getProduct($id);
if (!$product) {
    setFlashMessage('error', 'Product not found.');
    redirect(getBaseUrl() . '/product_list.php');
}

// Get additional data
$specifications = $productsModule->getProductSpecifications($id);
$images = $productsModule->getProductImages($id);
$salesHistory = $productsEnhanced->getSalesHistory($id, 30);
$priceHistory = [];
if (function_exists('_logTableExists') && _logTableExists('price_history')) {
    $priceHistory = $db->fetchAll(
        "SELECT ph.*, u.username as changed_by_name 
         FROM price_history ph 
         LEFT JOIN users u ON ph.changed_by = u.id 
         WHERE ph.product_id = ? 
         ORDER BY ph.created_at DESC 
         LIMIT 20",
        [$id]
    );
}

// Get supplier info
$supplier = null;
if ($product['supplier_id']) {
    $suppliersModule = new SuppliersModule();
    $supplier = $suppliersModule->getSupplier($product['supplier_id']);
}

// Stock level indicator: green > 10, yellow <= 10, red = 0
$stockLevel = 'good';
$stockClass = 'success';
if ((int)$product['stock_quantity'] === 0) {
    $stockLevel = 'out';
    $stockClass = 'danger';
} elseif ((int)$product['stock_quantity'] <= 10) {
    $stockLevel = 'low';
    $stockClass = 'warning';
}

include 'templates/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0"><?php echo escape($product['name']); ?></h1>
            <div>
                <?php if (hasPermission('products.edit')): ?>
                <a href="<?php echo getBaseUrl(); ?>/product_form.php?action=edit&id=<?php echo $product['id']; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <?php endif; ?>
                <a href="<?php echo getBaseUrl(); ?>/product_list.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left Column: Images and Basic Info -->
    <div class="col-lg-5">
        <!-- Image Gallery -->
        <div class="card mb-4">
            <div class="card-body">
                <?php if (!empty($images)): ?>
                    <div id="productImageCarousel" class="carousel slide" data-bs-ride="carousel">
                        <div class="carousel-inner">
                            <?php foreach ($images as $index => $image): ?>
                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                <img src="<?php echo getBaseUrl() . '/' . $image['image_path']; ?>" 
                                     class="d-block w-100" 
                                     alt="<?php echo escape($image['alt_text'] ?? $product['name']); ?>"
                                     style="max-height: 400px; object-fit: contain; cursor: pointer;"
                                     onclick="openImageModal('<?php echo getBaseUrl() . '/' . $image['image_path']; ?>')">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($images) > 1): ?>
                        <button class="carousel-control-prev" type="button" data-bs-target="#productImageCarousel" data-bs-slide="prev">
                            <span class="carousel-control-prev-icon"></span>
                        </button>
                        <button class="carousel-control-next" type="button" data-bs-target="#productImageCarousel" data-bs-slide="next">
                            <span class="carousel-control-next-icon"></span>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Thumbnails -->
                    <?php if (count($images) > 1): ?>
                    <div class="row mt-3">
                        <?php foreach ($images as $index => $image): ?>
                        <div class="col-3">
                            <img src="<?php echo getBaseUrl() . '/' . $image['image_path']; ?>" 
                                 class="img-thumbnail <?php echo $index === 0 ? 'border-primary' : ''; ?>"
                                 style="cursor: pointer;"
                                 onclick="$('#productImageCarousel').carousel(<?php echo $index; ?>)">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center py-5 bg-light">
                        <i class="bi bi-image" style="font-size: 5rem; color: #ccc;"></i>
                        <p class="text-muted mt-3">No images available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Info -->
        <div class="card">
            <div class="card-header">Quick Information</div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">SKU</th>
                        <td><?php echo escape($product['sku']); ?></td>
                    </tr>
                    <tr>
                        <th>Barcode</th>
                        <td><?php echo escape($product['barcode'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Category</th>
                        <td><?php echo escape($product['category_name'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Brand</th>
                        <td><?php echo escape($product['brand'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Model</th>
                        <td><?php echo escape($product['model'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo $product['is_active'] ? getStatusBadge('active') : getStatusBadge('inactive'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Right Column: Details -->
    <div class="col-lg-7">
        <!-- Stock Level Indicator -->
        <div class="card mb-4 border-<?php echo $stockClass; ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-1">Stock Level</h5>
                        <h2 class="mb-0 text-<?php echo $stockClass; ?>">
                            <?php echo $product['stock_quantity']; ?>
                            <small class="fs-6 text-muted">units</small>
                        </h2>
                        <small class="text-muted">
                            Min: <?php echo getProductThresholdValue($product); ?> | 
                            <?php echo !empty($product['max_stock_level']) ? 'Max: ' . $product['max_stock_level'] : 'No max limit'; ?>
                        </small>
                    </div>
                    <div class="text-center">
                        <i class="bi bi-<?php echo $stockLevel === 'out' ? 'x-circle' : ($stockLevel === 'low' ? 'exclamation-triangle' : 'check-circle'); ?> text-<?php echo $stockClass; ?>" 
                           style="font-size: 4rem;"></i>
                        <p class="mb-0 fw-bold text-<?php echo $stockClass; ?>">
                            <?php 
                            echo $stockLevel === 'out' ? 'Out of Stock' : 
                                ($stockLevel === 'low' ? 'Low Stock' : 'In Stock'); 
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Pricing -->
        <div class="card mb-4">
            <div class="card-header">Pricing Information</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 text-center">
                        <small class="text-muted">Cost Price</small>
                        <h4><?php echo formatCurrency($product['cost_price']); ?></h4>
                    </div>
                    <div class="col-md-4 text-center">
                        <small class="text-muted">Selling Price</small>
                        <h4 class="text-primary"><?php echo formatCurrency(getProductPriceValue($product)); ?></h4>
                    </div>
                    <div class="col-md-4 text-center">
                        <small class="text-muted">Markup</small>
                        <h4 class="text-success">
                            <?php 
                            $markup = $productsEnhanced->calculateMarkup($product['cost_price'], getProductPriceValue($product));
                            echo number_format($markup, 2) . '%';
                            ?>
                        </h4>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Description -->
        <?php if ($product['description']): ?>
        <div class="card mb-4">
            <div class="card-header">Description</div>
            <div class="card-body">
                <?php echo nl2br(escape($product['description'])); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Technical Specifications -->
        <?php if (!empty($specifications)): ?>
        <div class="card mb-4">
            <div class="card-header">Technical Specifications</div>
            <div class="card-body">
                <?php
                $groupedSpecs = [];
                foreach ($specifications as $spec) {
                    $group = $spec['spec_group'] ?? 'General';
                    if (!isset($groupedSpecs[$group])) {
                        $groupedSpecs[$group] = [];
                    }
                    $groupedSpecs[$group][] = $spec;
                }
                ?>
                <?php foreach ($groupedSpecs as $group => $specs): ?>
                    <h6 class="mt-3 mb-2"><?php echo escape(ucfirst($group)); ?></h6>
                    <table class="table table-sm">
                        <?php foreach ($specs as $spec): ?>
                        <tr>
                            <th width="40%"><?php echo escape($spec['spec_key']); ?></th>
                            <td><?php echo escape($spec['spec_value']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Supplier Information -->
        <?php if ($supplier): ?>
        <div class="card mb-4">
            <div class="card-header">Supplier Information</div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr>
                        <th width="40%">Name</th>
                        <td><?php echo escape($supplier['name']); ?></td>
                    </tr>
                    <?php if (!empty($supplier['contact_person'])): ?>
                    <tr>
                        <th>Contact Person</th>
                        <td><?php echo escape($supplier['contact_person']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($supplier['email'])): ?>
                    <tr>
                        <th>Email</th>
                        <td><a href="mailto:<?php echo escape($supplier['email']); ?>"><?php echo escape($supplier['email']); ?></a></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($supplier['phone'])): ?>
                    <tr>
                        <th>Phone</th>
                        <td><a href="tel:<?php echo escape($supplier['phone']); ?>"><?php echo escape($supplier['phone']); ?></a></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Sales History Chart -->
<?php if (!empty($salesHistory)): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Sales History (Last 30 Days)</div>
            <div class="card-body">
                <canvas id="salesHistoryChart" height="80"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Price History -->
<?php if (!empty($priceHistory)): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">Price Change History</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Old Price</th>
                                <th>New Price</th>
                                <th>Changed By</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceHistory as $history): ?>
                            <tr>
                                <td><?php echo formatDateTime($history['created_at']); ?></td>
                                <td><?php echo ucfirst($history['price_type']); ?></td>
                                <td><?php echo $history['old_price'] ? formatCurrency($history['old_price']) : '-'; ?></td>
                                <td><?php echo formatCurrency($history['new_price']); ?></td>
                                <td><?php echo escape($history['changed_by_name'] ?? '-'); ?></td>
                                <td><?php echo escape($history['reason'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Image Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center p-0">
                <img id="modalImage" src="" class="img-fluid" alt="Product Image">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
function openImageModal(imageSrc) {
    document.getElementById('modalImage').src = imageSrc;
    new bootstrap.Modal(document.getElementById('imageModal')).show();
}

<?php if (!empty($salesHistory)): ?>
// Sales History Chart
const ctx = document.getElementById('salesHistoryChart').getContext('2d');
const salesData = <?php echo json_encode(array_reverse($salesHistory)); ?>;
new Chart(ctx, {
    type: 'line',
    data: {
        labels: salesData.map(item => item.sale_date),
        datasets: [{
            label: 'Quantity Sold',
            data: salesData.map(item => parseInt(item.total_quantity)),
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1
        }, {
            label: 'Revenue',
            data: salesData.map(item => parseFloat(item.total_amount)),
            borderColor: 'rgb(255, 99, 132)',
            backgroundColor: 'rgba(255, 99, 132, 0.2)',
            yAxisID: 'y1',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
                position: 'left',
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                beginAtZero: true,
                grid: {
                    drawOnChartArea: false,
                },
            }
        }
    }
});
<?php endif; ?>
</script>

<?php include 'templates/footer.php'; ?>

