<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<nav class="sidebar bg-dark" id="sidebar">
    <div class="position-sticky pt-3">
        <div class="d-flex justify-content-between align-items-center text-white mb-4 sidebar-brand">
            <div class="text-center flex-grow-1">
                <h5><i class="bi bi-box-seam me-2"></i>Inventory System</h5>
            </div>
            <!-- Hamburger button in sidebar - hidden by default, shown when collapsed -->
            <button class="btn btn-sm btn-light sidebar-toggle d-none" id="sidebarToggleInner">
                <i class="bi bi-list"></i>
            </button>
        </div>
        
        <ul class="nav flex-column">
            <?php if (canViewPage('dashboard')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                    <i class="bi bi-speedometer2 me-2"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewPage('products')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'products.php' ? 'active' : ''; ?>" href="products.php">
                    <i class="bi bi-box me-2"></i>
                    <span>Products</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewPage('inventory')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'inventory.php' ? 'active' : ''; ?>" href="inventory.php">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i>
                    <span>Inventory</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewPage('sales')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'sales.php' ? 'active' : ''; ?>" href="sales.php">
                    <i class="bi bi-cart me-2"></i>
                    <span>Sales / POS</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewPage('cash_reconciliation')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'cash_reconciliation.php' ? 'active' : ''; ?>" href="cash_reconciliation.php">
                    <i class="bi bi-cash-coin me-2"></i>
                    <span>Cash Recon</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewPage('reports')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                    <i class="bi bi-graph-up me-2"></i>
                    <span>Reports</span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (canViewPage('settings')): ?>
            <li class="nav-item">
                <a class="nav-link text-white <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
                    <i class="bi bi-gear me-2"></i>
                    <span>Settings</span>
                </a>
            </li>
            <?php endif; ?>
            <li class="nav-item mt-4">
                <a class="nav-link text-danger" href="../logout.php">
                    <i class="bi bi-box-arrow-right me-2"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>
</nav>