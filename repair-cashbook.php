<?php
/**
 * REPAIR TOOL - Fix Missing Cash Book Entries
 * Akses: https://adfsystem.online/repair-cashbook.php?action=view
 * 
 * Actions:
 * - view: Lihat state saat ini
 * - repair: Reset sync flags dan re-create entries
 * - add_ota_settings: Tambah OTA fee settings
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    die('Please login first');
}

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'view';
$today = date('Y-m-d');

echo '<!DOCTYPE html><html><head>';
echo '<meta charset="utf-8"><title>Cash Book Repair Tool</title>';
echo '<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; line-height: 1.6; }
h1, h2, h3 { color: #333; margin-top: 30px; }
.box { background: white; padding: 20px; margin: 15px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.success { background: #d4edda; border-left: 4px solid #28a745; }
.warning { background: #fff3cd; border-left: 4px solid #ffc107; }
.danger { background: #f8d7da; border-left: 4px solid #dc3545; }
.info { background: #d1ecf1; border-left: 4px solid #17a2b8; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
th { background: #4a90d9; color: white; }
.btn { display: inline-block; padding: 10px 20px; margin: 5px; text-decoration: none; border-radius: 4px; color: white; }
.btn-primary { background: #007bff; }
.btn-success { background: #28a745; }
.btn-danger { background: #dc3545; }
.btn-warning { background: #ffc107; color: #333; }
code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; }
</style></head><body>';

echo '<h1>🔧 Cash Book Repair Tool</h1>';
echo '<p>Database: <code>' . $conn->query("SELECT DATABASE()")->fetchColumn() . '</code> | Date: ' . date('Y-m-d H:i:s') . '</p>';

// Navigation
echo '<div class="box">';
echo '<a class="btn btn-primary" href="?action=view">📊 View Status</a> ';
echo '<a class="btn btn-warning" href="?action=add_ota_settings">⚙️ Add OTA Settings</a> ';
echo '<a class="btn btn-success" href="?action=repair" onclick="return confirm(\'Re-sync OTA payments ke Cash Book?\')">🔄 Repair/Re-sync</a> ';
echo '</div>';

// ============ VIEW STATUS ============
if ($action === 'view') {
    echo '<h2>📊 Current Status</h2>';
    
    // 1. Booking dengan status checked_in
    echo '<div class="box">';
    echo '<h3>✅ Checked-In Bookings (Revenue should be recorded)</h3>';
    $checkedIn = $conn->query("
        SELECT b.id, b.booking_code, b.booking_source, b.status, b.final_price,
               b.check_in_date, b.check_out_date, g.guest_name, r.room_number
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        ORDER BY b.check_in_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($checkedIn) === 0) {
        echo '<p>Tidak ada tamu yang sedang check-in.</p>';
    } else {
        echo '<table><tr><th>Code</th><th>Guest</th><th>Room</th><th>Source</th><th>Check-In</th><th>Price</th><th>Cash Book?</th></tr>';
        foreach ($checkedIn as $b) {
            // Check if entry exists in cash_book
            $cbEntry = $conn->query("SELECT id, amount, transaction_date FROM cash_book WHERE description LIKE '%" . $b['booking_code'] . "%' AND transaction_type='income' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $cbStatus = $cbEntry ? "✅ ID={$cbEntry['id']} ({$cbEntry['transaction_date']})" : "❌ Missing";
            
            echo "<tr>";
            echo "<td>{$b['booking_code']}</td>";
            echo "<td>{$b['guest_name']}</td>";
            echo "<td>{$b['room_number']}</td>";
            echo "<td>{$b['booking_source']}</td>";
            echo "<td>{$b['check_in_date']}</td>";
            echo "<td>Rp " . number_format($b['final_price'], 0, ',', '.') . "</td>";
            echo "<td>{$cbStatus}</td>";
            echo "</tr>";
        }
        echo '</table>';
    }
    echo '</div>';
    
    // 2. Payment sync status
    echo '<div class="box">';
    echo '<h3>💰 Payment Sync Status</h3>';
    $paymentsMissing = $conn->query("
        SELECT bp.id, bp.booking_id, bp.amount, bp.payment_date, bp.synced_to_cashbook, bp.cashbook_id,
               b.booking_code, b.booking_source, b.status as booking_status
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        WHERE bp.synced_to_cashbook = 1
        AND bp.cashbook_id IS NOT NULL
        ORDER BY bp.id DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $orphaned = 0;
    echo '<table><tr><th>PayID</th><th>Booking</th><th>Source</th><th>Status</th><th>Amount</th><th>CbID</th><th>Exists?</th></tr>';
    foreach ($paymentsMissing as $p) {
        $cbExists = $conn->query("SELECT id FROM cash_book WHERE id = " . (int)$p['cashbook_id'])->fetch();
        $existsStatus = $cbExists ? "✅ Yes" : "❌ NO (ORPHANED)";
        if (!$cbExists) $orphaned++;
        
        echo "<tr" . (!$cbExists ? " style='background:#fee'" : "") . ">";
        echo "<td>{$p['id']}</td>";
        echo "<td>{$p['booking_code']}</td>";
        echo "<td>{$p['booking_source']}</td>";
        echo "<td>{$p['booking_status']}</td>";
        echo "<td>Rp " . number_format($p['amount'], 0, ',', '.') . "</td>";
        echo "<td>{$p['cashbook_id']}</td>";
        echo "<td>{$existsStatus}</td>";
        echo "</tr>";
    }
    echo '</table>';
    
    if ($orphaned > 0) {
        echo "<div class='box danger'><strong>⚠️ Found {$orphaned} orphaned payments</strong> - marked as synced but cash_book entry doesn't exist. Click <strong>Repair/Re-sync</strong> to fix.</div>";
    }
    echo '</div>';
    
    // 3. Revenue explanation
    echo '<div class="box info">';
    echo '<h3>ℹ️ How Revenue Works</h3>';
    echo '<ul>';
    echo '<li><strong>OTA Booking</strong>: Revenue masuk ke Cash Book saat <strong>CHECK-IN</strong>, bukan saat payment</li>';
    echo '<li><strong>Direct Booking</strong>: Revenue masuk saat DP/payment</li>';
    echo '<li><strong>Transaction Date</strong>: Menggunakan tanggal CHECK-IN, bukan tanggal hari ini</li>';
    echo '<li><strong>OTA Fee</strong>: Otomatis dipotong sesuai setting (Agoda 15%, Tiket 10%, dll)</li>';
    echo '</ul>';
    echo '</div>';
    
    // 4. Today's expected revenue
    echo '<div class="box">';
    echo '<h3>📅 Today\'s Activity ({$today})</h3>';
    $todayCheckins = $conn->query("
        SELECT b.*, g.guest_name, r.room_number 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id  
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE DATE(b.check_in_date) = '{$today}'
        AND b.status = 'checked_in'
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($todayCheckins) === 0) {
        echo '<p><strong>Tidak ada tamu yang CHECK-IN hari ini.</strong></p>';
        echo '<p>Patrycja Maliszewska check-in tanggal <strong>2 Maret 2026</strong>, jadi revenue-nya masuk di tanggal itu.</p>';
    } else {
        echo '<p>Check-in hari ini:</p>';
        foreach ($todayCheckins as $c) {
            echo "<p>• {$c['guest_name']} - Room {$c['room_number']} - {$c['booking_source']} - Rp " . number_format($c['final_price'], 0) . "</p>";
        }
    }
    echo '</div>';
}

// ============ ADD OTA SETTINGS ============
if ($action === 'add_ota_settings') {
    echo '<h2>⚙️ Add OTA Fee Settings</h2>';
    
    // Get master DB
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
    try {
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // OTA fee settings to add
        $otaSettings = [
            'ota_fee_agoda' => 15,
            'ota_fee_booking_com' => 12,
            'ota_fee_tiket_com' => 10,
            'ota_fee_traveloka' => 15,
            'ota_fee_airbnb' => 3,
            'ota_fee_expedia' => 18,
            'ota_fee_other_ota' => 15
        ];
        
        $added = 0;
        $exists = 0;
        
        // Check settings table structure
        $hasBusinessId = false;
        try {
            $cols = $masterDb->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
            $hasBusinessId = in_array('business_id', $cols);
        } catch (\Throwable $e) {}
        
        foreach ($otaSettings as $key => $value) {
            // Check if exists
            $check = $masterDb->prepare("SELECT id FROM settings WHERE setting_key = ?");
            $check->execute([$key]);
            
            if (!$check->fetch()) {
                // Insert
                if ($hasBusinessId) {
                    $stmt = $masterDb->prepare("INSERT INTO settings (setting_key, setting_value, business_id) VALUES (?, ?, 0)");
                } else {
                    $stmt = $masterDb->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)");
                }
                $stmt->execute([$key, $value]);
                echo "<div class='box success'>✅ Added: {$key} = {$value}%</div>";
                $added++;
            } else {
                echo "<div class='box info'>ℹ️ Already exists: {$key}</div>";
                $exists++;
            }
        }
        
        echo "<div class='box'><strong>Done!</strong> Added: {$added}, Already exist: {$exists}</div>";
        
        // Show current settings
        echo '<h3>Current OTA Settings:</h3>';
        $settings = $masterDb->query("SELECT * FROM settings WHERE setting_key LIKE 'ota_fee%' ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
        echo '<table><tr><th>Key</th><th>Value</th></tr>';
        foreach ($settings as $s) {
            echo "<tr><td>{$s['setting_key']}</td><td>{$s['setting_value']}%</td></tr>";
        }
        echo '</table>';
        
    } catch (\Throwable $e) {
        echo "<div class='box danger'>Error: " . $e->getMessage() . "</div>";
    }
}

// ============ REPAIR ============
if ($action === 'repair') {
    echo '<h2>🔄 Repair/Re-sync Cash Book Entries</h2>';
    
    // Get master DB
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
    try {
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=utf8mb4",
            DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (\Throwable $e) {
        $masterDb = $conn;
    }
    
    $businessId = $_SESSION['business_id'] ?? 7; // Narayana Hotel = business_id 7
    
    // Step 1: Find orphaned payments
    echo '<div class="box">';
    echo '<h3>Step 1: Find orphaned payments</h3>';
    
    $orphaned = $conn->query("
        SELECT bp.*, b.booking_code, b.booking_source, b.final_price, b.check_in_date, b.status as booking_status,
               g.guest_name, r.room_number
        FROM booking_payments bp
        JOIN bookings b ON bp.booking_id = b.id
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE bp.synced_to_cashbook = 1
        AND bp.cashbook_id IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM cash_book cb WHERE cb.id = bp.cashbook_id)
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<p>Found ' . count($orphaned) . ' orphaned payments</p>';
    echo '</div>';
    
    // Step 2: Reset sync flags for orphaned
    echo '<div class="box">';
    echo '<h3>Step 2: Reset sync flags</h3>';
    
    if (count($orphaned) > 0) {
        $conn->exec("
            UPDATE booking_payments bp
            SET synced_to_cashbook = 0, cashbook_id = NULL
            WHERE synced_to_cashbook = 1
            AND cashbook_id IS NOT NULL
            AND NOT EXISTS (SELECT 1 FROM cash_book cb WHERE cb.id = bp.cashbook_id)
        ");
        echo '<p>✅ Reset ' . count($orphaned) . ' payment sync flags</p>';
    } else {
        echo '<p>No orphaned payments to reset</p>';
    }
    echo '</div>';
    
    // Step 3: Re-sync checked_in OTA bookings
    echo '<div class="box">';
    echo '<h3>Step 3: Re-sync checked-in OTA bookings</h3>';
    
    $checkedInOTA = $conn->query("
        SELECT b.*, g.guest_name, r.room_number,
               (SELECT SUM(amount) FROM booking_payments WHERE booking_id = b.id) as total_paid
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.status = 'checked_in'
        AND LOWER(b.booking_source) IN ('ota', 'agoda', 'tiket', 'tiket.com', 'booking', 'booking.com', 'traveloka', 'airbnb', 'expedia')
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get division and category
    $div = $conn->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $divisionId = $div['id'] ?? 5;
    
    $cat = $conn->query("SELECT id FROM categories WHERE category_type = 'income' AND (LOWER(category_name) LIKE '%room%' OR LOWER(category_name) LIKE '%kamar%') ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $categoryId = $cat['id'] ?? 4;
    
    // Get OTA fees
    $otaFees = [];
    try {
        $feeRows = $masterDb->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ota_fee%'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($feeRows as $f) {
            $otaFees[$f['setting_key']] = (float)$f['setting_value'];
        }
    } catch (\Throwable $e) {}
    
    $synced = 0;
    foreach ($checkedInOTA as $booking) {
        // Check if already in cash_book
        $existing = $conn->query("SELECT id FROM cash_book WHERE description LIKE '%" . $booking['booking_code'] . "%' AND transaction_type = 'income' LIMIT 1")->fetch();
        if ($existing) {
            echo "<p>⏭️ {$booking['booking_code']} - already exists (ID={$existing['id']})</p>";
            continue;
        }
        
        $amount = (float)$booking['total_paid'] ?: (float)$booking['final_price'];
        
        // Calculate OTA fee
        $source = strtolower($booking['booking_source']);
        $source = str_replace(['.com', '.co.id'], '', $source);
        $feeKey = "ota_fee_{$source}";
        if (!isset($otaFees[$feeKey])) $feeKey = 'ota_fee_other_ota';
        $feePercent = $otaFees[$feeKey] ?? 15;
        $feeAmount = $amount * ($feePercent / 100);
        $netAmount = $amount - $feeAmount;
        
        // Build description
        $desc = "Pembayaran Reservasi - {$booking['guest_name']}";
        if ($booking['room_number']) $desc .= " (Room {$booking['room_number']})";
        $desc .= " - {$booking['booking_code']} [OTA - ESTIMASI]";
        
        // Use check_in_date as transaction_date
        $txDate = $booking['check_in_date'];
        
        // Insert into cash_book
        $stmt = $conn->prepare("
            INSERT INTO cash_book (
                transaction_date, transaction_time, division_id, category_id,
                description, transaction_type, amount, payment_method,
                is_editable, created_by, created_at
            ) VALUES (DATE(?), '12:00:00', ?, ?, ?, 'income', ?, 'ota', 1, 1, NOW())
        ");
        $stmt->execute([$txDate, $divisionId, $categoryId, $desc, $netAmount]);
        $newId = $conn->lastInsertId();
        
        // Update booking_payments
        $conn->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = {$newId} WHERE booking_id = {$booking['id']}");
        
        echo "<p>✅ {$booking['booking_code']} - {$booking['guest_name']} - Rp " . number_format($netAmount, 0) . 
             " (Net after {$feePercent}% OTA fee) - Date: {$txDate} - New CbID={$newId}</p>";
        $synced++;
    }
    
    echo "<p><strong>Synced: {$synced} bookings</strong></p>";
    echo '</div>';
    
    // Summary
    echo '<div class="box success">';
    echo '<h3>✅ Repair Complete</h3>';
    echo '<p>Cash book entries telah dibuat untuk tamu yang sudah check-in.</p>';
    echo '<p>Transaction date menggunakan tanggal CHECK-IN, bukan hari ini.</p>';
    echo '<p><a class="btn btn-primary" href="?action=view">View Status</a></p>';
    echo '</div>';
}

echo '</body></html>';
