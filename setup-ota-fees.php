<?php
define('APP_ACCESS', true);
require_once './config/config.php';
require_once './config/database.php';

$db = Database::getInstance();

echo "<h2>OTA Fee Settings Check & Auto-Setup</h2>";
echo "<hr>";

// Default OTA fees
$defaultFees = [
    'ota_fee_agoda' => 15,
    'ota_fee_booking_com' => 12,
    'ota_fee_tiket_com' => 10,
    'ota_fee_airbnb' => 3,
    'ota_fee_other_ota' => 10
];

try {
    echo "<h3>Current OTA Fee Settings:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>Provider</th><th>Setting Key</th><th>Fee %</th><th>Status</th></tr>";
    
    $inserted = 0;
    $existing = 0;
    
    foreach ($defaultFees as $key => $defaultFee) {
        // Check if exists
        $current = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        
        if ($current) {
            $feeValue = $current['setting_value'];
            $status = "✅ Exists";
            $existing++;
        } else {
            // Insert default
            $db->query(
                "INSERT INTO settings (setting_key, setting_value, setting_type, created_at) VALUES (?, ?, 'number', NOW())",
                [$key, $defaultFee]
            );
            $feeValue = $defaultFee;
            $status = "✨ Created with default";
            $inserted++;
        }
        
        $providerName = str_replace(['ota_fee_', '_'], ['', ' '], $key);
        $providerName = ucwords($providerName);
        
        echo "<tr>";
        echo "<td><strong>{$providerName}</strong></td>";
        echo "<td><code>{$key}</code></td>";
        echo "<td style='text-align: center; font-weight: bold; color: #ef4444;'>{$feeValue}%</td>";
        echo "<td>{$status}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    echo "<hr>";
    echo "<div style='background: #dcfce7; padding: 1rem; border-left: 4px solid #10b981; margin: 1rem 0;'>";
    if ($inserted > 0) {
        echo "<p style='color: #065f46; font-weight: bold;'>✅ {$inserted} OTA fee setting(s) created with default values!</p>";
    }
    if ($existing > 0) {
        echo "<p style='color: #065f46;'>ℹ️  {$existing} OTA fee setting(s) already exist</p>";
    }
    echo "<p style='color: #065f46;'>You can adjust these values in <a href='modules/frontdesk/settings.php?tab=ota_fees'>FrontDesk Settings → OTA Fees</a></p>";
    echo "</div>";
    
    echo "<h3>Test OTA Fee Calculation:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>Booking Source</th><th>Fee %</th><th>Gross Amount</th><th>Fee Amount</th><th>Net to Hotel</th></tr>";
    
    $testAmount = 1450000; // Rp 1.450.000
    
    $testSources = [
        ['source' => 'agoda', 'key' => 'ota_fee_agoda'],
        ['source' => 'booking', 'key' => 'ota_fee_booking_com'],
        ['source' => 'tiket', 'key' => 'ota_fee_tiket_com'],
        ['source' => 'airbnb', 'key' => 'ota_fee_airbnb'],
        ['source' => 'ota', 'key' => 'ota_fee_other_ota'],
    ];
    
    foreach ($testSources as $test) {
        $fee = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$test['key']]);
        $feePercent = (float)($fee['setting_value'] ?? 0);
        $feeAmount = ($testAmount * $feePercent) / 100;
        $netAmount = $testAmount - $feeAmount;
        
        echo "<tr>";
        echo "<td><strong>" . ucfirst($test['source']) . "</strong></td>";
        echo "<td style='text-align: center; color: #ef4444; font-weight: bold;'>{$feePercent}%</td>";
        echo "<td style='text-align: right;'>Rp " . number_format($testAmount, 0, ',', '.') . "</td>";
        echo "<td style='text-align: right; color: #dc2626;'>-Rp " . number_format($feeAmount, 0, ',', '.') . "</td>";
        echo "<td style='text-align: right; color: #059669; font-weight: bold;'>Rp " . number_format($netAmount, 0, ',', '.') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='modules/frontdesk/calendar.php' style='display: inline-block; padding: 0.75rem 1.5rem; background: #6366f1; color: white; text-decoration: none; border-radius: 6px;'>← Back to Calendar</a></p>";
?>
