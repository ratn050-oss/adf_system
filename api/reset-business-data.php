<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

// Only admin or developer can reset data
if (!$auth->hasRole('admin') && !$auth->hasRole('developer')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Hanya admin atau developer yang bisa reset data.']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$businessId = $input['business_id'] ?? '';
$resetType = $input['reset_type'] ?? '';

if (empty($businessId) || empty($resetType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Business ID dan tipe reset harus diisi.']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Get business database name
    $business = $db->fetchOne("SELECT business_name, database_name FROM businesses WHERE business_id = ?", [$businessId]);
    if (!$business) {
        echo json_encode(['success' => false, 'message' => 'Business tidak ditemukan.']);
        exit;
    }
    
    $dbName = $business['database_name'] ?? 'adf_' . strtolower(str_replace(' ', '_', $business['business_name']));
    
    // Connect to business database
    $businessDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . $dbName . ";charset=utf8", 
        DB_USER, 
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $deletedCount = 0;
    $tables = [];
    
    // Define reset operations
    switch ($resetType) {
        case 'accounting':
            $tables = ['cashbook_transactions', 'daily_cash_summary'];
            break;
            
        case 'bookings':
            $tables = ['bookings', 'reservations', 'room_bookings'];
            break;
            
        case 'invoices':
            $tables = ['invoices', 'invoice_items'];
            break;
            
        case 'procurement':
            $tables = ['purchase_orders', 'po_items', 'procurement_requests'];
            break;
            
        case 'inventory':
            $tables = ['inventory_items', 'stock_movements', 'stock_opname'];
            break;
            
        case 'employees':
            $tables = ['employees', 'employee_shifts', 'payroll'];
            break;
            
        case 'users':
            // Delete non-admin users only
            $stmt = $businessDb->prepare("DELETE FROM users WHERE role != 'admin' AND role != 'developer'");
            $stmt->execute();
            $deletedCount = $stmt->rowCount();
            break;
            
        case 'guests':
            $tables = ['guests', 'guest_history'];
            break;
            
        case 'menu':
            $tables = ['menu_items', 'menu_categories'];
            break;
            
        case 'orders':
            $tables = ['orders', 'order_items'];
            break;
            
        case 'reports':
            $tables = ['daily_reports', 'shift_reports', 'breakfast_records'];
            break;
            
        case 'logs':
            $tables = ['activity_logs', 'user_logs', 'system_logs'];
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Tipe reset tidak dikenal: ' . $resetType]);
            exit;
    }
    
    // Execute deletions for table-based resets
    if (!empty($tables)) {
        foreach ($tables as $table) {
            try {
                // Check if table exists
                $checkTable = $businessDb->query("SHOW TABLES LIKE '$table'")->fetch();
                if ($checkTable) {
                    $stmt = $businessDb->prepare("DELETE FROM `$table`");
                    $stmt->execute();
                    $deletedCount += $stmt->rowCount();
                }
            } catch (PDOException $e) {
                // Ignore table not found errors
                if ($e->getCode() != '42S02') {
                    throw $e;
                }
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Reset $resetType berhasil. $deletedCount record dihapus.",
        'deleted_count' => $deletedCount,
        'business_id' => $businessId,
        'reset_type' => $resetType
    ]);
    
} catch (Exception $e) {
    error_log("Reset business data error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error saat reset data: ' . $e->getMessage()
    ]);
}
?>