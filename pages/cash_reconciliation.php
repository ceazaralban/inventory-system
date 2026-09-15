<?php
require_once __DIR__ . '/../includes/init.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$page_title = "Cash Reconciliation - Inventory System";

$today = date('Y-m-d');
$selected_date = $_GET['date'] ?? $today;

// Expected cash = sum(amount_paid) - sum(change_due) for NON-VOID sales that day
$expected_stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(amount_paid),0) as total_paid,
        COALESCE(SUM(change_due),0) as total_change,
        COALESCE(SUM(amount_paid) - SUM(change_due),0) as expected_cash
    FROM sales
    WHERE DATE(sale_date) = ? AND (is_void = 0 OR is_void IS NULL)
");
$expected_stmt->execute([$selected_date]);
$expected = $expected_stmt->fetch(PDO::FETCH_ASSOC);

$existing_stmt = $db->prepare("SELECT * FROM cash_reconciliations WHERE recon_date = ?");
$existing_stmt->execute([$selected_date]);
$existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);

$logs_stmt = $db->prepare("
    SELECT *
    FROM cash_reconciliation_logs
    WHERE recon_date = ?
    ORDER BY created_at DESC
    LIMIT 50
");
$logs_stmt->execute([$selected_date]);
$logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_POST) {
    $counted_cash = (float)($_POST['counted_cash'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $expected_cash = (float)($_POST['expected_cash'] ?? 0);
    $diff = $counted_cash - $expected_cash;

// Insert log entry every time (history)
$action_type = $existing ? 'update' : 'save';

$log = $db->prepare("
    INSERT INTO cash_reconciliation_logs
    (recon_date, expected_cash, counted_cash, difference, notes, action_type, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?)
");
$log->execute([
    $selected_date,
    $expected_cash,
    $counted_cash,
    $diff,
    $notes,
    $action_type,
    $_SESSION['username']
]);

    // Refresh
    header("Location: cash_reconciliation.php?date=" . urlencode($selected_date));
    exit;
}

include '../includes/header.php';
?>
<div class="container-fluid">
  <div class="row">
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content" id="mainContent">
      <nav class="navbar navbar-light bg-white border-bottom">
        <div class="container-fluid">
          <button class="btn btn-light sidebar-toggle" id="sidebarToggleNav">
            <i class="bi bi-list"></i>
          </button>
          <div class="d-flex">
            <span class="navbar-text me-3">Welcome, <?php echo $_SESSION['username']; ?></span>
          </div>
        </div>
      </nav>

      <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h2>Cash Count Reconciliation</h2>
          <form method="GET" class="d-flex gap-2">
            <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" class="form-control">
            <button class="btn btn-primary">Load</button>
          </form>
        </div>

        <?php if (!empty($success)): ?>
          <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>

        <div class="row">
          <div class="col-md-6">
            <div class="card">
              <div class="card-header"><strong>Expected Cash (System)</strong></div>
              <div class="card-body">
                <p class="mb-1">Total Paid: <strong>₱<?php echo number_format($expected['total_paid'], 2); ?></strong></p>
                <p class="mb-1">Total Change: <strong>₱<?php echo number_format($expected['total_change'], 2); ?></strong></p>
                <p class="mb-0">Expected Cash: <strong class="text-success">₱<?php echo number_format($expected['expected_cash'], 2); ?></strong></p>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="card">
              <div class="card-header"><strong>Count Cash (Actual)</strong></div>
              <div class="card-body">
                <form method="POST">
                  <input type="hidden" name="expected_cash" value="<?php echo htmlspecialchars($expected['expected_cash']); ?>">

                  <div class="mb-3">
                    <label class="form-label">Counted Cash</label>
                    <input type="number" step="0.01" min="0" name="counted_cash"
                      class="form-control"
                      value="<?php echo htmlspecialchars($existing['counted_cash'] ?? '0'); ?>"
                      required>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control"
                      value="<?php echo htmlspecialchars($existing['notes'] ?? ''); ?>"
                      placeholder="Optional">
                  </div>

                  <button class="btn btn-success">Save Reconciliation</button>
                </form>

                <?php if ($existing): ?>
                  <hr>
                  <p class="mb-1">Last Saved Difference:</p>
                  <h5 class="<?php echo ($existing['difference'] < 0) ? 'text-danger' : 'text-success'; ?>">
                    ₱<?php echo number_format($existing['difference'], 2); ?>
                  </h5>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="card mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Reconciliation Logs</strong>
                <small class="text-muted">Last 50 updates</small>
            </div>
                <div class="card-body">
                    <?php if (!empty($logs)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                            <th>Date & Time</th>
                            <th>Expected</th>
                            <th>Counted</th>
                            <th>Difference</th>
                            <th>Action</th>
                            <th>By</th>
                            <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo date('M j, Y h:i A', strtotime($log['created_at'])); ?></td>
                                <td>₱<?php echo number_format((float)$log['expected_cash'], 2); ?></td>
                                <td>₱<?php echo number_format((float)$log['counted_cash'], 2); ?></td>
                                <td>
                                <span class="<?php echo ((float)$log['difference'] < 0) ? 'text-danger' : 'text-success'; ?>">
                                    ₱<?php echo number_format((float)$log['difference'], 2); ?>
                                </span>
                                </td>
                                <td>
                                <span class="badge bg-<?php echo ($log['action_type'] === 'update') ? 'warning' : 'success'; ?>">
                                    <?php echo htmlspecialchars(strtoupper($log['action_type'])); ?>
                                </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['created_by']); ?></td>
                                <td><?php echo htmlspecialchars($log['notes'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted mb-0">No logs for this date yet.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

      </div>
    </div>
  </div>
</div>

<script src="../assets/js/bootstrap.bundle.min.js"></script>

<script>

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


</script>

</body>
</html>