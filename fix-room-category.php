<?php
require_once 'config/database.php';

echo "<h2>🔧 Fix Category - Move to Room Sales</h2>";

try {
    // Business DB
    $db = Database::getInstance();
    
    // 1. Find Room Sales category
    echo "<h3>1️⃣ Finding Room Sales Category...</h3>";
    $roomCategory = $db->fetchOne("
        SELECT id, category_name 
        FROM categories 
        WHERE category_type = 'income' 
        AND (
            LOWER(category_name) LIKE '%room%' 
            OR LOWER(category_name) LIKE '%kamar%'
            OR LOWER(category_name) LIKE '%penjualan kamar%'
        )
        ORDER BY id ASC 
        LIMIT 1
    ");
    
    if (!$roomCategory) {
        echo "<p style='color: red;'>❌ Room Sales category NOT FOUND!</p>";
        echo "<p>Please create a category with name containing 'Room' or 'Kamar'</p>";
        exit;
    }
    
    echo "<div style='background: #d4edda; padding: 10px; margin: 10px 0;'>";
    echo "✅ Found: <strong>{$roomCategory['category_name']}</strong> (ID: {$roomCategory['id']})<br>";
    echo "</div>";
    
    // 2. Find wrong category (Food Sales or others)
    echo "<h3>2️⃣ Finding Reservation Payments in Wrong Category...</h3>";
    $wrongTransactions = $db->fetchAll("
        SELECT id, transaction_date, description, amount, category_id 
        FROM cash_book 
        WHERE description LIKE '%Pembayaran Reservasi%'
        AND category_id != ?
        ORDER BY id DESC
        LIMIT 20
    ", [$roomCategory['id']]);
    
    if (empty($wrongTransactions)) {
        echo "<p style='color: green;'>✅ No transactions to fix! All reservation payments already in correct category.</p>";
    } else {
        echo "<p>Found <strong>" . count($wrongTransactions) . "</strong> transactions in wrong category:</p>";
        echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
        echo "<tr style='background: #f4f4f4;'>";
        echo "<th>ID</th><th>Date</th><th>Description</th><th>Amount</th><th>Current Category ID</th>";
        echo "</tr>";
        
        foreach ($wrongTransactions as $tx) {
            echo "<tr>";
            echo "<td>{$tx['id']}</td>";
            echo "<td>{$tx['transaction_date']}</td>";
            echo "<td>{$tx['description']}</td>";
            echo "<td>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
            echo "<td>{$tx['category_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // 3. Update to correct category
        echo "<h3>3️⃣ Updating Category...</h3>";
        
        $updateStmt = $db->getConnection()->prepare("
            UPDATE cash_book 
            SET category_id = ? 
            WHERE description LIKE '%Pembayaran Reservasi%'
            AND category_id != ?
        ");
        $updateStmt->execute([$roomCategory['id'], $roomCategory['id']]);
        
        $affected = $updateStmt->rowCount();
        
        echo "<div style='background: #d4edda; padding: 15px; margin: 10px 0; border: 2px solid green;'>";
        echo "<h2 style='color: green;'>✅ SUCCESS!</h2>";
        echo "<p><strong>{$affected}</strong> transactions updated to category: <strong>{$roomCategory['category_name']}</strong></p>";
        echo "</div>";
    }
    
    // 4. Verify
    echo "<h3>4️⃣ Verification - Recent Reservation Payments:</h3>";
    $recent = $db->fetchAll("
        SELECT cb.id, cb.transaction_date, cb.description, cb.amount, c.category_name
        FROM cash_book cb
        LEFT JOIN categories c ON cb.category_id = c.id
        WHERE cb.description LIKE '%Pembayaran Reservasi%'
        ORDER BY cb.id DESC
        LIMIT 10
    ");
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr style='background: #d4edda;'>";
    echo "<th>ID</th><th>Date</th><th>Description</th><th>Amount</th><th>Category</th>";
    echo "</tr>";
    
    foreach ($recent as $r) {
        $highlight = (stripos($r['category_name'], 'room') !== false || stripos($r['category_name'], 'kamar') !== false) ? '#d4edda' : '#fff3cd';
        echo "<tr style='background: {$highlight};'>";
        echo "<td>{$r['id']}</td>";
        echo "<td>{$r['transaction_date']}</td>";
        echo "<td>{$r['description']}</td>";
        echo "<td>Rp " . number_format($r['amount'], 0, ',', '.') . "</td>";
        echo "<td><strong>{$r['category_name']}</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<p><a href='modules/accounting/cashbook.php'>📊 View Cashbook</a></p>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px;'>";
    echo "<h3 style='color: red;'>❌ Error:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
