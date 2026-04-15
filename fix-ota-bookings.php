<?php
/**
 * HOTFIX: Update existing OTA bookings
 * Sets paid_amount = final_price untuk OTA bookings yang belum di-set
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

echo "<pre style='font-family: monospace; padding: 20px; background: #f5f5f5; border-radius: 8px;'>\n";
echo "🔧 OTA BOOKINGS HOTFIX\n";
echo str_repeat("=", 60) . "\n\n";

// First, check how many bookings need fixing
$checkSql = "SELECT COUNT(*) as cnt FROM bookings 
             WHERE booking_source IN ('agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota', 'expedia', 'pegipegi')
             AND status IN ('confirmed', 'pending')
             AND (paid_amount = 0 OR paid_amount IS NULL)";

$check = $db->fetchOne($checkSql);
$toFix = $check['cnt'] ?? 0;

echo "📊 Bookings to fix: $toFix\n\n";

if ($toFix > 0) {
    echo "🔄 Updating...\n\n";
    
    // Get details first
    $bookings = $db->fetchAll(
        "SELECT booking_code, booking_source, final_price, paid_amount 
         FROM bookings 
         WHERE booking_source IN ('agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota', 'expedia', 'pegipegi')
         AND status IN ('confirmed', 'pending')
         AND (paid_amount = 0 OR paid_amount IS NULL)
         LIMIT 100"
    );
    
    foreach ($bookings as $bk) {
        $code = $bk['booking_code'];
        $source = $bk['booking_source'];
        $price = number_format($bk['final_price'], 0, ',', '.');
        echo "  ✓ $code ($source): Rp $price\n";
    }
    
    echo "\n";
    
    // Now UPDATE
    $result = $db->query(
        "UPDATE bookings 
         SET paid_amount = final_price, 
             payment_status = 'paid',
             updated_at = NOW()
         WHERE booking_source IN ('agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota', 'expedia', 'pegipegi')
         AND status IN ('confirmed', 'pending')
         AND (paid_amount = 0 OR paid_amount IS NULL)"
    );
    
    if ($result) {
        echo "✅ SUCCESS! Fixed $toFix OTA bookings\n";
        echo "\nBookings sekarang sudah marked as PAID (payment_status = 'paid')\n";
        echo "Saat check-in: tidak akan minta pembayaran lagi ✨\n";
    } else {
        echo "❌ ERROR: Update failed\n";
    }
} else {
    echo "✅ Semua OTA bookings sudah OK - tidak ada yang perlu di-fix\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "</pre>";
?>

