<?php
/**
 * Check transactions in cash_account_transactions
 */
define('APP_ACCESS', true);
require_once 'config/config.php';

echo "<h2>🔍 Checking Cash Account Transactions</h2>";
echo "<hr>";

try {
    // Connect to MASTER database
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h3>Database: " . MASTER_DB_NAME . "</h3>";
    
    // Get business mapping
    $businessId = getMasterBusinessId();
    
    echo "Current Business: <strong>" . ACTIVE_BUSINESS_ID . "</strong> (ID: {$businessId})<br><br>";
    
    // Check cash_account_transactions table
    $tables = $masterDb->query("SHOW TABLES LIKE 'cash_account_transactions'")->fetchAll();
    
    if (empty($tables)) {
        echo "<div style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
        echo "❌ Table 'cash_account_transactions' NOT FOUND!<br>";
        echo "<a href='fix-create-tables-now.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 6px;'>🚀 Run Setup</a>";
        echo "</div>";
        exit;
    }
    
    echo "✅ Table 'cash_account_transactions' exists<br><br>";
    
    // Get accounts for this business
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY account_type");
    $stmt->execute([$businessId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "<div style='background: #fef3c7; padding: 1rem; border-left: 4px solid #f59e0b;'>";
        echo "⚠️  No cash accounts found for this business!<br>";
        echo "<a href='fix-create-tables-now.php' style='display: inline-block; margin-top: 1rem; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 6px;'>🚀 Create Accounts</a>";
        echo "</div>";
        exit;
    }
    
    echo "<h3>📊 Accounts for " . ACTIVE_BUSINESS_ID . ":</h3>";
    echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; margin: 1rem 0;'>";
    echo "<thead style='background: #3b82f6; color: white;'>";
    echo "<tr><th>ID</th><th>Account Name</th><th>Type</th><th>Transactions</th><th>Total In</th><th>Total Out</th><th>Balance</th></tr>";
    echo "</thead><tbody>";
    
    foreach ($accounts as $acc) {
        $accountId = $acc['id'];
        
        // Get transaction count
        $stmt = $masterDb->prepare("SELECT COUNT(*) as cnt FROM cash_account_transactions WHERE cash_account_id = ?");
        $stmt->execute([$accountId]);
        $txnCount = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        
        // Get totals
        $stmt = $masterDb->prepare("
            SELECT 
                SUM(CASE WHEN transaction_type IN ('income', 'capital_injection') THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as total_out
            FROM cash_account_transactions 
            WHERE cash_account_id = ?
        ");
        $stmt->execute([$accountId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalIn = $totals['total_in'] ?? 0;
        $totalOut = $totals['total_out'] ?? 0;
        $balance = $totalIn - $totalOut;
        
        $rowColor = ($acc['account_type'] == 'owner_capital') ? 'background: #fef3c7;' : '';
        
        echo "<tr style='$rowColor'>";
        echo "<td>{$acc['id']}</td>";
        echo "<td><strong>{$acc['account_name']}</strong></td>";
        echo "<td>{$acc['account_type']}</td>";
        echo "<td style='text-align: center;'>{$txnCount}</td>";
        echo "<td style='text-align: right; color: #10b981;'>Rp " . number_format($totalIn, 0, ',', '.') . "</td>";
        echo "<td style='text-align: right; color: #ef4444;'>Rp " . number_format($totalOut, 0, ',', '.') . "</td>";
        echo "<td style='text-align: right; font-weight: 700;'>Rp " . number_format($balance, 0, ',', '.') . "</td>";
        echo "</tr>";
    }
    
    echo "</tbody></table>";
    
    // Get owner capital account
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? AND account_type = 'owner_capital'");
    $stmt->execute([$businessId]);
    $ownerAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ownerAccount) {
        echo "<hr>";
        echo "<h3>💰 Kas Modal Owner - Recent Transactions:</h3>";
        
        $stmt = $masterDb->prepare("
            SELECT * FROM cash_account_transactions 
            WHERE cash_account_id = ? 
            ORDER BY transaction_date DESC, id DESC 
            LIMIT 20
        ");
        $stmt->execute([$ownerAccount['id']]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($transactions)) {
            echo "<div style='background: #fef3c7; padding: 1rem; border-left: 4px solid #f59e0b; margin: 1rem 0;'>";
            echo "⚠️  <strong>No transactions found in Kas Modal Owner!</strong><br>";
            echo "Silakan input transaksi melalui: <a href='modules/cashbook/add.php'>Tambah Transaksi Kas</a>";
            echo "</div>";
        } else {
            echo "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%; margin: 1rem 0;'>";
            echo "<thead style='background: #f59e0b; color: white;'>";
            echo "<tr><th>ID</th><th>Date</th><th>Description</th><th>Type</th><th>Amount</th><th>Ref</th></tr>";
            echo "</thead><tbody>";
            
            foreach ($transactions as $txn) {
                $typeColor = '';
                if (in_array($txn['transaction_type'], ['income', 'capital_injection'])) {
                    $typeColor = 'color: #10b981; font-weight: 600;';
                } else if ($txn['transaction_type'] == 'expense') {
                    $typeColor = 'color: #ef4444; font-weight: 600;';
                }
                
                echo "<tr>";
                echo "<td>{$txn['id']}</td>";
                echo "<td>" . date('d M Y', strtotime($txn['transaction_date'])) . "</td>";
                echo "<td>{$txn['description']}</td>";
                echo "<td style='$typeColor'>" . strtoupper($txn['transaction_type']) . "</td>";
                echo "<td style='text-align: right; $typeColor'>Rp " . number_format($txn['amount'], 0, ',', '.') . "</td>";
                echo "<td><small>{$txn['reference_number']}</small></td>";
                echo "</tr>";
            }
            
            echo "</tbody></table>";
        }
    }
    
    echo "<hr>";
    echo "<div style='margin: 2rem 0;'>";
    echo "<a href='index.php' style='display: inline-block; padding: 0.75rem 1.5rem; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; margin-right: 1rem;'>🏠 Dashboard</a>";
    echo "<a href='modules/cashbook/add.php' style='display: inline-block; padding: 0.75rem 1.5rem; background: #10b981; color: white; text-decoration: none; border-radius: 6px; margin-right: 1rem;'>➕ Tambah Transaksi</a>";
    echo "<a href='modules/owner/owner-capital-monitor.php' style='display: inline-block; padding: 0.75rem 1.5rem; background: #f59e0b; color: white; text-decoration: none; border-radius: 6px;'>📊 Monitor Kas Modal</a>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "</div>";
}
?>
