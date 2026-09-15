<?php
require_once __DIR__ . '/../includes/init.php';
requireRole(['admin','manager']);
// requireRole(['admin']);
redirectIfNotLoggedIn();
requirePageAccess('products');

$database = new Database();
$db = $database->getConnection();

$page_title = "Products - Inventory System";

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_product') {
        // Fix SKU generation - check for empty string, not just null
        $sku = trim($_POST['sku'] ?? '');
        if (empty($sku)) {
            $sku = 'SKU-' . strtoupper(uniqid());
        }
        
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'] ?? '';
        $unit_type = $_POST['unit_type'] ?? 'piece';
        $weight_per_piece = $_POST['weight_per_piece'] ?? 1.000;
        $min_weight = $_POST['min_weight'] ?? 0.100;
        $cost_price = $_POST['cost_price'] ?? 0;
        $sale_price = $_POST['sale_price'] ?? 0;
        $wholesale_price = $_POST['wholesale_price'] ?? 0;
        $stock_qty = $_POST['stock_qty'] ?? 0;
        $location = $_POST['location'] ?? '';
        $reorder_level = $_POST['reorder_level'] ?? 10;
        
        // If wholesale price is not set, use retail price
        if ($wholesale_price <= 0) {
            $wholesale_price = $sale_price;
        }
        
        // Debug: Check what values we're getting
        error_log("SKU: $sku, Name: $name, Unit Type: $unit_type, Cost: $cost_price, Retail: $sale_price, Wholesale: $wholesale_price");
        
        $query = "INSERT INTO products (sku, name, description, category, unit_type, weight_per_piece, min_weight, cost_price, sale_price, wholesale_price, last_purchase_cost, stock_qty, location, reorder_level) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$sku, $name, $description, $category, $unit_type, $weight_per_piece, $min_weight, $cost_price, $sale_price, $wholesale_price, $cost_price, $stock_qty, $location, $reorder_level])) {
            $success = "Product added successfully! SKU: $sku";
        } else {
            $error = "Failed to add product! Error: " . implode(", ", $stmt->errorInfo());
        }
    }
    
    if ($action === 'update_product') {
        $id = $_POST['id'] ?? 0;
        $name = $_POST['name'] ?? '';
        $description = $_POST['description'] ?? '';
        $category = $_POST['category'] ?? '';
        $unit_type = $_POST['unit_type'] ?? 'piece';
        $weight_per_piece = $_POST['weight_per_piece'] ?? 1.000;
        $min_weight = $_POST['min_weight'] ?? 0.100;
        $cost_price = $_POST['cost_price'] ?? 0;
        $sale_price = $_POST['sale_price'] ?? 0;
        $wholesale_price = $_POST['wholesale_price'] ?? 0;
        $stock_qty = $_POST['stock_qty'] ?? 0;
        $location = $_POST['location'] ?? '';
        $reorder_level = $_POST['reorder_level'] ?? 10;
        
        // If wholesale price is not set, use retail price
        if ($wholesale_price <= 0) {
            $wholesale_price = $sale_price;
        }
        
        $query = "UPDATE products SET name=?, description=?, category=?, unit_type=?, weight_per_piece=?, min_weight=?, cost_price=?, sale_price=?, wholesale_price=?, stock_qty=?, location=?, reorder_level=? WHERE id=?";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$name, $description, $category, $unit_type, $weight_per_piece, $min_weight, $cost_price, $sale_price, $wholesale_price, $stock_qty, $location, $reorder_level, $id])) {
            $success = "Product updated successfully!";
        } else {
            $error = "Failed to update product!";
        }
    }
    
    if ($action === 'delete_product') {
        $id = $_POST['id'] ?? 0;
        
        $query = "DELETE FROM products WHERE id=?";
        $stmt = $db->prepare($query);
        
        if ($stmt->execute([$id])) {
            $success = "Product deleted successfully!";
        } else {
            $error = "Failed to delete product!";
        }
    }
}

// Get all products
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

$query = "SELECT * FROM products WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR sku LIKE ? OR description LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if (!empty($category_filter)) {
    $query .= " AND category = ?";
    $params[] = $category_filter;
}

$query .= " ORDER BY name ASC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique categories for filter
$categories = $db->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
?>

<?php include '../includes/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="main-content" id="mainContent">
                <!-- Header -->
                <nav class="navbar navbar-light bg-white border-bottom">
                    <div class="container-fluid">
                        <button class="btn btn-light sidebar-toggle" id="sidebarToggleNav">
                            <i class="bi bi-list"></i>
                        </button>
                        <div class="d-flex">
                            <span class="navbar-text me-3">
                                Welcome, <?php echo $_SESSION['username']; ?>
                            </span>
                        </div>
                    </div>
                </nav>

                <!-- Products Content -->
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <h2>Products Management</h2>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
                                    <i class="bi bi-plus-circle me-1"></i>Add Product
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Alerts -->
                    <?php if (isset($success)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo $success; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <form method="GET" class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Search</label>
                                            <input type="text" name="search" class="form-control" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Category</label>
                                            <select name="category" class="form-select">
                                                <option value="">All Categories</option>
                                                <?php foreach ($categories as $cat): ?>
                                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($cat); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary me-2">Filter</button>
                                            <a href="products.php" class="btn btn-secondary">Clear</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

<!-- Products Table -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Unit Type</th>
                                <th>Cost Price</th>
                                <th>Retail Price</th>
                                <th>Wholesale Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($products) > 0): ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($product['sku']); ?></strong></td>
                                        <td>
                                            <div><strong><?php echo htmlspecialchars($product['name']); ?></strong></div>
                                            <small class="text-muted"><?php echo htmlspecialchars(substr($product['description'] ?? '', 0, 50)); ?>...</small>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['category'] ?? ''); ?></td>
                                        <td>
                                            <?php if ($product['unit_type'] == 'kg'): ?>
                                                <span class="badge bg-info">KG</span>
                                                <small class="text-muted d-block">
                                                    <?php echo $product['weight_per_piece']; ?>kg/pc
                                                </small>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Piece</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo '₱' . number_format($product['cost_price'], 2); ?></td>
                                        <td><strong><?php echo '₱' . number_format($product['sale_price'], 2); ?>
                                            <?php if ($product['sale_price'] < $product['cost_price']): ?>
                                                <span class="badge bg-danger ms-2">
                                                    Selling at Loss
                                                </span>
                                            <?php endif; ?>
                                    </strong></td>


                                        <td>
                                            <?php if ($product['wholesale_price'] > 0): ?>
                                                <strong class="text-success">₱<?php echo number_format($product['wholesale_price'], 2); ?></strong>

                                                
                                        <?php if ($product['wholesale_price'] > 0 && $product['wholesale_price'] < $product['cost_price']): ?>
                                            <span class="badge bg-warning ms-2">
                                                Wholesale Loss
                                            </span>
                                        <?php endif; ?>
                                            <?php else: ?>
                                                <small class="text-muted">Same as retail</small>
                                            <?php endif; ?>
                                        </td>


                                        <td>
                                            <span class="badge bg-<?php echo $product['stock_qty'] > $product['reorder_level'] ? 'success' : ($product['stock_qty'] > 0 ? 'warning' : 'danger'); ?>">
                                                <?php echo $product['stock_qty']; ?>
                                                <?php echo $product['unit_type'] == 'kg' ? ' kg' : ' pcs'; ?>
                                            </span>
                                        </td>
                                                            <td>
                                                                <?php if ($product['stock_qty'] == 0): ?>
                                                                    <span class="badge bg-danger">Out of Stock</span>
                                                                <?php elseif ($product['stock_qty'] <= $product['reorder_level']): ?>
                                                                    <span class="badge bg-warning">Low Stock</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-success">In Stock</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="table-actions">
<button class="btn btn-sm btn-outline-primary edit-product" 
        data-bs-toggle="modal" 
        data-bs-target="#editProductModal"
        data-id="<?php echo $product['id']; ?>"
        data-name="<?php echo htmlspecialchars($product['name']); ?>"
        data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
        data-category="<?php echo htmlspecialchars($product['category'] ?? ''); ?>"
        data-unit-type="<?php echo htmlspecialchars($product['unit_type'] ?? 'piece'); ?>"
        data-weight="<?php echo $product['weight_per_piece'] ?? '1.000'; ?>"
        data-min-weight="<?php echo $product['min_weight'] ?? '0.100'; ?>"
        data-wholesale="<?php echo $product['wholesale_price'] ?? '0'; ?>"
        data-cost="<?php echo $product['cost_price']; ?>"
        data-price="<?php echo $product['sale_price']; ?>"
        data-stock="<?php echo $product['stock_qty']; ?>"
        data-location="<?php echo htmlspecialchars($product['location'] ?? ''); ?>"
        data-reorder="<?php echo $product['reorder_level']; ?>">
    <i class="bi bi-pencil"></i>
</button>
                                                                <button class="btn btn-sm btn-outline-danger delete-product" 
                                                                        data-bs-toggle="modal" 
                                                                        data-bs-target="#deleteProductModal"
                                                                        data-id="<?php echo $product['id']; ?>"
                                                                        data-name="<?php echo htmlspecialchars($product['name']); ?>">
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="8" class="text-center text-muted py-4">
                                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                                            No products found. <a href="#" data-bs-toggle="modal" data-bs-target="#addProductModal">Add your first product</a>.
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Product Modal -->
<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">SKU <small class="text-muted">(Auto-generated if empty)</small></label>
                                <input type="text" name="sku" class="form-control" placeholder="Leave empty for auto-generation">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Product Name *</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <input type="text" name="category" class="form-control" placeholder="e.g., Electronics, Clothing">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Unit Type *</label>
                                <select name="unit_type" class="form-select" required id="unit_type_add">
                                    <option value="piece">Piece/Unit (Sold by piece)</option>
                                    <option value="kg">Kilogram (Sold by weight)</option>
                                </select>
                            </div>
                            <div class="mb-3 kg-fields" style="display: none;">
                                <label class="form-label">Weight per Piece (kg)</label>
                                <input type="number" name="weight_per_piece" class="form-control" step="0.001" min="0.001" value="1.000" placeholder="e.g., 0.500 for 500g">
                                <small class="text-muted">Weight of one piece in kilograms</small>
                            </div>
                            <div class="mb-3 kg-fields" style="display: none;">
                                <label class="form-label">Minimum Weight (kg)</label>
                                <input type="number" name="min_weight" class="form-control" step="0.001" min="0.001" value="0.100" placeholder="e.g., 0.100 for 100g minimum">
                                <small class="text-muted">Minimum weight that can be sold</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Cost Price (Purchase) *</label>
                                <input type="number" name="cost_price" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Retail Price *</label>
                                <input type="number" name="sale_price" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Wholesale Price</label>
                                <input type="number" name="wholesale_price" class="form-control" step="0.01" min="0">
                                <small class="text-muted">Leave empty if same as retail price</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Stock Quantity *</label>
                                <input type="number" name="stock_qty" class="form-control" min="0" required id="stock_qty_add">
                                <small class="text-muted" id="stock_unit_add">pieces</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" class="form-control" value="10" min="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control" placeholder="e.g., Warehouse A, Shelf B2">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Product description..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Product Modal -->
<div class="modal fade" id="editProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_product">
                <input type="hidden" name="id" id="edit_product_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Product Name *</label>
                                <input type="text" name="name" id="edit_product_name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Category</label>
                                <input type="text" name="category" id="edit_product_category" class="form-control">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Unit Type *</label>
                                <select name="unit_type" class="form-select" required id="edit_product_unit_type">
                                    <option value="piece">Piece/Unit (Sold by piece)</option>
                                    <option value="kg">Kilogram (Sold by weight)</option>
                                </select>
                            </div>
                            <div class="mb-3 kg-fields-edit" style="display: none;">
                                <label class="form-label">Weight per Piece (kg)</label>
                                <input type="number" name="weight_per_piece" id="edit_product_weight" class="form-control" step="0.001" min="0.001" value="1.000">
                            </div>
                            <div class="mb-3 kg-fields-edit" style="display: none;">
                                <label class="form-label">Minimum Weight (kg)</label>
                                <input type="number" name="min_weight" id="edit_product_min_weight" class="form-control" step="0.001" min="0.001" value="0.100">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Cost Price (Purchase) *</label>
                                <input type="number" name="cost_price" id="edit_product_cost" class="form-control" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Retail Price *</label>
                                <input type="number" name="sale_price" id="edit_product_price" class="form-control" step="0.01" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Wholesale Price</label>
                                <input type="number" name="wholesale_price" id="edit_product_wholesale" class="form-control" step="0.01" min="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Stock Quantity *</label>
                                <input type="number" name="stock_qty" id="edit_product_stock" class="form-control" min="0" required>
                                <small class="text-muted" id="edit_stock_unit">pieces</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Reorder Level</label>
                                <input type="number" name="reorder_level" id="edit_product_reorder" class="form-control" min="0">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" id="edit_product_location" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="edit_product_description" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Delete Product Modal -->
    <div class="modal fade" id="deleteProductModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="id" id="delete_product_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete <strong id="delete_product_name"></strong>?</p>
                        <p class="text-danger">This action cannot be undone and will remove all associated sales and purchase records.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Product</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Local Bootstrap JS -->
    <script src="../assets/js/bootstrap.bundle.min.js"></script>

    <script>
    // Sidebar Toggle
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggleNav = document.getElementById('sidebarToggleNav');
        const sidebarToggleInner = document.getElementById('sidebarToggleInner');
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        // Debug modal functionality
        console.log('DOM loaded');
        const addProductModal = document.getElementById('addProductModal');
        const addProductBtn = document.querySelector('[data-bs-target="#addProductModal"]');
        
        
        // Function to toggle sidebar
        function toggleSidebar() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
            updateIcons();
        }
        
        // Function to close sidebar (for mobile)
        function closeSidebar() {
            if (window.innerWidth <= 768 && !sidebar.classList.contains('collapsed')) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
                updateIcons();
            }
        }
        
        // Function to update all toggle icons
        function updateIcons() {
            const icons = document.querySelectorAll('.sidebar-toggle i');
            icons.forEach(icon => {
                if (sidebar.classList.contains('collapsed')) {
                    icon.classList.remove('bi-list');
                    icon.classList.add('bi-chevron-right');
                } else {
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-list');
                }
            });
        }
        
        // Navbar hamburger button click
        if (sidebarToggleNav && sidebar && mainContent) {
            sidebarToggleNav.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        // Sidebar inner hamburger button click
        if (sidebarToggleInner && sidebar && mainContent) {
            sidebarToggleInner.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleSidebar();
            });
        }
        
        // Close sidebar when clicking outside on mobile
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                closeSidebar();
            });
        }

        // Debug modal functionality
        if (addProductModal) {
            console.log('Add Product Modal found');
            addProductModal.addEventListener('show.bs.modal', function() {
                console.log('Add Product Modal is opening');
            });
        } else {
            console.log('Add Product Modal NOT found');
        }
        
        if (addProductBtn) {
            console.log('Add Product Button found');
            addProductBtn.addEventListener('click', function() {
                console.log('Add Product Button clicked');
            });
        } else {
            console.log('Add Product Button NOT found');
        }
        
        // Check if Bootstrap is loaded
        if (typeof bootstrap !== 'undefined') {
            console.log('Bootstrap JS is loaded');
        } else {
            console.log('Bootstrap JS is NOT loaded');
        }
        
        // Close sidebar when clicking on main content on mobile
        mainContent.addEventListener('click', function() {
            closeSidebar();
        });
        
        // Don't close when clicking inside sidebar
        sidebar.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Auto-collapse on mobile
        function handleResize() {
            if (window.innerWidth <= 768) {
                sidebar.classList.add('collapsed');
                mainContent.classList.add('expanded');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent.classList.remove('expanded');
            }
            updateIcons();
        }
        
        // Initial check
        handleResize();
        
        // Listen for resize events
        window.addEventListener('resize', handleResize);
        
        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSidebar();
            }
        });

        // Edit product modal data population
        document.querySelectorAll('.edit-product').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const description = this.getAttribute('data-description');
                const category = this.getAttribute('data-category');
                const cost = this.getAttribute('data-cost');
                const price = this.getAttribute('data-price');
                const stock = this.getAttribute('data-stock');
                const location = this.getAttribute('data-location');
                const reorder = this.getAttribute('data-reorder');
                
                document.getElementById('edit_product_id').value = id;
                document.getElementById('edit_product_name').value = name;
                document.getElementById('edit_product_description').value = description;
                document.getElementById('edit_product_category').value = category;
                document.getElementById('edit_product_cost').value = cost;
                document.getElementById('edit_product_price').value = price;
                document.getElementById('edit_product_stock').value = stock;
                document.getElementById('edit_product_location').value = location;
                document.getElementById('edit_product_reorder').value = reorder;
            });
        });

        // Delete product modal data population
        document.querySelectorAll('.delete-product').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                
                document.getElementById('delete_product_id').value = id;
                document.getElementById('delete_product_name').textContent = name;
            });
        });
    });

    // Unit Type Toggle Functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add Product Modal Toggle
    const unitTypeAdd = document.getElementById('unit_type_add');
    const kgFieldsAdd = document.querySelectorAll('.kg-fields');
    const stockQtyAdd = document.getElementById('stock_qty_add');
    const stockUnitAdd = document.getElementById('stock_unit_add');
    
    if (unitTypeAdd) {
        unitTypeAdd.addEventListener('change', function() {
            if (this.value === 'kg') {
                kgFieldsAdd.forEach(field => field.style.display = 'block');
                stockUnitAdd.textContent = 'kilograms';
                stockQtyAdd.step = '0.001';
                stockQtyAdd.min = '0';
            } else {
                kgFieldsAdd.forEach(field => field.style.display = 'none');
                stockUnitAdd.textContent = 'pieces';
                stockQtyAdd.step = '1';
                stockQtyAdd.min = '0';
            }
        });
        
        // Trigger change on load
        unitTypeAdd.dispatchEvent(new Event('change'));
    }
    
    // Edit Product Modal Toggle
    const editProductButtons = document.querySelectorAll('.edit-product');
    editProductButtons.forEach(button => {
        button.addEventListener('click', function() {
            const unitType = this.getAttribute('data-unit-type') || 'piece';
            const weight = this.getAttribute('data-weight') || '1.000';
            const minWeight = this.getAttribute('data-min-weight') || '0.100';
            const wholesale = this.getAttribute('data-wholesale') || '0';
            
            // Set unit type
            document.getElementById('edit_product_unit_type').value = unitType;
            
            // Set KG fields
            document.getElementById('edit_product_weight').value = weight;
            document.getElementById('edit_product_min_weight').value = minWeight;
            document.getElementById('edit_product_wholesale').value = wholesale;
            
            // Toggle KG fields visibility
            const kgFieldsEdit = document.querySelectorAll('.kg-fields-edit');
            const editStockUnit = document.getElementById('edit_stock_unit');
            const editStockQty = document.getElementById('edit_product_stock');
            
            if (unitType === 'kg') {
                kgFieldsEdit.forEach(field => field.style.display = 'block');
                editStockUnit.textContent = 'kilograms';
                editStockQty.step = '0.001';
            } else {
                kgFieldsEdit.forEach(field => field.style.display = 'none');
                editStockUnit.textContent = 'pieces';
                editStockQty.step = '1';
            }
        });
    });
    
    // Edit modal unit type change listener
    const editUnitType = document.getElementById('edit_product_unit_type');
    if (editUnitType) {
        editUnitType.addEventListener('change', function() {
            const kgFieldsEdit = document.querySelectorAll('.kg-fields-edit');
            const editStockUnit = document.getElementById('edit_stock_unit');
            const editStockQty = document.getElementById('edit_product_stock');
            
            if (this.value === 'kg') {
                kgFieldsEdit.forEach(field => field.style.display = 'block');
                editStockUnit.textContent = 'kilograms';
                editStockQty.step = '0.001';
            } else {
                kgFieldsEdit.forEach(field => field.style.display = 'none');
                editStockUnit.textContent = 'pieces';
                editStockQty.step = '1';
            }
        });
    }
});
    </script>
</body>
</html>