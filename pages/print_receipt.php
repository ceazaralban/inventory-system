<?php
require_once __DIR__ . '/../includes/init.php';
redirectIfNotLoggedIn();

$database = new Database();
$db = $database->getConnection();

$receipt_no = $_GET['receipt_no'] ?? '';
if (!$receipt_no) die("Missing receipt_no");

$stmt = $db->prepare("
    SELECT s.*, p.name as product_name, p.sku, p.unit_type
    FROM sales s
    JOIN products p ON s.product_id = p.id
    WHERE s.receipt_no = ?
    ORDER BY s.id ASC
");
$stmt->execute([$receipt_no]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) die("Receipt not found.");

$is_void = (int)($rows[0]['is_void'] ?? 0);
$sale_date = $rows[0]['sale_date'];
$customer = $rows[0]['customer_name'] ?: 'Walk-in';

$grand_total = 0;
foreach ($rows as $r) $grand_total += (float)$r['total'];

$amount_paid = (float)($rows[0]['amount_paid'] ?? 0);
$change_due = (float)($rows[0]['change_due'] ?? 0);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Receipt <?php echo htmlspecialchars($receipt_no); ?></title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .wrap { max-width: 420px; margin: 0 auto; }
    h2,h3 { text-align:center; margin: 0; }
    .muted { color:#666; font-size:12px; }
    table { width:100%; border-collapse: collapse; margin-top: 10px; }
    th, td { font-size: 13px; padding: 6px 4px; border-bottom: 1px dashed #ccc; }
    th { text-align:left; }
    .right { text-align:right; }
    .totalrow td { border-bottom: none; font-weight: bold; }
    .void { color: #b00020; font-weight: bold; text-align:center; margin-top:10px; }
    @media print { button { display:none; } }
  </style>
</head>
<body>
<div class="wrap">
  <h2>INVENTORY SYSTEM</h2>
  <h3>SALES RECEIPT</h3>
  <p class="muted">
    Receipt: <strong><?php echo htmlspecialchars($receipt_no); ?></strong><br>
    Date: <?php echo htmlspecialchars($sale_date); ?><br>
    Customer: <?php echo htmlspecialchars($customer); ?>
  </p>

  <?php if ($is_void): ?>
    <div class="void">*** VOIDED ***</div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Item</th>
        <th class="right">Qty</th>
        <th class="right">Price</th>
        <th class="right">Sub</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo htmlspecialchars($r['product_name']); ?></td>
          <td class="right">
            <?php
              if (($r['unit_type'] ?? '') === 'kg') {
                $kg = (float)($r['weight_kg'] ?? 0);
                if ($kg <= 0) $kg = (float)$r['qty'];
                echo number_format($kg, 3) . ' kg';
              } else {
                echo (int)$r['qty'] . ' pcs';
              }
            ?>
          </td>
          <td class="right">₱<?php echo number_format((float)$r['price'], 2); ?></td>
          <td class="right">₱<?php echo number_format((float)$r['total'], 2); ?></td>
        </tr>
      <?php endforeach; ?>

      <tr class="totalrow">
        <td colspan="3" class="right">Grand Total</td>
        <td class="right">₱<?php echo number_format($grand_total, 2); ?></td>
      </tr>
      <tr class="totalrow">
        <td colspan="3" class="right">Paid</td>
        <td class="right">₱<?php echo number_format($amount_paid, 2); ?></td>
      </tr>
      <tr class="totalrow">
        <td colspan="3" class="right">Change</td>
        <td class="right">₱<?php echo number_format($change_due, 2); ?></td>
      </tr>
    </tbody>
  </table>

  <div style="text-align:center; margin-top: 12px;">
    <button onclick="window.print()">Print</button>
  </div>
</div>
</body>
</html>