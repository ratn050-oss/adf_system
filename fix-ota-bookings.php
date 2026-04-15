<?php
/**
 * HOTFIX: Update existing OTA bookings
 * 1. Fix bookings dengan booking_source kosong (set ke 'walk_in')
 * 2. Fix OTA bookings dengan paid_amount = 0 (set ke final_price)
 */

define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

echo "<pre style='font-family: monospace; padding: 20px; background: #f5f5f5; border-radius: 8px;'>\n";
echo "🔧 OTA BOOKINGS HOTFIX\n";
echo str_repeat("=", 60) . "\n\n";

// STEP 1: Fix bookings dengan booking_source kosong
echo "📋 STEP 1: Fix empty booking_source\n";
echo "Mencari bookings dengan booking_source kosong atau NULL...\n\n";

$emptySourceCount = $db->fetchOne("
    SELECT COUNT(*) as cnt FROM bookings 
    WHERE (booking_source = '' OR booking_source IS NULL)
    AND status IN ('confirmed', 'pending')
")->cnt ?? 0;

if ($emptySourceCount > 0) {
    echo "  Found: $emptySourceCount bookings dengan booking_source kosong\n\n";
    
    // Ambil details
    $emptySource = $db->fetchAll(
        "SELECT id, booking_code, final_price FROM bookings 
         WHERE (booking_source = '' OR booking_source IS NULL)
         AND status IN ('confirmed', 'pending')
         LIMIT 50"
    );
    
    foreach ($emptySource as $bk) {
        echo "  ✓ {$bk['booking_code']}\n";
    }
    
    echo "\n  🔄 Updating to 'walk_in' (Direct)...\n";
    $fixedEmpty = $db->query(
        "UPDATE bookings 
         SET booking_source = 'walk_in'
         WHERE (booking_source = '' OR booking_source IS NULL)
         AND status IN ('confirmed', 'pending')"
    );
    
    if ($fixedEmpty) {
        echo "  ✅ Fixed $emptySourceCount bookings\n";
    } else {
        echo "  ❌ Update failed\n";
    }
} else {
    echo "  ✅ No bookings dengan empty booking_source\n";
}

// STEP 2: Fix OTA bookings dengan paid_amount = 0
echo "\n" . str_repeat("-", 60) . "\n";
echo "📋 STEP 2: Fix OTA bookings dengan paid_amount = 0\n";
echo "Mencari OTA bookings yang belum di-set paid_amount...\n\n";

$otaSources = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota', 'expedia', 'pegipegi'];

$otaCount = 0;
foreach ($otaSources as $source) {
    $count = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM bookings 
         WHERE booking_source = ? 
         AND status IN ('confirmed', 'pending')
         AND (paid_amount = 0 OR paid_amount IS NULL)",
        [$source]
    )->cnt ?? 0;
    
    $otaCount += $count;
}

if ($otaCount > 0) {
    echo "  Found: $otaCount OTA bookings dengan paid_amount = 0\n\n";
    
    // Get details
    $otaBookings = $db->fetchAll(
        "SELECT booking_code, booking_source, final_price FROM bookings 
         WHERE booking_source IN (?, ?, ?, ?, ?, ?, ?, ?)
         AND status IN ('confirmed', 'pending')
         AND (paid_amount = 0 OR paid_amount IS NULL)
         LIMIT 50",
        $otaSources
    );
    
    foreach ($otaBookings as $bk) {
        $price = number_format($bk['final_price'], 0, ',', '.');
        echo "  ✓ {$bk['booking_code']} ({$bk['booking_source']}): Rp $price\n";
    }
    
    echo "\n  🔄 Updating to paid_amount = final_price...\n";
    $fixedOta = $db->query(
        "UPDATE bookings 
         SET paid_amount = final_price, 
             payment_status = 'paid',
             updated_at = NOW()
         WHERE booking_source IN (?, ?, ?, ?, ?, ?, ?, ?)
         AND status IN ('confirmed', 'pending')
         AND (paid_amount = 0 OR paid_amount IS NULL)",
        $otaSources
    );
    
    if ($fixedOta) {
        echo "  ✅ Fixed $otaCount OTA bookings\n";
    } else {
        echo "  ❌ Update failed\n";
    }
} else {
    echo "  ✅ Semua OTA bookings sudah OK\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✨ SELESAI!\n";
echo "\n📊 RINGKASAN:\n";
echo "  - Empty booking_source fixed: " . ($emptySourceCount > 0 ? "$emptySourceCount ✅" : "0 ✅") . "\n";
echo "  - OTA paid_amount fixed: " . ($otaCount > 0 ? "$otaCount ✅" : "0 ✅") . "\n";
echo "\n💡 Hasil:\n";
echo "  ✅ Booking lama dengan empty source sekarang jadi 'walk_in'\n";
echo "  ✅ OTA bookings sekarang marked as PAID (payment_status = 'paid')\n";
echo "  ✅ Saat check-in: tidak akan minta pembayaran lagi ✨\n";
echo str_repeat("=", 60) . "\n";
echo "</pre>";
?>

