<?php
/**
 * Check Available Databases
 */

header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🔍 Check Available Databases</h1>";
echo "<style>
    body { font-family: 'Segoe UI', sans-serif; padding: 20px; background: #f5f5f5; }
    .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 5px; margin: 10px 0; }
    .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 12px; border-radius: 5px; margin: 10px 0; }
    table { background: white; border-collapse: collapse; width: 100%; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
    th { background: #6366f1; color: white; }
    .btn { display: inline-block; padding: 10px 20px; background: #6366f1; color: white; text-decoration: none; border-radius: 5px; margin: 5px; }
</style>";

try {
    // Connect without database
    $pdo = new PDO('mysql:host=localhost;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<div class='success'>✅ Connected to MySQL Server</div>";
    
    // Get all databases
    $stmt = $pdo->query("SHOW DATABASES");
    $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h2>Available Databases:</h2>";
    echo "<table>";
    echo "<tr><th>#</th><th>Database Name</th><th>Action</th></tr>";
    
    $adfDatabases = [];
    $i = 1;
    foreach ($databases as $db) {
        // Skip system databases
        if (in_array($db, ['information_schema', 'mysql', 'performance_schema', 'phpmyadmin', 'sys'])) {
            continue;
        }
        
        $isAdf = (strpos(strtolower($db), 'adf') !== false);
        if ($isAdf) {
            $adfDatabases[] = $db;
        }
        
        echo "<tr" . ($isAdf ? " style='background: #e7f3ff;'" : "") . ">";
        echo "<td>$i</td>";
        echo "<td><strong>$db</strong>" . ($isAdf ? " 🎯" : "") . "</td>";
        echo "<td><a href='check-lucca-user.php?db=$db' class='btn'>Check Lucca in This DB</a></td>";
        echo "</tr>";
        $i++;
    }
    echo "</table>";
    
    if (!empty($adfDatabases)) {
        echo "<div class='success'>";
        echo "<h3>🎯 ADF System Databases Found:</h3>";
        echo "<ul>";
        foreach ($adfDatabases as $db) {
            echo "<li><strong>$db</strong> - <a href='check-lucca-user.php?db=$db'>Check Lucca Here</a></li>";
        }
        echo "</ul>";
        echo "</div>";
        
        // Auto check first ADF database
        echo "<div class='info'>";
        echo "💡 Checking first ADF database automatically: <strong>{$adfDatabases[0]}</strong>";
        echo "</div>";
        
        // Check users table in first ADF database
        try {
            $pdoCheck = new PDO("mysql:host=localhost;dbname={$adfDatabases[0]};charset=utf8mb4", 'root', '');
            $pdoCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Check if users table exists
            $checkTable = $pdoCheck->query("SHOW TABLES LIKE 'users'")->fetch();
            
            if ($checkTable) {
                // Get all users
                $stmt = $pdoCheck->query("SELECT u.id, u.username, u.full_name, u.email, u.is_active FROM users u ORDER BY u.id");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<h3>Users in Database: {$adfDatabases[0]}</h3>";
                echo "<table>";
                echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Active</th></tr>";
                
                $luccaFound = false;
                $developerFound = false;
                
                foreach ($users as $user) {
                    $isLucca = ($user['username'] === 'lucca');
                    $isDeveloper = ($user['username'] === 'developer');
                    
                    if ($isLucca) $luccaFound = true;
                    if ($isDeveloper) $developerFound = true;
                    
                    $highlight = ($isLucca || $isDeveloper) ? " style='background: #fffacd; font-weight: bold;'" : "";
                    echo "<tr$highlight>";
                    echo "<td>{$user['id']}</td>";
                    echo "<td>{$user['username']}" . ($isLucca ? " 🎯" : "") . ($isDeveloper ? " 👨‍💻" : "") . "</td>";
                    echo "<td>{$user['full_name']}</td>";
                    echo "<td>{$user['email']}</td>";
                    echo "<td>" . ($user['is_active'] ? '✅' : '❌') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
                
                if ($luccaFound) {
                    echo "<div class='success'>✅ User 'lucca' FOUND in database: {$adfDatabases[0]}</div>";
                    echo "<a href='check-lucca-user.php?db={$adfDatabases[0]}' class='btn'>Check Full Details</a>";
                } else {
                    echo "<div class='error'>❌ User 'lucca' NOT FOUND in this database</div>";
                }
                
                if ($developerFound) {
                    echo "<div class='info'>👨‍💻 User 'developer' found - you can use this to login to developer panel</div>";
                }
            } else {
                echo "<div class='error'>❌ Table 'users' not found in {$adfDatabases[0]}</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='error'>Error checking database: " . $e->getMessage() . "</div>";
        }
    } else {
        echo "<div class='error'>❌ No ADF databases found!</div>";
        echo "<div class='info'>
            Please check:
            <ul>
                <li>Database name might be different</li>
                <li>Database might not be imported yet</li>
                <li>Check your config/config.php for DB_NAME constant</li>
            </ul>
        </div>";
    }
    
    // Check config.php
    echo "<h2>Config Check:</h2>";
    $configFile = __DIR__ . '/config/config.php';
    if (file_exists($configFile)) {
        $configContent = file_get_contents($configFile);
        if (preg_match("/define\('DB_NAME',\s*'([^']+)'/", $configContent, $matches)) {
            $configDb = $matches[1];
            echo "<div class='info'>📋 config.php DB_NAME: <strong>$configDb</strong></div>";
            
            if (in_array($configDb, $databases)) {
                echo "<div class='success'>✅ Config database exists!</div>";
                echo "<a href='check-lucca-user.php?db=$configDb' class='btn'>Check Lucca in Config DB</a>";
            } else {
                echo "<div class='error'>❌ Config database '$configDb' does NOT exist!</div>";
            }
        }
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Connection Error: " . $e->getMessage() . "</div>";
    echo "<div class='info'>
        Please check:
        <ul>
            <li>MySQL server is running (XAMPP Apache & MySQL started)</li>
            <li>MySQL credentials are correct (root/empty password)</li>
        </ul>
    </div>";
}
?>
