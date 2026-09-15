<?php
require_once __DIR__ . '/../includes/init.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$page_title = "Sales - Inventory System";

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_sale') {
        // Check if it's a single product sale or multiple products
        if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
            // Multiple products sale
            $product_ids = $_POST['product_id'] ?? [];
            $qtys = $_POST['qty'] ?? [];
            $prices = $_POST['price'] ?? [];
            $totals = $_POST['item_total'] ?? [];
            $sale_date = $_POST['sale_date'] ?? date('Y-m-d H:i:s');
            $customer_name = $_POST['customer_name'] ?? '';
            $customer_email = $_POST['customer_email'] ?? '';
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            
            try {
                // Start transaction
                $db->beginTransaction();
                
                $grand_total = 0;
                $sale_details = [];
                
                // Process each product
                foreach ($product_ids as $index => $product_id) {
                    if (empty($product_id) || empty($qtys[$index])) continue;
                    
                    $qty = intval($qtys[$index]);
                    $price = floatval($prices[$index]);
                    
                    // Get product details
                    $product_query = "SELECT name, sale_price, stock_qty FROM products WHERE id = ?";
                    $product_stmt = $db->prepare($product_query);
                    $product_stmt->execute([$product_id]);
                    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$product) {
                        throw new Exception("Product not found!");
                    }
                    
                    if ($product['stock_qty'] < $qty) {
                        throw new Exception("Insufficient stock for " . $product['name'] . "! Available: " . $product['stock_qty']);
                    }
                    
                    // Use database price for calculation
                    $actual_price = $product['sale_price'];
                    $item_total = $actual_price * $qty;
                    $grand_total += $item_total;
                    
                    // Store sale details for later insertion
                    $sale_details[] = [
                        'product_id' => $product_id,
                        'qty' => $qty,
                        'price' => $actual_price,
                        'total' => $item_total
                    ];
                    
                    // Update product stock
                    $update_query = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->execute([$qty, $product_id]);
                }
                
                if (count($sale_details) === 0) {
                    throw new Exception("No products selected!");
                }
                
                // Calculate change due
                $change_due = $amount_paid - $grand_total;
                
                if ($amount_paid < $grand_total) {
                    throw new Exception("Insufficient payment! Total: ₱" . number_format($grand_total, 2) . 
                                      ", Paid: ₱" . number_format($amount_paid, 2));
                }
                
                // Insert sales records
                $query = "INSERT INTO sales (product_id, sale_date, qty, price, total, customer_name, customer_email, amount_paid, change_due) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($query);
                
                foreach ($sale_details as $detail) {
                    $stmt->execute([
                        $detail['product_id'],
                        $sale_date,
                        $detail['qty'],
                        $detail['price'],
                        $detail['total'],
                        $customer_name,
                        $customer_email,
                        $amount_paid,
                        $change_due
                    ]);
                }
                
                $db->commit();
                $success = "Sale recorded successfully! " . 
                          "Total: ₱" . number_format($grand_total, 2) . 
                          " | Paid: ₱" . number_format($amount_paid, 2) . 
                          " | Change: ₱" . number_format($change_due, 2);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to record sale: " . $e->getMessage();
            }
            
        } else {
            // Single product sale (for backward compatibility)
            $product_id = $_POST['product_id'] ?? 0;
            $sale_date = $_POST['sale_date'] ?? date('Y-m-d H:i:s');
            $qty = $_POST['qty'] ?? 0;
            $customer_name = $_POST['customer_name'] ?? '';
            $customer_email = $_POST['customer_email'] ?? '';
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            
            try {
                // Start transaction
                $db->beginTransaction();
                
                // Get product details
                $product_query = "SELECT sale_price, stock_qty FROM products WHERE id = ?";
                $product_stmt = $db->prepare($product_query);
                $product_stmt->execute([$product_id]);
                $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception("Product not found!");
                }
                
                if ($product['stock_qty'] < $qty) {
                    throw new Exception("Insufficient stock! Available: " . $product['stock_qty']);
                }
                
                $price = $product['sale_price'];
                $total = $price * $qty;
                
                // Calculate change due
                $change_due = $amount_paid - $total;
                
                if ($amount_paid < $total) {
                    throw new Exception("Insufficient payment! Total: ₱" . number_format($total, 2) . 
                                      ", Paid: ₱" . number_format($amount_paid, 2));
                }
                
                // Add sale record
                $query = "INSERT INTO sales (product_id, sale_date, qty, price, total, customer_name, customer_email, amount_paid, change_due) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($query);
                $stmt->execute([$product_id, $sale_date, $qty, $price, $total, $customer_name, $customer_email, $amount_paid, $change_due]);
                
                // Update product stock
                $update_query = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$qty, $product_id]);
                
                $db->commit();
                $success = "Sale recorded successfully! Total: ₱" . number_format($total, 2) . 
                          " | Paid: ₱" . number_format($amount_paid, 2) . 
                          " | Change: ₱" . number_format($change_due, 2);
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to record sale: " . $e->getMessage();
            }
        }
    }
}

// Get all products for dropdowns
$products = $db->query("SELECT id, name, sku, stock_qty, sale_price FROM products WHERE stock_qty > 0 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get recent sales with payment info
$sales = $db->query("
    SELECT s.*, p.name as product_name, p.sku 
    FROM sales s 
    JOIN products p ON s.product_id = p.id 
    ORDER BY s.sale_date DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get sales summary (all-time instead of just today)
$sales_summary = $db->query("
    SELECT 
        COUNT(*) as total_sales,
        SUM(s.qty) as total_items_sold,
        SUM(s.total) as total_revenue,
        SUM(s.amount_paid) as total_paid,
        AVG(s.total) as avg_sale_value
    FROM sales s
")->fetch(PDO::FETCH_ASSOC);

// If no sales at all, set defaults
if (!$sales_summary['total_sales']) {
    $sales_summary = [
        'total_sales' => 0,
        'total_items_sold' => 0,
        'total_revenue' => 0,
        'total_paid' => 0,
        'avg_sale_value' => 0
    ];
}

// Get top selling products
$top_products = $db->query("
    SELECT p.name, p.sku, SUM(s.qty) as total_sold, SUM(s.total) as revenue
    FROM sales s 
    JOIN products p ON s.product_id = p.id 
    GROUP BY p.id, p.name, p.sku 
    ORDER BY total_sold DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
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

                <!-- Sales Content -->
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <h2>Sales Management</h2>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSaleModal">
                                    <i class="bi bi-cart-check me-1"></i>New Sale (Multiple Products)
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Add Sale Modal for Multiple Products -->
                    <div class="modal fade" id="addSaleModal" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Record New Sale (Multiple Products)</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST" id="addSaleForm">
                                    <input type="hidden" name="action" value="add_sale">
                                    <input type="hidden" name="sale_date" value="<?php echo date('Y-m-d H:i:s'); ?>">
                                    <div class="modal-body">
                                        <!-- Products Table -->
                                        <div class="mb-4">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6>Products</h6>
                                                <button type="button" class="btn btn-sm btn-outline-primary" id="addProductRow">
                                                    <i class="bi bi-plus-circle me-1"></i>Add Product
                                                </button>
                                            </div>
                                            <div class="table-responsive">
                                                <table class="table table-sm" id="productsTable">
                                                    <thead>
                                                        <tr>
                                                            <th width="40%">Product</th>
                                                            <th width="15%">Quantity</th>
                                                            <th width="15%">Unit Price</th>
                                                            <th width="15%">Subtotal</th>
                                                            <th width="15%">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody id="productRows">
                                                        <!-- Product rows will be added here dynamically -->
                                                        <tr class="product-row">
                                                            <td>
                                                                <select name="product_id[]" class="form-select product-select" required>
                                                                    <option value="">Select Product</option>
                                                                    <?php foreach ($products as $product): ?>
                                                                        <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['sale_price']; ?>" data-stock="<?php echo $product['stock_qty']; ?>">
                                                                            <?php echo htmlspecialchars($product['name']); ?> - ₱<?php echo number_format($product['sale_price'], 2); ?> (Stock: <?php echo $product['stock_qty']; ?>)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </td>
                                                            <td>
                                                                <input type="number" name="qty[]" class="form-control qty-input" min="1" value="1" required>
                                                            </td>
                                                            <td>
                                                                <input type="text" name="price[]" class="form-control price-input" readonly>
                                                            </td>
                                                            <td>
                                                                <input type="text" name="item_total[]" class="form-control subtotal-input" readonly>
                                                            </td>
                                                            <td>
                                                                <button type="button" class="btn btn-sm btn-outline-danger remove-row" disabled>
                                                                    <i class="bi bi-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- Customer Information -->
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Customer Name</label>
                                                    <input type="text" name="customer_name" class="form-control" placeholder="Optional">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Customer Email</label>
                                                    <input type="email" name="customer_email" class="form-control" placeholder="Optional">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Sale Date -->
                                        <div class="mb-3">
                                            <label class="form-label">Sale Date & Time</label>
                                            <input type="datetime-local" name="sale_date" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>">
                                        </div>

                                        <!-- Payment Summary -->
                                        <div class="card bg-light">
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Grand Total</label>
                                                            <input type="text" class="form-control bg-white" id="grandTotal" value="₱0.00" readonly>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Amount Paid *</label>
                                                            <input type="number" name="amount_paid" class="form-control" id="amountPaid" min="0" step="0.01" required value="0">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Change Due</label>
                                                            <input type="text" class="form-control bg-white" id="changeDue" value="₱0.00" readonly>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <div class="mb-3">
                                                            <label class="form-label fw-bold">Status</label>
                                                            <input type="text" class="form-control bg-white" id="paymentStatus" value="Waiting for payment" readonly>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary" id="submitSaleBtn">Record Sale</button>
                                    </div>
                                </form>
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

                    <!-- Sales Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card metric-card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $sales_summary['total_sales'] ?? 0; ?></h3>
                                    <small>All-Time Sales</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card metric-card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $sales_summary['total_items_sold'] ?? 0; ?></h3>
                                    <small>Items Sold</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card metric-card bg-info text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($sales_summary['total_revenue'] ?? 0, 2); ?></h3>
                                    <small>Total Revenue</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card metric-card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($sales_summary['total_paid'] ?? 0, 2); ?></h3>
                                    <small>Total Payments</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Quick Sale & Top Products -->
                        <div class="col-md-4">
                            <!-- Quick Sale Form (Single Product) -->
                            <div class="card mb-4">
                                <div class="card-header bg-success text-white">
                                    <h6 class="card-title mb-0"><i class="bi bi-lightning me-2"></i>Quick Sale (Single Product)</h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="quickSaleForm">
                                        <input type="hidden" name="action" value="add_sale">
                                        <input type="hidden" name="sale_date" value="<?php echo date('Y-m-d H:i:s'); ?>">
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Product *</label>
                                            <select name="product_id" class="form-select" required id="quickProductSelect">
                                                <option value="">Select Product</option>
                                                <?php foreach ($products as $product): ?>
                                                    <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['sale_price']; ?>" data-stock="<?php echo $product['stock_qty']; ?>">
                                                        <?php echo htmlspecialchars($product['name']); ?> - ₱<?php echo number_format($product['sale_price'], 2); ?> (Stock: <?php echo $product['stock_qty']; ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Quantity *</label>
                                            <input type="number" name="qty" class="form-control" min="1" value="1" required id="quickQty">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Unit Price</label>
                                            <input type="text" class="form-control" id="quickPrice" readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Total</label>
                                            <input type="text" class="form-control" id="quickTotal" readonly>
                                            <small class="text-muted" id="quickStockWarning" style="display: none;"></small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Customer Name</label>
                                            <input type="text" name="customer_name" class="form-control" placeholder="Optional">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Amount Paid *</label>
                                            <input type="number" name="amount_paid" class="form-control" id="quickAmountPaid" min="0" step="0.01" required value="0">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Change Due</label>
                                            <input type="text" class="form-control" id="quickChangeDue" readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Status</label>
                                            <input type="text" class="form-control" id="quickPaymentStatus" value="Waiting for payment" readonly>
                                        </div>
                                        <button type="submit" class="btn btn-success w-100">
                                            <i class="bi bi-cart-check me-2"></i>Complete Sale
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Top Selling Products -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Top Selling Products</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (count($top_products) > 0): ?>
                                        <?php foreach ($top_products as $product): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border rounded">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                                    <br>
                                                    <small>Sold: <?php echo $product['total_sold']; ?> units</small>
                                                </div>
                                                <span class="badge bg-success">₱<?php echo number_format($product['revenue'], 2); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted text-center">No sales data</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Sales -->
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0">Recent Sales</h6>
                                    <small class="text-muted">Last 50 sales</small>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Date & Time</th>
                                                    <th>Product</th>
                                                    <th>Qty</th>
                                                    <th>Price</th>
                                                    <th>Total</th>
                                                    <th>Paid</th>
                                                    <th>Change</th>
                                                    <th>Customer</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($sales) > 0): ?>
                                                    <?php foreach ($sales as $sale): ?>
                                                        <tr>
                                                            <td>
                                                                <small><?php echo date('M j, Y', strtotime($sale['sale_date'])); ?></small>
                                                                <br>
                                                                <small class="text-muted"><?php echo date('H:i', strtotime($sale['sale_date'])); ?></small>
                                                            </td>
                                                            <td>
                                                                <div><strong><?php echo htmlspecialchars($sale['product_name']); ?></strong></div>
                                                                <small class="text-muted"><?php echo htmlspecialchars($sale['sku']); ?></small>
                                                            </td>
                                                            <td><span class="badge bg-primary"><?php echo $sale['qty']; ?></span></td>
                                                            <td>₱<?php echo number_format($sale['price'], 2); ?></td>
                                                            <td><strong>₱<?php echo number_format($sale['total'], 2); ?></strong></td>
                                                            <td><span class="badge bg-success">₱<?php echo number_format($sale['amount_paid'] ?? 0, 2); ?></span></td>
                                                            <td><span class="badge bg-info">₱<?php echo number_format($sale['change_due'] ?? 0, 2); ?></span></td>
                                                            <td><small><?php echo htmlspecialchars($sale['customer_name'] ?: 'Walk-in'); ?></small></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="8" class="text-center text-muted py-4">
                                                            <i class="bi bi-cart-x display-4 d-block mb-2"></i>
                                                            No sales records found.
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

        // ============================================
        // MULTIPLE PRODUCTS SALE FUNCTIONS
        // ============================================
        
        // Function to add new product row
        function addProductRow() {
            const productRows = document.getElementById('productRows');
            const newRow = document.createElement('tr');
            newRow.className = 'product-row';
            newRow.innerHTML = `
                <td>
                    <select name="product_id[]" class="form-select product-select" required>
                        <option value="">Select Product</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['id']; ?>" data-price="<?php echo $product['sale_price']; ?>" data-stock="<?php echo $product['stock_qty']; ?>">
                                <?php echo htmlspecialchars($product['name']); ?> - ₱<?php echo number_format($product['sale_price'], 2); ?> (Stock: <?php echo $product['stock_qty']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input type="number" name="qty[]" class="form-control qty-input" min="1" value="1" required>
                </td>
                <td>
                    <input type="text" name="price[]" class="form-control price-input" readonly>
                </td>
                <td>
                    <input type="text" name="item_total[]" class="form-control subtotal-input" readonly>
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
            
            productRows.appendChild(newRow);
            
            // Enable remove buttons for all rows except first
            updateRemoveButtons();
            
            // Attach event listeners to new row
            attachRowEvents(newRow);
        }
        
        // Function to update remove buttons
        function updateRemoveButtons() {
            const rows = document.querySelectorAll('.product-row');
            rows.forEach((row, index) => {
                const removeBtn = row.querySelector('.remove-row');
                if (rows.length > 1) {
                    removeBtn.disabled = false;
                } else {
                    removeBtn.disabled = true;
                }
            });
        }
        
        // Function to attach event listeners to a row
        function attachRowEvents(row) {
            const productSelect = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.qty-input');
            const priceInput = row.querySelector('.price-input');
            const subtotalInput = row.querySelector('.subtotal-input');
            const removeBtn = row.querySelector('.remove-row');
            
            // Product select change
            productSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const price = selectedOption.getAttribute('data-price');
                const stock = selectedOption.getAttribute('data-stock');
                
                if (price) {
                    priceInput.value = '₱' + parseFloat(price).toFixed(2);
                    calculateRowTotal(row);
                } else {
                    priceInput.value = '';
                    subtotalInput.value = '';
                }
                
                // Check stock
                checkStock(this, qtyInput.value, stock);
            });
            
            // Quantity input change
            qtyInput.addEventListener('input', function() {
                const productSelect = row.querySelector('.product-select');
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const stock = selectedOption.getAttribute('data-stock');
                
                calculateRowTotal(row);
                checkStock(productSelect, this.value, stock);
            });
            
            // Remove row button
            removeBtn.addEventListener('click', function() {
                if (document.querySelectorAll('.product-row').length > 1) {
                    row.remove();
                    updateRemoveButtons();
                    calculateGrandTotal();
                }
            });
        }
        
        // Function to calculate row total
        function calculateRowTotal(row) {
            const productSelect = row.querySelector('.product-select');
            const qtyInput = row.querySelector('.qty-input');
            const priceInput = row.querySelector('.price-input');
            const subtotalInput = row.querySelector('.subtotal-input');
            
            const selectedOption = productSelect.options[productSelect.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            const qty = qtyInput.value;
            
            if (price && qty) {
                const subtotal = (parseFloat(price) * parseInt(qty)).toFixed(2);
                subtotalInput.value = '₱' + subtotal;
                calculateGrandTotal();
            }
        }
        
        // Function to check stock
        function checkStock(selectElement, qty, stock) {
            const row = selectElement.closest('.product-row');
            const qtyInput = row.querySelector('.qty-input');
            
            if (stock && parseInt(qty) > parseInt(stock)) {
                qtyInput.setCustomValidity(`Only ${stock} units available`);
                qtyInput.reportValidity();
            } else {
                qtyInput.setCustomValidity('');
            }
        }
        
        // Function to calculate grand total
        function calculateGrandTotal() {
            let grandTotal = 0;
            const subtotalInputs = document.querySelectorAll('.subtotal-input');
            
            subtotalInputs.forEach(input => {
                const value = input.value.replace('₱', '').trim();
                if (value) {
                    grandTotal += parseFloat(value);
                }
            });
            
            document.getElementById('grandTotal').value = '₱' + grandTotal.toFixed(2);
            calculateChangeDue();
        }
        
        // Function to calculate change due
        function calculateChangeDue() {
            const grandTotalElem = document.getElementById('grandTotal');
            const amountPaidElem = document.getElementById('amountPaid');
            const changeDueElem = document.getElementById('changeDue');
            const paymentStatusElem = document.getElementById('paymentStatus');
            const submitBtn = document.getElementById('submitSaleBtn');
            
            const grandTotal = parseFloat(grandTotalElem.value.replace('₱', '').trim()) || 0;
            const amountPaid = parseFloat(amountPaidElem.value) || 0;
            const changeDue = amountPaid - grandTotal;
            
            changeDueElem.value = '₱' + changeDue.toFixed(2);
            
            if (amountPaid >= grandTotal && grandTotal > 0) {
                paymentStatusElem.value = 'Payment OK';
                paymentStatusElem.className = 'form-control bg-success text-white';
                submitBtn.disabled = false;
            } else if (grandTotal === 0) {
                paymentStatusElem.value = 'Add products first';
                paymentStatusElem.className = 'form-control bg-warning text-white';
                submitBtn.disabled = true;
            } else {
                paymentStatusElem.value = 'Insufficient payment';
                paymentStatusElem.className = 'form-control bg-danger text-white';
                submitBtn.disabled = true;
            }
        }
        
        // Initialize multiple products functionality
        const addProductBtn = document.getElementById('addProductRow');
        if (addProductBtn) {
            addProductBtn.addEventListener('click', addProductRow);
            
            // Attach events to initial row
            document.querySelectorAll('.product-row').forEach(row => {
                attachRowEvents(row);
            });
            
            // Calculate initial totals
            calculateGrandTotal();
        }
        
        // Amount paid change listener
        const amountPaidElem = document.getElementById('amountPaid');
        if (amountPaidElem) {
            amountPaidElem.addEventListener('input', calculateChangeDue);
        }
        
        // ============================================
        // QUICK SALE FUNCTIONS (Single Product)
        // ============================================
        
        const quickProductSelect = document.getElementById('quickProductSelect');
        const quickQtyInput = document.getElementById('quickQty');
        const quickPriceInput = document.getElementById('quickPrice');
        const quickTotalInput = document.getElementById('quickTotal');
        const quickStockWarning = document.getElementById('quickStockWarning');
        const quickAmountPaid = document.getElementById('quickAmountPaid');
        const quickChangeDue = document.getElementById('quickChangeDue');
        const quickPaymentStatus = document.getElementById('quickPaymentStatus');

        function calculateQuickTotal() {
            const selectedOption = quickProductSelect.options[quickProductSelect.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            const stock = selectedOption.getAttribute('data-stock');
            const qty = quickQtyInput.value;
            
            if (price && qty) {
                const total = (parseFloat(price) * parseInt(qty)).toFixed(2);
                quickPriceInput.value = '₱' + parseFloat(price).toFixed(2);
                quickTotalInput.value = '₱' + total;
                
                // Check stock
                if (stock && parseInt(qty) > parseInt(stock)) {
                    quickStockWarning.textContent = `Warning: Only ${stock} units available!`;
                    quickStockWarning.style.display = 'block';
                    quickStockWarning.className = 'text-danger';
                } else {
                    quickStockWarning.style.display = 'none';
                }
                
                calculateQuickChange();
            } else {
                quickPriceInput.value = '';
                quickTotalInput.value = '';
                quickStockWarning.style.display = 'none';
                calculateQuickChange();
            }
        }
        
        function calculateQuickChange() {
            const total = parseFloat(quickTotalInput.value.replace('₱', '').trim()) || 0;
            const paid = parseFloat(quickAmountPaid.value) || 0;
            const change = paid - total;
            
            quickChangeDue.value = '₱' + change.toFixed(2);
            
            if (paid >= total && total > 0) {
                quickPaymentStatus.value = 'Payment OK';
                quickPaymentStatus.className = 'form-control bg-success text-white';
            } else if (total === 0) {
                quickPaymentStatus.value = 'Select product first';
                quickPaymentStatus.className = 'form-control bg-warning text-white';
            } else {
                quickPaymentStatus.value = 'Insufficient payment';
                quickPaymentStatus.className = 'form-control bg-danger text-white';
            }
        }

        quickProductSelect.addEventListener('change', calculateQuickTotal);
        quickQtyInput.addEventListener('input', calculateQuickTotal);
        quickAmountPaid.addEventListener('input', calculateQuickChange);

        // ============================================
        // MODAL SALE FUNCTIONS (Single Product - for backward compatibility)
        // ============================================
        
        const modalProductSelect = document.getElementById('modalProductSelect');
        const modalQtyInput = document.getElementById('modalQty');
        const modalPriceInput = document.getElementById('modalPrice');
        const modalTotalInput = document.getElementById('modalTotal');
        const modalStockWarning = document.getElementById('stockWarning');

        function calculateModalTotal() {
            const selectedOption = modalProductSelect.options[modalProductSelect.selectedIndex];
            const price = selectedOption.getAttribute('data-price');
            const stock = selectedOption.getAttribute('data-stock');
            const qty = modalQtyInput.value;
            
            if (price && qty) {
                const total = (parseFloat(price) * parseInt(qty)).toFixed(2);
                modalPriceInput.value = '₱' + parseFloat(price).toFixed(2);
                modalTotalInput.value = '₱' + total;
                
                // Check stock
                if (stock && parseInt(qty) > parseInt(stock)) {
                    modalStockWarning.textContent = `Warning: Only ${stock} units available!`;
                    modalStockWarning.style.display = 'block';
                    modalStockWarning.className = 'text-danger';
                } else {
                    modalStockWarning.style.display = 'none';
                }
            } else {
                modalPriceInput.value = '';
                modalTotalInput.value = '';
                modalStockWarning.style.display = 'none';
            }
        }

        // Note: The old modal form (single product) is still there for backward compatibility
        // but it doesn't have payment tracking. You might want to update it or remove it.
        
        // ============================================
        // FORM RESET FUNCTIONS
        // ============================================
        
        // Reset modal form when it's closed
        document.getElementById('addSaleModal').addEventListener('hidden.bs.modal', function () {
            // Reset the form but keep one product row
            const form = document.getElementById('addSaleForm');
            form.reset();
            
            // Remove all product rows except first
            const productRows = document.querySelectorAll('.product-row');
            productRows.forEach((row, index) => {
                if (index > 0) {
                    row.remove();
                }
            });
            
            // Reset values
            document.querySelectorAll('.price-input').forEach(input => input.value = '');
            document.querySelectorAll('.subtotal-input').forEach(input => input.value = '');
            document.getElementById('grandTotal').value = '₱0.00';
            document.getElementById('amountPaid').value = '0';
            document.getElementById('changeDue').value = '₱0.00';
            document.getElementById('paymentStatus').value = 'Waiting for payment';
            document.getElementById('paymentStatus').className = 'form-control bg-white';
            
            // Update remove buttons
            updateRemoveButtons();
            
            // Reattach events to first row
            const firstRow = document.querySelector('.product-row');
            if (firstRow) {
                attachRowEvents(firstRow);
            }
        });

        // Initialize calculations
        calculateQuickTotal();
        calculateQuickChange();
        
        // Note: The old modal form calculation is not needed if we're using the new multi-product form
        // calculateModalTotal();
    });
    </script>
</body>
</html>