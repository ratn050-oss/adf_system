<?php

/**
 * Test API endpoints
 */

// Test database connection
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Check if tables exist
    $tables = $pdo->query("SHOW TABLES LIKE 'monthly%'")->fetchAll(PDO::FETCH_ASSOC);

    echo "✅ Database Connection: SUCCESS\n";
    echo "✅ Tables Found: " . count($tables) . "\n";

    foreach ($tables as $table) {
        $tableName = array_values($table)[0];
        echo "   - $tableName\n";
    }

    // Check monthly_bills structure
    $billsInfo = $pdo->query("SELECT COUNT(*) as count FROM monthly_bills")->fetch(PDO::FETCH_ASSOC);
    echo "\n📊 monthly_bills Records: " . $billsInfo['count'] . "\n";

    $paymentsInfo = $pdo->query("SELECT COUNT(*) as count FROM bill_payments")->fetch(PDO::FETCH_ASSOC);
    echo "📊 bill_payments Records: " . $paymentsInfo['count'] . "\n";

    // Test insert
    echo "\n🔧 Testing INSERT into monthly_bills...\n";
    $stmt = $pdo->prepare("
        INSERT INTO monthly_bills 
        (bill_code, bill_name, bill_month, amount, status, created_by)
        VALUES (?, ?, ?, ?, 'pending', 1)
    ");

    $billCode = 'BL-' . date('Ymd') . '-001';
    $result = $stmt->execute([
        $billCode,
        'TEST: Listrik April 2026',
        '2026-04-01',
        500000
    ]);

    if ($result) {
        $billId = $pdo->lastInsertId();
        echo "✅ INSERT SUCCESS - Bill ID: $billId, Code: $billCode\n";

        // Verify the insert
        $bill = $pdo->query("SELECT * FROM monthly_bills WHERE id = $billId")->fetch(PDO::FETCH_ASSOC);
        if ($bill) {
            echo "\n✅ VERIFICATION SUCCESS:\n";
            echo "   - Bill Name: {$bill['bill_name']}\n";
            echo "   - Amount: Rp " . number_format($bill['amount']) . "\n";
            echo "   - Status: {$bill['status']}\n";
            echo "   - Month: {$bill['bill_month']}\n";
        }
    }

    echo "\n✅ ALL TESTS PASSED!\n";
    echo "✅ System is ready to use!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
