<?php
$pdo = new PDO('mysql:host=localhost;dbname=adf_system', 'root', '');
$stmt = $pdo->query('SELECT id, menu_code, menu_name, menu_order, is_active FROM menu_items ORDER BY menu_order');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Menu Items in Database:\n";
echo str_repeat('=', 80) . "\n";
foreach($rows as $row) {
    echo sprintf(
        "ID: %2d | Code: %-18s | Name: %-20s | Order: %2d | Active: %d\n",
        $row['id'],
        $row['menu_code'],
        $row['menu_name'],
        $row['menu_order'],
        $row['is_active']
    );
}
echo str_repeat('=', 80) . "\n";
echo "Total: " . count($rows) . " menus\n";

// Check if Payroll exists
$payroll = $pdo->query("SELECT * FROM menu_items WHERE menu_code = 'payroll'")->fetch(PDO::FETCH_ASSOC);
if ($payroll) {
    echo "\n✅ Payroll menu FOUND in database:\n";
    print_r($payroll);
} else {
    echo "\n❌ Payroll menu NOT found in database\n";
}
?>
