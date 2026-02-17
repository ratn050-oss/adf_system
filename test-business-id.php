<?php
/**
 * TEST: Debug business_id issue on hosting
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test Business ID</h1><pre>";

// Step 1: Database constants (HOSTING values)
$isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);

echo "1. Environment: " . ($isProduction ? "HOSTING" : "LOCAL") . "\n\n";

if ($isProduction) {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'adfb2574_adfsystem');
    define('DB_PASS', '@Nnoc2025');
    define('MASTER_DB_NAME', 'adfb2574_adf');
} else {
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('MASTER_DB_NAME', 'adf_system');
}

echo "2. DB Config:\n";
echo "   DB_HOST: " . DB_HOST . "\n";
echo "   DB_USER: " . DB_USER . "\n";
echo "   MASTER_DB_NAME: " . MASTER_DB_NAME . "\n\n";

// Step 2: Connect to master DB
echo "3. Connecting to master DB...\n";
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "   SUCCESS!\n\n";
} catch (Exception $e) {
    echo "   FAILED: " . $e->getMessage() . "\n\n";
    die("Cannot continue without DB connection");
}

// Step 3: Check businesses table
echo "4. Businesses table:\n";
$stmt = $pdo->query("SELECT id, business_code, business_name FROM businesses");
$businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($businesses as $b) {
    echo "   ID {$b['id']}: {$b['business_code']} = {$b['business_name']}\n";
}
echo "\n";

// Step 4: Test query for bens-cafe
$codeMap = [
    'narayana-hotel' => 'NARAYANAHOTEL',
    'bens-cafe' => 'BENSCAFE'
];

echo "5. Testing getNumericBusinessId logic:\n";

foreach (['bens-cafe', 'narayana-hotel'] as $businessSlug) {
    $dbCode = $codeMap[$businessSlug] ?? strtoupper(str_replace('-', '', $businessSlug));
    echo "   Slug: '$businessSlug' => DB Code: '$dbCode'\n";
    
    $stmt = $pdo->prepare("SELECT id FROM businesses WHERE business_code = ?");
    $stmt->execute([$dbCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo "   FOUND: id = {$row['id']}\n\n";
    } else {
        echo "   NOT FOUND! Checking what business_code values exist...\n";
        $all = $pdo->query("SELECT business_code FROM businesses")->fetchAll(PDO::FETCH_COLUMN);
        echo "   Existing codes: " . implode(", ", $all) . "\n\n";
    }
}

// Step 5: Session test
echo "6. Session test:\n";
session_start();
$_SESSION['test_business_id'] = 2;
echo "   Set test_business_id = 2\n";
echo "   Read back: " . $_SESSION['test_business_id'] . "\n\n";

echo "=== DONE ===\n";
echo "</pre>";
