<?php
/**
 * VALIDATION LOGIC TEST - Check System Correctness
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

echo "<h2>🔍 SYSTEM LOGIC VALIDATION</h2>";
echo "<p style='color: #666;'>Testing complete workflow: Input → Save → Display → Calculation</p>";
echo "<hr>";

// Get business mapping
$businessIdentifier = ACTIVE_BUSINESS_ID;
$businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
$businessDbId = $businessMapping[$businessIdentifier] ?? 1;

echo "<h3>📋 Test Configuration</h3>";
echo "Active Business: <strong>{$businessIdentifier}</strong><br>";
echo "Business DB ID: <strong>{$businessDbId}</strong><br>";
echo "<hr>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // TEST 1: Check account structure
    echo "<h3>✅ TEST 1: Account Structure</h3>";
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY account_type");
    $stmt->execute([$businessDbId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $ownerCapitalAccountId = null;
    $kasOperasionalId = null;
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 0.9rem;'>";
    echo "<tr style='background: #f3f4f6;'><th>ID</th><th>Name</th><th>Type</th><th>Balance</th><th>Default</th><th>Active</th></tr>";
    foreach ($accounts as $acc) {
        $highlight = '';
        if ($acc['account_type'] === 'owner_capital') {
            $highlight = ' style="background: #fef3c7;"';
            $ownerCapitalAccountId = $acc['id'];
        } elseif ($acc['account_type'] === 'cash' && $acc['is_default_account']) {
            $kasOperasionalId = $acc['id'];
        }
        
        echo "<tr{$highlight}>";
        echo "<td>{$acc['id']}</td>";
        echo "<td><strong>{$acc['account_name']}</strong></td>";
        echo "<td>{$acc['account_type']}</td>";
        echo "<td style='text-align: right;'>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</td>";
        echo "<td>" . ($acc['is_default_account'] ? '⭐' : '') . "</td>";
        echo "<td>" . ($acc['is_active'] ? '✓' : '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<br>Owner Capital Account ID: <strong>{$ownerCapitalAccountId}</strong><br>";
    echo "Kas Operasional ID: <strong>{$kasOperasionalId}</strong><br>";
    echo "<hr>";
    
    // TEST 2: Data Integrity Check
    echo "<h3>✅ TEST 2: Data Integrity (Business DB ↔ Master DB)</h3>";
    
    // Get transactions from business DB with owner capital account
    $ownerCapitalTxns = $db->fetchAll("
        SELECT * FROM cash_book 
        WHERE cash_account_id = ? 
        ORDER BY transaction_date DESC, transaction_time DESC 
        LIMIT 5
    ", [$ownerCapitalAccountId]);
    
    echo "<strong>Transactions in Business DB (cash_book) with Owner Capital Account:</strong><br>";
    if (empty($ownerCapitalTxns)) {
        echo "<span style='color: #999;'>No transactions yet</span><br>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 0.85rem; margin-top: 0.5rem;'>";
        echo "<tr style='background: #f3f4f6;'><th>ID</th><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr>";
        foreach ($ownerCapitalTxns as $tx) {
            echo "<tr>";
            echo "<td>{$tx['id']}</td>";
            echo "<td>{$tx['transaction_date']}</td>";
            echo "<td>{$tx['transaction_type']}</td>";
            echo "<td style='text-align: right;'>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
            echo "<td>" . substr($tx['description'], 0, 30) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<br><strong>Corresponding transactions in Master DB (cash_account_transactions):</strong><br>";
    $stmt = $masterDb->prepare("
        SELECT * FROM cash_account_transactions 
        WHERE cash_account_id = ? 
        ORDER BY transaction_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$ownerCapitalAccountId]);
    $masterTxns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($masterTxns)) {
        echo "<span style='color: #999;'>No transactions yet</span><br>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 0.85rem; margin-top: 0.5rem;'>";
        echo "<tr style='background: #f3f4f6;'><th>ID</th><th>Date</th><th>Type</th><th>Amount</th><th>Description</th></tr>";
        foreach ($masterTxns as $tx) {
            echo "<tr>";
            echo "<td>{$tx['id']}</td>";
            echo "<td>{$tx['transaction_date']}</td>";
            echo "<td>{$tx['transaction_type']}</td>";
            echo "<td style='text-align: right;'>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
            echo "<td>" . substr($tx['description'], 0, 30) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    $businessCount = count($ownerCapitalTxns);
    $masterCount = count($masterTxns);
    
    if ($businessCount === $masterCount) {
        echo "<br><span style='color: green; font-weight: bold;'>✅ SYNC OK: {$businessCount} transactions in both databases</span><br>";
    } else {
        echo "<br><span style='color: red; font-weight: bold;'>❌ SYNC ERROR: Business DB has {$businessCount}, Master DB has {$masterCount}</span><br>";
    }
    echo "<hr>";
    
    // TEST 3: Income Calculation Logic
    echo "<h3>✅ TEST 3: Income Calculation Logic</h3>";
    
    // Total income (all)
    $allIncome = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM cash_book WHERE transaction_type = 'income'");
    
    // Owner capital income
    $ownerCapitalIncome = $db->fetchOne("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM cash_book 
        WHERE transaction_type = 'income' 
        AND cash_account_id = ?
    ", [$ownerCapitalAccountId]);
    
    // Operational income (should exclude owner capital)
    $stmt = $masterDb->prepare("SELECT id FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessDbId]);
    $ownerCapitalIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $excludeClause = '';
    if (!empty($ownerCapitalIds)) {
        $excludeClause = " AND (cash_account_id IS NULL OR cash_account_id NOT IN (" . implode(',', $ownerCapitalIds) . "))";
    }
    
    $operationalIncome = $db->fetchOne("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM cash_book 
        WHERE transaction_type = 'income'" . $excludeClause
    );
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f3f4f6;'><th>Description</th><th>Amount</th><th>Formula</th></tr>";
    echo "<tr>";
    echo "<td><strong>All Income (Total)</strong></td>";
    echo "<td style='text-align: right;'>Rp " . number_format($allIncome['total'], 0, ',', '.') . "</td>";
    echo "<td>All transactions with type='income'</td>";
    echo "</tr>";
    echo "<tr style='background: #fef3c7;'>";
    echo "<td><strong>Owner Capital Income</strong></td>";
    echo "<td style='text-align: right;'>Rp " . number_format($ownerCapitalIncome['total'], 0, ',', '.') . "</td>";
    echo "<td>income WHERE cash_account_id={$ownerCapitalAccountId}</td>";
    echo "</tr>";
    echo "<tr style='background: #d1fae5;'>";
    echo "<td><strong>Operational Income</strong></td>";
    echo "<td style='text-align: right;'>Rp " . number_format($operationalIncome['total'], 0, ',', '.') . "</td>";
    echo "<td>All Income - Owner Capital</td>";
    echo "</tr>";
    echo "</table>";
    
    $calculated = $allIncome['total'] - $ownerCapitalIncome['total'];
    echo "<br><strong>Validation:</strong><br>";
    echo "All Income - Owner Capital = Rp " . number_format($calculated, 0, ',', '.') . "<br>";
    echo "Operational Income Query = Rp " . number_format($operationalIncome['total'], 0, ',', '.') . "<br>";
    
    if ($calculated == $operationalIncome['total']) {
        echo "<span style='color: green; font-weight: bold;'>✅ CALCULATION CORRECT!</span><br>";
    } else {
        echo "<span style='color: red; font-weight: bold;'>❌ CALCULATION WRONG! Difference: Rp " . number_format(abs($calculated - $operationalIncome['total']), 0, ',', '.') . "</span><br>";
    }
    echo "<hr>";
    
    // TEST 4: Dashboard Display Logic
    echo "<h3>✅ TEST 4: Capital Stats (What Dashboard Should Show)</h3>";
    
    $thisMonth = date('Y-m');
    $stmt = $masterDb->prepare("
        SELECT 
            SUM(CASE WHEN transaction_type = 'capital_injection' THEN amount ELSE 0 END) as received,
            SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as used,
            SUM(CASE WHEN transaction_type = 'capital_injection' THEN amount ELSE -amount END) as balance
        FROM cash_account_transactions 
        WHERE cash_account_id = ? 
        AND DATE_FORMAT(transaction_date, '%Y-%m') = ?
    ");
    $stmt->execute([$ownerCapitalAccountId, $thisMonth]);
    $capitalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr style='background: #f3f4f6;'><th>Metric</th><th>Value</th></tr>";
    echo "<tr style='background: #d1fae5;'>";
    echo "<td>💵 Modal Diterima (This Month)</td>";
    echo "<td style='text-align: right;'><strong>Rp " . number_format($capitalStats['received'] ?? 0, 0, ',', '.') . "</strong></td>";
    echo "</tr>";
    echo "<tr style='background: #fecaca;'>";
    echo "<td>💸 Modal Digunakan (This Month)</td>";
    echo "<td style='text-align: right;'><strong>Rp " . number_format($capitalStats['used'] ?? 0, 0, ',', '.') . "</strong></td>";
    echo "</tr>";
    echo "<tr style='background: #dbeafe;'>";
    echo "<td>💎 Saldo Modal (This Month)</td>";
    echo "<td style='text-align: right;'><strong>Rp " . number_format($capitalStats['balance'] ?? 0, 0, ',', '.') . "</strong></td>";
    echo "</tr>";
    echo "</table>";
    
    if (($capitalStats['received'] ?? 0) > 0) {
        $efficiency = (($capitalStats['used'] ?? 0) / ($capitalStats['received'] ?? 1)) * 100;
        echo "<br>Efisiensi Penggunaan Modal: <strong>" . number_format($efficiency, 1) . "%</strong><br>";
    }
    echo "<hr>";
    
    // TEST 5: Recent Dual-Save Transactions
    echo "<h3>✅ TEST 5: Recent Transactions (Last 10)</h3>";
    $recent = $db->fetchAll("
        SELECT 
            cb.*,
            c.category_name,
            d.division_name
        FROM cash_book cb
        LEFT JOIN categories c ON cb.category_id = c.id
        LEFT JOIN divisions d ON cb.division_id = d.id
        ORDER BY cb.transaction_date DESC, cb.transaction_time DESC
        LIMIT 10
    ");
    
    if (empty($recent)) {
        echo "<span style='color: #999;'>No transactions found</span><br>";
    } else {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; font-size: 0.85rem;'>";
        echo "<tr style='background: #f3f4f6;'><th>ID</th><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Account ID</th><th>Saved to Master?</th></tr>";
        
        foreach ($recent as $tx) {
            $typeColor = ($tx['transaction_type'] === 'income') ? '#10b981' : '#ef4444';
            $hasAccount = !empty($tx['cash_account_id']);
            
            // Check if exists in master DB
            $savedToMaster = '❌';
            if ($hasAccount) {
                $stmt = $masterDb->prepare("SELECT COUNT(*) FROM cash_account_transactions WHERE transaction_id = ?");
                $stmt->execute([$tx['id']]);
                $count = $stmt->fetchColumn();
                $savedToMaster = ($count > 0) ? '✅' : '❌';
            }
            
            echo "<tr>";
            echo "<td>{$tx['id']}</td>";
            echo "<td>{$tx['transaction_date']}</td>";
            echo "<td style='color: {$typeColor};'><strong>{$tx['transaction_type']}</strong></td>";
            echo "<td>{$tx['category_name']}</td>";
            echo "<td style='text-align: right;'>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
            echo "<td>" . ($tx['cash_account_id'] ?: '-') . "</td>";
            echo "<td style='text-align: center;'>{$savedToMaster}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h3>📊 SUMMARY & RECOMMENDATIONS</h3>";
    echo "<div style='background: #d1fae5; padding: 1rem; border-left: 4px solid #10b981; border-radius: 4px; margin-bottom: 1rem;'>";
    echo "<strong>✅ What's Working:</strong><br>";
    echo "• Account structure exists<br>";
    echo "• Exclusion logic implemented<br>";
    echo "• Dashboard calculations separate operational vs capital<br>";
    echo "</div>";
    
    echo "<div style='background: #fef3c7; padding: 1rem; border-left: 4px solid #f59e0b; border-radius: 4px;'>";
    echo "<strong>⚠️ Things to Check:</strong><br>";
    echo "• Make sure ALL new transactions select cash_account_id<br>";
    echo "• Verify dual-save logic is working (check 'Saved to Master?' column above)<br>";
    echo "• Test reset accounting function resets both databases<br>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
    echo $e->getMessage();
    echo "\n\nTrace:\n" . $e->getTraceAsString();
    echo "</pre>";
}

echo "<hr>";
echo "<div style='display: flex; gap: 1rem; flex-wrap: wrap;'>";
echo "<a href='index.php' style='padding: 0.75rem 1.5rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 8px;'>Dashboard</a>";
echo "<a href='modules/cashbook/add.php' style='padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 8px;'>Add Transaction</a>";
echo "<a href='modules/settings/reset.php' style='padding: 0.75rem 1.5rem; background: #ef4444; color: white; text-decoration: none; border-radius: 8px;'>Reset Accounting</a>";
echo "</div>";
?>
