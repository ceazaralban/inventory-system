<?php
require_once __DIR__ . '/../includes/init.php';
redirectIfNotLoggedIn();
requirePageAccess('sales');

$database = new Database();
$db = $database->getConnection();

function logAudit(PDO $db, string $action, ?string $receipt_no, ?int $product_id, string $details, string $created_by): void {
    $stmt = $db->prepare("
        INSERT INTO audit_logs (action, receipt_no, product_id, details, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$action, $receipt_no, $product_id, $details, $created_by]);
}

/**
 * Manager override verification:
 * - requires role='manager' in users table
 * - verifies password hash
 * Adjust table/columns if your users table differs.
 */
function verifyManagerOverride(PDO $db, string $username, string $password): bool {
    $stmt = $db->prepare("SELECT username, password_hash, role, is_active FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) return false;
    if ((int)($u['is_active'] ?? 0) !== 1) return false;
    if (($u['role'] ?? '') !== 'manager' && ($u['role'] ?? '') !== 'admin') return false;

    return password_verify($password, $u['password_hash']);
}

$page_title = "Sales - Inventory System";

// Handle form submissions
if ($_POST) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'void_receipt') {

        requireRole(['admin','manager']);

        $receipt_no = $_POST['receipt_no'] ?? '';
        $void_reason = trim($_POST['void_reason'] ?? 'Voided');

        try {
            if (empty($receipt_no)) {
                throw new Exception("Invalid receipt number.");
            }
            $db->beginTransaction();

            // Get sales rows (non-void)
            $rows_stmt = $db->prepare("
                SELECT s.*, p.unit_type
                FROM sales s
                JOIN products p ON s.product_id = p.id
                WHERE s.receipt_no = ? AND (s.is_void = 0 OR s.is_void IS NULL)
            ");
            $rows_stmt->execute([$receipt_no]);
            $rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                throw new Exception("Receipt not found or already voided.");
            }

            // Restore stock
            foreach ($rows as $r) {
                $restore = 0;
                if (($r['unit_type'] ?? '') === 'kg') {
                    $restore = (float)($r['weight_kg'] ?? 0);
                    if ($restore <= 0) $restore = (float)($r['qty'] ?? 0);
                } else {
                    $restore = (float)($r['qty'] ?? 0);
                }

                $upd = $db->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?");
                $upd->execute([$restore, $r['product_id']]);
            }

            // Mark as void
            $void_stmt = $db->prepare("
                UPDATE sales
                SET is_void = 1,
                    void_reason = ?,
                    void_by = ?,
                    void_at = NOW()
                WHERE receipt_no = ?
            ");
            $void_stmt->execute([$void_reason, $_SESSION['username'], $receipt_no]);

            $db->commit();
            $success = "Receipt voided successfully! Stock restored.";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Failed to void receipt: " . $e->getMessage();
        }
}


    if ($action === 'add_sale') {
        // Check if it's a single product sale or multiple products
        if (isset($_POST['product_id']) && is_array($_POST['product_id'])) {
            // Multiple products sale
            $product_ids = $_POST['product_id'] ?? [];
            $qtys = $_POST['qty'] ?? [];
            $kg_weights = $_POST['kg_weight'] ?? [];

            $sale_date = $_POST['sale_date'] ?? date('Y-m-d H:i:s');
            $customer_name = $_POST['customer_name'] ?? '';
            $customer_email = $_POST['customer_email'] ?? '';
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);


            $receipt_no = 'R-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            
            try {
                // Start transaction
                $db->beginTransaction();
                
                $sale_details = [];
                
                // Process each product
                foreach ($product_ids as $index => $product_id) {
                    if (empty($product_id)) continue;
                    
                    // Get product details
                    $product_query = "
                        SELECT name, cost_price, sale_price, wholesale_price, stock_qty, unit_type, weight_per_piece
                        FROM products 
                        WHERE id = ?
                        FOR UPDATE
                    ";
                    $product_stmt = $db->prepare($product_query);
                    $product_stmt->execute([$product_id]);
                    $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$product) {
                        throw new Exception("Product not found!");
                    }
                    
                    // Determine quantity based on unit type
                    $quantity = 0;
                    $weight_kg = 0;
                    
                    if ($product['unit_type'] == 'kg' && isset($kg_weights[$index]) && !empty($kg_weights[$index])) {
                        // Product sold by weight (KG)
                        $weight_kg = floatval($kg_weights[$index]);
                        $weight_per_piece = floatval($product['weight_per_piece']);
                        
                        if ($weight_per_piece <= 0) {
                            $weight_per_piece = 1; // Default to 1 kg per piece
                        }
                        
                        // For KG products, stock_qty is in KG, so deduct the weight in KG
                        $quantity = $weight_kg; // Deduct this amount in KG from stock
                        $display_qty = $weight_kg; // Display KG to user
                    } else {
                        // Product sold by piece
                        $quantity = intval($qtys[$index] ?? 0);
                        $display_qty = $quantity;
                    }
                    
                    if ($quantity <= 0) {
                        continue; // Skip if no quantity
                    }
                    
                    // Check stock - FOR KG PRODUCTS, stock is in KG, quantity is in KG
                    if ($product['unit_type'] == 'kg') {
                        if ($product['stock_qty'] < $quantity) {
                            throw new Exception("Insufficient stock for " . $product['name'] . "! Available: " . $product['stock_qty'] . " kg");
                        }
                    } else {
                        if ($product['stock_qty'] < $quantity) {
                            throw new Exception("Insufficient stock for " . $product['name'] . "! Available: " . $product['stock_qty']);
                        }
                    }
                    
                    // Get price from form (already calculated by JavaScript)
                    $price_type = $_POST['price_type'] ?? 'retail';

                    $price = ($price_type === 'wholesale' && (float)$product['wholesale_price'] > 0)
                        ? (float)$product['wholesale_price']
                        : (float)$product['sale_price'];
                    // $item_total = floatval(str_replace('₱', '', $totals[$index] ?? 0));

                    // 🚨 Prevent selling below cost
                    $below_cost = ($price < (float)$product['cost_price']);

                    if ($below_cost) {
                        $override_ok = false;

                        // manager override fields sent only when override is used
                        $mgr_user = trim($_POST['manager_user'] ?? '');
                        $mgr_pass = (string)($_POST['manager_pass'] ?? '');
                        $mgr_reason = trim($_POST['manager_reason'] ?? '');

                        if ($mgr_user && $mgr_pass) {
                            $override_ok = verifyManagerOverride($db, $mgr_user, $mgr_pass);
                        }

                        if (!$override_ok) {
                            throw new Exception(
                                "Below-cost sale blocked for {$product['name']} (Cost ₱" .
                                number_format($product['cost_price'], 2) . ", Selling ₱" .
                                number_format($price, 2) .
                                "). Manager override required."
                            );
                        }

                        // Log approved override (receipt not created yet, so store null for now)
                        logAudit(
                            $db,
                            'BELOW_COST_OVERRIDE_APPROVED',
                            null,
                            (int)$product_id,
                            "Product={$product['name']} | Cost={$product['cost_price']} | Selling={$price} | Reason={$mgr_reason} | Manager={$mgr_user}",
                            $_SESSION['username'] ?? 'unknown'
                        );
                    }

                    // $min_price = $product['cost_price'] * 1.20;

                    // if ($price < $min_price) {
                    //     throw new Exception(
                    //         "Minimum allowed price for {$product['name']} is ₱" . 
                    //         number_format($min_price,2) . " (20% margin rule)"
                    //     );
                    // }

                    $calculated_total = $price * $quantity;

                    // Store sale details for later insertion
                    if ($product['unit_type'] === 'kg') {
                        $sale_details[] = [
                            'product_id' => $product_id,
                            'qty' => 1,              // 1 line item
                            'display_qty' => 1,
                            'weight_kg' => $weight_kg,
                            'price' => $price,
                            'total' => $calculated_total
                        ];
                    } else {
                        $sale_details[] = [
                            'product_id' => $product_id,
                            'qty' => $quantity,
                            'display_qty' => $quantity,
                            'weight_kg' => 0,
                            'price' => $price,
                            'total' => $calculated_total
                        ];
                    }
                    
                    // Update product stock

                    $update_stmt = $db->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?");
                    $update_stmt->execute([$quantity, $product_id]);

                }
                
                if (count($sale_details) === 0) {
                    throw new Exception("No products selected!");
                }
                

                // ✅ Compute server-side grand total AFTER items are prepared
                $grand_total_server = array_sum(array_column($sale_details, 'total'));
                                
                // ✅ Validate payment using server grand total
                if ($amount_paid < $grand_total_server) {
                    throw new Exception(
                        "Insufficient payment! Total: ₱" . number_format($grand_total_server, 2) .
                        ", Paid: ₱" . number_format($amount_paid, 2)
                    );
                }

                // Calculate change due
                $change_due = $amount_paid - $grand_total_server;
                
                // Insert sales rows
                $stmt = $db->prepare("
                    INSERT INTO sales
                    (receipt_no, product_id, sale_date, qty, weight_kg, price, total, customer_name, customer_email, amount_paid, change_due, is_void)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                ");
                
                foreach ($sale_details as $detail) {
                    $stmt->execute([
                        $receipt_no,
                        $detail['product_id'],
                        $sale_date,
                        $detail['display_qty'],
                        $detail['weight_kg'],
                        $detail['price'],
                        $detail['total'],
                        $customer_name,
                        $customer_email,
                        $amount_paid,
                        $change_due
                    ]);
                }
                
                $db->commit();

                // Attach receipt_no to any override logs created during this transaction
                $db->prepare("
                    UPDATE audit_logs 
                    SET receipt_no = ?
                    WHERE receipt_no IS NULL 
                    AND created_by = ?
                    AND action = 'BELOW_COST_OVERRIDE_APPROVED'
                    AND created_at >= (NOW() - INTERVAL 5 MINUTE)
                ")->execute([$receipt_no, $_SESSION['username'] ?? 'unknown']);

                // ✅ Use grand_total_server in message
                $success = "Sale recorded successfully! " .
                    "Total: ₱" . number_format($grand_total_server, 2) .
                    " | Paid: ₱" . number_format($amount_paid, 2) .
                    " | Change: ₱" . number_format($change_due, 2);

            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to record sale: " . $e->getMessage();
            }
            
        } else {
            // Single product sale (Quick Sale)
            $receipt_no = 'R-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            $product_id = $_POST['product_id'] ?? 0;
            $sale_date = $_POST['sale_date'] ?? date('Y-m-d H:i:s');

            $customer_name = $_POST['customer_name'] ?? '';
            $customer_email = $_POST['customer_email'] ?? '';
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);


            try {
                $db->beginTransaction();

                // Get product details
                $product_query = "
                    SELECT name, cost_price, sale_price, wholesale_price, stock_qty, unit_type, weight_per_piece
                    FROM products 
                    WHERE id = ?
                    FOR UPDATE
                ";
                $product_stmt = $db->prepare($product_query);
                $product_stmt->execute([$product_id]);
                $product = $product_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new Exception("Product not found!");
                }

                // Determine qty (pcs) or kg_weight (kg)
                $qty = floatval($_POST['qty'] ?? 0);
                $kg_weight = floatval($_POST['kg_weight'] ?? 0);

                if ($product['unit_type'] === 'kg') {
                    $qty = $kg_weight; // qty becomes KG amount
                }

                // Determine price
                $price_type = $_POST['price_type'] ?? 'retail';

                $price = ($price_type === 'wholesale' && (float)$product['wholesale_price'] > 0)
                    ? (float)$product['wholesale_price']
                    : (float)$product['sale_price'];

                // Prevent selling below cost + 20% rule (keep both if you want)
                $below_cost = ((float)$price < (float)$product['cost_price']);

                if ($below_cost) {
                    $mgr_user = trim($_POST['manager_user'] ?? '');
                    $mgr_pass = (string)($_POST['manager_pass'] ?? '');
                    $mgr_reason = trim($_POST['manager_reason'] ?? '');

                    $override_ok = ($mgr_user && $mgr_pass) ? verifyManagerOverride($db, $mgr_user, $mgr_pass) : false;

                    if (!$override_ok) {
                        throw new Exception(
                            "Below-cost sale blocked for {$product['name']} (Cost ₱" .
                            number_format($product['cost_price'], 2) . ", Selling ₱" .
                            number_format($price, 2) .
                            "). Manager override required."
                        );
                    }

                    // Log approved override (receipt is already known in quick sale)
                    logAudit(
                        $db,
                        'BELOW_COST_OVERRIDE_APPROVED',
                        $receipt_no,
                        (int)$product_id,
                        "Product={$product['name']} | Cost={$product['cost_price']} | Selling={$price} | Reason={$mgr_reason} | Manager={$mgr_user}",
                        $_SESSION['username'] ?? 'unknown'
                    );
                }

                // $min_price = (float)$product['cost_price'] * 1.20;
                // if ((float)$price < $min_price) {
                //     throw new Exception(
                //         "Minimum allowed price for {$product['name']} is ₱" .
                //         number_format($min_price, 2) . " (20% margin rule)"
                //     );
                // }

                // Stock check
                if ((float)$product['stock_qty'] < (float)$qty) {
                    $unit = ($product['unit_type'] === 'kg') ? 'kg' : 'pcs';
                    throw new Exception("Insufficient stock! Available: {$product['stock_qty']} {$unit}");
                }

                $total = $price * $qty;

                if ($amount_paid < $total) {
                    throw new Exception(
                        "Insufficient payment! Total: ₱" . number_format($total, 2) .
                        ", Paid: ₱" . number_format($amount_paid, 2)
                    );
                }

                $change_due = $amount_paid - $total;

                // Insert sale
                $query = "INSERT INTO sales (receipt_no, product_id, sale_date, qty, weight_kg, price, total, customer_name, customer_email, amount_paid, change_due, is_void)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
                $stmt = $db->prepare($query);

                $weight_kg_to_save = ($product['unit_type'] === 'kg') ? $qty : 0;

                $stmt->execute([
                    $receipt_no,
                    $product_id,
                    $sale_date,
                    $qty,
                    $weight_kg_to_save,
                    $price,
                    $total,
                    $customer_name,
                    $customer_email,
                    $amount_paid,
                    $change_due
                ]);

                // Deduct stock
                $update_query = "UPDATE products SET stock_qty = stock_qty - ? WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$qty, $product_id]);

                $db->commit();

                // Attach receipt_no to any override logs created during this transaction
                $db->prepare("
                    UPDATE audit_logs 
                    SET receipt_no = ?
                    WHERE receipt_no IS NULL 
                    AND created_by = ?
                    AND action = 'BELOW_COST_OVERRIDE_APPROVED'
                    AND created_at >= (NOW() - INTERVAL 5 MINUTE)
                ")->execute([$receipt_no, $_SESSION['username'] ?? 'unknown']);

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

// Get all products for dropdowns - UPDATED WITH UNIT TYPE
$products = $db->query("
  SELECT id, name, sku, stock_qty, sale_price, cost_price, unit_type, weight_per_piece, min_weight, wholesale_price
  FROM products
  WHERE stock_qty > 0
  ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get recent sales with payment info
$sales = $db->query("
    SELECT s.*, p.name as product_name, p.sku, p.unit_type
    FROM sales s 
    JOIN products p ON s.product_id = p.id 
    ORDER BY s.sale_date DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);


// Get sales summary (all-time instead of just today)
$sales_summary = $db->query("
    SELECT
        COUNT(*) AS total_sales,
        SUM(receipt_items) AS total_items_sold,
        SUM(receipt_total) AS total_revenue,
        SUM(receipt_paid) AS total_paid,
        AVG(receipt_total) AS avg_sale_value
    FROM (
        SELECT
            receipt_no,
            SUM(qty) AS receipt_items,
            SUM(total) AS receipt_total,
            MAX(amount_paid) AS receipt_paid
        FROM sales
        WHERE (is_void = 0 OR is_void IS NULL)
        GROUP BY receipt_no
    ) r
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
    SELECT p.name, p.sku,
           SUM(CASE WHEN p.unit_type='kg' THEN s.weight_kg ELSE s.qty END) as total_sold,
           SUM(s.total) as revenue
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE (s.is_void = 0 OR s.is_void IS NULL)
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
                <input type="hidden" name="manager_user" id="mgr_user_multi">
                <input type="hidden" name="manager_pass" id="mgr_pass_multi">
                <input type="hidden" name="manager_reason" id="mgr_reason_multi">
                <div class="modal-body">
<!-- Price Type Selector -->
<div class="row mb-3">
    <div class="col-md-12">
        <div class="mb-3">
            <label class="form-label">Price Type</label>
            <select class="form-select" id="priceType" name="price_type">
                <option value="retail">Retail Price</option>
                <option value="wholesale">Wholesale Price</option>
            </select>
            <small class="text-muted">Use wholesale price for bulk purchases</small>
        </div>
    </div>
</div>

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
                                    <th width="20%">Quantity / Weight</th>
                                    <th width="15%">Unit Price</th>
                                    <th width="15%">Subtotal</th>
                                    <th width="10%">Action</th>
                                </tr>
                            </thead>
                            <tbody id="productRows">
                            <!-- Product rows will be added here dynamically -->
                            <tr class="product-row">
                                <td>
                                    <select name="product_id[]" class="form-select product-select" required>
                                        <option value="">Select Product</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo $product['id']; ?>" 
                                                    data-price="<?php echo $product['sale_price']; ?>"
                                                    data-wholesale="<?php echo $product['wholesale_price'] > 0 ? $product['wholesale_price'] : $product['sale_price']; ?>"
                                                    data-cost="<?php echo $product['cost_price']; ?>"
                                                    data-stock="<?php echo $product['stock_qty']; ?>"
                                                    data-unit-type="<?php echo $product['unit_type']; ?>"
                                                    data-weight="<?php echo $product['weight_per_piece']; ?>"
                                                    data-min-weight="<?php echo $product['min_weight']; ?>">
                                                <?php echo htmlspecialchars($product['name']); ?> 
                                                <?php if ($product['unit_type'] == 'kg'): ?>
                                                    <span class="badge bg-info">KG</span>
                                                <?php endif; ?>
                                                (₱<?php echo number_format($product['sale_price'], 2); ?>)
                                                <?php if ($product['wholesale_price'] > 0): ?>
                                                    <small class="text-muted">Wholesale: ₱<?php echo number_format($product['wholesale_price'], 2); ?></small>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="quantity-cell">
                                    <!-- This div will contain either kg or piece input -->
                                    <div class="quantity-container">
                                        <!-- Default shows pieces input -->
                                        <div class="piece-input-container">
                                            <input type="number" name="qty[]" class="form-control qty-input" min="1" value="1" required>
                                            <small class="text-muted">pcs</small>
                                        </div>
                                        <div class="kg-input-container" style="display: none;">
                                            <input type="number" name="kg_weight[]" class="form-control kg-input" step="0.001" min="0.001" value="1.000">
                                            <small class="text-muted">kg</small>
                                        </div>
                                    </div>
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
                                        <input type="hidden" name="grand_total" id="grandTotalHidden" value="0">
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
            <input type="hidden" name="manager_user" id="mgr_user_quick">
            <input type="hidden" name="manager_pass" id="mgr_pass_quick">
            <input type="hidden" name="manager_reason" id="mgr_reason_quick">
            
            <!-- Price Type -->
            <div class="mb-3">
                <label class="form-label">Price Type</label>
                <select name="price_type" class="form-select" id="quickPriceType">
                    <option value="retail">Retail Price</option>
                    <option value="wholesale">Wholesale Price</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Product *</label>
                <select name="product_id" class="form-select" required id="quickProductSelect">
                    <option value="">Select Product</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>" 
                                data-price="<?php echo $product['sale_price']; ?>"
                                data-wholesale="<?php echo $product['wholesale_price'] > 0 ? $product['wholesale_price'] : $product['sale_price']; ?>"
                                data-cost="<?php echo $product['cost_price']; ?>"
                                data-stock="<?php echo $product['stock_qty']; ?>"
                                data-unit-type="<?php echo $product['unit_type']; ?>"
                                data-weight="<?php echo $product['weight_per_piece']; ?>"
                                data-min-weight="<?php echo $product['min_weight']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?> 
                            (₱<?php echo number_format($product['sale_price'], 2); ?>)
                            <?php if ($product['wholesale_price'] > 0): ?>
                                <small class="text-muted">Wholesale: ₱<?php echo number_format($product['wholesale_price'], 2); ?></small>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- KG Weight Input (Hidden by default) -->
            <div class="mb-3" id="quickKgContainer" style="display: none;">
                <label class="form-label">Weight (KG) *</label>
                <div class="input-group">
                    <input type="number" name="kg_weight" class="form-control" step="0.001" min="0.001" value="1.000" id="quickKgWeight">
                    <span class="input-group-text">kg</span>
                </div>
                <small class="text-muted" id="quickMinWeight"></small>
            </div>
            
            <!-- Regular Quantity Input (Shown by default) -->
            <div class="mb-3" id="quickQtyContainer">
                <label class="form-label">Quantity *</label>
                <div class="input-group">
                    <input type="number" name="qty" class="form-control" min="1" value="1" required id="quickQty">
                    <span class="input-group-text" id="quickUnitDisplay">pcs</span>
                </div>
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
<!-- Recent Sales Table - Updated to show KG -->
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
                        <th>Weight (KG)</th>
                        <th>Price</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Change</th>
                        <th>Customer</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($sales) > 0): ?>
                        <?php foreach ($sales as $sale): 
                            // Use joined unit_type (NO extra query)
                            $is_kg = (($sale['unit_type'] ?? '') === 'kg');
                        ?>
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
<td>
  <span class="badge bg-primary">
    <?php
      if ($is_kg) {
        $kg = (float)($sale['weight_kg'] ?? 0);
        if ($kg <= 0) $kg = (float)$sale['qty'];
        echo number_format($kg, 3) . ' kg';
      } else {
        echo (int)$sale['qty'] . ' pcs';
      }
    ?>
  </span>
</td>
<td>
  <?php if ($is_kg): ?>
    <small class="text-muted">
      <?php
        $kg = (float)($sale['weight_kg'] ?? 0);
        if ($kg <= 0) $kg = (float)$sale['qty'];
        echo number_format($kg, 3);
      ?> kg
    </small>
  <?php else: ?>
    <small class="text-muted">-</small>
  <?php endif; ?>
</td>
                                <td>₱<?php echo number_format($sale['price'], 2); ?></td>
                                <td><strong>₱<?php echo number_format($sale['total'], 2); ?></strong></td>
                                <td><span class="badge bg-success">₱<?php echo number_format($sale['amount_paid'] ?? 0, 2); ?></span></td>
                                <td><span class="badge bg-info">₱<?php echo number_format($sale['change_due'] ?? 0, 2); ?></span></td>
                                <td><small><?php echo htmlspecialchars($sale['customer_name'] ?: 'Walk-in'); ?></small></td>
                                <td>
                                    <?php if (!empty($sale['receipt_no'])): ?>
                                        <a class="btn btn-sm btn-outline-secondary"
                                        href="print_receipt.php?receipt_no=<?php echo urlencode($sale['receipt_no']); ?>"
                                        target="_blank">
                                        <i class="bi bi-printer"></i>
                                        </a>

                                        <?php if (empty($sale['is_void'])): ?>
                                        <button class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#voidModal"
                                                data-receipt="<?php echo htmlspecialchars($sale['receipt_no']); ?>">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                        <?php else: ?>
                                        <span class="badge bg-danger">VOID</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <small class="text-muted">-</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
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
<div class="modal fade" id="voidModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Void Receipt</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="void_receipt">
        <input type="hidden" name="receipt_no" id="void_receipt_no">
        <div class="modal-body">
          <p>Are you sure you want to void this receipt?</p>
          <div class="mb-3">
            <label class="form-label">Reason</label>
            <input type="text" name="void_reason" class="form-control" placeholder="Optional">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger">Void</button>
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

    // ============================================
    // MULTIPLE PRODUCTS SALE FUNCTIONS
    // ============================================
    
    // Global variable for price type
    const priceTypeSelect = document.getElementById('priceType');
    
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
                        <option value="<?php echo $product['id']; ?>" 
                                data-price="<?php echo $product['sale_price']; ?>"
                                data-wholesale="<?php echo $product['wholesale_price'] > 0 ? $product['wholesale_price'] : $product['sale_price']; ?>"
                                data-cost="<?php echo $product['cost_price']; ?>"
                                data-stock="<?php echo $product['stock_qty']; ?>"
                                data-unit-type="<?php echo $product['unit_type']; ?>"
                                data-weight="<?php echo $product['weight_per_piece']; ?>"
                                data-min-weight="<?php echo $product['min_weight']; ?>">
                            <?php echo htmlspecialchars($product['name']); ?> 
                            <?php if ($product['unit_type'] == 'kg'): ?>
                                <span class="badge bg-info">KG</span>
                            <?php endif; ?>
                            (₱<?php echo number_format($product['sale_price'], 2); ?>)
                            <?php if ($product['wholesale_price'] > 0): ?>
                                <small class="text-muted">Wholesale: ₱<?php echo number_format($product['wholesale_price'], 2); ?></small>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td class="quantity-cell">
                <div class="quantity-container">
                    <div class="piece-input-container">
                        <input type="number" name="qty[]" class="form-control qty-input" min="1" value="1" required>
                        <small class="text-muted">pcs</small>
                    </div>
                    <div class="kg-input-container" style="display: none;">
                        <input type="number" name="kg_weight[]" class="form-control kg-input" step="0.001" min="0.001" value="1.000">
                        <small class="text-muted">kg</small>
                    </div>
                </div>
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
        
        // Apply current price type to new row
        updateRowForPriceType(newRow, priceTypeSelect.value);
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
    
    // Function to update row based on product unit type
    function updateRowForProductType(row) {
        const productSelect = row.querySelector('.product-select');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const unitType = selectedOption.getAttribute('data-unit-type');
        const minWeight = selectedOption.getAttribute('data-min-weight');
        const weight = selectedOption.getAttribute('data-weight') || '1.000';
        
        const pieceContainer = row.querySelector('.piece-input-container');
        const kgContainer = row.querySelector('.kg-input-container');
        const qtyInput = row.querySelector('.qty-input');
        const kgInput = row.querySelector('.kg-input');
        
        if (unitType === 'kg') {
            // Show KG input, hide piece input
            pieceContainer.style.display = 'none';
            kgContainer.style.display = 'block';
            
            // Set KG input attributes
            kgInput.step = '0.001';
            kgInput.min = minWeight || '0.001';
            kgInput.value = weight || '1.000';
            kgInput.required = true;
            qtyInput.required = false;
        } else {
            // Show piece input, hide KG input
            pieceContainer.style.display = 'block';
            kgContainer.style.display = 'none';
            
            // Set piece input attributes
            qtyInput.step = '1';
            qtyInput.min = '1';
            qtyInput.value = '1';
            qtyInput.required = true;
            kgInput.required = false;
        }
    }
    
    // Function to update row based on price type (retail/wholesale)
    function updateRowForPriceType(row, priceType) {
        const productSelect = row.querySelector('.product-select');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (selectedOption.value) {
            const price = priceType === 'wholesale' ? 
                selectedOption.getAttribute('data-wholesale') : 
                selectedOption.getAttribute('data-price');
            
            const priceInput = row.querySelector('.price-input');
            priceInput.value = '₱' + parseFloat(price).toFixed(2);
            calculateRowTotal(row);
        }
    }
    
    // Function to attach event listeners to a row
    function attachRowEvents(row) {
        const productSelect = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        const kgInput = row.querySelector('.kg-input');
        const priceInput = row.querySelector('.price-input');
        const subtotalInput = row.querySelector('.subtotal-input');
        const removeBtn = row.querySelector('.remove-row');
        
        // Product select change
        productSelect.addEventListener('change', function() {
            // Update input type based on product unit type
            updateRowForProductType(row);
            
            // Update price based on current price type
            updateRowForPriceType(row, priceTypeSelect.value);
            
            // Check stock
            checkStock(row);
            
            // Calculate total
            calculateRowTotal(row);
        });
        
        // Quantity input change (pieces)
        qtyInput.addEventListener('input', function() {
            calculateRowTotal(row);
            checkStock(row);
        });
        
        // KG input change
        if (kgInput) {
            kgInput.addEventListener('input', function() {
                calculateRowTotal(row);
                checkStock(row);
            });
        }
        
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
        const kgInput = row.querySelector('.kg-input');
        const priceInput = row.querySelector('.price-input');
        const subtotalInput = row.querySelector('.subtotal-input');
        
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const unitType = selectedOption.getAttribute('data-unit-type');
        const price = parseFloat(priceInput.value.replace('₱', '').trim()) || 0;
        
        let quantity = 0;
        if (unitType === 'kg') {
            quantity = parseFloat(kgInput.value) || 0;
        } else {
            quantity = parseFloat(qtyInput.value) || 0;
        }
        
        if (price && quantity > 0) {
            const subtotal = (price * quantity).toFixed(2);
            subtotalInput.value = '₱' + subtotal;
            calculateGrandTotal();
        } else {
            subtotalInput.value = '';
        }
    }
    
    // Function to check stock
    function checkStock(row) {
        const productSelect = row.querySelector('.product-select');
        const qtyInput = row.querySelector('.qty-input');
        const kgInput = row.querySelector('.kg-input');
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        
        if (!selectedOption.value) return;
        
        const stock = parseFloat(selectedOption.getAttribute('data-stock')) || 0;
        const unitType = selectedOption.getAttribute('data-unit-type');
        
        let quantity = 0;
        if (unitType === 'kg') {
            quantity = parseFloat(kgInput.value) || 0;
        } else {
            quantity = parseFloat(qtyInput.value) || 0;
        }
        
        if (quantity > stock) {
            const unit = unitType === 'kg' ? 'kg' : 'pcs';
            const message = `Only ${stock} ${unit} available!`;
            
            if (unitType === 'kg') {
                kgInput.setCustomValidity(message);
                kgInput.reportValidity();
            } else {
                qtyInput.setCustomValidity(message);
                qtyInput.reportValidity();
            }
        } else {
            if (kgInput) kgInput.setCustomValidity('');
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
        document.getElementById('grandTotalHidden').value = grandTotal.toFixed(2);
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
    
    // Price type change listener
    if (priceTypeSelect) {
        priceTypeSelect.addEventListener('change', function() {
            document.querySelectorAll('.product-row').forEach(row => {
                updateRowForPriceType(row, this.value);
            });
            calculateGrandTotal();
        });
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
    const quickPriceType = document.getElementById('quickPriceType');
    const quickQtyContainer = document.getElementById('quickQtyContainer');
    const quickKgContainer = document.getElementById('quickKgContainer');
    const quickQtyInput = document.getElementById('quickQty');
    const quickKgWeight = document.getElementById('quickKgWeight');
    const quickUnitDisplay = document.getElementById('quickUnitDisplay');
    const quickPriceInput = document.getElementById('quickPrice');
    const quickTotalInput = document.getElementById('quickTotal');
    const quickStockWarning = document.getElementById('quickStockWarning');
    const quickMinWeight = document.getElementById('quickMinWeight');
    const quickAmountPaid = document.getElementById('quickAmountPaid');
    const quickChangeDue = document.getElementById('quickChangeDue');
    const quickPaymentStatus = document.getElementById('quickPaymentStatus');
    const quickSaleForm = document.getElementById('quickSaleForm');

    function updateQuickSaleUI() {
        const selectedOption = quickProductSelect.options[quickProductSelect.selectedIndex];
        if (!selectedOption.value) return;
        
        const unitType = selectedOption.getAttribute('data-unit-type');
        const minWeight = selectedOption.getAttribute('data-min-weight');
        
        // Show/hide KG or Quantity inputs
        if (unitType === 'kg') {
            quickQtyContainer.style.display = 'none';
            quickKgContainer.style.display = 'block';
            quickUnitDisplay.textContent = 'kg';
            quickQtyInput.step = '0.001';
            quickQtyInput.min = minWeight || '0.001';
            quickKgWeight.min = minWeight || '0.001';
            quickKgWeight.value = '1.000';
            
            if (minWeight) {
                quickMinWeight.textContent = `Minimum weight: ${minWeight} kg`;
            }
            
            // Clear piece quantity and set kg as required
            quickQtyInput.value = '';
            quickQtyInput.required = false;
            quickKgWeight.required = true;
        } else {
            quickQtyContainer.style.display = 'block';
            quickKgContainer.style.display = 'none';
            quickUnitDisplay.textContent = 'pcs';
            quickQtyInput.step = '1';
            quickQtyInput.min = '1';
            quickQtyInput.value = '1';
            
            // Clear kg weight and set piece as required
            quickKgWeight.value = '';
            quickKgWeight.required = false;
            quickQtyInput.required = true;
        }
        
        calculateQuickTotal();
    }

    function calculateQuickTotal() {
        const selectedOption = quickProductSelect.options[quickProductSelect.selectedIndex];
        if (!selectedOption.value) {
            quickPriceInput.value = '';
            quickTotalInput.value = '';
            quickStockWarning.style.display = 'none';
            calculateQuickChange();
            return;
        }
        
        const priceType = quickPriceType.value;
        const price = priceType === 'wholesale' ? 
            selectedOption.getAttribute('data-wholesale') : 
            selectedOption.getAttribute('data-price');
        const stock = selectedOption.getAttribute('data-stock');
        const unitType = selectedOption.getAttribute('data-unit-type');
        
        let quantity = 0;
        if (unitType === 'kg') {
            quantity = parseFloat(quickKgWeight.value) || 0;
        } else {
            quantity = parseFloat(quickQtyInput.value) || 0;
        }
        
        if (price && quantity > 0) {
            const total = (parseFloat(price) * quantity).toFixed(2);
            quickPriceInput.value = '₱' + parseFloat(price).toFixed(2);
            quickTotalInput.value = '₱' + total;
            
            // Check stock
            if (stock && quantity > parseFloat(stock)) {
                const unit = unitType === 'kg' ? 'kg' : 'pcs';
                quickStockWarning.textContent = `Warning: Only ${stock} ${unit} available!`;
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

    // Event listeners for quick sale
    quickProductSelect.addEventListener('change', updateQuickSaleUI);
    quickPriceType.addEventListener('change', calculateQuickTotal);
    quickQtyInput.addEventListener('input', calculateQuickTotal);
    quickKgWeight.addEventListener('input', calculateQuickTotal);
    quickAmountPaid.addEventListener('input', calculateQuickChange);

    // Modify quick sale form submission to handle KG products
    if (quickSaleForm) {
        quickSaleForm.addEventListener('submit', function(e) {
            const selectedOption = quickProductSelect.options[quickProductSelect.selectedIndex];
            const unitType = selectedOption.getAttribute('data-unit-type');
            
            // If it's a KG product, we need to handle the kg_weight field
            if (unitType === 'kg') {
                // Remove the qty field from form submission
                const qtyInput = this.querySelector('input[name="qty"]');
                if (qtyInput) {
                    qtyInput.disabled = true; // Disable so it doesn't get submitted
                }
            } else {
                // Remove the kg_weight field from form submission
                const kgWeightInput = this.querySelector('input[name="kg_weight"]');
                if (kgWeightInput) {
                    kgWeightInput.disabled = true; // Disable so it doesn't get submitted
                }
            }
        });
    }

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
        document.getElementById('grandTotalHidden').value = '0';
        document.getElementById('amountPaid').value = '0';
        document.getElementById('changeDue').value = '₱0.00';
        document.getElementById('paymentStatus').value = 'Waiting for payment';
        document.getElementById('paymentStatus').className = 'form-control bg-white';
        
        // Reset price type selector
        if (priceTypeSelect) priceTypeSelect.value = 'retail';
        
        // Update rows for price type
        document.querySelectorAll('.product-row').forEach(row => {
            updateRowForPriceType(row, 'retail');
        });
        
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

    const voidModal = document.getElementById('voidModal');
    if (voidModal) {
    voidModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const receipt = button.getAttribute('data-receipt');
        document.getElementById('void_receipt_no').value = receipt;
    });
    }

let pendingOverrideForm = null;

// helper to check if any item is below cost based on data attributes
function hasBelowCostItemMulti() {
  const rows = document.querySelectorAll('#productRows .product-row');
  const priceType = document.getElementById('priceType')?.value || 'retail';

  for (const row of rows) {
    const sel = row.querySelector('.product-select');
    if (!sel || !sel.value) continue;

    const opt = sel.options[sel.selectedIndex];
    const cost = parseFloat(opt.getAttribute('data-cost')) || 0; // (we will add this attribute)
    const price = priceType === 'wholesale'
      ? parseFloat(opt.getAttribute('data-wholesale')) || 0
      : parseFloat(opt.getAttribute('data-price')) || 0;

    if (cost > 0 && price > 0 && price < cost) return true;
  }
  return false;
}

function isBelowCostQuick() {
  const sel = document.getElementById('quickProductSelect');
  if (!sel || !sel.value) return false;

  const opt = sel.options[sel.selectedIndex];
  const cost = parseFloat(opt.getAttribute('data-cost')) || 0;
  const priceType = document.getElementById('quickPriceType')?.value || 'retail';
  const price = priceType === 'wholesale'
    ? parseFloat(opt.getAttribute('data-wholesale')) || 0
    : parseFloat(opt.getAttribute('data-price')) || 0;

  return (cost > 0 && price > 0 && price < cost);
}

// intercept submissions
const addSaleForm = document.getElementById('addSaleForm');
if (addSaleForm) {
  addSaleForm.addEventListener('submit', function(e) {
    // if below-cost, require confirmation + manager modal
    if (hasBelowCostItemMulti()) {
      e.preventDefault();
      if (!confirm('This sale contains below-cost pricing. Manager approval is required. Continue?')) return;

      pendingOverrideForm = 'multi';
      const modal = new bootstrap.Modal(document.getElementById('managerOverrideModal'));
      modal.show();
    }
  });
}

const quickForm = document.getElementById('quickSaleForm');
if (quickForm) {
  quickForm.addEventListener('submit', function(e) {
    if (isBelowCostQuick()) {
      e.preventDefault();
      if (!confirm('This sale contains below-cost pricing. Manager approval is required. Continue?')) return;

      pendingOverrideForm = 'quick';
      const modal = new bootstrap.Modal(document.getElementById('managerOverrideModal'));
      modal.show();
    }
  });
}

// approve button: copy values and submit
document.getElementById('confirmOverrideBtn')?.addEventListener('click', function() {
  const u = document.getElementById('overrideManagerUser').value.trim();
  const p = document.getElementById('overrideManagerPass').value;
  const r = document.getElementById('overrideReason').value.trim();

  if (!u || !p) {
    alert('Manager username and password are required.');
    return;
  }

  if (pendingOverrideForm === 'multi') {
    document.getElementById('mgr_user_multi').value = u;
    document.getElementById('mgr_pass_multi').value = p;
    document.getElementById('mgr_reason_multi').value = r;
    document.getElementById('addSaleForm').submit();
  }

  if (pendingOverrideForm === 'quick') {
    document.getElementById('mgr_user_quick').value = u;
    document.getElementById('mgr_pass_quick').value = p;
    document.getElementById('mgr_reason_quick').value = r;
    document.getElementById('quickSaleForm').submit();
  }
});

});
</script>

<div class="modal fade" id="managerOverrideModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Manager Override Required</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-warning">
          This sale includes an item priced below cost. Manager approval is required.
        </div>

        <div class="mb-3">
          <label class="form-label">Manager Username</label>
          <input type="text" class="form-control" id="overrideManagerUser" autocomplete="username">
        </div>

        <div class="mb-3">
          <label class="form-label">Manager Password</label>
          <input type="password" class="form-control" id="overrideManagerPass" autocomplete="current-password">
        </div>

        <div class="mb-3">
          <label class="form-label">Reason</label>
          <input type="text" class="form-control" id="overrideReason" placeholder="e.g. promo, damaged box, clearance">
        </div>

        <small class="text-muted">Manager credentials are verified on the server.</small>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmOverrideBtn">Approve & Continue</button>
      </div>
    </div>
  </div>
</div>

</body>
</html>