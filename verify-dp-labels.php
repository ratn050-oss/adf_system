<?php
require_once 'config/database.php';

echo "<h2>🔍 Verify DP/LUNAS Labels in Cashbook</h2>";

try {
    $db = Database::getInstance();
    
    echo "<h3>📋 Recent Reservation Payments with Status Labels:</h3>";
    
    $transactions = $db->fetchAll("
        SELECT 
            id,
            transaction_date,
            description,
            amount,
            payment_method,
            created_at
        FROM cash_book 
        WHERE description LIKE '%Pembayaran Reservasi%'
        ORDER BY id DESC 
        LIMIT 20
    ");
    
    if (empty($transactions)) {
        echo "<p style='color: orange;'>⚠️ No reservation payments found in cashbook yet.</p>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #007bff; color: white;'>";
        echo "<th>ID</th><th>Date</th><th>Description</th><th>Amount</th><th>Status Label</th>";
        echo "</tr>";
        
        foreach ($transactions as $tx) {
            $desc = $tx['description'];
            
            // Check for status labels
            $hasLunas = stripos($desc, '[LUNAS]') !== false;
            $hasDP = stripos($desc, '[DP]') !== false;
            $hasPelunasan = stripos($desc, '[PELUNASAN]') !== false;
            $hasCicilan = stripos($desc, '[CICILAN]') !== false;
            
            $statusLabel = '❌ NO LABEL';
            $bgColor = '#fff3cd';
            
            if ($hasLunas) {
                $statusLabel = '✅ LUNAS';
                $bgColor = '#d4edda';
            } elseif ($hasDP) {
                $statusLabel = '📌 DP';
                $bgColor = '#cce5ff';
            } elseif ($hasPelunasan) {
                $statusLabel = '✅ PELUNASAN';
                $bgColor = '#d4edda';
            } elseif ($hasCicilan) {
                $statusLabel = '📌 CICILAN';
                $bgColor = '#cce5ff';
            }
            
            echo "<tr style='background: {$bgColor};'>";
            echo "<td>{$tx['id']}</td>";
            echo "<td>{$tx['transaction_date']}</td>";
            echo "<td><strong>{$desc}</strong></td>";
            echo "<td>Rp " . number_format($tx['amount'], 0, ',', '.') . "</td>";
            echo "<td><strong>{$statusLabel}</strong></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Summary
        echo "<hr>";
        echo "<h3>📊 Summary:</h3>";
        $lunas = 0;
        $dp = 0;
        $pelunasan = 0;
        $cicilan = 0;
        $noLabel = 0;
        
        foreach ($transactions as $tx) {
            $desc = $tx['description'];
            if (stripos($desc, '[LUNAS]') !== false) $lunas++;
            elseif (stripos($desc, '[DP]') !== false) $dp++;
            elseif (stripos($desc, '[PELUNASAN]') !== false) $pelunasan++;
            elseif (stripos($desc, '[CICILAN]') !== false) $cicilan++;
            else $noLabel++;
        }
        
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr><td><strong>LUNAS (Full Payment)</strong></td><td>{$lunas}</td></tr>";
        echo "<tr><td><strong>DP (Down Payment)</strong></td><td>{$dp}</td></tr>";
        echo "<tr><td><strong>PELUNASAN (Settlement)</strong></td><td>{$pelunasan}</td></tr>";
        echo "<tr><td><strong>CICILAN (Installment)</strong></td><td>{$cicilan}</td></tr>";
        echo "<tr style='background: #fff3cd;'><td><strong>No Label</strong></td><td>{$noLabel}</td></tr>";
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h3>🧪 Expected Behavior:</h3>";
    echo "<ul>";
    echo "<li><strong>[LUNAS]</strong> = New reservation with full payment (paid_amount >= final_price)</li>";
    echo "<li><strong>[DP]</strong> = New reservation with partial payment (paid_amount < final_price)</li>";
    echo "<li><strong>[PELUNASAN]</strong> = Additional payment that completes the booking</li>";
    echo "<li><strong>[CICILAN]</strong> = Additional payment but still not complete</li>";
    echo "</ul>";
    
    echo "<hr>";
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px;'>";
    echo "<h3>✅ Feature Status:</h3>";
    echo "<p><strong>Code already implemented in:</strong></p>";
    echo "<ul>";
    echo "<li>✅ <code>api/create-reservation.php</code> - Lines 320-327 (Adds [LUNAS] or [DP])</li>";
    echo "<li>✅ <code>api/add-booking-payment.php</code> - Lines 216-221 (Adds [PELUNASAN] or [CICILAN])</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<hr>";
    echo "<p><a href='modules/accounting/cashbook.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>📊 View Full Cashbook</a></p>";
    echo "<p><a href='modules/frontdesk/calendar.php' style='padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px;'>➕ Create Test Reservation</a></p>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px;'>";
    echo "<h3 style='color: red;'>❌ Error:</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}
?>
