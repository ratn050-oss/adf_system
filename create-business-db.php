<?php
/**
 * Create Business Database via cPanel API
 * For shared hosting where CREATE DATABASE SQL is denied
 * Access: https://adfsystem.online/create-business-db.php
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';

// Only allow on production or with dev token
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                 strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

$results = [];
$dbName = trim($_POST['db_name'] ?? $_GET['db'] ?? '');
$cpanelUser = trim($_POST['cpanel_user'] ?? '');
$cpanelPass = trim($_POST['cpanel_pass'] ?? '');
$action = $_POST['action'] ?? '';

// Auto-detect cPanel username from DB_USER
$defaultCpanelUser = '';
if (defined('DB_USER')) {
    $parts = explode('_', DB_USER);
    if (count($parts) >= 2) {
        $defaultCpanelUser = $parts[0];
    }
}

// Get existing databases for reference
$existingDbs = [];
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE '{$defaultCpanelUser}%' ORDER BY SCHEMA_NAME");
    $existingDbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $results[] = ['type' => 'error', 'msg' => 'Cannot list databases: ' . $e->getMessage()];
}

// Get pending businesses (registered but DB doesn't exist)
$pendingBusinesses = [];
try {
    if (isset($pdo)) {
        $bizStmt = $pdo->query("SELECT id, business_name, database_name, business_code FROM businesses WHERE is_active = 1 ORDER BY id");
        while ($biz = $bizStmt->fetch(PDO::FETCH_ASSOC)) {
            $exists = in_array($biz['database_name'], $existingDbs);
            if (!$exists) {
                $pendingBusinesses[] = $biz;
            }
        }
    }
} catch (Exception $e) {}

if ($action === 'create' && !empty($dbName) && !empty($cpanelUser) && !empty($cpanelPass)) {
    
    // Sanitize DB name
    $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $dbName);
    
    // Check if already exists
    if (in_array($dbName, $existingDbs)) {
        $results[] = ['type' => 'success', 'msg' => "Database '{$dbName}' already exists!"];
    } else {
        // Strategy 1: cPanel UAPI via HTTP (most reliable on shared hosting)
        $created = false;
        
        // Try localhost:2083
        $cpanelHost = 'localhost';
        $cpanelPort = 2083;
        
        // Method A: cPanel UAPI via curl
        $apiUrl = "https://{$cpanelHost}:{$cpanelPort}/execute/Mysql/create_database";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['name' => $dbName]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => "{$cpanelUser}:{$cpanelPass}",
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $json = json_decode($response, true);
            if (isset($json['status']) && $json['status'] == 1) {
                $results[] = ['type' => 'success', 'msg' => "Database '{$dbName}' created via cPanel API!"];
                $created = true;
            } elseif (isset($json['errors'])) {
                $errMsg = is_array($json['errors']) ? implode(', ', $json['errors']) : $json['errors'];
                // "already exists" is also fine
                if (stripos($errMsg, 'already exists') !== false) {
                    $results[] = ['type' => 'success', 'msg' => "Database '{$dbName}' already exists."];
                    $created = true;
                } else {
                    $results[] = ['type' => 'error', 'msg' => "cPanel API error: {$errMsg}"];
                }
            }
        } else {
            $results[] = ['type' => 'warning', 'msg' => "cPanel API HTTP {$httpCode}. Curl: {$curlError}. Trying alternative..."];
            
            // Method B: Try cPanel JSON API v2 (older)
            $apiUrl2 = "https://{$cpanelHost}:{$cpanelPort}/json-api/cpanel?cpanel_jsonapi_user={$cpanelUser}&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=MysqlFE&cpanel_jsonapi_func=createdb&db=" . urlencode($dbName);
            $ch2 = curl_init();
            curl_setopt_array($ch2, [
                CURLOPT_URL => $apiUrl2,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => "{$cpanelUser}:{$cpanelPass}",
                CURLOPT_TIMEOUT => 30,
            ]);
            $response2 = curl_exec($ch2);
            $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            
            if ($httpCode2 === 200) {
                $json2 = json_decode($response2, true);
                if (isset($json2['cpanelresult']['data'][0]['result']) && $json2['cpanelresult']['data'][0]['result'] == 1) {
                    $results[] = ['type' => 'success', 'msg' => "Database '{$dbName}' created via cPanel JSON API v2!"];
                    $created = true;
                } else {
                    $results[] = ['type' => 'error', 'msg' => "cPanel v2 API response: " . substr($response2, 0, 500)];
                }
            } else {
                $results[] = ['type' => 'error', 'msg' => "cPanel v2 API HTTP {$httpCode2}"];
            }
        }
        
        // If DB was created, grant privileges to MySQL user
        if ($created) {
            $grantUrl = "https://{$cpanelHost}:{$cpanelPort}/execute/Mysql/set_privileges_on_database";
            $ch3 = curl_init();
            curl_setopt_array($ch3, [
                CURLOPT_URL => $grantUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'user' => DB_USER,
                    'database' => $dbName,
                    'privileges' => 'ALL PRIVILEGES'
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => "{$cpanelUser}:{$cpanelPass}",
                CURLOPT_TIMEOUT => 30,
            ]);
            $grantResponse = curl_exec($ch3);
            $grantCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
            curl_close($ch3);
            
            if ($grantCode === 200) {
                $grantJson = json_decode($grantResponse, true);
                if (isset($grantJson['status']) && $grantJson['status'] == 1) {
                    $results[] = ['type' => 'success', 'msg' => "Privileges granted to '" . DB_USER . "' on '{$dbName}'"];
                } else {
                    $results[] = ['type' => 'warning', 'msg' => "Privilege grant response: " . substr($grantResponse, 0, 300)];
                }
            }
            
            // Run business template SQL
            $templatePath = __DIR__ . '/database/business_template.sql';
            if (file_exists($templatePath)) {
                try {
                    $newPdo = new PDO("mysql:host=" . DB_HOST . ";dbname={$dbName}", DB_USER, DB_PASS);
                    $newPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $sql = file_get_contents($templatePath);
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    $executed = 0;
                    foreach ($statements as $statement) {
                        if (!empty($statement) && strpos($statement, '--') !== 0) {
                            $newPdo->exec($statement);
                            $executed++;
                        }
                    }
                    $results[] = ['type' => 'success', 'msg' => "Business template executed: {$executed} statements in '{$dbName}'"];
                } catch (PDOException $e) {
                    $results[] = ['type' => 'error', 'msg' => "Template execution failed: " . $e->getMessage()];
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Business Database</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; padding: 2rem; color: #1e293b; }
        .container { max-width: 700px; margin: 0 auto; }
        h1 { font-size: 1.5rem; margin-bottom: 1rem; }
        .card { background: #fff; border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .card h2 { font-size: 1.1rem; margin-bottom: 1rem; color: #334155; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 0.3rem; }
        input[type=text], input[type=password] { width: 100%; padding: 0.6rem 0.8rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; margin-bottom: 0.8rem; }
        input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        button { background: #3b82f6; color: #fff; border: none; padding: 0.7rem 1.5rem; border-radius: 8px; font-size: 0.9rem; cursor: pointer; font-weight: 600; }
        button:hover { background: #2563eb; }
        .result { padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 0.5rem; font-size: 0.85rem; }
        .result.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .result.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .result.warning { background: #fef9c3; color: #854d0e; border: 1px solid #fde68a; }
        .db-list { font-size: 0.8rem; color: #64748b; }
        .db-list span { display: inline-block; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; margin: 2px; }
        .pending { background: #fef3c7; padding: 0.6rem 1rem; border-radius: 8px; margin-bottom: 0.5rem; font-size: 0.85rem; border: 1px solid #fde68a; }
        .pending a { color: #d97706; font-weight: 600; cursor: pointer; }
        .hint { font-size: 0.78rem; color: #94a3b8; margin-bottom: 0.8rem; }
    </style>
</head>
<body>
<div class="container">
    <h1>🗄️ Create Business Database</h1>
    
    <?php foreach ($results as $r): ?>
        <div class="result <?= $r['type'] ?>"><?= htmlspecialchars($r['msg']) ?></div>
    <?php endforeach; ?>
    
    <?php if (!empty($pendingBusinesses)): ?>
    <div class="card">
        <h2>⚠️ Businesses Missing Database</h2>
        <?php foreach ($pendingBusinesses as $pb): ?>
            <div class="pending">
                <strong><?= htmlspecialchars($pb['business_name']) ?></strong> — 
                DB: <code><?= htmlspecialchars($pb['database_name']) ?></code>
                <a onclick="document.getElementById('db_name').value='<?= htmlspecialchars($pb['database_name']) ?>'">[Select]</a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h2>Create New Database</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            
            <label>Database Name</label>
            <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($dbName) ?>" placeholder="e.g. <?= $defaultCpanelUser ?>_newbusiness" required>
            <div class="hint">Must start with "<?= $defaultCpanelUser ?>_" on shared hosting</div>
            
            <label>cPanel Username</label>
            <input type="text" name="cpanel_user" value="<?= htmlspecialchars($cpanelUser ?: $defaultCpanelUser) ?>" required>
            
            <label>cPanel Password</label>
            <input type="password" name="cpanel_pass" value="" placeholder="Your cPanel login password" required>
            <div class="hint">Required for cPanel API. Not stored anywhere.</div>
            
            <button type="submit">Create Database + Run Template</button>
        </form>
    </div>
    
    <div class="card">
        <h2>Existing Databases (<?= count($existingDbs) ?>)</h2>
        <div class="db-list">
            <?php foreach ($existingDbs as $db): ?>
                <span><?= htmlspecialchars($db) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>
