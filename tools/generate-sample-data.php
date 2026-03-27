<?php
/**
 * NARAYANA HOTEL MANAGEMENT SYSTEM
 * Insert Sample Transactions for Testing
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance();

// Clear existing data
$db->query("DELETE FROM cash_book");

// Sample transactions for last 7 days
$sampleData = [
    // Today
    ['date' => date('Y-m-d'), 'division' => 1, 'category' => 1, 'type' => 'income', 'amount' => 2500000],
    ['date' => date('Y-m-d'), 'division' => 2, 'category' => 5, 'type' => 'income', 'amount' => 1800000],
    ['date' => date('Y-m-d'), 'division' => 7, 'category' => 18, 'type' => 'expense', 'amount' => 750000],
    ['date' => date('Y-m-d'), 'division' => 10, 'category' => 30, 'type' => 'expense', 'amount' => 5000000],
    
    // Yesterday
    ['date' => date('Y-m-d', strtotime('-1 day')), 'division' => 1, 'category' => 2, 'type' => 'income', 'amount' => 3200000],
    ['date' => date('Y-m-d', strtotime('-1 day')), 'division' => 3, 'category' => 8, 'type' => 'income', 'amount' => 450000],
    ['date' => date('Y-m-d', strtotime('-1 day')), 'division' => 8, 'category' => 21, 'type' => 'expense', 'amount' => 1200000],
    
    // 2 days ago
    ['date' => date('Y-m-d', strtotime('-2 days')), 'division' => 1, 'category' => 1, 'type' => 'income', 'amount' => 2800000],
    ['date' => date('Y-m-d', strtotime('-2 days')), 'division' => 4, 'category' => 10, 'type' => 'income', 'amount' => 1500000],
    ['date' => date('Y-m-d', strtotime('-2 days')), 'division' => 9, 'category' => 24, 'type' => 'expense', 'amount' => 850000],
    
    // 3 days ago
    ['date' => date('Y-m-d', strtotime('-3 days')), 'division' => 2, 'category' => 6, 'type' => 'income', 'amount' => 2200000],
    ['date' => date('Y-m-d', strtotime('-3 days')), 'division' => 5, 'category' => 12, 'type' => 'income', 'amount' => 380000],
    ['date' => date('Y-m-d', strtotime('-3 days')), 'division' => 10, 'category' => 31, 'type' => 'expense', 'amount' => 950000],
    
    // 4 days ago
    ['date' => date('Y-m-d', strtotime('-4 days')), 'division' => 1, 'category' => 3, 'type' => 'income', 'amount' => 4500000],
    ['date' => date('Y-m-d', strtotime('-4 days')), 'division' => 6, 'category' => 14, 'type' => 'income', 'amount' => 650000],
    ['date' => date('Y-m-d', strtotime('-4 days')), 'division' => 7, 'category' => 19, 'type' => 'expense', 'amount' => 420000],
    
    // 5 days ago
    ['date' => date('Y-m-d', strtotime('-5 days')), 'division' => 2, 'category' => 5, 'type' => 'income', 'amount' => 1950000],
    ['date' => date('Y-m-d', strtotime('-5 days')), 'division' => 3, 'category' => 9, 'type' => 'income', 'amount' => 780000],
    ['date' => date('Y-m-d', strtotime('-5 days')), 'division' => 9, 'category' => 26, 'type' => 'expense', 'amount' => 1100000],
    
    // 6 days ago
    ['date' => date('Y-m-d', strtotime('-6 days')), 'division' => 1, 'category' => 1, 'type' => 'income', 'amount' => 3100000],
    ['date' => date('Y-m-d', strtotime('-6 days')), 'division' => 4, 'category' => 11, 'type' => 'income', 'amount' => 2400000],
    ['date' => date('Y-m-d', strtotime('-6 days')), 'division' => 10, 'category' => 32, 'type' => 'expense', 'amount' => 680000],
];

$success = 0;
foreach ($sampleData as $data) {
    $insertData = [
        'transaction_date' => $data['date'],
        'transaction_time' => '14:' . rand(10, 59) . ':00',
        'division_id' => $data['division'],
        'category_id' => $data['category'],
        'transaction_type' => $data['type'],
        'amount' => $data['amount'],
        'description' => 'Sample transaction - ' . ucfirst($data['type']),
        'payment_method' => 'cash',
        'created_by' => 1
    ];
    
    if ($db->insert('cash_book', $insertData)) {
        $success++;
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Sample Data Generated</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .box {
            background: white;
            color: #333;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
        }
        .success { color: #10b981; font-size: 4rem; }
        h1 { color: #667eea; margin: 20px 0; }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 40px;
            text-decoration: none;
            border-radius: 50px;
            margin: 10px;
            font-weight: bold;
        }
        .stats {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="box">
        <div class="success">✅</div>
        <h1>Sample Data Berhasil Dibuat!</h1>
        
        <div class="stats">
            <h3>📊 Data yang Ditambahkan:</h3>
            <p><strong><?php echo $success; ?></strong> transaksi sample</p>
            <p>Periode: 7 hari terakhir</p>
            <p>Berbagai divisi & kategori</p>
        </div>
        
        <a href="../index.php" class="btn">🏠 Lihat Dashboard</a>
        <a href="../modules/cashbook/index.php" class="btn">📊 Buku Kas</a>
        
        <p style="margin-top: 20px; font-size: 0.9rem; color: #999;">
            Grafik sekarang sudah muncul! 🎉
        </p>
    </div>
</body>
</html>
