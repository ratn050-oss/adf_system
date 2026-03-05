<?php
/**
 * Debug: Hotel Services Cashbook Sync
 * Access: /debug-hotel-cashbook.php
 * DELETE after diagnosis
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db  = Database::getInstance();
$pdo = $db->getConnection();

echo "<style>body{font-family:monospace;padding:20px;background:#f8fafc} h3{color:#1e3a5f} .ok{color:#10b981} .err{color:#ef4444} .warn{color:#f59e0b} table{border-collapse:collapse;margin:10px 0} td,th{border:1px solid #e2e8f0;padding:6px 12px;font-size:13px}</style>";
echo "<h2>Hotel Services — Cashbook Sync Diagnostics</h2>";
echo "<p>DB: <b>" . Database::getCurrentDatabase() . "</b> | Business ID: <b>" . ($_SESSION['business_id'] ?? '?') . "</b></p>";

// ── 1. cash_book structure ────────────────────────────────────────────────────
echo "<h3>1. cash_book columns</h3><table><tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cash_book")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "<tr><td>{$c['Field']}</td><td>{$c['Type']}</td><td>{$c['Null']}</td><td>{$c['Default']}</td></tr>";
    }
} catch (Exception $e) {
    echo "<tr><td colspan=4 class=err>ERROR: {$e->getMessage()}</td></tr>";
}
echo "</table>";

// ── 2. payment_method ENUM ───────────────────────────────────────────────────
echo "<h3>2. payment_method ENUM values</h3>";
try {
    $pmCol = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
    if ($pmCol) {
        preg_match_all("/'([^']+)'/", $pmCol['Type'], $m);
        $enumVals = $m[1] ?? [];
        echo "<span class=ok>Allowed: " . implode(', ', $enumVals) . "</span><br>";
        foreach (['cash', 'transfer', 'qris', 'card'] as $v) {
            $ok = in_array($v, $enumVals);
            echo ($ok ? "<span class=ok>✓</span>" : "<span class=err>✗</span>") . " '$v'<br>";
        }
    }
} catch (Exception $e) {
    echo "<span class=err>ERROR: {$e->getMessage()}</span>";
}

// ── 3. Divisions ─────────────────────────────────────────────────────────────
echo "<h3>3. Divisions</h3><table><tr><th>id</th><th>name</th><th>is_active</th></tr>";
try {
    $divs = $pdo->query("SELECT id, division_name, is_active FROM divisions ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($divs)) echo "<tr><td colspan=3 class=warn>No divisions found!</td></tr>";
    foreach ($divs as $d) echo "<tr><td>{$d['id']}</td><td>{$d['division_name']}</td><td>{$d['is_active']}</td></tr>";
} catch (Exception $e) {
    echo "<tr><td colspan=3 class=err>ERROR: {$e->getMessage()}</td></tr>";
}
echo "</table>";

// ── 4. Income categories ─────────────────────────────────────────────────────
echo "<h3>4. Income categories</h3><table><tr><th>id</th><th>name</th><th>type</th></tr>";
try {
    $cats = $pdo->query("SELECT id, category_name, category_type FROM categories WHERE category_type='income' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($cats)) echo "<tr><td colspan=3 class=warn>No income categories found!</td></tr>";
    foreach ($cats as $c) echo "<tr><td>{$c['id']}</td><td>{$c['category_name']}</td><td>{$c['category_type']}</td></tr>";
} catch (Exception $e) {
    echo "<tr><td colspan=3 class=err>ERROR: {$e->getMessage()}</td></tr>";
}
echo "</table>";

// ── 5. Cash accounts in master DB ────────────────────────────────────────────
echo "<h3>5. Cash accounts (master DB) for this business</h3>";
$businessId = $_SESSION['business_id'] ?? 1;
try {
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : (defined('DB_NAME') ? DB_NAME : 'adf_system');
    $mPdo = new PDO("mysql:host=" . DB_HOST . ";dbname={$masterDbName};charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "<p class=ok>Master DB connected: <b>{$masterDbName}</b></p>";
    $accts = $mPdo->prepare("SELECT id, account_name, account_type, current_balance FROM cash_accounts WHERE business_id = ? ORDER BY id");
    $accts->execute([$businessId]);
    $rows = $accts->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "<p class=warn>⚠ No cash accounts for business_id={$businessId} — syncServiceCashbook will try to create one automatically.</p>";
    } else {
        echo "<table><tr><th>id</th><th>name</th><th>type</th><th>balance</th></tr>";
        foreach ($rows as $r) echo "<tr><td>{$r['id']}</td><td>{$r['account_name']}</td><td>{$r['account_type']}</td><td>" . number_format($r['current_balance']) . "</td></tr>";
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<span class=err>ERROR connecting master DB: {$e->getMessage()}</span>";
}

// ── 6. Recent cash_book entries ──────────────────────────────────────────────
echo "<h3>6. Recent cash_book entries (last 10)</h3><table><tr><th>id</th><th>date</th><th>type</th><th>amount</th><th>method</th><th>description</th></tr>";
try {
    $recs = $pdo->query("SELECT id, transaction_date, transaction_type, amount, payment_method, description FROM cash_book ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($recs)) echo "<tr><td colspan=6 class=warn>cash_book is empty</td></tr>";
    foreach ($recs as $r) {
        $isHotelSvc = strpos($r['description'] ?? '', 'Hotel Services') !== false;
        $style = $isHotelSvc ? ' style="background:#d1fae5"' : '';
        echo "<tr{$style}><td>{$r['id']}</td><td>{$r['transaction_date']}</td><td>{$r['transaction_type']}</td><td>" . number_format($r['amount']) . "</td><td>{$r['payment_method']}</td><td>" . htmlspecialchars(substr($r['description'] ?? '', 0, 80)) . "</td></tr>";
    }
} catch (Exception $e) {
    echo "<tr><td colspan=6 class=err>ERROR: {$e->getMessage()}</td></tr>";
}
echo "</table>";

// ── 7. Trial sync ────────────────────────────────────────────────────────────
echo "<h3>7. Test sync (dry run — reads only, no INSERT)</h3>";
try {
    require_once 'includes/CashbookHelper.php';
    $userId = $auth->getCurrentUser()['id'] ?? 1;
    $helper = new CashbookHelper($db, $businessId, $userId);
    $account = $helper->getCashAccount('cash');
    if ($account) {
        echo "<span class=ok>✓ getCashAccount('cash') OK — ID={$account['id']}, Name={$account['account_name']}, Balance=" . number_format($account['current_balance']) . "</span><br>";
    } else {
        echo "<span class=err>✗ getCashAccount('cash') returned NULL — cashbook sync will fail!</span><br>";
    }
    $divId = $helper->getDivisionId();
    echo "<span class=ok>✓ getDivisionId() = {$divId}</span><br>";
    $catId = $helper->getCategoryId();
    echo "<span class=ok>✓ getCategoryId() = {$catId}</span><br>";
    $hasCa = $helper->hasCashAccountIdColumn();
    echo "<span class=ok>✓ hasCashAccountIdColumn() = " . ($hasCa ? 'YES' : 'NO') . "</span><br>";
} catch (Exception $e) {
    echo "<span class=err>✗ CashbookHelper error: {$e->getMessage()}</span>";
}

// ── 8. hotel_invoices with cashbook=false would indicate the issue ────────────
echo "<h3>8. Recent hotel_invoices</h3><table><tr><th>id</th><th>invoice</th><th>guest</th><th>total</th><th>paid</th><th>pay_status</th><th>pay_method</th><th>created</th></tr>";
try {
    $invs = $pdo->query("SELECT id, invoice_number, guest_name, total, paid_amount, payment_status, payment_method, created_at FROM hotel_invoices ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($invs)) echo "<tr><td colspan=8 class=warn>No hotel invoices yet</td></tr>";
    foreach ($invs as $i) {
        echo "<tr><td>{$i['id']}</td><td><b>{$i['invoice_number']}</b></td><td>" . htmlspecialchars($i['guest_name']) . "</td><td>" . number_format($i['total']) . "</td><td>" . number_format($i['paid_amount']) . "</td><td>{$i['payment_status']}</td><td>{$i['payment_method']}</td><td>{$i['created_at']}</td></tr>";
    }
} catch (Exception $e) {
    echo "<tr><td colspan=8 class=err>ERROR: {$e->getMessage()}</td></tr>";
}
echo "</table>";
echo "<p style='color:#64748b;font-size:12px'>Delete this file after diagnosis: <code>debug-hotel-cashbook.php</code></p>";
