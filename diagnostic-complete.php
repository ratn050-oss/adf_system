<?php
/**
 * COMPREHENSIVE DIAGNOSTIC - Check Everything
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

// Connect to master DB
$masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$businessMapping = [
    'narayana-hotel' => 1,
    'bens-cafe' => 2
];

$businessId = $businessMapping[ACTIVE_BUSINESS_ID] ?? 1;

echo "<h1>🔍 COMPREHENSIVE DIAGNOSTIC</h1>";
echo "<p><strong>Business:</strong> " . ACTIVE_BUSINESS_ID . " (ID: $businessId)</p>";
echo "<hr>";

// ============================================
// 1. CHECK CASH ACCOUNTS
// ============================================
echo "<h2>1️⃣ CASH ACCOUNTS (Master DB)</h2>";

$stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY id");
$stmt->execute([$businessId]);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($accounts)) {
    echo "<div style='background: #f8d7da; padding: 20px; color: #721c24;'><strong>❌ FATAL: Tidak ada cash accounts!</strong></div>";
} else {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Account Name</th><th>Account Type</th><th>Balance</th><th>Default</th><th>Status</th></tr>";
    
    $ownerCapitalIds = [];
    
    foreach ($accounts as $acc) {
        $status = '';
        $bgColor = '#fff';
        
        if ($acc['account_type'] === 'owner_capital') {
            $ownerCapitalIds[] = $acc['id'];
            $status = '✅ OWNER CAPITAL - Will be EXCLUDED';
            $bgColor = '#d4edda';
        } elseif ($acc['account_type'] === 'cash') {
            $status = '⚠️ CASH - Will be INCLUDED';
            $bgColor = '#fff3cd';
        } elseif ($acc['account_type'] === 'bank') {
            $status = '💰 BANK - Will be INCLUDED';
            $bgColor = '#d1ecf1';
        }
        
        echo "<tr style='background: $bgColor;'>";
        echo "<td><strong>{$acc['id']}</strong></td>";
        echo "<td><strong>{$acc['account_name']}</strong></td>";
        echo "<td><code>{$acc['account_type']}</code></td>";
        echo "<td>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</td>";
        echo "<td>" . ($acc['is_default_account'] ? 'YES' : 'No') . "</td>";
        echo "<td>{$status}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    if (empty($ownerCapitalIds)) {
        echo "<div style='background: #f8d7da; padding: 15px; margin: 20px 0; color: #721c24;'>";
        echo "<strong>❌ MASALAH KRITIS: Tidak ada account dengan type 'owner_capital'!</strong><br>";
        echo "Semua income akan masuk ke pendapatan hotel karena tidak ada yang di-exclude!";
        echo "</div>";
    } else {
        echo "<div style='background: #d4edda; padding: 15px; margin: 20px 0; color: #155724;'>";
        echo "<strong>✅ Owner Capital Account IDs:</strong> " . implode(', ', $ownerCapitalIds);
        echo "</div>";
    }
}

// ============================================
// 2. CHECK LAST TRANSACTIONS IN CASH_BOOK
// ============================================
echo "<hr><h2>2️⃣ LAST 5 TRANSACTIONS (cash_book - Business DB)</h2>";

$transactions = $db->fetchAll("
    SELECT id, transaction_date, description, amount, transaction_type, cash_account_id, created_at
    FROM cash_book 
    ORDER BY id DESC 
    LIMIT 5
");

if (empty($transactions)) {
    echo "<div style='background: #f8d7da; padding: 20px; color: #721c24;'><strong>❌ Tidak ada transaksi!</strong></div>";
} else {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'><th>ID</th><th>Date</th><th>Description</th><th>Amount</th><th>Type</th><th>cash_account_id</th><th>Account Name</th><th>Account Type</th><th>Will be?</th></tr>";
    
    $accountMap = [];
    foreach ($accounts as $acc) {
        $accountMap[$acc['id']] = $acc;
    }
    
    foreach ($transactions as $tx) {
        $accountId = $tx['cash_account_id'];
        $accountName = 'NULL';
        $accountType = '-';
        $willBe = '';
        $bgColor = '#fff';
        
        if ($accountId && isset($accountMap[$accountId])) {
            $account = $accountMap[$accountId];
            $accountName = $account['account_name'];
            $accountType = $account['account_type'];
            
            if ($tx['transaction_type'] === 'income') {
                if ($accountType === 'owner_capital') {
                    $willBe = '✅ EXCLUDED (correct)';
                    $bgColor = '#d4edda';
                } else {
                    $willBe = '❌ INCLUDED (wrong if modal owner)';
                    $bgColor = '#f8d7da';
                }
            } elseif ($tx['transaction_type'] === 'expense') {
                if ($accountType === 'owner_capital') {
                    $willBe = '✅ EXCLUDED (correct)';
                    $bgColor = '#d4edda';
                } else {
                    $willBe = '⚠️ INCLUDED';
                    $bgColor = '#fff3cd';
                }
            }
        } else {
            $willBe = '⚠️ NO ACCOUNT - NOT EXCLUDED';
            $bgColor = '#fff3cd';
        }
        
        echo "<tr style='background: $bgColor;'>";
        echo "<td>{$tx['id']}</td>";
        echo "<td>" . date('d M H:i', strtotime($tx['transaction_date'])) . "</td>";
        echo "<td>{$tx['description']}</td>";
        echo "<td><strong>Rp " . number_format($tx['amount'], 0, ',', '.') . "</strong></td>";
        echo "<td style='font-weight: bold;'>{$tx['transaction_type']}</td>";
        echo "<td><strong>{$accountId}</strong></td>";
        echo "<td>{$accountName}</td>";
        echo "<td><code>{$accountType}</code></td>";
        echo "<td>{$willBe}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

// ============================================
// 3. TEST EXCLUSION FILTER
// ============================================
echo "<hr><h2>3️⃣ TEST EXCLUSION FILTER</h2>";

if (!empty($ownerCapitalIds)) {
    $excludeClause = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
    
    echo "<div style='background: #e3f2fd; padding: 15px; margin: 10px 0;'>";
    echo "<strong>Exclusion Clause:</strong><br>";
    echo "<code style='background: #fff; padding: 5px; display: block; margin-top: 5px;'>" . htmlspecialchars($excludeClause) . "</code>";
    echo "</div>";
    
    // Test query
    $allIncome = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income'");
    $filteredIncome = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income'" . $excludeClause);
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Query</th><th>Result</th></tr>";
    echo "<tr><td>Total ALL income (no filter)</td><td style='font-weight: bold;'>Rp " . number_format($allIncome['total'], 0, ',', '.') . "</td></tr>";
    echo "<tr style='background: #d4edda;'><td>Total income AFTER exclusion</td><td style='font-weight: bold; color: #155724;'>Rp " . number_format($filteredIncome['total'], 0, ',', '.') . "</td></tr>";
    echo "<tr><td>Excluded amount</td><td style='font-weight: bold; color: #721c24;'>Rp " . number_format($allIncome['total'] - $filteredIncome['total'], 0, ',', '.') . "</td></tr>";
    echo "</table>";
    
    // Income by account
    echo "<h3>Income Breakdown by Account:</h3>";
    $incomeByAccount = $db->fetchAll("
        SELECT 
            cash_account_id,
            SUM(amount) as total
        FROM cash_book 
        WHERE transaction_type = 'income'
        GROUP BY cash_account_id
    ");
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Account ID</th><th>Account Name</th><th>Account Type</th><th>Total Income</th><th>Status</th></tr>";
    
    foreach ($incomeByAccount as $row) {
        $accountId = $row['cash_account_id'];
        $accountName = 'NULL';
        $accountType = '-';
        $status = '';
        $bgColor = '#fff';
        
        if ($accountId && isset($accountMap[$accountId])) {
            $account = $accountMap[$accountId];
            $accountName = $account['account_name'];
            $accountType = $account['account_type'];
            
            if ($accountType === 'owner_capital') {
                $status = '✅ EXCLUDED';
                $bgColor = '#d4edda';
            } else {
                $status = '⚠️ INCLUDED';
                $bgColor = '#fff3cd';
            }
        } else {
            $status = '⚠️ NULL - INCLUDED';
            $bgColor = '#fff3cd';
        }
        
        echo "<tr style='background: $bgColor;'>";
        echo "<td><strong>{$accountId}</strong></td>";
        echo "<td>{$accountName}</td>";
        echo "<td><code>{$accountType}</code></td>";
        echo "<td><strong>Rp " . number_format($row['total'], 0, ',', '.') . "</strong></td>";
        echo "<td>{$status}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} else {
    echo "<div style='background: #f8d7da; padding: 20px; color: #721c24;'>";
    echo "<strong>❌ Tidak ada owner_capital account - Exclusion filter tidak berfungsi!</strong>";
    echo "</div>";
}

// ============================================
// 4. CHECK DASHBOARD STATS (Today)
// ============================================
echo "<hr><h2>4️⃣ DASHBOARD STATS (TODAY)</h2>";

$today = date('Y-m-d');

if (!empty($ownerCapitalIds)) {
    $excludeClause = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
} else {
    $excludeClause = "";
}

$todayIncomeAll = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND transaction_date = ?", [$today]);
$todayIncomeFiltered = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income' AND transaction_date = ?" . $excludeClause, [$today]);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr style='background: #f0f0f0;'><th>Metric</th><th>Value</th></tr>";
echo "<tr><td>Today's ALL income</td><td style='font-weight: bold;'>Rp " . number_format($todayIncomeAll['total'], 0, ',', '.') . "</td></tr>";
echo "<tr style='background: #d4edda;'><td>Today's HOTEL income (after exclusion)</td><td style='font-weight: bold; color: #155724;'>Rp " . number_format($todayIncomeFiltered['total'], 0, ',', '.') . "</td></tr>";
echo "<tr style='background: #ffe4e6;'><td>Today's OWNER CAPITAL income (excluded)</td><td style='font-weight: bold; color: #721c24;'>Rp " . number_format($todayIncomeAll['total'] - $todayIncomeFiltered['total'], 0, ',', '.') . "</td></tr>";
echo "</table>";

// ============================================
// 5. CHECK KAS MODAL OWNER
// ============================================
echo "<hr><h2>5️⃣ KAS MODAL OWNER (This Month)</h2>";

$thisMonth = date('Y-m');

if (!empty($ownerCapitalIds)) {
    $ownerCapitalId = $ownerCapitalIds[0]; // Take first one
    
    // From cash_account_transactions
    $stmt = $masterDb->prepare("
        SELECT 
            SUM(CASE WHEN transaction_type IN ('income', 'capital_injection') THEN amount ELSE 0 END) as received,
            SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as used
        FROM cash_account_transactions
        WHERE cash_account_id = ? 
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
    ");
    $stmt->execute([$ownerCapitalId, $thisMonth]);
    $modalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f0f0f0;'><th>Metric</th><th>Value</th></tr>";
    echo "<tr style='background: #d4edda;'><td>Modal Diterima</td><td style='font-weight: bold; color: #155724;'>Rp " . number_format($modalStats['received'] ?? 0, 0, ',', '.') . "</td></tr>";
    echo "<tr style='background: #f8d7da;'><td>Modal Digunakan</td><td style='font-weight: bold; color: #721c24;'>Rp " . number_format($modalStats['used'] ?? 0, 0, ',', '.') . "</td></tr>";
    echo "<tr style='background: #d1ecf1;'><td>Saldo Modal</td><td style='font-weight: bold; color: #0c5460;'>Rp " . number_format(($modalStats['received'] ?? 0) - ($modalStats['used'] ?? 0), 0, ',', '.') . "</td></tr>";
    echo "</table>";
    
    // Check if data exists in cash_account_transactions
    $stmt = $masterDb->prepare("SELECT COUNT(*) as count FROM cash_account_transactions WHERE cash_account_id = ?");
    $stmt->execute([$ownerCapitalId]);
    $txnCount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<div style='background: #e3f2fd; padding: 15px; margin: 10px 0;'>";
    echo "<strong>Transactions in cash_account_transactions:</strong> {$txnCount['count']}<br>";
    if ($txnCount['count'] == 0) {
        echo "<span style='color: #d32f2f;'>⚠️ WARNING: No transactions found! Data tidak sync ke cash_account_transactions.</span>";
    }
    echo "</div>";
}

// ============================================
// 6. FINAL DIAGNOSIS
// ============================================
echo "<hr><h2>🎯 FINAL DIAGNOSIS</h2>";

echo "<div style='background: #fff3cd; padding: 20px; border-left: 4px solid #ffc107;'>";
echo "<h3>Problems Found:</h3>";
echo "<ol style='line-height: 2;'>";

$problemsFound = 0;

if (empty($ownerCapitalIds)) {
    echo "<li style='color: #d32f2f; font-weight: bold;'>❌ NO OWNER_CAPITAL ACCOUNT! Semua income masuk ke hotel revenue.</li>";
    $problemsFound++;
}

// Check if there are transactions to owner_capital type
$hasWrongType = false;
foreach ($transactions as $tx) {
    if ($tx['transaction_type'] === 'income' && $tx['cash_account_id']) {
        $accountId = $tx['cash_account_id'];
        if (isset($accountMap[$accountId]) && $accountMap[$accountId]['account_type'] !== 'owner_capital') {
            if (stripos($accountMap[$accountId]['account_name'], 'modal') !== false || 
                stripos($accountMap[$accountId]['account_name'], 'owner') !== false) {
                $hasWrongType = true;
                break;
            }
        }
    }
}

if ($hasWrongType) {
    echo "<li style='color: #d32f2f; font-weight: bold;'>❌ Account dengan nama 'Modal'/'Owner' tapi type BUKAN owner_capital!</li>";
    $problemsFound++;
}

if (isset($txnCount) && $txnCount['count'] == 0) {
    echo "<li style='color: #ff9800; font-weight: bold;'>⚠️ Tidak ada data di cash_account_transactions - Kas Modal Owner widget akan 0!</li>";
    $problemsFound++;
}

if ($problemsFound == 0) {
    echo "<li style='color: #2e7d32; font-weight: bold;'>✅ Tidak ada masalah ditemukan!</li>";
}

echo "</ol>";
echo "</div>";

// SOLUTIONS
if ($problemsFound > 0) {
    echo "<div style='background: #e3f2fd; padding: 20px; border-left: 4px solid #2196f3; margin-top: 20px;'>";
    echo "<h3>✅ SOLUTIONS:</h3>";
    echo "<ol style='line-height: 2;'>";
    echo "<li><strong><a href='fix-account-setup.php' style='color: #1565c0;'>Buka Fix Account Setup</a></strong> - Ubah account type jadi owner_capital</li>";
    echo "<li><strong>Input transaksi baru</strong> - Pilih account yang sudah difix</li>";
    echo "<li><strong><a href='index.php' style='color: #1565c0;'>Refresh Dashboard</a></strong> - Lihat hasilnya</li>";
    echo "</ol>";
    echo "</div>";
}

?>

<style>
    body { 
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
        padding: 30px;
        background: #f5f5f5;
        max-width: 1400px;
        margin: 0 auto;
    }
    h1, h2, h3 { color: #333; }
    table { background: white; margin: 10px 0; }
    code { 
        background: #f4f4f4; 
        padding: 2px 6px; 
        border-radius: 3px; 
    }
</style>
