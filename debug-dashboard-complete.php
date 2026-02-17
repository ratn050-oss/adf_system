<?php
/**
 * Comprehensive Dashboard Debug Script
 * Checks: Session, Database, Businesses, Cash Accounts, Transactions, APIs
 */

session_start();

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Dashboard Complete Debug</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #1a1a2e;
            color: #eee;
            padding: 20px;
            line-height: 1.6;
        }
        .section {
            background: #16213e;
            border: 1px solid #0f3460;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        h1, h2, h3 {
            color: #a855f7;
            margin-top: 0;
        }
        .success {
            color: #4ade80;
            font-weight: bold;
        }
        .error {
            color: #ef4444;
            font-weight: bold;
        }
        .warning {
            color: #fbbf24;
            font-weight: bold;
        }
        .info {
            color: #60a5fa;
        }
        pre {
            background: #0f172a;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border-left: 4px solid #a855f7;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #0f3460;
        }
        th {
            background: #0f3460;
            color: #a855f7;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .badge-success { background: #166534; color: #4ade80; }
        .badge-error { background: #7f1d1d; color: #ef4444; }
        .badge-warning { background: #713f12; color: #fbbf24; }
        .test-button {
            display: inline-block;
            padding: 8px 16px;
            background: #a855f7;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            margin: 5px;
            cursor: pointer;
        }
        .test-button:hover {
            background: #9333ea;
        }
    </style>
</head>
<body>

<h1>🔍 Dashboard Complete Debug - <?php echo date('Y-m-d H:i:s'); ?></h1>

<?php
// Database config
require_once __DIR__ . '/config/database.php';

// ===========================
// 1. SESSION CHECK
// ===========================
echo '<div class="section">';
echo '<h2>1. Session Status</h2>';

if (empty($_SESSION)) {
    echo '<p class="error">❌ Session is EMPTY</p>';
    echo '<p>You need to login first!</p>';
    echo '<a href="index.php" class="test-button">Go to Login</a>';
} else {
    echo '<p class="success">✅ Session is active</p>';
    echo '<table>';
    echo '<tr><th>Key</th><th>Value</th></tr>';
    foreach ($_SESSION as $key => $value) {
        if ($key !== 'password') {
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($key) . '</strong></td>';
            echo '<td>' . htmlspecialchars(is_array($value) ? json_encode($value) : $value) . '</td>';
            echo '</tr>';
        }
    }
    echo '</table>';
    
    // Auth status
    $is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    $has_role = !empty($_SESSION['role']);
    $auth_ok = $is_logged_in || $has_role;
    
    if ($auth_ok) {
        echo '<p class="success">✅ Auth Check: PASSED</p>';
    } else {
        echo '<p class="error">❌ Auth Check: FAILED</p>';
        echo '<p>Neither logged_in=true nor role is set</p>';
    }
}

echo '</div>';

// ===========================
// DATABASE CONNECTION SETUP
// ===========================
// Detect environment
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

if ($isProduction) {
    // Production (Hosting)
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'adfb2574_adf');
    define('DB_USER', 'adfb2574_adfsystem');
    define('DB_PASS', '@Nnoc2025');
} else {
    // Local development
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'adf_system');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

// Create PDO connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    $pdo = null;
}

// ===========================
// 2. DATABASE CONNECTION
// ===========================
echo '<div class="section">';
echo '<h2>2. Database Connection</h2>';

if ($pdo === null) {
    echo '<p class="error">❌ Database connection failed</p>';
    echo '<pre>Could not connect to database</pre>';
} else {
    try {
        // Test main database
        $testQuery = $pdo->query("SELECT DATABASE() as current_db, VERSION() as mysql_version");
    $dbInfo = $testQuery->fetch(PDO::FETCH_ASSOC);
    
    echo '<p class="success">✅ Connected to MySQL</p>';
    echo '<table>';
    echo '<tr><td><strong>Current Database</strong></td><td>' . $dbInfo['current_db'] . '</td></tr>';
    echo '<tr><td><strong>MySQL Version</strong></td><td>' . $dbInfo['mysql_version'] . '</td></tr>';
    echo '</table>';
    
} catch (Exception $e) {
    echo '<p class="error">❌ Database connection failed</p>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}

echo '</div>';

// ===========================
// 3. BUSINESSES DATA
// ===========================
echo '<div class="section">';
echo '<h2>3. Businesses Table</h2>';

try {
    $bizQuery = $pdo->query("
        SELECT 
            id,
            business_code,
            branch_name,
            business_type,
            database_name,
            status
        FROM businesses
        WHERE status = 'active'
        ORDER BY id ASC
    ");
    
    $businesses = $bizQuery->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($businesses)) {
        echo '<p class="error">❌ No active businesses found!</p>';
        echo '<p>The businesses table is empty or all businesses are inactive.</p>';
    } else {
        echo '<p class="success">✅ Found ' . count($businesses) . ' active business(es)</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Code</th><th>Branch Name</th><th>Type</th><th>Database</th><th>Status</th></tr>';
        foreach ($businesses as $biz) {
            echo '<tr>';
            echo '<td><strong>' . $biz['id'] . '</strong></td>';
            echo '<td>' . htmlspecialchars($biz['business_code']) . '</td>';
            echo '<td>' . htmlspecialchars($biz['branch_name']) . '</td>';
            echo '<td>' . htmlspecialchars($biz['business_type']) . '</td>';
            echo '<td><span class="info">' . htmlspecialchars($biz['database_name']) . '</span></td>';
            echo '<td><span class="badge badge-success">' . $biz['status'] . '</span></td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
} catch (Exception $e) {
    echo '<p class="error">❌ Error querying businesses</p>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}

echo '</div>';

// ===========================
// 4. CASH ACCOUNTS
// ===========================
echo '<div class="section">';
echo '<h2>4. Cash Accounts Table</h2>';

try {
    $cashQuery = $pdo->query("
        SELECT 
            id,
            business_id,
            account_name,
            account_type,
            balance,
            is_active
        FROM cash_accounts
        WHERE is_active = 1
        ORDER BY business_id, id ASC
    ");
    
    $cashAccounts = $cashQuery->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cashAccounts)) {
        echo '<p class="error">❌ No active cash accounts found!</p>';
        echo '<p>The cash_accounts table is empty.</p>';
    } else {
        echo '<p class="success">✅ Found ' . count($cashAccounts) . ' active cash account(s)</p>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Business ID</th><th>Account Name</th><th>Type</th><th>Balance</th><th>Active</th></tr>';
        foreach ($cashAccounts as $acc) {
            echo '<tr>';
            echo '<td><strong>' . $acc['id'] . '</strong></td>';
            echo '<td><span class="badge badge-warning">' . $acc['business_id'] . '</span></td>';
            echo '<td>' . htmlspecialchars($acc['account_name']) . '</td>';
            echo '<td>' . htmlspecialchars($acc['account_type']) . '</td>';
            echo '<td class="success">Rp ' . number_format($acc['balance'], 0, ',', '.') . '</td>';
            echo '<td>' . ($acc['is_active'] ? '✅' : '❌') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
} catch (Exception $e) {
    echo '<p class="error">❌ Error querying cash_accounts</p>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}

echo '</div>';

// ===========================
// 5. TRANSACTIONS IN BUSINESS DATABASES
// ===========================
if (!empty($businesses)) {
    foreach ($businesses as $biz) {
        echo '<div class="section">';
        echo '<h2>5.' . $biz['id'] . '. Transactions - ' . htmlspecialchars($biz['branch_name']) . '</h2>';
        echo '<p class="info">Database: <strong>' . $biz['database_name'] . '</strong></p>';
        
        try {
            // Switch to business database
            $bizPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $biz['database_name'] . ";charset=utf8mb4",
                DB_USER,
                DB_PASS
            );
            $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if cash_book table exists
            $tableCheck = $bizPdo->query("SHOW TABLES LIKE 'cash_book'");
            if ($tableCheck->rowCount() === 0) {
                echo '<p class="error">❌ Table cash_book does not exist!</p>';
                continue;
            }
            
            echo '<p class="success">✅ Table cash_book exists</p>';
            
            // Count total transactions
            $countQuery = $bizPdo->query("SELECT COUNT(*) as total FROM cash_book");
            $totalTrans = $countQuery->fetch(PDO::FETCH_ASSOC)['total'];
            
            echo '<p>Total transactions: <strong>' . $totalTrans . '</strong></p>';
            
            if ($totalTrans > 0) {
                // Today's transactions
                $todayQuery = $bizPdo->query("
                    SELECT COUNT(*) as today_count, 
                           COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END), 0) as today_income,
                           COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END), 0) as today_expense
                    FROM cash_book
                    WHERE DATE(transaction_date) = CURDATE()
                ");
                $todayStats = $todayQuery->fetch(PDO::FETCH_ASSOC);
                
                echo '<h3>Today\'s Transactions (' . date('Y-m-d') . ')</h3>';
                echo '<table>';
                echo '<tr><td><strong>Count</strong></td><td>' . $todayStats['today_count'] . '</td></tr>';
                echo '<tr><td><strong>Income</strong></td><td class="success">Rp ' . number_format($todayStats['today_income'], 0, ',', '.') . '</td></tr>';
                echo '<tr><td><strong>Expense</strong></td><td class="error">Rp ' . number_format($todayStats['today_expense'], 0, ',', '.') . '</td></tr>';
                echo '</table>';
                
                // This month's transactions
                $monthQuery = $bizPdo->query("
                    SELECT COUNT(*) as month_count,
                           COALESCE(SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END), 0) as month_income,
                           COALESCE(SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END), 0) as month_expense
                    FROM cash_book
                    WHERE YEAR(transaction_date) = YEAR(CURDATE())
                    AND MONTH(transaction_date) = MONTH(CURDATE())
                ");
                $monthStats = $monthQuery->fetch(PDO::FETCH_ASSOC);
                
                echo '<h3>This Month (' . date('F Y') . ')</h3>';
                echo '<table>';
                echo '<tr><td><strong>Count</strong></td><td>' . $monthStats['month_count'] . '</td></tr>';
                echo '<tr><td><strong>Income</strong></td><td class="success">Rp ' . number_format($monthStats['month_income'], 0, ',', '.') . '</td></tr>';
                echo '<tr><td><strong>Expense</strong></td><td class="error">Rp ' . number_format($monthStats['month_expense'], 0, ',', '.') . '</td></tr>';
                echo '</table>';
                
                // Recent 5 transactions
                $recentQuery = $bizPdo->query("
                    SELECT 
                        id,
                        transaction_date,
                        transaction_type,
                        category,
                        amount,
                        description
                    FROM cash_book
                    ORDER BY transaction_date DESC, id DESC
                    LIMIT 5
                ");
                $recentTrans = $recentQuery->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h3>Recent 5 Transactions</h3>';
                echo '<table>';
                echo '<tr><th>Date</th><th>Type</th><th>Category</th><th>Amount</th><th>Description</th></tr>';
                foreach ($recentTrans as $trans) {
                    $amountClass = $trans['transaction_type'] === 'Income' ? 'success' : 'error';
                    echo '<tr>';
                    echo '<td>' . date('Y-m-d H:i', strtotime($trans['transaction_date'])) . '</td>';
                    echo '<td><span class="badge badge-' . ($trans['transaction_type'] === 'Income' ? 'success' : 'error') . '">' . $trans['transaction_type'] . '</span></td>';
                    echo '<td>' . htmlspecialchars($trans['category']) . '</td>';
                    echo '<td class="' . $amountClass . '">Rp ' . number_format($trans['amount'], 0, ',', '.') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($trans['description'] ?? '', 0, 50)) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
                
            } else {
                echo '<p class="warning">⚠️ No transactions found in this database</p>';
            }
            
        } catch (Exception $e) {
            echo '<p class="error">❌ Error querying database</p>';
            echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        }
        
        echo '</div>';
    }
}

// ===========================
// 6. API TESTS
// ===========================
echo '<div class="section">';
echo '<h2>6. API Tests</h2>';
echo '<p>Test the APIs with current session:</p>';

// Use actual domain for easy testing
$protocol = 'https';
$host = 'adfsystem.online';
$basePath = '';

$apiTests = [
    'Branches API' => $protocol . '://' . $host . $basePath . '/api/owner-branches-simple.php',
    'Stats API (All)' => $protocol . '://' . $host . $basePath . '/api/owner-stats-simple.php',
];

if (!empty($businesses)) {
    foreach ($businesses as $biz) {
        $apiTests['Stats API (' . $biz['branch_name'] . ')'] = $protocol . '://' . $host . $basePath . '/api/owner-stats-simple.php?db=' . $biz['database_name'] . '&biz_id=' . $biz['id'];
    }
}

echo '<div style="margin: 20px 0;">';
foreach ($apiTests as $name => $url) {
    echo '<a href="' . $url . '" target="_blank" class="test-button">🧪 Test: ' . $name . '</a>';
}
echo '</div>';

echo '<p class="info">💡 These will open in new tabs. Check the JSON responses.</p>';

echo '</div>';

// ===========================
// 7. DASHBOARD LINK
// ===========================
echo '<div class="section">';
echo '<h2>7. Dashboard Link</h2>';
$dashboardUrl = $protocol . '://' . $host . $basePath . '/modules/owner/dashboard-2028.php';
echo '<a href="' . $dashboardUrl . '" class="test-button">📊 Open Owner Dashboard</a>';
echo '<p class="info">💡 After opening, press F12 to see Console logs</p>';
echo '</div>';

// ===========================
// SUMMARY
// ===========================
echo '<div class="section">';
echo '<h2>📋 Summary</h2>';

$issues = [];
$warnings = [];

if (empty($_SESSION)) {
    $issues[] = 'Session is empty - you need to login';
}

if (empty($businesses)) {
    $issues[] = 'No businesses found in database';
}

if (empty($cashAccounts)) {
    $warnings[] = 'No cash accounts found';
}

if (!empty($businesses)) {
    foreach ($businesses as $biz) {
        try {
            $bizPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $biz['database_name'] . ";charset=utf8mb4",
                DB_USER,
                DB_PASS
            );
            $countQuery = $bizPdo->query("SELECT COUNT(*) as total FROM cash_book");
            $total = $countQuery->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total === 0) {
                $warnings[] = 'No transactions in ' . $biz['branch_name'];
            }
        } catch (Exception $e) {
            $issues[] = 'Cannot access database: ' . $biz['database_name'];
        }
    }
}

if (empty($issues) && empty($warnings)) {
    echo '<p class="success">✅ All checks passed! Dashboard should work correctly.</p>';
} else {
    if (!empty($issues)) {
        echo '<h3 class="error">❌ Critical Issues:</h3>';
        echo '<ul>';
        foreach ($issues as $issue) {
            echo '<li class="error">' . $issue . '</li>';
        }
        echo '</ul>';
    }
    
    if (!empty($warnings)) {
        echo '<h3 class="warning">⚠️ Warnings:</h3>';
        echo '<ul>';
        foreach ($warnings as $warning) {
            echo '<li class="warning">' . $warning . '</li>';
        }
        echo '</ul>';
    }
}

echo '</div>';

?>

<div class="section">
    <h2>🔄 Refresh</h2>
    <button onclick="location.reload()" class="test-button">🔄 Refresh Debug Page</button>
</div>

<div style="text-align: center; margin-top: 40px; padding: 20px; color: #666;">
    <p>Debug script: debug-dashboard-complete.php</p>
    <p>Generated: <?php echo date('Y-m-d H:i:s'); ?></p>
</div>

</body>
</html>
