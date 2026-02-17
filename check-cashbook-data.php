<?php
/**
 * Quick Check - Data in cashbook
 */
require_once 'config/config.php';
require_once 'config/database.php';

echo "<h1>Quick Data Check</h1>";
echo "<style>body{font-family:Arial;padding:20px;} table{border-collapse:collapse;margin:10px 0;} th,td{border:1px solid #ddd;padding:8px;} th{background:#333;color:white;}</style>";

$db = Database::getInstance();
$today = date('Y-m-d');
$thisMonth = date('Y-m');

echo "<h2>Date Info</h2>";
echo "<p>Today: <strong>$today</strong></p>";
echo "<p>This Month: <strong>$thisMonth</strong></p>";
echo "<hr>";

// Check cashbook count
$count = $db->fetchOne("SELECT COUNT(*) as total FROM cash_book");
echo "<h2>Total Transactions in cash_book</h2>";
echo "<p style='font-size:24px;color:blue;'><strong>" . $count['total'] . " transactions</strong></p>";

if ($count['total'] == 0) {
    echo "<p style='color:red;font-size:18px;'><strong>⚠️ WARNING: No data in cash_book table!</strong></p>";
    echo "<p>Please add some transactions first to see data in dashboard.</p>";
    exit;
}

echo "<hr>";

// Today's data
echo "<h2>Today's Transactions ($today)</h2>";
$todayData = $db->fetchAll("
    SELECT transaction_type, COUNT(*) as count, SUM(amount) as total
    FROM cash_book
    WHERE transaction_date = ?
    GROUP BY transaction_type
", [$today]);

if (count($todayData) > 0) {
    echo "<table>";
    echo "<tr><th>Type</th><th>Count</th><th>Total (Rp)</th></tr>";
    $todayIncome = 0;
    $todayExpense = 0;
    foreach ($todayData as $row) {
        echo "<tr>";
        echo "<td>" . strtoupper($row['transaction_type']) . "</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>Rp " . number_format($row['total'], 0, ',', '.') . "</td>";
        echo "</tr>";
        if ($row['transaction_type'] == 'income') $todayIncome = $row['total'];
        if ($row['transaction_type'] == 'expense') $todayExpense = $row['total'];
    }
    $todayProfit = $todayIncome - $todayExpense;
    echo "<tr style='background:#f0f0f0;font-weight:bold;'>";
    echo "<td>PROFIT</td><td>-</td><td style='color:" . ($todayProfit >= 0 ? 'green' : 'red') . "'>Rp " . number_format($todayProfit, 0, ',', '.') . "</td>";
    echo "</tr>";
    echo "</table>";
} else {
    echo "<p style='color:orange;'>No transactions today</p>";
}

echo "<hr>";

// This month's data
echo "<h2>This Month's Transactions ($thisMonth)</h2>";
$monthData = $db->fetchAll("
    SELECT transaction_type, COUNT(*) as count, SUM(amount) as total
    FROM cash_book
    WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?
    GROUP BY transaction_type
", [$thisMonth]);

if (count($monthData) > 0) {
    echo "<table>";
    echo "<tr><th>Type</th><th>Count</th><th>Total (Rp)</th></tr>";
    $monthIncome = 0;
    $monthExpense = 0;
    foreach ($monthData as $row) {
        echo "<tr>";
        echo "<td>" . strtoupper($row['transaction_type']) . "</td>";
        echo "<td>{$row['count']}</td>";
        echo "<td>Rp " . number_format($row['total'], 0, ',', '.') . "</td>";
        echo "</tr>";
        if ($row['transaction_type'] == 'income') $monthIncome = $row['total'];
        if ($row['transaction_type'] == 'expense') $monthExpense = $row['total'];
    }
    $monthProfit = $monthIncome - $monthExpense;
    echo "<tr style='background:#f0f0f0;font-weight:bold;'>";
    echo "<td>PROFIT</td><td>-</td><td style='color:" . ($monthProfit >= 0 ? 'green' : 'red') . "'>Rp " . number_format($monthProfit, 0, ',', '.') . "</td>";
    echo "</tr>";
    echo "</table>";
} else {
    echo "<p style='color:orange;'>No transactions this month</p>";
}

echo "<hr>";

// Latest 10 transactions
echo "<h2>Latest 10 Transactions</h2>";
$latest = $db->fetchAll("
    SELECT transaction_date, transaction_type, description, amount
    FROM cash_book
    ORDER BY id DESC
    LIMIT 10
");

if (count($latest) > 0) {
    echo "<table>";
    echo "<tr><th>Date</th><th>Type</th><th>Description</th><th>Amount (Rp)</th></tr>";
    foreach ($latest as $row) {
        echo "<tr>";
        echo "<td>{$row['transaction_date']}</td>";
        echo "<td>" . strtoupper($row['transaction_type']) . "</td>";
        echo "<td>{$row['description']}</td>";
        echo "<td>Rp " . number_format($row['amount'], 0, ',', '.') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No transactions found</p>";
}

echo "<hr>";
echo "<p><strong>Check completed at " . date('Y-m-d H:i:s') . "</strong></p>";
echo "<p><a href='modules/owner/dashboard-dev.php'>Open Dashboard Dev</a> | <a href='index.php'>Back to Main Dashboard</a></p>";
?>
