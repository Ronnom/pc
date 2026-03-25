<?php
/**
 * Product List Page
 * Advanced listing with SaaS-style table view
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('products.view');

$pageTitle = 'Products';
$productsModule = new ProductsModule();
$productsEnhanced = new ProductsModuleEnhanced();
$categoriesModule = new CategoriesModule();
$db = getDB();
$canCreatePo = function_exists('has_permission')
    ? has_permission('purchase.create')
    : (function_exists('hasPermission') ? hasPermission('purchase.create') : false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $postAction = $_POST['action'] ?? '';
    if ($postAction === 'delete_product') {
        if (!isAdmin()) {
            setFlashMessage('error', 'Only administrators can delete products.');
            redirect(getBaseUrl() . '/product_list.php');
        }

        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            setFlashMessage('error', 'Invalid product selected.');
            redirect(getBaseUrl() . '/product_list.php');
        }

        try {
            $productsModule->deleteProduct($deleteId);
            setFlashMessage('success', 'Product deleted successfully.');
        } catch (Exception $e) {
            setFlashMessage('error', $e->getMessage());
        }

        $returnQuery = trim((string)($_POST['return_query'] ?? ''));
        $returnUrl = getBaseUrl() . '/product_list.php' . ($returnQuery !== '' ? ('?' . ltrim($returnQuery, '?')) : '');
        redirect($returnUrl);
    }
}

$filters = [
    'search' => $_GET['search'] ?? '',
    'category_id' => $_GET['category_id'] ?? '',
    'brand' => $_GET['brand'] ?? '',
    'price_min' => $_GET['price_min'] ?? '',
    'price_max' => $_GET['price_max'] ?? '',
    'stock_status' => $_GET['stock_status'] ?? '',
    'low_stock' => isset($_GET['low_stock']) ? true : false
];

$orderBy = $_GET['order_by'] ?? 'created_at';
$orderDir = $_GET['order_dir'] ?? 'DESC';
$itemsPerPage = (int)($_GET['per_page'] ?? $_SESSION['products_per_page'] ?? ITEMS_PER_PAGE);
$_SESSION['products_per_page'] = $itemsPerPage;
$currentPage = (int)($_GET['page'] ?? 1);

$totalProducts = $productsEnhanced->getProductCount($filters);
$pagination = getPagination($currentPage, $totalProducts, $itemsPerPage);
$products = $productsEnhanced->getProducts($filters, $itemsPerPage, $pagination['offset'], $orderBy, $orderDir);
$categories = $categoriesModule->getCategories();
$brands = $productsEnhanced->getBrands();

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $ajaxAction = trim($_GET['action'] ?? $_POST['action'] ?? '');

    if ($ajaxAction === 'search') {
        try {
            if (!hasPermission('products.view') && !hasPermission('products.edit')) {
                throw new Exception('You do not have permission to view products');
            }

            $searchTerm = trim($_GET['search'] ?? '');
            $categoryId = (int)($_GET['category_id'] ?? 0);
            $searchFilters = ['search' => $searchTerm];
            if ($categoryId > 0) {
                $searchFilters['category_id'] = $categoryId;
            }

            $results = $productsEnhanced->getProducts($searchFilters, 18, 0);
            foreach ($results as &$p) {
                $img = $db->fetchOne(
                    "SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC LIMIT 1",
                    [$p['id']]
                );
                $p['image'] = $img['image_path'] ?? null;
                $p['selling_price'] = getProductPriceValue($p);
                $p['is_taxable'] = isset($p['is_taxable']) ? (int)$p['is_taxable'] : 1;
                $p['tax_rate'] = isset($p['tax_rate']) ? (float)$p['tax_rate'] : 0.0;
                $p['category_name'] = $p['category_name'] ?? '';
                $p['brand'] = $p['brand'] ?? '';
            }
            unset($p);

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'products' => $results]);
            exit;
        } catch (Exception $e) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit;
        }
    }
}

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page']);
$pageStart = $totalProducts > 0 ? (($pagination['current_page'] - 1) * $pagination['items_per_page']) + 1 : 0;
$pageEnd = $totalProducts > 0 ? min($pagination['current_page'] * $pagination['items_per_page'], $pagination['total_items']) : 0;

include 'templates/header.php';
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    .products-dashboard{--card:#fff;--border:#e2e8f0;--muted:#64748b;--text:#0f172a;--accent:#4f46e5;--accent-dark:#4338ca;--shadow:0 10px 24px rgba(15,23,42,.06);font-family:'Inter',system-ui,sans-serif;color:var(--text)}
    .products-dashboard .shell{max-width:1480px;margin:0 auto;padding:8px 0 24px}
    .products-dashboard .saas-card{background:var(--card);border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow)}
    .products-dashboard .title-badge,.products-dashboard .stock-pill,.products-dashboard .pagination-pill{display:inline-flex;align-items:center;border-radius:999px;padding:.35rem .7rem;font-size:.78rem;font-weight:600}
    .products-dashboard .title-badge{background:#e0e7ff;color:#3730a3}
    .products-dashboard .products-title{font-size:1.8rem;font-weight:700;letter-spacing:-.02em}
    .products-dashboard .products-subtitle{color:var(--muted)}
    .products-dashboard .btn-primary-saas{background:var(--accent);border-color:var(--accent);color:#fff;border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .products-dashboard .btn-primary-saas:hover{background:var(--accent-dark);border-color:var(--accent-dark);color:#fff}
    .products-dashboard .btn-outline-saas{border:1px solid #cbd5e1;background:#fff;color:var(--text);border-radius:10px;padding:.72rem 1rem;font-weight:600}
    .products-dashboard .filter-grid{display:grid;grid-template-columns:minmax(240px,1.3fr) repeat(3,minmax(150px,.8fr)) minmax(180px,.9fr) minmax(160px,.85fr) auto auto;gap:12px;align-items:center}
    .products-dashboard .search-wrap{position:relative}
    .products-dashboard .search-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#94a3b8}
    .products-dashboard .search-wrap input{padding-left:40px}
    .products-dashboard .price-group{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .products-dashboard .form-control,.products-dashboard .form-select{border-radius:10px;border-color:#dbe1ea;min-height:44px}
    .products-dashboard .form-control:focus,.products-dashboard .form-select:focus,.products-dashboard .form-check-input:focus{border-color:#a5b4fc;box-shadow:0 0 0 .2rem rgba(79,70,229,.12)}
    .products-dashboard .switch-wrap{display:flex;align-items:center;gap:.6rem;white-space:nowrap}
    .products-dashboard .toolbar-meta{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
    .products-dashboard .table-shell{overflow-x:auto}
    .products-dashboard .table-modern{margin:0;min-width:980px}
    .products-dashboard .table-modern thead th{font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:#64748b;border-bottom:1px solid #e2e8f0;padding:1rem .85rem;background:#fff}
    .products-dashboard .table-modern tbody td{padding:1rem .85rem;border-bottom:1px solid #eef2f7;vertical-align:middle}
    .products-dashboard .table-modern tbody tr:hover{background:#f8fafc}
    .products-dashboard .product-name{font-weight:600;color:#0f172a;text-decoration:none}
    .products-dashboard .product-name:hover{color:#4338ca}
    .products-dashboard .product-meta{font-size:.8rem;color:#64748b}
    .products-dashboard .stock-pill.in{background:#dcfce7;color:#166534}
    .products-dashboard .stock-pill.low{background:#fef3c7;color:#92400e}
    .products-dashboard .stock-pill.out{background:#fee2e2;color:#991b1b}
    .products-dashboard .action-cell{text-align:center;white-space:nowrap}
    .products-dashboard .icon-btn{width:36px;height:36px;border-radius:10px;border:1px solid #dbe1ea;background:#fff;color:#475569;display:inline-flex;align-items:center;justify-content:center;transition:.15s ease}
    .products-dashboard .icon-btn:hover{border-color:#a5b4fc;color:#4338ca;background:#eef2ff}
    .products-dashboard .pagination-wrap{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
    .products-dashboard .pagination-modern{display:flex;align-items:center;gap:8px}
    .products-dashboard .pagination-modern a,.products-dashboard .pagination-modern span{min-width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;padding:0 .8rem;border:1px solid #dbe1ea;background:#fff;color:#334155;text-decoration:none;font-weight:600}
    .products-dashboard .pagination-modern a:hover{border-color:#a5b4fc;color:#4338ca;background:#eef2ff}
    .products-dashboard .pagination-modern .active{background:#4f46e5;border-color:#4f46e5;color:#fff}
    .products-dashboard .pagination-modern .disabled{opacity:.45;pointer-events:none}
    .products-dashboard .empty-state{padding:48px 24px;text-align:center}
    .products-dashboard .empty-art{width:84px;height:84px;border-radius:24px;background:linear-gradient(135deg,#e0e7ff,#eef2ff);display:inline-flex;align-items:center;justify-content:center;color:#4f46e5;font-size:2rem;margin-bottom:16px}
    .products-dashboard .skeleton-grid{display:grid;gap:12px}
    .products-dashboard .skeleton-row{display:grid;grid-template-columns:2fr 1fr 1fr 1fr .8fr .8fr 1fr;gap:12px}
    .products-dashboard .skeleton-block{height:18px;border-radius:999px;background:linear-gradient(90deg,#eef2f7,#f8fafc,#eef2f7);background-size:200% 100%;animation:productsPulse 1.4s linear infinite}
    .products-dashboard .loading-shell{display:none}
    .products-dashboard.is-loading .loading-shell{display:block}
    .products-dashboard.is-loading .content-shell{display:none}
    @keyframes productsPulse{0%{background-position:200% 0}100%{background-position:-200% 0}}
    @media (max-width:1200px){.products-dashboard .filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:768px){.products-dashboard .filter-grid{grid-template-columns:1fr}.products-dashboard .toolbar-meta,.products-dashboard .pagination-wrap{justify-content:flex-start}}
</style>
<div class="products-dashboard is-loading" id="productsDashboard">
    <div class="shell">
        <div class="loading-shell saas-card p-4 mb-3">
            <div class="skeleton-grid">
                <div class="skeleton-row"><?php for ($i = 0; $i < 7; $i++): ?><div class="skeleton-block"></div><?php endfor; ?></div>
                <div class="skeleton-row"><?php for ($i = 0; $i < 7; $i++): ?><div class="skeleton-block"></div><?php endfor; ?></div>
                <div class="skeleton-row"><?php for ($i = 0; $i < 7; $i++): ?><div class="skeleton-block"></div><?php endfor; ?></div>
            </div>
        </div>
        <div class="content-shell">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <div class="products-title">Products</div>
                        <span class="title-badge"><?php echo number_format($totalProducts); ?> total</span>
                    </div>
                    <div class="products-subtitle">Filter, scan, and manage inventory with a clean dashboard workflow.</div>
                </div>
                <?php if (hasPermission('products.create')): ?>
                    <a href="<?php echo getBaseUrl(); ?>/product_form.php?action=add" class="btn btn-primary-saas"><i class="bi bi-plus-lg"></i> Add Product</a>
                <?php endif; ?>
            </div>

            <div class="saas-card p-3 p-lg-4 mb-3">
                <form method="GET" class="filter-grid" id="filterForm">
                    <div class="search-wrap">
                        <i class="bi bi-search"></i>
                        <input type="text" class="form-control" name="search" placeholder="Search by name or SKU" value="<?php echo escape($filters['search']); ?>">
                    </div>
                    <select class="form-select" name="category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?><option value="<?php echo $cat['id']; ?>" <?php echo $filters['category_id'] == $cat['id'] ? 'selected' : ''; ?>><?php echo escape($cat['name']); ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select" name="brand">
                        <option value="">All Brands</option>
                        <?php foreach ($brands as $brand): ?><option value="<?php echo escape($brand['brand']); ?>" <?php echo $filters['brand'] == $brand['brand'] ? 'selected' : ''; ?>><?php echo escape($brand['brand']); ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select" name="stock_status">
                        <option value="">All Stock</option>
                        <option value="in_stock" <?php echo $filters['stock_status'] === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                        <option value="low_stock" <?php echo $filters['stock_status'] === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out_of_stock" <?php echo $filters['stock_status'] === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                    <div class="price-group">
                        <input type="number" class="form-control" name="price_min" placeholder="Min Price" value="<?php echo escape($filters['price_min']); ?>" step="0.01">
                        <input type="number" class="form-control" name="price_max" placeholder="Max Price" value="<?php echo escape($filters['price_max']); ?>" step="0.01">
                    </div>
                    <label class="switch-wrap">
                        <input class="form-check-input mt-0" type="checkbox" name="low_stock" value="1" <?php echo $filters['low_stock'] ? 'checked' : ''; ?>>
                        <span>Show Low Stock Only</span>
                    </label>
                    <button type="submit" class="btn btn-primary-saas">Filter</button>
                    <a href="<?php echo getBaseUrl(); ?>/product_list.php" class="btn btn-outline-saas">Reset</a>
                </form>
                <div class="toolbar-meta mt-3 pt-3 border-top">
                    <div class="text-muted small">Showing <?php echo number_format($pageStart); ?>-<?php echo number_format($pageEnd); ?> of <?php echo number_format($totalProducts); ?> products</div>
                    <div class="pagination-wrap">
                        <div class="d-flex align-items-center gap-2">
                            <label for="perPageSelect" class="text-muted small mb-0">Per page</label>
                            <select id="perPageSelect" name="per_page" class="form-select form-select-sm" form="filterForm" onchange="document.getElementById('filterForm').submit()">
                                <option value="10" <?php echo $itemsPerPage == 10 ? 'selected' : ''; ?>>10 per page</option>
                                <option value="25" <?php echo $itemsPerPage == 25 ? 'selected' : ''; ?>>25 per page</option>
                                <option value="50" <?php echo $itemsPerPage == 50 ? 'selected' : ''; ?>>50 per page</option>
                                <option value="100" <?php echo $itemsPerPage == 100 ? 'selected' : ''; ?>>100 per page</option>
                            </select>
                        </div>
                        <div class="pagination-modern">
                            <?php $prevUrl = getBaseUrl() . '/product_list.php?' . http_build_query(array_merge($queryWithoutPage, ['page' => max(1, $pagination['current_page'] - 1)])); ?>
                            <?php $nextUrl = getBaseUrl() . '/product_list.php?' . http_build_query(array_merge($queryWithoutPage, ['page' => min(max(1, $pagination['total_pages']), $pagination['current_page'] + 1)])); ?>
                            <a href="<?php echo $prevUrl; ?>" class="<?php echo !$pagination['has_prev'] ? 'disabled' : ''; ?>" aria-label="Previous"><i class="bi bi-chevron-left"></i></a>
                            <span class="pagination-pill active"><?php echo number_format(max(1, $pagination['current_page'])); ?></span>
                            <span class="pagination-pill">/ <?php echo number_format(max(1, $pagination['total_pages'])); ?></span>
                            <a href="<?php echo $nextUrl; ?>" class="<?php echo !$pagination['has_next'] ? 'disabled' : ''; ?>" aria-label="Next"><i class="bi bi-chevron-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="saas-card overflow-hidden">
                <?php if (empty($products)): ?>
                    <div class="empty-state">
                        <div class="empty-art"><i class="bi bi-box-seam"></i></div>
                        <h3 class="h5 mb-2">No products match these filters</h3>
                        <p class="text-muted mb-3">Try widening the search, adjusting the price range, or clearing the stock filters.</p>
                        <a href="<?php echo getBaseUrl(); ?>/product_list.php" class="btn btn-outline-saas">Clear Filters</a>
                    </div>
                <?php else: ?>
                    <div class="table-shell">
                        <table class="table table-modern align-middle">
                            <thead>
                                <tr>
                                    <th>NAME</th>
                                    <th>SKU</th>
                                    <th>CATEGORY</th>
                                    <th>BRAND</th>
                                    <th class="text-end">STOCK</th>
                                    <th class="text-end">PRICE</th>
                                    <th class="text-center">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <?php
                                    $stockQty = (int)($product['stock_quantity'] ?? 0);
                                    $stockClass = $stockQty <= 0 ? 'out' : (($stockQty <= 5 || isProductLowStock($product)) ? 'low' : 'in');
                                    $stockLabel = $stockQty <= 0 ? 'Out of stock' : ($stockClass === 'low' ? 'Low stock' : 'In stock');
                                    $price = '$' . number_format((float)getProductPriceValue($product), 2);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo getBaseUrl(); ?>/product_view.php?id=<?php echo $product['id']; ?>" class="product-name"><?php echo escape($product['name']); ?></a>
                                            <div class="product-meta"><?php echo $stockLabel; ?></div>
                                        </td>
                                        <td><?php echo escape($product['sku']); ?></td>
                                        <td><?php echo escape($product['category_name'] ?? 'Uncategorized'); ?></td>
                                        <td><?php echo escape($product['brand'] ?? '-'); ?></td>
                                        <td class="text-end"><span class="stock-pill <?php echo $stockClass; ?>"><?php echo number_format($stockQty); ?></span></td>
                                        <td class="text-end fw-semibold"><?php echo $price; ?></td>
                                        <td class="action-cell">
                                            <a href="<?php echo getBaseUrl(); ?>/product_view.php?id=<?php echo $product['id']; ?>" class="icon-btn" data-bs-toggle="tooltip" title="View Product"><i class="bi bi-eye"></i></a>
                                            <?php if (hasPermission('products.edit')): ?><a href="<?php echo getBaseUrl(); ?>/product_form.php?action=edit&id=<?php echo $product['id']; ?>" class="icon-btn" data-bs-toggle="tooltip" title="Edit Product"><i class="bi bi-pencil"></i></a><?php endif; ?>
                                            <?php if ($canCreatePo && isProductLowStock($product)): ?><a href="<?php echo getBaseUrl(); ?>/po_form.php?product_id=<?php echo $product['id']; ?>" class="icon-btn" data-bs-toggle="tooltip" title="Create Purchase Order"><i class="bi bi-cart-plus"></i></a><?php endif; ?>
                                            <?php if (isAdmin()): ?><form method="POST" class="d-inline" onsubmit="return confirm('Delete this product? This action cannot be undone for records without sales history.');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>"><input type="hidden" name="return_query" value="<?php echo escape(http_build_query($_GET)); ?>"><button type="submit" class="icon-btn" data-bs-toggle="tooltip" title="Delete Product"><i class="bi bi-trash"></i></button></form><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('productsDashboard')?.classList.remove('is-loading');
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });
});
</script>
<?php include 'templates/footer.php'; ?>

