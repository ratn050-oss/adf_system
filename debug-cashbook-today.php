<?php
/**
 * Debug: Cash Book Today Revenue
 * Check what's in cash_book for today
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();
$today = date('Y-m-d');

echo "<h2>Debug Cash Book - Hari Ini ($today)</h2>";
echo "<p>Database: " . Database::getCurrentDatabase() . "</p>";

// Get ALL cash_book entries for today
$records = $db->fetchAll("
    SELECT id, transaction_date, transaction_time, description, transaction_type, amount, payment_method, created_at
    FROM cash_book
    WHERE transaction_date = ?
    ORDER BY created_at DESC
", [$today]);

echo "<h3>Semua transaksi cash_book hari ini:</h3>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Tanggal</th><th>Waktu</th><th>Deskripsi</th><th>Tipe</th><th>Amount</th><th>Payment Method</th><th>Created At</th></tr>";

$totalIncome = 0;
$totalOTA = 0;

foreach ($records as $row) {
    $isIncome = $row['transaction_type'] === 'income';
    $isOTA = in_array(strtolower($row['payment_method'] ?? ''), ['ota', 'agoda', 'booking']);
    
    if ($isIncome) {
        $totalIncome += $row['amount'];
        if ($isOTA) {
            $totalOTA += $row['amount'];
        }
    }
    
    $bgColor = $isIncome ? '#e8f5e9' : '#ffebee';
    echo "<tr style='background: $bgColor;'>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['transaction_date']}</td>";
    echo "<td>{$row['transaction_time']}</td>";
    echo "<td>" . htmlspecialchars(substr($row['description'] ?? '', 0, 60)) . "</td>";
    echo "<td>{$row['transaction_type']}</td>";
    echo "<td style='text-align: right;'>Rp " . number_format($row['amount'], 0, ',', '.') . "</td>";
    echo "<td>{$row['payment_method']}</td>";
    echo "<td>{$row['created_at']}</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Summary:</h3>";
echo "<p><strong>Total Income hari ini:</strong> Rp " . number_format($totalIncome, 0, ',', '.') . "</p>";
echo "<p><strong>Total OTA Income:</strong> Rp " . number_format($totalOTA, 0, ',', '.') . "</p>";
echo "<p><strong>Jumlah record:</strong> " . count($records) . "</p>";

// Also check what query Dashboard uses
echo "<hr><h3>Query Dashboard:</h3>";
$dashboardRevenue = $db->fetchOne("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM cash_book
    WHERE transaction_type = 'income'
    AND transaction_date = ?
", [$today]);
echo "<p><strong>Dashboard Actual Revenue Query:</strong> Rp " . number_format($dashboardRevenue['total'], 0, ',', '.') . "</p>";

$dashboardOTA = $db->fetchOne("
    SELECT COALESCE(SUM(amount), 0) as total
    FROM cash_book
    WHERE transaction_type = 'income'
    AND transaction_date = ?
    AND (LOWER(payment_method) = 'ota' OR LOWER(payment_method) = 'agoda' OR LOWER(payment_method) = 'booking')
", [$today]);
echo "<p><strong>Dashboard OTA Revenue Query:</strong> Rp " . number_format($dashboardOTA['total'], 0, ',', '.') . "</p>";
