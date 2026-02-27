<?php
/**
 * Remove Duplicate CQC Entries
 * Cleans up duplicate CQC records from database
 */

header('Content-Type: text/html; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$masterDb = 'adf_system';

echo "<!DOCTYPE html>\n<html><head><meta charset='utf-8'><title>Cleanup CQC Duplicates</title>\n";
echo "<style>body{font-family:Arial;padding:20px;}\n";
echo ".ok{background:#e8f5e9;padding:15px;margin:10px 0;border-left:4px solid #4caf50;}\n";
echo ".warning{background:#fff3e0;padding:15px;margin:10px 0;border-left:4px solid #ff9800;}\n";
echo "code{background:#f0f0f0;padding:3px 6px;border-radius:3px;}\n";
echo "</style></head><body>\n";

echo "<h1>🧹 CQC Duplicate Cleanup</h1>\n";

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 1: Check for duplicate CQC business records
    echo "<h2>Step 1️⃣: Checking for Duplicate CQC Business Records</h2>\n";
    
    $cqcRecords = $pdo->query("
        SELECT id, business_code, slug, business_name, is_active
        FROM businesses
        WHERE LOWER(business_code) LIKE '%cqc%' OR LOWER(slug) LIKE '%cqc%' OR LOWER(business_name) LIKE '%cqc%'
        ORDER BY id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($cqcRecords) > 1) {
        echo "<div class='warning'>\n";
        echo "⚠️ Found " . count($cqcRecords) . " CQC records (should be 1):<br>\n";
        foreach ($cqcRecords as $rec) {
            echo "   - ID " . $rec['id'] . ": " . $rec['business_code'] . " (" . $rec['business_name'] . ")<br>\n";
        }
        
        echo "<br>Keeping ID 7, removing others...<br>\n";
        
        // Delete duplicates, keep ID 7
        foreach ($cqcRecords as $rec) {
            if ($rec['id'] != 7) {
                echo "   Deleting ID " . $rec['id'] . "... ";
                
                // First remove assignments
                $pdo->exec("DELETE FROM user_business_assignment WHERE business_id = " . $rec['id']);
                // Remove menu configs
                $pdo->exec("DELETE FROM business_menu_config WHERE business_id = " . $rec['id']);
                // Remove permissions
                $pdo->exec("DELETE FROM user_menu_permissions WHERE business_id = " . $rec['id']);
                // Delete business
                $pdo->exec("DELETE FROM businesses WHERE id = " . $rec['id']);
                
                echo "✅<br>\n";
            }
        }
        echo "</div>\n";
    } else {
        echo "<div class='ok'>✅ Only 1 CQC business record found (correct)</div>\n";
    }
    
    // Step 2: Check for duplicate assignments for lucca
    echo "<h2>Step 2️⃣: Checking Lucca's Assignments</h2>\n";
    
    $lucca = $pdo->query("SELECT id, username FROM users WHERE username='lucca' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lucca) {
        $assignments = $pdo->query("
            SELECT count(*) as cnt, business_id
            FROM user_business_assignment
            WHERE user_id = " . $lucca['id'] . "
            GROUP BY business_id
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $hasDups = false;
        foreach ($assignments as $assign) {
            if ($assign['cnt'] > 1) {
                $hasDups = true;
                echo "<div class='warning'>\n";
                echo "⚠️ Lucca is assigned " . $assign['cnt'] . " times to business " . $assign['business_id'] . "\n";
                echo "</div>\n";
                
                // Keep only 1 assignment
                $keep = $pdo->query("
                    SELECT id FROM user_business_assignment
                    WHERE user_id = " . $lucca['id'] . " AND business_id = " . $assign['business_id'] . "
                    LIMIT 1
                ")->fetch(PDO::FETCH_ASSOC);
                
                $pdo->exec("
                    DELETE FROM user_business_assignment
                    WHERE user_id = " . $lucca['id'] . " AND business_id = " . $assign['business_id'] . "
                    AND id != " . $keep['id']
                );
                
                echo "   Kept 1 assignment, removed duplicates\n";
            }
        }
        
        if (!$hasDups) {
            echo "<div class='ok'>✅ No duplicate assignments for lucca</div>\n";
        }
    }
    
    // Step 3: Final verification
    echo "<h2>Step 3️⃣: Final Verification</h2>\n";
    
    $cqcCount = $pdo->query("SELECT COUNT(*) as cnt FROM businesses WHERE LOWER(business_code) LIKE '%cqc%' OR LOWER(business_name) LIKE '%cqc%'")->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    echo "<div class='ok'>\n";
    echo "✅ CQC business records: " . $cqcCount . "<br>\n";
    
    if ($lucca) {
        $assignCount = $pdo->query("SELECT COUNT(DISTINCT business_id) as cnt FROM user_business_assignment WHERE user_id = " . $lucca['id'])->fetch(PDO::FETCH_ASSOC)['cnt'];
        echo "✅ Lucca's assigned businesses: " . $assignCount . "\n";
    }
    echo "</div>\n";
    
    echo "<div class='ok' style='margin-top:30px;'>\n";
    echo "<strong>✨ Cleanup Complete!</strong><br>\n";
    echo "Next: Go to https://adfsystem.online/ and test if CQC still appears multiple times<br>\n";
    echo "If yes: There may be cached data. Try CTRL+F5 hard refresh to clear cache.\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<div style='background:#ffebee;padding:15px;border-left:4px solid #f44336;'>\n";
    echo "❌ Error: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "</div>\n";
}

echo "</body></html>\n";
?>
