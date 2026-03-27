<?php
/**
 * Migration: Add Missing Tables
 * Adds user_preferences and settings tables to all business databases
 */

define('APP_ACCESS', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

echo "🔄 Migration: Adding Missing Tables\n";
echo str_repeat("=", 60) . "\n\n";

// SQL for user_preferences table
$userPreferencesSql = "
CREATE TABLE IF NOT EXISTS user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    theme VARCHAR(50) DEFAULT 'dark',
    language VARCHAR(20) DEFAULT 'id',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

// SQL for settings table
$settingsSql = "
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    setting_type VARCHAR(50) DEFAULT 'text',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

try {
    // Add user_preferences table
    echo "📝 Creating user_preferences table...\n";
    $pdo->exec($userPreferencesSql);
    echo "   ✅ user_preferences table created successfully\n\n";

    // Add settings table
    echo "📝 Creating settings table...\n";
    $pdo->exec($settingsSql);
    echo "   ✅ settings table created successfully\n\n";

    // Insert default settings
    echo "📝 Inserting default settings...\n";
    $defaultSettings = [
        ['company_name', '', 'text', 'Company name'],
        ['company_tagline', '', 'text', 'Company tagline'],
        ['company_address', '', 'text', 'Company address'],
        ['company_phone', '', 'text', 'Company phone number'],
        ['company_email', '', 'text', 'Company email'],
        ['company_website', '', 'text', 'Company website'],
        ['company_logo', '', 'file', 'Company logo'],
        ['invoice_logo', '', 'file', 'Invoice logo'],
        ['developer_whatsapp', '', 'text', 'Developer WhatsApp number'],
        ['login_background', '', 'file', 'Login page background'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description)
        VALUES (?, ?, ?, ?)
    ");

    $inserted = 0;
    foreach ($defaultSettings as $setting) {
        $stmt->execute($setting);
        if ($stmt->rowCount() > 0) {
            $inserted++;
        }
    }
    echo "   ✅ Inserted $inserted default settings\n\n";

    echo str_repeat("=", 60) . "\n";
    echo "✨ Migration completed successfully!\n";
    echo "\n📋 Summary:\n";
    echo "   • user_preferences table: Added\n";
    echo "   • settings table: Added\n";
    echo "   • Default settings: Inserted\n";
    echo "\n✅ You can now change themes without errors!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
