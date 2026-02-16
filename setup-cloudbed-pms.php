<?php
/**
 * Cloudbed PMS Integration Setup
 * Script untuk setup database dan konfigurasi awal Cloudbed PMS
 */

require_once 'config/config.php';

// Check if user has admin access
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Access denied. Please login first.');
}

echo "<h2>Setting up Cloudbed PMS Integration...</h2>";

try {
    $db = Database::getInstance();
    
    // Read and execute SQL file
    $sqlFile = 'database-cloudbed-pms.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    echo "<p>Reading SQL file: $sqlFile</p>";
    
    $sql = file_get_contents($sqlFile);
    
    if (!$sql) {
        throw new Exception("Could not read SQL file");
    }
    
    // Split into individual queries
    $queries = preg_split('/;[\r\n]+/', $sql);
    $queries = array_filter(array_map('trim', $queries), function($query) {
        return !empty($query) && 
               !preg_match('/^--/', $query) && 
               !preg_match('/^\/\*/', $query) &&
               !preg_match('/^DELIMITER/', $query) &&
               $query !== '$$';
    });
    
    echo "<p>Found " . count($queries) . " SQL queries to execute.</p>";
    echo "<hr>";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($queries as $index => $query) {
        $query = trim($query);
        if (empty($query)) continue;
        
        echo "<div style='margin-bottom: 10px;'>";
        echo "<strong>Query " . ($index + 1) . ":</strong> ";
        
        // Show first 100 characters of query for identification
        $preview = strlen($query) > 100 ? substr($query, 0, 100) . '...' : $query;
        echo "<code>" . htmlspecialchars($preview) . "</code><br>";
        
        try {
            $result = $db->execute($query);
            echo "<span style='color: green;'>✓ SUCCESS</span>";
            $successCount++;
        } catch (Exception $e) {
            $error = $e->getMessage();
            // Ignore "already exists" errors as they're expected
            if (strpos($error, 'already exists') !== false || 
                strpos($error, 'Duplicate') !== false ||
                strpos($error, 'column already exists') !== false) {
                echo "<span style='color: orange;'>⚠️ SKIPPED (already exists)</span>";
                $successCount++;
            } else {
                echo "<span style='color: red;'>✗ ERROR: " . htmlspecialchars($error) . "</span>";
                $errorCount++;
            }
        }
        echo "</div>";
    }
    
    echo "<hr>";
    echo "<h3>Setup Summary</h3>";
    echo "<p><strong>Successful queries:</strong> $successCount</p>";
    echo "<p><strong>Failed queries:</strong> $errorCount</p>";
    
    if ($errorCount === 0) {
        echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>🎉 Cloudbed PMS Setup Complete!</h4>";
        echo "<p>All database tables and configurations have been created successfully.</p>";
        echo "<p><strong>Next steps:</strong></p>";
        echo "<ol>";
        echo "<li><a href='modules/settings/cloudbed-pms.php'>Configure your Cloudbed credentials</a></li>";
        echo "<li>Test the connection to verify setup</li>";
        echo "<li>Run initial sync to import existing reservations</li>";
        echo "<li>Configure auto-sync settings</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>⚠️ Setup Completed with Errors</h4>";
        echo "<p>Some queries failed during setup. This might be because:</p>";
        echo "<ul>";
        echo "<li>Tables or columns already exist (this is usually OK)</li>";
        echo "<li>Database permissions issues</li>";
        echo "<li>MySQL version compatibility issues</li>";
        echo "</ul>";
        echo "<p>Check the errors above and contact your developer if needed.</p>";
        echo "</div>";
    }
    
    // Show integration status
    echo "<hr>";
    echo "<h3>Integration Status</h3>";
    
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'cloudbed_%'");
    
    if ($settings) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
        echo "<tr><th style='padding: 10px; background: #f8f9fa;'>Setting</th><th style='padding: 10px; background: #f8f9fa;'>Value</th><th style='padding: 10px; background: #f8f9fa;'>Status</th></tr>";
        
        foreach ($settings as $setting) {
            $key = $setting['setting_key'];
            $value = $setting['setting_value'];
            
            // Hide sensitive values
            if (strpos($key, 'secret') !== false || strpos($key, 'token') !== false) {
                $displayValue = empty($value) ? '<em>Not set</em>' : '<em>***configured***</em>';
            } else {
                $displayValue = empty($value) ? '<em>Not set</em>' : htmlspecialchars($value);
            }
            
            $status = empty($value) ? 
                "<span style='color: orange;'>⚠️ Needs configuration</span>" : 
                "<span style='color: green;'>✓ Configured</span>";
            
            echo "<tr>";
            echo "<td style='padding: 8px;'>" . str_replace('cloudbed_', '', $key) . "</td>";
            echo "<td style='padding: 8px;'>" . $displayValue . "</td>";
            echo "<td style='padding: 8px;'>" . $status . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
    // Show created tables
    echo "<hr>";
    echo "<h3>Database Tables Verification</h3>";
    
    $pmsTables = [
        'cloudbed_api_log' => 'API call logging',
        'cloudbed_sync_log' => 'Sync status tracking', 
        'cloudbed_room_mapping' => 'Room type mapping',
        'cloudbed_webhook_events' => 'Webhook event storage',
        'cloudbed_rate_sync' => 'Rate synchronization'
    ];
    
    foreach ($pmsTables as $table => $description) {
        try {
            $result = $db->fetchOne("SHOW TABLES LIKE '$table'");
            if ($result) {
                // Get row count
                $countResult = $db->fetchOne("SELECT COUNT(*) as count FROM $table");
                $rowCount = $countResult['count'];
                echo "<p>✓ <strong>$table</strong> - $description ($rowCount rows)</p>";
            } else {
                echo "<p>✗ <strong>$table</strong> - $description (missing)</p>";
            }
        } catch (Exception $e) {
            echo "<p>⚠️ <strong>$table</strong> - Error checking: " . $e->getMessage() . "</p>";
        }
    }
    
    // Check modified existing tables
    echo "<hr>";
    echo "<h3>Enhanced Tables Verification</h3>";
    
    $enhancedTables = [
        'reservasi' => ['cloudbed_reservation_id', 'sync_status', 'sync_error'],
        'guest' => ['cloudbed_guest_id', 'sync_status']
    ];
    
    foreach ($enhancedTables as $table => $columns) {
        echo "<p><strong>$table table enhancements:</strong></p>";
        foreach ($columns as $column) {
            try {
                $result = $db->fetchOne("SHOW COLUMNS FROM $table LIKE '$column'");
                if ($result) {
                    echo "<span style='color: green; margin-left: 20px;'>✓ $column column added</span><br>";
                } else {
                    echo "<span style='color: red; margin-left: 20px;'>✗ $column column missing</span><br>";
                }
            } catch (Exception $e) {
                echo "<span style='color: orange; margin-left: 20px;'>⚠️ $column - " . $e->getMessage() . "</span><br>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>Setup Failed</h4>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<div style='background: #e7f3ff; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
echo "<h4><i class='fas fa-info-circle'></i> What's been set up:</h4>";
echo "<ul>";
echo "<li><strong>Database Tables:</strong> Complete schema for PMS integration</li>";
echo "<li><strong>API Logging:</strong> Track all Cloudbed API calls and performance</li>";
echo "<li><strong>Sync Management:</strong> Monitor reservation and guest data sync</li>";
echo "<li><strong>Room Mapping:</strong> Map your rooms to Cloudbed room types</li>";
echo "<li><strong>Webhook Support:</strong> Real-time updates from Cloudbed</li>";
echo "<li><strong>Rate Sync:</strong> Synchronize room rates between systems</li>";
echo "</ul>";
echo "</div>";

echo "<p>";
echo "<a href='index.php' class='btn btn-secondary'>← Back to Dashboard</a> | ";
echo "<a href='modules/settings/cloudbed-pms.php' class='btn btn-primary'>Configure Cloudbed →</a> | ";
echo "<a href='test-pms-connection.php' class='btn btn-success'>Test Connection</a>";
echo "</p>";
?>