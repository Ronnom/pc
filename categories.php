<?php
/**
 * Categories Management Page
 * Hierarchical category management with pre-populated PC categories
 */

require_once 'includes/init.php';
requireLogin();
requirePermission('products.view');

$pageTitle = 'Categories';
$categoriesModule = new CategoriesModule();
$db = getDB();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    
    try {
        if ($action === 'add' || $action === 'edit') {
            $data = [
                'name' => sanitize($_POST['name'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'parent_id' => !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            
            if (empty($data['name'])) {
                throw new Exception('Category name is required');
            }
            
            if ($action === 'add') {
                $categoryId = $categoriesModule->createCategory($data);
                setFlashMessage('success', 'Category created successfully.');
                redirect(getBaseUrl() . "/categories.php?view={$categoryId}");
            } else {
                $categoriesModule->updateCategory($id, $data);
                setFlashMessage('success', 'Category updated successfully.');
                redirect(getBaseUrl() . "/categories.php?view={$id}");
            }
        } elseif ($action === 'delete' && $id) {
            $categoriesModule->deleteCategory($id);
            setFlashMessage('success', 'Category deleted successfully.');
            redirect(getBaseUrl() . '/categories.php');
        } elseif ($action === 'populate') {
            // Pre-populate PC categories
            $this->populatePCCategories();
            setFlashMessage('success', 'PC categories populated successfully.');
            redirect(getBaseUrl() . '/categories.php');
        }
    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }
}

// Get data
$category = null;
if ($id) {
    $category = $categoriesModule->getCategory($id);
}

$categoryTree = $categoriesModule->getCategoryTree();
$allCategories = $categoriesModule->getCategories();

include 'templates/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">Categories</h1>
            <div>
                <?php if (hasPermission('products.create')): ?>
                <a href="<?php echo getBaseUrl(); ?>/categories.php?action=populate" class="btn btn-outline-info me-2" onclick="return confirm('This will add pre-populated PC component categories. Continue?');">
                    <i class="bi bi-download"></i> Populate PC Categories
                </a>
                <a href="<?php echo getBaseUrl(); ?>/categories.php?action=add" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Add Category
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Category Tree View -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Category Hierarchy</h5>
            </div>
            <div class="card-body">
                <?php if (empty($categoryTree)): ?>
                    <p class="text-muted text-center mb-0">No categories found. <a href="<?php echo getBaseUrl(); ?>/categories.php?action=populate">Populate PC Categories</a> to get started.</p>
                <?php else: ?>
                    <div class="category-tree">
                        <?php foreach ($categoryTree as $cat): ?>
                            <div class="category-item mb-3 p-3 border rounded">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1">
                                            <a href="<?php echo getBaseUrl(); ?>/categories.php?view=<?php echo $cat['id']; ?>" class="text-decoration-none">
                                                <?php echo escape($cat['name']); ?>
                                            </a>
                                            <?php echo $cat['is_active'] ? '' : '<span class="badge bg-secondary ms-2">Inactive</span>'; ?>
                                        </h6>
                                        <?php if (!empty($cat['description'])): ?>
                                            <small class="text-muted"><?php echo escape($cat['description']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <?php if (hasPermission('products.edit')): ?>
                                        <a href="<?php echo getBaseUrl(); ?>/categories.php?action=edit&id=<?php echo $cat['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($cat['children'])): ?>
                                    <div class="ms-4 mt-2">
                                        <?php foreach ($cat['children'] as $child): ?>
                                            <div class="category-item mb-2 p-2 border-start border-3">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <small>
                                                            <a href="<?php echo getBaseUrl(); ?>/categories.php?view=<?php echo $child['id']; ?>" class="text-decoration-none">
                                                                <?php echo escape($child['name']); ?>
                                                            </a>
                                                        </small>
                                                    </div>
                                                    <div>
                                                        <?php if (hasPermission('products.edit')): ?>
                                                        <a href="<?php echo getBaseUrl(); ?>/categories.php?action=edit&id=<?php echo $child['id']; ?>" class="btn btn-sm btn-outline-secondary btn-sm">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'add' || $action === 'edit'): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0"><?php echo $action === 'add' ? 'Add' : 'Edit'; ?> Category</h1>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="">
                    <?php echo csrfField(); ?>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required value="<?php echo escape($category['name'] ?? ''); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo escape($category['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="parent_id" class="form-label">Parent Category</label>
                            <select class="form-select" id="parent_id" name="parent_id">
                                <option value="">None (Top Level)</option>
                                <?php foreach ($allCategories as $cat): ?>
                                    <?php if ($action === 'edit' && $cat['id'] == $id) continue; ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo (isset($category['parent_id']) && $category['parent_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo escape($cat['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6 mb-3">
                            <label for="sort_order" class="form-label">Sort Order</label>
                            <input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo $category['sort_order'] ?? 0; ?>">
                        </div>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" <?php echo (!isset($category) || $category['is_active']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo getBaseUrl(); ?>/categories.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><?php echo $action === 'add' ? 'Create' : 'Update'; ?> Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'view' && $category): ?>
<div class="row mb-4">
    <div class="col-12">
        <h1 class="h3 mb-0">Category: <?php echo escape($category['name']); ?></h1>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">Category Information</div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th width="200">Name</th>
                        <td><?php echo escape($category['name']); ?></td>
                    </tr>
                    <tr>
                        <th>Description</th>
                        <td><?php echo escape($category['description'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <th>Parent Category</th>
                        <td><?php echo escape($category['parent_name'] ?? 'None (Top Level)'); ?></td>
                    </tr>
                    <tr>
                        <th>Status</th>
                        <td><?php echo $category['is_active'] ? getStatusBadge('active') : getStatusBadge('inactive'); ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php
// Function to populate PC categories (should be in module, but here for convenience)
if ($action === 'populate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pcCategories = [
        ['name' => 'Processors', 'slug' => 'processors', 'description' => 'CPU and Processors', 'children' => [
            ['name' => 'Intel', 'slug' => 'intel', 'description' => 'Intel Processors'],
            ['name' => 'AMD', 'slug' => 'amd', 'description' => 'AMD Processors']
        ]],
        ['name' => 'Graphics Cards', 'slug' => 'graphics-cards', 'description' => 'GPU and Graphics Cards', 'children' => [
            ['name' => 'NVIDIA', 'slug' => 'nvidia', 'description' => 'NVIDIA Graphics Cards'],
            ['name' => 'AMD Radeon', 'slug' => 'amd-radeon', 'description' => 'AMD Radeon Graphics Cards']
        ]],
        ['name' => 'Motherboards', 'slug' => 'motherboards', 'description' => 'Motherboards', 'children' => [
            ['name' => 'ATX', 'slug' => 'atx', 'description' => 'ATX Form Factor'],
            ['name' => 'Micro-ATX', 'slug' => 'micro-atx', 'description' => 'Micro-ATX Form Factor'],
            ['name' => 'Mini-ITX', 'slug' => 'mini-itx', 'description' => 'Mini-ITX Form Factor']
        ]],
        ['name' => 'Memory', 'slug' => 'memory', 'description' => 'RAM Modules', 'children' => [
            ['name' => 'DDR4', 'slug' => 'ddr4', 'description' => 'DDR4 RAM'],
            ['name' => 'DDR5', 'slug' => 'ddr5', 'description' => 'DDR5 RAM']
        ]],
        ['name' => 'Storage', 'slug' => 'storage', 'description' => 'SSD and HDD', 'children' => [
            ['name' => 'HDD', 'slug' => 'hdd', 'description' => 'Hard Disk Drives'],
            ['name' => 'SSD', 'slug' => 'ssd', 'description' => 'Solid State Drives'],
            ['name' => 'NVMe', 'slug' => 'nvme', 'description' => 'NVMe SSDs']
        ]],
        ['name' => 'Power Supplies', 'slug' => 'power-supplies', 'description' => 'PSU'],
        ['name' => 'Cases', 'slug' => 'cases', 'description' => 'PC Cases'],
        ['name' => 'Cooling', 'slug' => 'cooling', 'description' => 'CPU Coolers and Fans'],
        ['name' => 'Peripherals', 'slug' => 'peripherals', 'description' => 'Keyboards, Mice, Monitors']
    ];
    
    foreach ($pcCategories as $cat) {
        $parentId = $categoriesModule->createCategory([
            'name' => $cat['name'],
            'slug' => $cat['slug'],
            'description' => $cat['description']
        ]);
        
        if (isset($cat['children'])) {
            foreach ($cat['children'] as $child) {
                $categoriesModule->createCategory([
                    'name' => $child['name'],
                    'slug' => $child['slug'],
                    'description' => $child['description'],
                    'parent_id' => $parentId
                ]);
            }
        }
    }
}
?>

<?php include 'templates/footer.php'; ?>

