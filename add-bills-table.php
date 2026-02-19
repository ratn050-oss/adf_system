<?php
/**
 * Add Investor Bills Table
 * Run this once: http://localhost:8081/adf_system/add-bills-table.php
 */

require_once 'config/config.php';
require_once 'config/database.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = Database::getInstance()->getConnection();
    
    echo "<h2>Adding investor_bills table...</h2>";
    
    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'investor_bills'");
    if ($stmt->rowCount() > 0) {
        echo "<p style='color: orange;'>⚠️ Table 'investor_bills' already exists!</p>";
    } else {
        // Create table
        $sql = file_get_contents('database-investor-bills.sql');
        $db->exec($sql);
        
        echo "<p style='color: green;'>✅ Table 'investor_bills' created successfully!</p>";
        
        // Insert sample data
        $sampleSQL = "
        INSERT INTO investor_bills (title, description, amount, category, due_date, status) VALUES
        ('Pembayaran Tanah Kavling A', 'Cicilan pertama pembayaran tanah untuk pengembangan properti', 50000000, 'land', '2026-03-15', 'unpaid'),
        ('PBB Properti 2026', 'Pajak Bumi dan Bangunan tahun 2026', 5000000, 'tax', '2026-04-30', 'unpaid'),
        ('Listrik & Air - Februari', 'Tagihan utilitas bulan Februari 2026', 2500000, 'utility', '2026-02-28', 'paid'),
        ('Notaris - Pembuatan Akta', 'Biaya pembuatan akta tanah', 3500000, 'legal', '2026-02-15', 'paid')
        ";
        
        $db->exec($sampleSQL);
        echo "<p style='color: green;'>✅ Sample bills added!</p>";
    }
    
    // Show table structure
    echo "<h3>Table Structure:</h3>";
    $stmt = $db->query("DESCRIBE investor_bills");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Count records
    $count = $db->query("SELECT COUNT(*) FROM investor_bills")->fetchColumn();
    echo "<p><strong>Total bills:</strong> {$count}</p>";
    
    echo "<p style='margin-top: 2rem;'><a href='modules/investor/' style='color: #667eea; text-decoration: none; font-weight: 600;'>→ Go to Investor Module</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
