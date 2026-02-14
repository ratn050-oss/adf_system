<?php
/**
 * CREATE CATEGORY: Setoran Modal Owner
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';

$db = Database::getInstance();

echo "<h2>📝 CREATE CATEGORY: Setoran Modal Owner</h2>";
echo "<hr>";

try {
    // Check if category exists
    $existing = $db->fetchOne("SELECT * FROM categories WHERE category_name = 'Setoran Modal Owner'");
    
    if ($existing) {
        echo "ℹ️  Category <strong>Setoran Modal Owner</strong> already exists (ID: {$existing['id']})<br>";
    } else {
        // Get first division as default
        $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 ORDER BY id LIMIT 1");
        
        if (!$division) {
            echo "❌ No active division found! Cannot create category.<br>";
            exit;
        }
        
        // Insert category
        $db->insert('categories', [
            'category_name' => 'Setoran Modal Owner',
            'category_type' => 'income',
            'division_id' => $division['id'],
            'is_active' => 1
        ]);
        
        $categoryId = $db->getConnection()->lastInsertId();
        
        echo "✅ Category <strong>Setoran Modal Owner</strong> created successfully (ID: {$categoryId})<br>";
    }
    
    echo "<hr>";
    echo "<h3>All Income Categories:</h3>";
    
    $incomeCategories = $db->fetchAll("SELECT * FROM categories WHERE category_type = 'income' ORDER BY category_name");
    
    echo "<ul>";
    foreach ($incomeCategories as $cat) {
        $highlight = ($cat['category_name'] === 'Setoran Modal Owner') ? " <strong style='color: green;'>← NEW!</strong>" : "";
        echo "<li>{$cat['category_name']} (Division: {$cat['division_id']}){$highlight}</li>";
    }
    echo "</ul>";
    
    echo "<hr>";
    echo "<a href='modules/cashbook/add.php' style='padding: 1rem 2rem; background: #10b981; color: white; text-decoration: none; border-radius: 8px; display: inline-block;'>Go to Cashbook Form</a>";
    
} catch (Exception $e) {
    echo "<h3>❌ ERROR!</h3>";
    echo "<pre style='background: #fee; padding: 1rem; border-left: 4px solid #f00;'>";
    echo $e->getMessage();
    echo "</pre>";
}
?>
