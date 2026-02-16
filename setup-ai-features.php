<?php
/**
 * AI Features Database Setup
 * Script untuk menginstall database tables yang diperlukan untuk fitur AI
 */

require_once 'config/config.php';

// Check if user has admin access
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Access denied. Please login first.');
}

echo "<h2>Setting up AI Features Database...</h2>";

try {
    $db = Database::getInstance();
    
    // Read and execute SQL file
    $sqlFile = 'database-ai-integrations.sql';
    
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    echo "<p>Reading SQL file: $sqlFile</p>";
    
    $sql = file_get_contents($sqlFile);
    
    if (!$sql) {
        throw new Exception("Could not read SQL file");
    }
    
    // Split into individual queries
    $queries = array_filter(
        array_map('trim', preg_split('/;[\r\n]+/', $sql)),
        function($query) {
            return !empty($query) && 
                   !preg_match('/^--/', $query) && 
                   !preg_match('/^\/\*/', $query);
        }
    );
    
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
            echo "<span style='color: red;'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span>";
            $errorCount++;
        }
        echo "</div>";
    }
    
    echo "<hr>";
    echo "<h3>Setup Summary</h3>";
    echo "<p><strong>Successful queries:</strong> $successCount</p>";
    echo "<p><strong>Failed queries:</strong> $errorCount</p>";
    
    if ($errorCount === 0) {
        echo "<div style='background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
        echo "<h4>🎉 Setup Complete!</h4>";
        echo "<p>All AI features database tables have been created successfully.</p>";
        echo "<p><strong>Next steps:</strong></p>";
        echo "<ol>";
        echo "<li><a href='modules/settings/api-integrations.php'>Configure your OpenAI API key</a></li>";
        echo "<li><a href='modules/settings/api-integrations.php'>Set up Cloudbed integration (optional)</a></li>";
        echo "<li><a href='ai-features-demo.php'>Try the AI features demo</a></li>";
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
    
    // Check current integration status
    echo "<hr>";
    echo "<h3>Current Integration Status</h3>";
    
    $settings = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%api%' OR setting_key LIKE '%openai%' OR setting_key LIKE '%cloudbed%'");
    
    if ($settings) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 20px 0;'>";
        echo "<tr><th style='padding: 10px; background: #f8f9fa;'>Setting Key</th><th style='padding: 10px; background: #f8f9fa;'>Current Value</th><th style='padding: 10px; background: #f8f9fa;'>Status</th></tr>";
        
        foreach ($settings as $setting) {
            $key = $setting['setting_key'];
            $value = $setting['setting_value'];
            
            // Hide sensitive values
            if (strpos($key, 'secret') !== false || strpos($key, 'key') !== false) {
                $displayValue = empty($value) ? '<em>Not set</em>' : '<em>***hidden***</em>';
            } else {
                $displayValue = empty($value) ? '<em>Not set</em>' : htmlspecialchars($value);
            }
            
            $status = empty($value) ? 
                "<span style='color: orange;'>⚠️ Not configured</span>" : 
                "<span style='color: green;'>✓ Configured</span>";
            
            echo "<tr>";
            echo "<td style='padding: 8px;'>" . htmlspecialchars($key) . "</td>";
            echo "<td style='padding: 8px;'>" . $displayValue . "</td>";
            echo "<td style='padding: 8px;'>" . $status . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
    // Show created tables
    echo "<hr>";
    echo "<h3>Verification: Created Tables</h3>";
    
    $aiTables = [
        'review_analysis',
        'guest_sync',
        'daily_reports',
        'api_usage_log',
        'ai_content_cache',
        'guest_communications',
        'rate_recommendations'
    ];
    
    foreach ($aiTables as $table) {
        try {
            $result = $db->fetchOne("SHOW TABLES LIKE '$table'");
            if ($result) {
                // Get row count
                $countResult = $db->fetchOne("SELECT COUNT(*) as count FROM $table");
                $rowCount = $countResult['count'];
                echo "<p>✓ Table <strong>$table</strong> exists ($rowCount rows)</p>";
            } else {
                echo "<p>✗ Table <strong>$table</strong> does not exist</p>";
            }
        } catch (Exception $e) {
            echo "<p>⚠️ Table <strong>$table</strong>: " . $e->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 20px 0;'>";
    echo "<h4>Setup Failed</h4>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}

echo "<hr>";
echo "<p><a href='index.php'>← Back to Dashboard</a> | <a href='ai-features-demo.php'>Try AI Features Demo →</a></p>";
?>