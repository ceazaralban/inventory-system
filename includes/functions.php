<?php
function getProductCount($db) {
    $stmt = $db->query("SELECT COUNT(*) FROM products");
    return $stmt->fetchColumn();
}

function getLowStockCount($db) {
    $stmt = $db->query("SELECT COUNT(*) FROM products WHERE stock_qty <= reorder_level");
    return $stmt->fetchColumn();
}

function getTotalSales($db, $start_date = null, $end_date = null) {
    $query = "SELECT COALESCE(SUM(total), 0) FROM sales";
    $params = [];
    
    if ($start_date && $end_date) {
        $query .= " WHERE sale_date BETWEEN ? AND ?";
        $params[] = $start_date . ' 00:00:00';
        $params[] = $end_date . ' 23:59:59';
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function formatCurrency($amount) {
    return '$' . number_format($amount, 2);
}

function formatDate($date) {
    return date('M j, Y', strtotime($date));
}

function formatDateTime($datetime) {
    return date('M j, Y H:i', strtotime($datetime));
}

// Validation functions
function validateRequired($value) {
    return !empty(trim($value));
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validateNumber($number) {
    return is_numeric($number) && $number >= 0;
}

function generateSKU($db, $prefix = 'SKU') {
    // Generate a unique SKU
    $unique = false;
    $attempts = 0;
    $max_attempts = 10;
    
    while (!$unique && $attempts < $max_attempts) {
        $sku = $prefix . '-' . strtoupper(substr(uniqid(), -8));
        
        // Check if SKU already exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
        $stmt->execute([$sku]);
        $count = $stmt->fetchColumn();
        
        if ($count == 0) {
            $unique = true;
        }
        $attempts++;
    }
    
    return $sku;
}
?>