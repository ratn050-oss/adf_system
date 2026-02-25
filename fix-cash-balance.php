<?php
/**
 * Auto-Fix Cash Accounts Balance
 * Calculate balance from cash_account_transactions and update cash_accounts.current_balance
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/config.php';

echo "<h2>🔧 Auto-Fix Cash Accounts Balance</h2>";

try {
    $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $businessIdentifier = ACTIVE_BUSINESS_ID;
    $businessId = getMasterBusinessId();
    
    echo "<p><strong>Business:</strong> {$businessIdentifier} (ID: {$businessId})</p>";
    echo "<hr>";
    
    // Get all cash accounts for this business
    $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY account_type");
    $stmt->execute([$businessId]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo "<p style='color: red;'>❌ No cash accounts found for business_id = {$businessId}</p>";
        echo "<p>Please create cash accounts first!</p>";
        exit;
    }
    
    echo "<h3>📊 Current Account Balances (Before Fix):</h3>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #f0f0f0;'>";
    echo "<th>ID</th><th>Account Name</th><th>Type</th><th>Current Balance</th></tr>";
    
    foreach ($accounts as $acc) {
        echo "<tr>";
        echo "<td>{$acc['id']}</td>";
        echo "<td>{$acc['account_name']}</td>";
        echo "<td>{$acc['account_type']}</td>";
        echo "<td style='text-align: right;'><strong>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<hr>";
    echo "<h3>🔄 Calculating Balances from Transactions...</h3>";
    
    $updates = [];
    
    foreach ($accounts as $acc) {
        $accountId = $acc['id'];
        $accountName = $acc['account_name'];
        
        // Calculate balance from cash_account_transactions
        $stmt = $masterDb->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN transaction_type IN ('income', 'capital_injection', 'opening_balance') THEN amount ELSE 0 END) as total_in,
                SUM(CASE WHEN transaction_type IN ('expense', 'transfer') THEN amount ELSE 0 END) as total_out
            FROM cash_account_transactions
            WHERE cash_account_id = ?
        ");
        $stmt->execute([$accountId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalTransactions = $result['total_transactions'] ?? 0;
        $totalIn = $result['total_in'] ?? 0;
        $totalOut = $result['total_out'] ?? 0;
        $calculatedBalance = $totalIn - $totalOut;
        
        echo "<div style='background: #f0f9ff; padding: 15px; margin: 10px 0; border-left: 4px solid #0284c7;'>";
        echo "<h4>{$accountName} (ID: {$accountId})</h4>";
        echo "<p><strong>Total Transactions:</strong> {$totalTransactions}</p>";
        echo "<p><strong>Total In:</strong> Rp " . number_format($totalIn, 0, ',', '.') . "</p>";
        echo "<p><strong>Total Out:</strong> Rp " . number_format($totalOut, 0, ',', '.') . "</p>";
        echo "<p style='font-size: 1.2em;'><strong>Calculated Balance:</strong> <span style='color: " . ($calculatedBalance >= 0 ? '#16a34a' : '#dc2626') . "; font-weight: bold;'>Rp " . number_format($calculatedBalance, 0, ',', '.') . "</span></p>";
        
        // Check if update needed
        if ($acc['current_balance'] != $calculatedBalance) {
            echo "<p style='color: #f59e0b;'>⚠️ Balance mismatch! Need update from Rp " . number_format($acc['current_balance'], 0, ',', '.') . " → Rp " . number_format($calculatedBalance, 0, ',', '.') . "</p>";
            $updates[] = [
                'account_id' => $accountId,
                'account_name' => $accountName,
                'old_balance' => $acc['current_balance'],
                'new_balance' => $calculatedBalance
            ];
        } else {
            echo "<p style='color: #16a34a;'>✅ Balance already correct!</p>";
        }
        echo "</div>";
    }
    
    // Apply updates
    if (!empty($updates)) {
        echo "<hr>";
        echo "<h3>💾 Applying Updates...</h3>";
        
        foreach ($updates as $update) {
            $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = ? WHERE id = ?");
            $stmt->execute([$update['new_balance'], $update['account_id']]);
            
            echo "<p style='color: #16a34a;'>✅ Updated <strong>{$update['account_name']}</strong>: Rp " . number_format($update['old_balance'], 0, ',', '.') . " → <strong>Rp " . number_format($update['new_balance'], 0, ',', '.')  . "</strong></p>";
        }
        
        echo "<hr>";
        echo "<div style='background: #dcfce7; padding: 20px; margin: 20px 0; border-left: 4px solid #16a34a; border-radius: 8px;'>";
        echo "<h3 style='color: #166534; margin-top: 0;'>✅ Balance Update Complete!</h3>";
        echo "<p><strong>Total accounts updated:</strong> " . count($updates) . "</p>";
        echo "<p>Silakan refresh halaman laporan untuk melihat saldo yang benar.</p>";
        echo "</div>";
        
        // Show final balances
        echo "<h3>📊 Final Account Balances (After Fix):</h3>";
        $stmt = $masterDb->prepare("SELECT * FROM cash_accounts WHERE business_id = ? ORDER BY account_type");
        $stmt->execute([$businessId]);
        $finalAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th><th>Account Name</th><th>Type</th><th>Current Balance</th></tr>";
        
        foreach ($finalAccounts as $acc) {
            $color = $acc['current_balance'] >= 0 ? '#16a34a' : '#dc2626';
            echo "<tr>";
            echo "<td>{$acc['id']}</td>";
            echo "<td>{$acc['account_name']}</td>";
            echo "<td>{$acc['account_type']}</td>";
            echo "<td style='text-align: right; color: {$color};'><strong>Rp " . number_format($acc['current_balance'], 0, ',', '.') . "</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<hr>";
        echo "<div style='background: #dcfce7; padding: 20px; margin: 20px 0; border-left: 4px solid #16a34a;'>";
        echo "<h3 style='color: #166534; margin-top: 0;'>✅ All Balances Already Correct!</h3>";
        echo "<p>No updates needed. Cash account balances match transaction data.</p>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #fee2e2; padding: 20px; margin: 20px 0; border-left: 4px solid #dc2626;'>";
    echo "<h3 style='color: #991b1b;'>❌ Error</h3>";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>";
    echo "<pre style='background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 6px; overflow-x: auto;'>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
}

echo "<hr>";
echo "<div style='text-align: center; padding: 20px;'>";
echo "<a href='modules/reports/daily.php' style='background: #6366f1; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 5px;'>📊 Test Laporan Harian</a> ";
echo "<a href='index.php' style='background: #10b981; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 5px;'>🏠 Dashboard</a> ";
echo "<a href='debug-cash-balance.php' style='background: #f59e0b; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin: 5px;'>🔍 Debug Balance</a>";
echo "</div>";
?>
