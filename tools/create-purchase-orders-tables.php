<?php
/**
 * Create Purchase Orders tables in all business databases
 */

echo "Creating Purchase Orders tables in all business databases...\n";
echo str_repeat("=", 60) . "\n";

$databases = [
    'narayana_benscafe',
    'narayana_hotel',
    'narayana_eatmeet',
    'narayana_pabrikkapal',
    'narayana_furniture',
    'narayana_karimunjawa'
];

$createHeaderSQL = "
CREATE TABLE IF NOT EXISTS `purchase_orders_header` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `po_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `status` enum('draft','submitted','approved','completed','cancelled') DEFAULT 'draft',
  `subtotal` decimal(15,2) NOT NULL,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`),
  CONSTRAINT `purchase_orders_header_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `purchase_orders_header_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `purchase_orders_header_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

$createDetailSQL = "
CREATE TABLE IF NOT EXISTS `purchase_orders_detail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_header_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `item_description` text DEFAULT NULL,
  `unit_of_measure` varchar(20) DEFAULT 'pcs',
  `quantity` decimal(10,2) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL,
  `total_price` decimal(15,2) NOT NULL,
  `division_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `po_header_id` (`po_header_id`),
  KEY `division_id` (`division_id`),
  CONSTRAINT `purchase_orders_detail_ibfk_1` FOREIGN KEY (`po_header_id`) REFERENCES `purchase_orders_header` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_orders_detail_ibfk_2` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
";

foreach ($databases as $dbName) {
    echo "\nProcessing: $dbName\n";
    echo str_repeat("-", 60) . "\n";
    
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=$dbName;charset=utf8mb4",
            "root",
            "",
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Create purchase_orders_header table
        try {
            $pdo->exec($createHeaderSQL);
            echo "  ✅ purchase_orders_header created/verified\n";
        } catch (PDOException $e) {
            echo "  ❌ purchase_orders_header: " . $e->getMessage() . "\n";
        }
        
        // Create purchase_orders_detail table
        try {
            $pdo->exec($createDetailSQL);
            echo "  ✅ purchase_orders_detail created/verified\n";
        } catch (PDOException $e) {
            echo "  ❌ purchase_orders_detail: " . $e->getMessage() . "\n";
        }
        
    } catch (PDOException $e) {
        echo "  ❌ Database connection error: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ Done!\n";
echo "\nPurchase Orders tables are now available in all business databases.\n";
echo "You can now create PO in any business without errors.\n";
