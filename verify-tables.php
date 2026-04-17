<?php

/**
 * Verify table structures and check for issues
 */

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=adf_narayana_hotel;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "📋 TABLE STRUCTURE VERIFICATION\n";
    echo "================================\n\n";

    // Check monthly_bills
    echo "1️⃣  TABLE: monthly_bills\n";
    $columns = $pdo->query("DESCRIBE monthly_bills")->fetchAll(PDO::FETCH_ASSOC);
    echo "   Columns: " . count($columns) . "\n";
    foreach ($columns as $col) {
        echo "   - {$col['Field']} ({$col['Type']})\n";
    }

    // Check bill_payments
    echo "\n2️⃣  TABLE: bill_payments\n";
    $columns = $pdo->query("DESCRIBE bill_payments")->fetchAll(PDO::FETCH_ASSOC);
    echo "   Columns: " . count($columns) . "\n";
    foreach ($columns as $col) {
        echo "   - {$col['Field']} ({$col['Type']})\n";
    }

    // Check sample data
    echo "\n3️⃣  SAMPLE DATA\n";
    $bills = $pdo->query("SELECT * FROM monthly_bills LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    echo "   Monthly Bills Sample:\n";
    foreach ($bills as $bill) {
        echo "   - ID:{$bill['id']} | {$bill['bill_name']} | Rp " . number_format($bill['amount']) . " | {$bill['status']}\n";
    }

    echo "\n✅ ALL VERIFICATIONS PASSED!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
