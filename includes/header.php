<?php
// This file will be included in all pages for common header content
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Inventory System'; ?></title>
    
    <!-- Local Bootstrap CSS -->
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Local Chart.js (for reports and dashboard) -->
    <script src="../assets/js/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
    /* Common CSS for all pages */
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        background-color: #f8f9fa;
        overflow-x: hidden;
    }
    .metric-card {
        border: none;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.04);
        transition: transform 0.2s;
    }
    .metric-card:hover {
        transform: translateY(-2px);
    }
    .card {
        border-radius: 10px;
    }
    .sidebar .nav-link {
        color: rgba(255,255,255,0.85);
        padding: 0.75rem 1.25rem;
        border-radius: 4px;
        margin: 2px 8px;
        transition: all 0.2s;
    }
    .sidebar .nav-link:hover, .sidebar .nav-link.active {
        color: #fff;
        background: rgba(255,255,255,0.15);
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
    
    /* Table styles */
    .table-actions {
        white-space: nowrap;
    }

    @media print {
        .sidebar, .navbar, .btn, .table-actions, .sidebar-overlay, .modal, .sidebar-toggle {
            display: none !important;
        }
        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
            padding: 0 !important;
        }
        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
            break-inside: avoid;
        }
    }
    </style>
</head>
<body>
    <!-- Sidebar Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>