<?php
require_once __DIR__ . '/../includes/init.php';
requireRole(['admin']);
// requireRole(['admin','manager']);
redirectIfNotLoggedIn();
requirePageAccess('reports');

$database = new Database();
$db = $database->getConnection();

$page_title = "Reports - Inventory System";

// Default date range (this month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$report_type = $_GET['report_type'] ?? 'sales';

// Get sales report data
if ($report_type === 'sales') {
    $sales_query = "
        SELECT 
            DATE(s.sale_date) as sale_day,
            COUNT(*) as total_sales,
            SUM(s.qty) as total_items,
            SUM(s.total) as total_revenue,
            AVG(s.total) as avg_sale
        FROM sales s
        WHERE s.sale_date BETWEEN ? AND ?
        GROUP BY DATE(s.sale_date)
        ORDER BY sale_day
    ";
    $stmt = $db->prepare($sales_query);
    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get product performance data WITH GROSS MARGIN CALCULATIONS
if ($report_type === 'products') {
    $products_query = "
        SELECT 
            p.name,
            p.sku,
            p.category,
            p.unit_type,
            p.weight_per_piece,
            p.cost_price,
            p.sale_price,
            SUM(s.qty) as total_sold,
            SUM(s.total) as total_revenue,
            SUM(s.qty * p.cost_price) as total_cost_value,
            SUM(s.total) - SUM(s.qty * p.cost_price) as gross_margin,
            CASE 
                WHEN SUM(s.total) > 0 
                THEN ROUND((SUM(s.total) - SUM(s.qty * p.cost_price)) / SUM(s.total) * 100, 2)
                ELSE 0
            END as gross_margin_percent,
            AVG(s.price) as avg_sale_price,
            p.stock_qty as current_stock
        FROM sales s
        JOIN products p ON s.product_id = p.id
        WHERE s.sale_date BETWEEN ? AND ?
        GROUP BY p.id, p.name, p.sku, p.category, p.unit_type, p.weight_per_piece, p.cost_price, p.sale_price, p.stock_qty
        ORDER BY total_revenue DESC
    ";
    $stmt = $db->prepare($products_query);
    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate overall summary for product performance
    $overall_summary_query = "
        SELECT 
            SUM(s.qty) as total_items_sold,
            SUM(s.total) as total_revenue,
            SUM(s.qty * p.cost_price) as total_cost,
            SUM(s.total) - SUM(s.qty * p.cost_price) as total_gross_margin,
            CASE 
                WHEN SUM(s.total) > 0 
                THEN ROUND((SUM(s.total) - SUM(s.qty * p.cost_price)) / SUM(s.total) * 100, 2)
                ELSE 0
            END as overall_margin_percent
        FROM sales s
        JOIN products p ON s.product_id = p.id
        WHERE s.sale_date BETWEEN ? AND ?
    ";
    $overall_stmt = $db->prepare($overall_summary_query);
    $overall_stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $product_summary = $overall_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get inventory report data
if ($report_type === 'inventory') {
    $inventory_query = "
        SELECT 
            name,
            sku,
            category,
            unit_type,
            weight_per_piece,
            stock_qty,
            reorder_level,
            cost_price,
            sale_price,
            (stock_qty * cost_price) as cost_value,
            (stock_qty * sale_price) as retail_value,
            (sale_price - cost_price) as unit_margin,
            CASE 
                WHEN sale_price > 0 
                THEN ROUND((sale_price - cost_price) / sale_price * 100, 2)
                ELSE 0
            END as margin_percent,
            CASE 
                WHEN stock_qty = 0 THEN 'Out of Stock'
                WHEN stock_qty <= reorder_level THEN 'Low Stock'
                ELSE 'In Stock'
            END as stock_status
        FROM products
        ORDER BY stock_qty ASC, name
    ";
    $stmt = $db->prepare($inventory_query);
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_sales,
        SUM(s.qty) as total_items_sold,
        SUM(s.total) as total_revenue,
        AVG(s.total) as avg_sale_value,
        COUNT(DISTINCT s.product_id) as unique_products_sold
    FROM sales s
    WHERE s.sale_date BETWEEN ? AND ?
";
$stmt = $db->prepare($summary_query);
$stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Get top products with margin calculations
$top_products_query = "
    SELECT 
        p.name, 
        p.unit_type,
        SUM(s.qty) as total_sold, 
        SUM(s.total) as revenue,
        SUM(s.qty * p.cost_price) as total_cost,
        SUM(s.total) - SUM(s.qty * p.cost_price) as gross_margin,
        CASE 
            WHEN SUM(s.total) > 0 
            THEN ROUND((SUM(s.total) - SUM(s.qty * p.cost_price)) / SUM(s.total) * 100, 2)
            ELSE 0
        END as margin_percent
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.sale_date BETWEEN ? AND ?
    GROUP BY p.id, p.name, p.unit_type
    ORDER BY revenue DESC
    LIMIT 5
";
$stmt = $db->prepare($top_products_query);
$stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare chart data for sales trend
$chart_labels = [];
$chart_revenue = [];
$chart_sales = [];

if ($report_type === 'sales' && !empty($report_data)) {
    foreach ($report_data as $data) {
        $chart_labels[] = date('M j', strtotime($data['sale_day']));
        $chart_revenue[] = (float)$data['total_revenue'];
        $chart_sales[] = (int)$data['total_sales'];
    }
}
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

                <!-- Reports Content -->
                <div class="container-fluid py-4">
                    <!-- Page Header -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <h2>Reports & Analytics</h2>
                                <div>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#exportModal">
                                        <i class="bi bi-download me-1"></i>Export Report
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Report Filters -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <form method="GET" class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label">Report Type</label>
                                            <select name="report_type" class="form-select" onchange="this.form.submit()">
                                                <option value="sales" <?php echo $report_type === 'sales' ? 'selected' : ''; ?>>Sales Report</option>
                                                <option value="products" <?php echo $report_type === 'products' ? 'selected' : ''; ?>>Product Performance</option>
                                                <option value="inventory" <?php echo $report_type === 'inventory' ? 'selected' : ''; ?>>Inventory Status</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Start Date</label>
                                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" onchange="this.form.submit()">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">End Date</label>
                                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" onchange="this.form.submit()">
                                        </div>
                                        <div class="col-md-3 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary me-2">Generate</button>
                                            <a href="reports.php" class="btn btn-secondary">Reset</a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Cards -->
                    <?php if ($report_type === 'products' && isset($product_summary)): ?>
                    <!-- Product Performance Specific Summary -->
                    <div class="row mb-4">
                        <div class="col-md-2">
                            <div class="card metric-card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo number_format($product_summary['total_items_sold'] ?? 0); ?></h3>
                                    <small>Units Sold</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-info text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($product_summary['total_revenue'] ?? 0, 2); ?></h3>
                                    <small>Total Revenue</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($product_summary['total_cost'] ?? 0, 2); ?></h3>
                                    <small>Total Cost</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($product_summary['total_gross_margin'] ?? 0, 2); ?></h3>
                                    <small>Gross Margin</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo number_format($product_summary['overall_margin_percent'] ?? 0, 1); ?>%</h3>
                                    <small>Margin %</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-dark text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo count($report_data ?? []); ?></h3>
                                    <small>Products</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- General Summary for other reports -->
                    <div class="row mb-4">
                        <div class="col-md-2">
                            <div class="card metric-card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $summary['total_sales'] ?? 0; ?></h3>
                                    <small>Total Sales</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $summary['total_items_sold'] ?? 0; ?></h3>
                                    <small>Items Sold</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-info text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($summary['total_revenue'] ?? 0, 2); ?></h3>
                                    <small>Total Revenue</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-warning text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo '₱' . number_format($summary['avg_sale_value'] ?? 0, 2); ?></h3>
                                    <small>Avg Sale Value</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo $summary['unique_products_sold'] ?? 0; ?></h3>
                                    <small>Products Sold</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card metric-card bg-dark text-white">
                                <div class="card-body text-center">
                                    <h3 class="card-text"><?php echo count($report_data ?? []); ?></h3>
                                    <small>Records</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <!-- Charts and Top Products -->
                        <div class="col-md-8">
                            <?php if ($report_type === 'sales' && !empty($chart_labels)): ?>
                                <!-- Sales Trend Chart -->
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <h6 class="card-title mb-0">Sales Trend</h6>
                                    </div>
                                    <div class="card-body">
                                        <canvas id="salesChart" height="300"></canvas>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Report Data Table -->
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="card-title mb-0">
                                        <?php 
                                        echo match($report_type) {
                                            'sales' => 'Daily Sales Report',
                                            'products' => 'Product Performance',
                                            'inventory' => 'Inventory Status',
                                            default => 'Report Data'
                                        };
                                        ?>
                                    </h6>
                                    <small class="text-muted"><?php echo count($report_data ?? []); ?> records</small>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <?php if ($report_type === 'sales'): ?>
                                                        <th>Date</th>
                                                        <th>Sales Count</th>
                                                        <th>Items Sold</th>
                                                        <th>Total Revenue</th>
                                                        <th>Avg Sale</th>
                                                    <?php elseif ($report_type === 'products'): ?>
    <th>Product</th>
    <th>Category</th>
    <th>Unit Type</th>
    <th>Weight (kg)</th>
    <th>Units Sold</th>
    <th>Unit Cost</th>
    <th>Unit Price</th>
    <th>Revenue</th>
    <th>Cost Value</th>
    <th>Gross Margin</th>
    <th>Margin %</th>
    <th>Current Stock</th>
<?php elseif ($report_type === 'inventory'): ?>
    <th>Product</th>
    <th>Category</th>
    <th>Unit Type</th>
    <th>Weight (kg)</th>
    <th>Stock Qty</th>
    <th>Status</th>
    <th>Unit Cost</th>
    <th>Unit Price</th>
    <th>Unit Margin</th>
    <th>Margin %</th>
    <th>Cost Value</th>
    <th>Retail Value</th>
                                                    <?php endif; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (!empty($report_data)): ?>
                                                    <?php foreach ($report_data as $data): ?>
                                                        <tr>
                                                            <?php if ($report_type === 'sales'): ?>
                                                                <td><?php echo date('M j, Y', strtotime($data['sale_day'])); ?></td>
                                                                <td><?php echo $data['total_sales']; ?></td>
                                                                <td><?php echo $data['total_items']; ?></td>
                                                                <td><strong>₱<?php echo number_format($data['total_revenue'], 2); ?></strong></td>
                                                                <td>₱<?php echo number_format($data['avg_sale'], 2); ?></td>
                                                            <?php elseif ($report_type === 'products'): ?>
                                                                <td>
        <div><strong><?php echo htmlspecialchars($data['name']); ?></strong></div>
        <small class="text-muted"><?php echo htmlspecialchars($data['sku']); ?></small>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($data['category']); ?></td>
                                                                    <td>
        <?php if ($data['unit_type'] == 'kg'): ?>
            <span class="badge bg-info">KG</span>
        <?php else: ?>
            <span class="badge bg-secondary">Piece</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($data['unit_type'] == 'kg'): ?>
            <?php echo number_format($data['weight_per_piece'], 3); ?>
        <?php else: ?>
            -
        <?php endif; ?>
    </td>

<td>
    <span class="badge bg-primary">
        <?php 
            if ($data['unit_type'] == 'kg') {
                echo number_format($data['total_sold'], 3) . ' kg';
            } else {
                echo number_format($data['total_sold']) . ' pcs';
            }
        ?>
    </span>
</td>
                                                                <td>₱<?php echo number_format($data['cost_price'], 2); ?></td>
                                                                <td>₱<?php echo number_format($data['avg_sale_price'], 2); ?></td>

                                                                <td><strong>₱<?php echo number_format($data['total_revenue'], 2); ?></strong></td>
                                                                <td>₱<?php echo number_format($data['total_cost_value'], 2); ?></td>
                                                                
                                                                <td>
                                                                    <span class="badge bg-<?php echo $data['gross_margin'] >= 0 ? 'success' : 'danger'; ?>">
                                                                        ₱<?php echo number_format($data['gross_margin'], 2); ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-<?php 
                                                                        $margin = $data['gross_margin_percent'];
                                                                        if ($margin >= 50) echo 'success';
                                                                        elseif ($margin >= 30) echo 'info';
                                                                        elseif ($margin >= 10) echo 'warning';
                                                                        else echo 'danger';
                                                                    ?>">
                                                                        <?php echo number_format($data['gross_margin_percent'], 1); ?>%
                                                                    </span>
                                                                </td>
                                                                <td><span class="badge bg-<?php echo $data['current_stock'] > 10 ? 'success' : 'warning'; ?>"><?php echo $data['current_stock']; ?></span></td>

                                                            <?php elseif ($report_type === 'inventory'): ?>
                                                                <td>
                                                                    <div><strong><?php echo htmlspecialchars($data['name']); ?></strong></div>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($data['sku']); ?></small>
                                                                </td>
                                                                <td><?php echo htmlspecialchars($data['category']); ?></td>

    <td>
        <?php if ($data['unit_type'] == 'kg'): ?>
            <span class="badge bg-info">KG</span>
        <?php else: ?>
            <span class="badge bg-secondary">Piece</span>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($data['unit_type'] == 'kg'): ?>
            <?php echo number_format($data['weight_per_piece'], 3); ?>
        <?php else: ?>
            -
        <?php endif; ?>
    </td>

<td>
    <span class="badge bg-<?php 
        echo match($data['stock_status']) {
            'Out of Stock' => 'danger',
            'Low Stock' => 'warning',
            default => 'success'
        };
    ?>">
        <?php 
            if ($data['unit_type'] == 'kg') {
                echo number_format($data['stock_qty'], 3) . ' kg';
            } else {
                echo number_format($data['stock_qty']) . ' pcs';
            }
        ?>
    </span>
</td>
                                                                <td>
                                                                    <span class="badge bg-<?php 
                                                                        echo match($data['stock_status']) {
                                                                            'Out of Stock' => 'danger',
                                                                            'Low Stock' => 'warning',
                                                                            default => 'success'
                                                                        };
                                                                    ?>">
                                                                        <?php echo $data['stock_status']; ?>
                                                                    </span>
                                                                </td>
                                                                <td>₱<?php echo number_format($data['cost_price'], 2); ?></td>
                                                                <td>₱<?php echo number_format($data['sale_price'], 2); ?></td>
                                                                <td>₱<?php echo number_format($data['unit_margin'], 2); ?></td>
                                                                <td>
                                                                    <span class="badge bg-<?php 
                                                                        $margin = $data['margin_percent'];
                                                                        if ($margin >= 50) echo 'success';
                                                                        elseif ($margin >= 30) echo 'info';
                                                                        elseif ($margin >= 10) echo 'warning';
                                                                        else echo 'danger';
                                                                    ?>">
                                                                        <?php echo number_format($data['margin_percent'], 1); ?>%
                                                                    </span>
                                                                </td>
                                                                <td>₱<?php echo number_format($data['cost_value'], 2); ?></td>
                                                                <td><strong>₱<?php echo number_format($data['retail_value'], 2); ?></strong></td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="<?php echo $report_type === 'products' ? 12 : ($report_type === 'inventory' ? 12 : 5); ?>" class="text-center text-muted py-4">
                                                            <i class="bi bi-clipboard-x display-4 d-block mb-2"></i>
                                                            No data found for the selected criteria.
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sidebar - Top Products & Quick Stats -->
                        <div class="col-md-4">
                            <!-- Top Products -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Top Performing Products</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($top_products)): ?>
                                        <?php foreach ($top_products as $product): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-3 p-2 border rounded">
                                                <div class="flex-grow-1">
                                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">Sold: <?php echo $product['total_sold']; ?> <?php echo $product['unit_type'] == 'kg' ? 'kg' : 'pcs'; ?></small>
                                                    <br>
                                                    <small class="text-muted">Margin: <span class="badge bg-<?php 
                                                        $margin = $product['margin_percent'];
                                                        if ($margin >= 50) echo 'success';
                                                        elseif ($margin >= 30) echo 'info';
                                                        elseif ($margin >= 10) echo 'warning';
                                                        else echo 'danger';
                                                    ?>"><?php echo number_format($product['margin_percent'], 1); ?>%</span></small>
                                                </div>
                                                <div class="text-end">
                                                    <strong>₱<?php echo number_format($product['revenue'], 2); ?></strong>
                                                    <br>
                                                    <small class="text-muted">Profit: ₱<?php echo number_format($product['gross_margin'], 2); ?></small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted text-center">No sales data available</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Quick Stats -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Quick Statistics</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <small class="text-muted d-block">Date Range</small>
                                                <strong><?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <small class="text-muted d-block">Days</small>
                                                <strong><?php echo round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1; ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <small class="text-muted d-block">Daily Avg Revenue</small>
                                                <strong>₱<?php echo number_format(($summary['total_revenue'] ?? 0) / max(1, (round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1)), 2); ?></strong>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <small class="text-muted d-block">Daily Avg Sales</small>
                                                <strong><?php echo number_format(($summary['total_sales'] ?? 0) / max(1, (round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1)), 1); ?></strong>
                                            </div>
                                        </div>
                                        <?php if ($report_type === 'products' && isset($product_summary)): ?>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <small class="text-muted d-block">Avg Margin %</small>
                                                <strong><?php echo number_format($product_summary['overall_margin_percent'], 1); ?>%</strong>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <small class="text-muted d-block">Profit per Unit</small>
                                                <strong>₱<?php echo number_format(($product_summary['total_gross_margin'] ?? 0) / max(1, $product_summary['total_items_sold'] ?? 1), 2); ?></strong>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Modal -->
    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Export Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="../exports/sales_report.php" method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Report Type</label>
                            <select name="report_type" class="form-select" required>
                                <option value="sales">Sales Report</option>
                                <option value="products">Product Performance</option>
                                <option value="inventory">Inventory Status</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date Range</label>
                            <div class="row">
                                <div class="col">
                                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" required>
                                </div>
                                <div class="col">
                                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Format</label>
                            <select name="format" class="form-select" required>
                                <option value="csv">CSV</option>
                                <option value="pdf">PDF</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Export Report</button>
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

        // Sales Chart
        <?php if ($report_type === 'sales' && !empty($chart_labels)): ?>
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Daily Revenue (₱)',
                    data: <?php echo json_encode($chart_revenue); ?>,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2,
                    yAxisID: 'y'
                }, {
                    label: 'Number of Sales',
                    data: <?php echo json_encode($chart_sales); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Revenue (₱)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '₱' + value;
                            }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Number of Sales'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });
        <?php endif; ?>
    });
    </script>
</body>
</html>