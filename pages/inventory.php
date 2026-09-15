<?php
require_once __DIR__ . '/../includes/init.php';
redirectIfNotLoggedIn();
requirePageAccess('inventory');

$database = new Database();
$db = $database->getConnection();

$page_title = "Inventory - Inventory System";

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
if ($action === 'add_purchase') {
    $product_id = $_POST['product_id'] ?? 0;
    $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
    $qty = (float)($_POST['qty'] ?? 0);
    $cost = (float)($_POST['cost'] ?? 0);
    $supplier = $_POST['supplier'] ?? '';
    $notes = $_POST['notes'] ?? '';

    try {
        $db->beginTransaction();

        // Get current product cost + stock
        $cur_stmt = $db->prepare("SELECT stock_qty, cost_price FROM products WHERE id = ? FOR UPDATE");
        $cur_stmt->execute([$product_id]);
        $current = $cur_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            throw new Exception("Product not found!");
        }

        $current_stock = (float)($current['stock_qty'] ?? 0);
        $current_cost  = (float)($current['cost_price'] ?? 0);

        $den = ($current_stock + $qty);
        $weighted_cost = ($den > 0)
            ? ((($current_stock * $current_cost) + ($qty * $cost)) / $den)
            : $cost;

        // Insert purchase record
        $query = "INSERT INTO purchases (product_id, purchase_date, qty, cost, supplier, notes) 
                  VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$product_id, $purchase_date, $qty, $cost, $supplier, $notes]);

        // Update product stock + cost (ONLY ONCE)
        $update_query = "UPDATE products 
                         SET stock_qty = stock_qty + ?,
                             cost_price = ?,
                             last_purchase_cost = ?
                         WHERE id = ?";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->execute([$qty, $weighted_cost, $cost, $product_id]);

        $db->commit();
        $success = "Purchase recorded successfully! Stock + cost updated.";

    } catch (Exception $e) {
        $db->rollBack();
        $error = "Failed to record purchase: " . $e->getMessage();
    }
}
    
    if ($action === 'adjust_stock') {
        $product_id = $_POST['product_id'] ?? 0;
        $adjustment_type = $_POST['adjustment_type'] ?? '';
        $qty = $_POST['qty'] ?? 0;
        $reason = $_POST['reason'] ?? '';
        
        try {
            $db->beginTransaction();
            
            if ($adjustment_type === 'add') {
                $update_query = "UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?";
            } else {
                $update_query = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?";
            }
            
            $stmt = $db->prepare($update_query);
            $stmt->execute([$qty, $product_id]);
            
            // Log the adjustment (you might want to create an adjustments table)
            $log_query = "INSERT INTO purchases (product_id, purchase_date, qty, cost, supplier, notes) 
                          VALUES (?, NOW(), ?, 0, 'System', ?)";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->execute([$product_id, $adjustment_type === 'add' ? $qty : -$qty, "Stock adjustment: $reason"]);
            
            $db->commit();
            $success = "Stock adjusted successfully!";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to adjust stock: " . $e->getMessage();
        }
    }
}

// Get all products for dropdowns
$products = $db->query("SELECT id, name, sku, stock_qty, reorder_level FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get low stock items
$low_stock = $db->query("SELECT * FROM products WHERE stock_qty <= reorder_level ORDER BY stock_qty ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get recent purchases
$purchases = $db->query("
    SELECT p.*, pr.name as product_name, pr.sku 
    FROM purchases p 
    JOIN products pr ON p.product_id = pr.id 
    ORDER BY p.purchase_date DESC, p.created_at DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get inventory summary
$inventory_summary = $db->query("
    SELECT 
        COUNT(*) as total_products,
        COALESCE(SUM(stock_qty), 0) as total_items,
        COALESCE(SUM(stock_qty * cost_price), 0) as total_cost_value,
        COALESCE(SUM(stock_qty * sale_price), 0) as total_retail_value,
        COALESCE(SUM(CASE WHEN stock_qty <= reorder_level THEN 1 ELSE 0 END), 0) as low_stock_count
    FROM products
")->fetch(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="main-content" id="mainContent">
                <!-- Top Navbar -->
                <nav class="navbar navbar-expand navbar-light bg-white border-bottom px-4 py-2 sticky-top shadow-sm">
                    <div class="container-fluid p-0">
                        <button class="btn btn-outline-secondary d-md-none me-2" id="sidebarToggleNav">
                            <i class="bi bi-list"></i>
                        </button>
                        <span class="navbar-brand mb-0 h6 text-secondary fw-bold">INVENTORY</span>
                        <div class="ms-auto d-flex align-items-center gap-3">
                            <span class="badge bg-light text-dark border px-3 py-2">
                                <i class="bi bi-person-circle me-1"></i>Welcome, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'admin'); ?></strong>
                            </span>
                            <a href="../logout.php" class="btn btn-sm btn-outline-danger" title="Logout">
                                <i class="bi bi-power"></i>
                            </a>
                        </div>
                    </div>
                </nav>

                <!-- Inventory Content -->
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <h2>Inventory Management</h2>
                                <div>
                                    <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">
                                        <i class="bi bi-plus-circle me-1"></i>Add Purchase
                                    </button>
                                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#adjustStockModal">
                                        <i class="bi bi-sliders me-1"></i>Adjust Stock
                                    </button>
                                </div>
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

                    <!-- Inventory Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-2">
                            <div class="card metric-card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $inventory_summary['total_products']; ?></h3>
                                    <small>Total Products</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo number_format((float)($inventory_summary['total_items'] ?? 0)); ?></h3>
                                    <small>Total Items</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-info text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format((float)($inventory_summary['total_cost_value'] ?? 0), 2); ?></h3>
                                    <small>Cost Value</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format((float)($inventory_summary['total_retail_value'] ?? 0), 2); ?></h3>
                                    <small>Retail Value</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $inventory_summary['low_stock_count']; ?></h3>
                                    <small>Low Stock Items</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo count($purchases); ?></h3>
                                    <small>Recent Purchases</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Low Stock Alert -->
                        <div class="col-md-4">
                            <div class="card mb-4">
                                <div class="card-header bg-warning text-white">
                                    <h6 class="card-title mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock Alert</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (count($low_stock) > 0): ?>
                                        <?php foreach ($low_stock as $product): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                                    <br>
                                                    <small>Current: <?php echo $product['stock_qty']; ?> | Min: <?php echo $product['reorder_level']; ?></small>
                                                </div>
                                                <span class="badge bg-danger">Reorder</span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted text-center">No low stock items</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Quick Actions -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Quick Actions</h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">
                                            <i class="bi bi-cart-plus me-2"></i>Record Purchase
                                        </button>
                                        <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#adjustStockModal">
                                            <i class="bi bi-sliders me-2"></i>Adjust Stock
                                        </button>
                                        <a href="products.php" class="btn btn-outline-info">
                                            <i class="bi bi-box me-2"></i>Manage Products
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Purchases -->
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0">Recent Stock Movements</h6>
                                    <small class="text-muted">Last 50 records</small>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Product</th>
                                                    <th>Type</th>
                                                    <th>Qty</th>
                                                    <th>Cost</th>
                                                    <th>Supplier</th>
                                                    <th>Notes</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($purchases) > 0): ?>
                                                    <?php foreach ($purchases as $purchase): ?>
                                                        <tr>
                                                            <td><?php echo date('M j, Y', strtotime($purchase['purchase_date'])); ?></td>
                                                            <td>
                                                                <div><strong><?php echo htmlspecialchars($purchase['product_name']); ?></strong></div>
                                                                <small class="text-muted"><?php echo htmlspecialchars($purchase['sku']); ?></small>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php echo $purchase['qty'] > 0 ? 'success' : 'danger'; ?>">
                                                                    <?php echo $purchase['qty'] > 0 ? 'Purchase' : 'Adjustment'; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-<?php echo $purchase['qty'] > 0 ? 'success' : 'danger'; ?>">
                                                                    <?php echo $purchase['qty'] > 0 ? '+' . $purchase['qty'] : $purchase['qty']; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo '₱' . number_format($purchase['cost'], 2); ?></td>
                                                            <td><?php echo htmlspecialchars($purchase['supplier']); ?></td>
                                                            <td><small class="text-muted"><?php echo htmlspecialchars(substr($purchase['notes'], 0, 30)); ?>...</small></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="7" class="text-center text-muted py-4">
                                                            <i class="bi bi-inbox display-4 d-block mb-2"></i>
                                                            No purchase records found.
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

    <!-- Add Purchase Modal -->
    <div class="modal fade" id="addPurchaseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Record New Purchase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_purchase">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Product *</label>
                            <select name="product_id" class="form-select" required>
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?> (Stock: <?php echo $product['stock_qty']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Purchase Date *</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Quantity *</label>
                                    <input type="number" name="qty" class="form-control" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Cost per Unit *</label>
                                    <input type="number" name="cost" class="form-control" step="0.01" min="0" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Supplier</label>
                            <input type="text" name="supplier" class="form-control" placeholder="Supplier name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Purchase notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Record Purchase</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Adjust Stock Modal -->
    <div class="modal fade" id="adjustStockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Stock Level</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="adjust_stock">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Product *</label>
                            <select name="product_id" class="form-select" required id="adjustProductSelect">
                                <option value="">Select Product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" data-stock="<?php echo $product['stock_qty']; ?>">
                                        <?php echo htmlspecialchars($product['name']); ?> (Current: <?php echo $product['stock_qty']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adjustment Type *</label>
                            <select name="adjustment_type" class="form-select" required>
                                <option value="add">Add Stock</option>
                                <option value="remove">Remove Stock</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Quantity *</label>
                            <input type="number" name="qty" class="form-control" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Reason *</label>
                            <input type="text" name="reason" class="form-control" placeholder="Reason for adjustment" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Adjust Stock</button>
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

        // Show current stock when product is selected in adjust stock modal
        document.getElementById('adjustProductSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const currentStock = selectedOption.getAttribute('data-stock');
            if (currentStock) {
                // You can display the current stock somewhere if needed
                console.log('Current stock:', currentStock);
            }
        });
    });
    </script>
</body>
</html>