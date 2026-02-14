<?php
require_once 'config/database.php';

echo "<h2>🔍 DEBUG OTA FEE FLOW</h2>";

try {
    // 1. Check Settings Table
    echo "<h3>1️⃣ Settings Table - OTA Fees</h3>";
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_system;charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $settings = $db->query("SELECT * FROM settings WHERE setting_key LIKE 'ota_fee_%' ORDER BY setting_key")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($settings);
    echo "</pre>";
    
    // 2. Simulate OTA Fee Calculation
    echo "<hr><h3>2️⃣ Simulate OTA Fee Calculation</h3>";
    
    $testCases = [
        ['source' => 'agoda', 'amount' => 1500000],
        ['source' => 'booking', 'amount' => 1500000],
        ['source' => 'tiket', 'amount' => 1500000],
        ['source' => 'airbnb', 'amount' => 1500000],
        ['source' => 'ota', 'amount' => 1500000],
    ];
    
    foreach ($testCases as $test) {
        $originalBookingSource = $test['source'];
        $paidAmount = $test['amount'];
        
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        echo "<strong>Test: {$originalBookingSource}</strong><br>";
        
        $otaSources = ['agoda', 'booking', 'tiket', 'airbnb', 'ota'];
        
        if (in_array($originalBookingSource, $otaSources)) {
            echo "✅ IS OTA SOURCE<br>";
            
            $settingKeyMap = [
                'agoda' => 'ota_fee_agoda',
                'booking' => 'ota_fee_booking_com',
                'tiket' => 'ota_fee_tiket_com',
                'airbnb' => 'ota_fee_airbnb',
                'ota' => 'ota_fee_other_ota'
            ];
            
            $settingKey = $settingKeyMap[$originalBookingSource] ?? 'ota_fee_other_ota';
            echo "Setting Key: <strong>{$settingKey}</strong><br>";
            
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$settingKey]);
            $feeQuery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($feeQuery) {
                echo "Found Setting: <strong>{$feeQuery['setting_value']}%</strong><br>";
                
                $otaFeePercent = (float)($feeQuery['setting_value'] ?? 0);
                if ($otaFeePercent > 0) {
                    $otaFeeAmount = ($paidAmount * $otaFeePercent) / 100;
                    $netAmount = $paidAmount - $otaFeeAmount;
                    
                    echo "Gross: Rp " . number_format($paidAmount, 0, ',', '.') . "<br>";
                    echo "OTA Fee ({$otaFeePercent}%): -Rp " . number_format($otaFeeAmount, 0, ',', '.') . "<br>";
                    echo "Net: Rp " . number_format($netAmount, 0, ',', '.') . "<br>";
                    
                    echo "<div style='background: #d4edda; padding: 10px; margin-top: 10px;'>";
                    echo "✅ <strong>POPUP MESSAGE SHOULD BE:</strong><br>";
                    echo "Gross: Rp " . number_format($paidAmount, 0, ',', '.') . "<br>";
                    echo "OTA Fee ({$otaFeePercent}%): -Rp " . number_format($otaFeeAmount, 0, ',', '.') . "<br>";
                    echo "Net: Rp " . number_format($netAmount, 0, ',', '.') . " → Bank<br>";
                    echo "</div>";
                } else {
                    echo "❌ Fee percent is 0<br>";
                }
            } else {
                echo "❌ Setting NOT FOUND in database<br>";
            }
        } else {
            echo "❌ NOT OTA SOURCE<br>";
        }
        echo "</div>";
    }
    
    // 3. Check actual booking sources in database
    echo "<hr><h3>3️⃣ Actual Booking Sources in Database</h3>";
    
    $businessDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=adf_narayana_hotel;charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $sources = $businessDb->query("SELECT DISTINCT booking_source FROM bookings ORDER BY booking_source")->fetchAll(PDO::FETCH_COLUMN);
    echo "<pre>";
    print_r($sources);
    echo "</pre>";
    
    echo "<hr><h3>4️⃣ Recent Bookings (Last 5)</h3>";
    $recentBookings = $businessDb->query("
        SELECT id, booking_code, booking_source, room_price, paid_amount, created_at 
        FROM bookings 
        ORDER BY id DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>ID</th><th>Code</th><th>Source</th><th>Price</th><th>Paid</th><th>Created</th></tr>";
    foreach ($recentBookings as $b) {
        echo "<tr>";
        echo "<td>{$b['id']}</td>";
        echo "<td>{$b['booking_code']}</td>";
        echo "<td><strong>{$b['booking_source']}</strong></td>";
        echo "<td>Rp " . number_format($b['room_price'], 0, ',', '.') . "</td>";
        echo "<td>Rp " . number_format($b['paid_amount'], 0, ',', '.') . "</td>";
        echo "<td>{$b['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<hr>";
echo "<p><a href='check-ota-fee-settings.php'>🔍 Check OTA Settings</a> | ";
echo "<a href='modules/frontdesk/calendar.php'>📅 Calendar</a></p>";
?>
