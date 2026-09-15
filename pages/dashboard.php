<?php
require_once __DIR__ . '/../includes/init.php';
redirectIfNotLoggedIn();
requirePageAccess('dashboard');


$database = new Database();
$db = $database->getConnection();

// Get date range (default: This Week) - AUTO-DETECT BASED ON SALES DATA
$range = $_GET['range'] ?? 'this_week';
// $start_date = $end_date = '';

// Get the actual date range of your sales data
$sales_range = $db->query("SELECT MIN(DATE(sale_date)) as first_sale, MAX(DATE(sale_date)) as last_sale FROM sales")->fetch(PDO::FETCH_ASSOC);

if ($sales_range['first_sale'] && $sales_range['last_sale']) {
    $last_sale_date = $sales_range['last_sale'];
    $first_sale_date = $sales_range['first_sale'];
} else {
    // Fallback to current date if no sales data
    $last_sale_date = date('Y-m-d');
    $first_sale_date = date('Y-m-01');
}

// Set default dates
$start_date = $last_sale_date;
$end_date = $last_sale_date;

switch ($range) {
    case 'today':
        $start_date = $end_date = $last_sale_date;
        break;
    case 'this_week':
        // Calculate the week containing the last sale date
        $start_date = date('Y-m-d', strtotime('monday this week', strtotime($last_sale_date)));
        $end_date = date('Y-m-d', strtotime('sunday this week', strtotime($last_sale_date)));
        break;
    case 'this_month':
        $start_date = date('Y-m-01', strtotime($last_sale_date));
        $end_date = date('Y-m-t', strtotime($last_sale_date));
        break;
    case 'custom':
        $start_date = $_GET['start_date'] ?? $start_date;
        $end_date = $_GET['end_date'] ?? $end_date;

        if (!empty($custom_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_start)) {
            $start_date = $custom_start;
        }
        if (!empty($custom_end) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $custom_end)) {
            $end_date = $custom_end;
        }
        break;
}

// Ensure end date doesn't go beyond last sale date
if ($end_date > $last_sale_date) {
    $end_date = $last_sale_date;
}

// Ensure start_date is not after end_date
if ($start_date > $end_date) {
    $start_date = $end_date;
}

// Final safety check - ensure dates are not in future
//if (strtotime($start_date) > strtotime($current_date)) {
//    $start_date = date('Y-m-01'); // First of current month
//}
// if (strtotime($end_date) > strtotime($current_date)) {
    // $end_date = $current_date;
// }

// Debug: Check the calculated dates
error_log("Calculated date range: $start_date to $end_date");
// error_log("Current year: $current_year, Current month: $current_month");

// Get total revenue for current period - FIXED WITH VALIDATION
$current_revenue = 0;
$prev_revenue = 0;

if (!empty($start_date) && !empty($end_date) && 
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && 
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {

    // Get total revenue for current period - SIMPLIFIED AND FIXED
    $query = "SELECT COALESCE(SUM(total), 0) as total_revenue 
            FROM sales 
            WHERE DATE(sale_date) >= ? AND DATE(sale_date) <= ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$start_date, $end_date]);
    $current_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];

    // Get revenue for previous period (7 days before)
    $prev_start = date('Y-m-d', strtotime($start_date . ' -7 days'));
    $prev_end = date('Y-m-d', strtotime($end_date . ' -7 days'));

    // Debug: Check the date ranges
    error_log("Current period: $start_date to $end_date");
    error_log("Previous period: $prev_start to $prev_end");

    $stmt->execute([$prev_start, $prev_end]);
    $prev_revenue = $stmt->fetch(PDO::FETCH_ASSOC)['total_revenue'];

    }
// Debug: Check revenue values
error_log("Current revenue: $current_revenue");
error_log("Previous revenue: $prev_revenue");

// Calculate percentage change
$revenue_change = 0;
if ($prev_revenue > 0) {
    $revenue_change = (($current_revenue - $prev_revenue) / $prev_revenue) * 100;
}

// Debug: Verify we have data
$debug_sales = $db->query("SELECT COUNT(*) as count, SUM(total) as total FROM sales")->fetch(PDO::FETCH_ASSOC);
error_log("Total sales in database: " . $debug_sales['count'] . ", Total revenue: " . $debug_sales['total']);

// Get sales data for spline chart - WITH VALIDATION
$sales_data = [];
    // Prepare chart data
    $chart_labels = [];
    $chart_revenue = [];

if (!empty($start_date) && !empty($end_date) && 
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) && 
    preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {

    // Get sales data for spline chart
    $query = "SELECT DATE(sale_date) as date, SUM(total) as daily_revenue 
            FROM sales 
            WHERE sale_date BETWEEN ? AND ? 
            GROUP BY DATE(sale_date) 
            ORDER BY date";
    $stmt = $db->prepare($query);
    $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
    $sales_data = $stmt->fetchAll(PDO::FETCH_ASSOC);


    // Prepare chart data
    foreach ($sales_data as $data) {
        $chart_labels[] = date('M j', strtotime($data['date']));
        $chart_revenue[] = (float)$data['daily_revenue'];
    }
}

// Get low stock products
$query = "SELECT name, stock_qty, reorder_level FROM products 
          WHERE stock_qty <= reorder_level 
          ORDER BY stock_qty ASC 
          LIMIT 5";
$low_stock = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Get recent sales
$query = "SELECT s.sale_date, p.name, s.qty, s.total 
          FROM sales s 
          JOIN products p ON s.product_id = p.id 
          ORDER BY s.sale_date DESC 
          LIMIT 5";
$recent_sales = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Inventory System</title>
    <!-- Bootstrap Icons -->
<!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"> -->
    <!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet"> -->
    <!-- <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"> -->
    <!-- <script src="https://cdn.jsdelivr.net/npm/chart.js"></script> -->
     
<!-- Local Bootstrap Icons CSS -->
<link href="../assets/css/bootstrap-icons.css" rel="stylesheet">
        <!-- Local Bootstrap CSS -->
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Local Font Awesome CSS -->
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet">
    
    <!-- Local Chart.js -->
    <script src="../assets/js/chart.js"></script>
<style>
    body {
        overflow-x: hidden;
    }
    .sidebar {
        width: 250px;
        min-height: 100vh;
        position: fixed;
        left: 0;
        top: 0;
        box-shadow: 0 0 10px rgba(179, 178, 178, 0.1);
        transition: all 0.3s ease;
        z-index: 1000;
        overflow-x: hidden;
    }
    .sidebar.collapsed {
        width: 60px;
    }
    .sidebar.collapsed .nav-link span {
        display: none;
    }
    .sidebar.collapsed .sidebar-brand h5 {
        display: none;
    }
    .sidebar.collapsed .sidebar-brand i {
        font-size: 1.5rem;
        margin-right: 0;
    }
    .sidebar:not(.collapsed) .sidebar-brand i {
        display: none;
    }
    
    /* Sidebar hamburger button styles */
    .sidebar-toggle {
        display: none;
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 1001;
    }
    .sidebar.collapsed .sidebar-toggle {
        display: block;
    }
    
    .main-content {
        margin-left: 250px;
        transition: all 0.3s ease;
        width: calc(100% - 250px);
        min-height: 100vh;
    }
    .main-content.expanded {
        margin-left: 60px;
        width: calc(100% - 60px);
    }
    
    /* Top navbar hamburger button - hidden when sidebar is collapsed */
    .navbar .sidebar-toggle {
        display: block;
    }
    .main-content.expanded .navbar .sidebar-toggle {
        display: none;
    }
    
    @media (max-width: 768px) {
        .sidebar {
            width: 60px;
        }
        .sidebar .nav-link span {
            display: none;
        }
        .sidebar .sidebar-brand h5 {
            display: none;
        }
        .sidebar .sidebar-brand i {
            font-size: 1.5rem;
            margin-right: 0;
        }
        .main-content {
            margin-left: 60px;
            width: calc(100% - 60px);
        }
        .sidebar:not(.collapsed) {
            width: 250px;
        }
        .sidebar:not(.collapsed) .nav-link span {
            display: inline;
        }
        .sidebar:not(.collapsed) .sidebar-brand h5 {
            display: block;
        }
        
        /* Mobile: always show hamburger in navbar */
        .navbar .sidebar-toggle {
            display: block !important;
        }
    }
    .metric-card {
        border-radius: 10px;
        transition: transform 0.2s;
    }
    .metric-card:hover {
        transform: translateY(-2px);
    }
    .navbar {
        position: sticky;
        top: 0;
        z-index: 999;
    }
    .nav-link {
        transition: all 0.2s;
    }
    .nav-link:hover {
        background-color: rgba(255,255,255,0.1);
    }
    
    /* Add overlay for mobile */
    .sidebar-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 999;
    }
    @media (max-width: 768px) {
        .sidebar:not(.collapsed) + .sidebar-overlay {
            display: block;
        }
    }
</style>
</head>
<body>
    <!-- Sidebar Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Main Content -->
            <div class="main-content" id="mainContent">
                <!-- Header -->
                <nav class="navbar navbar-light bg-white border-bottom">
                    <div class="container-fluid">
                        <!-- Hamburger button in navbar - hidden when sidebar is collapsed -->
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

<!-- Replace the entire debug section with this fixed version -->
<!-- <div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">Debug Information</h6>
            </div>
            <div class="card-body">
                <?php
                // Check current period sales
                $current_sales_check = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(sale_date) BETWEEN '$start_date' AND '$end_date'")->fetch(PDO::FETCH_ASSOC);
                
                // Check previous period sales  
                $prev_sales_check = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(sale_date) BETWEEN '$prev_start' AND '$prev_end'")->fetch(PDO::FETCH_ASSOC);
                
                // Check all sales
                $all_sales_check = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as total FROM sales")->fetch(PDO::FETCH_ASSOC);
                ?>
                <p><strong>Current Period (<?php echo $start_date; ?> to <?php echo $end_date; ?>):</strong></p>
                <p>Sales Count: <?php echo $current_sales_check['count']; ?>, Revenue: ₱<?php echo number_format($current_sales_check['total'], 2); ?></p>
                
                <p><strong>Previous Period (<?php echo $prev_start; ?> to <?php echo $prev_end; ?>):</strong></p>
                <p>Sales Count: <?php echo $prev_sales_check['count']; ?>, Revenue: ₱<?php echo number_format($prev_sales_check['total'], 2); ?></p>
                
                <p><strong>All Time Sales:</strong></p>
                <p>Total Sales: <?php echo $all_sales_check['count']; ?>, Total Revenue: ₱<?php echo number_format($all_sales_check['total'], 2); ?></p>
                
                <p><strong>Calculated Values:</strong></p>
                <p>Current Revenue: ₱<?php echo number_format($current_revenue, 2); ?></p>
                <p>Previous Revenue: ₱<?php echo number_format($prev_revenue, 2); ?></p>
                <p>Revenue Change: <?php echo number_format($revenue_change, 1); ?>%</p>
            </div>
        </div>
    </div>
</div> -->

                                        <!-- Date Range Debug - Remove this after fixing -->
                    <!-- <div class="row mb-2">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <strong>Debug Date Information:</strong><br>
                                Current Server Date: <?php echo date('Y-m-d'); ?><br>
                                Calculated Start Date: <?php echo $start_date; ?><br>
                                Calculated End Date: <?php echo $end_date; ?><br>
                                Date Range Setting: <?php echo $range; ?>
                            </div>
                        </div>
                    </div> -->

                <!-- Dashboard Content -->
                <div class="container-fluid py-4">
                    <!-- Date Range Selector -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <form method="GET" class="row g-3 align-items-center">
                                        <div class="col-auto">
                                            <label class="form-label">Date Range:</label>
                                        </div>
                                        <div class="col-auto">
                                            <select name="range" class="form-select" onchange="this.form.submit()">
                                                <option value="today" <?php echo $range == 'today' ? 'selected' : ''; ?>>Today</option>
                                                <option value="this_week" <?php echo $range == 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                                <option value="this_month" <?php echo $range == 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                                <option value="custom">Custom Range</option>
                                            </select>
                                        </div>
                                        <?php if ($range == 'custom'): ?>
                                        <div class="col-auto">
                                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                                        </div>
                                        <div class="col-auto">
                                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-primary">Apply</button>
                                        </div>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Metrics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card metric-card bg-primary text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Total Revenue</h6>
                                            <h3 class="card-text">₱<?php echo number_format($current_revenue, 2); ?></h3>
                                            <small>
                                                <?php if ($revenue_change >= 0): ?>
                                                    <i class="bi bi-arrow-up"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-arrow-down"></i>
                                                <?php endif; ?>
                                                <?php echo number_format(abs($revenue_change), 1); ?>% vs previous 7 days
                                            </small>
                                        </div>
                                        <div class="align-self-center">
                                            <!-- <i class="bi bi-currency-dollar fa-2x"></i> -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card metric-card bg-success text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Total Products</h6>
                                            <?php
                                            $total_products = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
                                            ?>
                                            <h3 class="card-text"><?php echo $total_products; ?></h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="bi bi-box fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card metric-card bg-warning text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">Low Stock Items</h6>
                                            <?php
                                            $low_stock_count = $db->query("SELECT COUNT(*) FROM products WHERE stock_qty <= reorder_level")->fetchColumn();
                                            ?>
                                            <h3 class="card-text"><?php echo $low_stock_count; ?></h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="bi bi-exclamation-triangle fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="card metric-card bg-info text-white">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="card-title">This Month Sales</h6>
                                            <?php
                                            $month_start = date('Y-m-01', strtotime($last_sale_date ?? 'now'));
                                            $month_end = date('Y-m-t', strtotime($last_sale_date ?? 'now'));
                                            $month_sales = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) BETWEEN '$month_start' AND '$month_end'")->fetchColumn();
                                            ?>
                                            <h3 class="card-text"><?php echo $month_sales; ?></h3>
                                        </div>
                                        <div class="align-self-center">
                                            <i class="bi bi-cart fa-2x"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts and Tables -->
                    <div class="row">
                        <!-- Sales Chart -->
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">Sales Over Time</h5>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#exportModal">
                                        <i class="bi bi-download me-1"></i>Generate Report
                                    </button>
                                </div>
                                <div class="card-body">
                                    <canvas id="salesChart" height="300"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Side Panels -->
                        <div class="col-md-4">
                            <!-- Low Stock Alert -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Low Stock Alert</h6>
                                </div>
                                <div class="card-body">
                                    <?php if (count($low_stock) > 0): ?>
                                        <?php foreach ($low_stock as $product): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 alert alert-warning">
                                                <div>
                                                    <strong><?php echo $product['name']; ?></strong>
                                                    <br>
                                                    <small>Stock: <?php echo $product['stock_qty']; ?> (Min: <?php echo $product['reorder_level']; ?>)</small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted">No low stock items</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Recent Sales -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">Recent Sales</h6>
                                </div>
                                <div class="card-body">
                                    <?php foreach ($recent_sales as $sale): ?>
                                        <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                            <div>
                                                <strong><?php echo $sale['name']; ?></strong>
                                                <br>
                                                <small><?php echo date('M j, H:i', strtotime($sale['sale_date'])); ?></small>
                                            </div>
                                            <span class="badge bg-success">₱<?php echo number_format($sale['total'], 2); ?></span>
                                        </div>
                                    <?php endforeach; ?>
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
                <h5 class="modal-title">Generate Report</h5>
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
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script> -->

    <!-- Local Bootstrap JS -->
<script src="../assets/js/bootstrap.bundle.min.js"></script>

<script>
// Sidebar Toggle with enhanced functionality
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
                borderWidth: 2
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
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            return `₱${context.parsed.y.toFixed(2)}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + value;
                        }
                    }
                }
            }
        }
    });
});
</script>
</body>
</html>