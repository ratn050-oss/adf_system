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
$resetType = $input['reset_type'] ?? '';

if (empty($resetType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Tipe reset tidak valid.']);
    exit;
}

/**
 * Helper function to check if table exists
 */
function tableExists($conn, $tableName) {
    try {
        $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Helper function to check if column exists in table
 */
function columnExists($conn, $tableName, $columnName) {
    try {
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Helper function to safely delete data from a table
 */
function safeDelete($conn, $table, $where = null, $params = []) {
    if (!tableExists($conn, $table)) {
        return ['deleted' => 0, 'error' => "Table {$table} tidak ditemukan"];
    }
    
    try {
        // Count first
        $countSql = "SELECT COUNT(*) FROM `{$table}`" . ($where ? " WHERE {$where}" : "");
        $stmt = $conn->prepare($countSql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();
        
        // Delete
        $deleteSql = "DELETE FROM `{$table}`" . ($where ? " WHERE {$where}" : "");
        $stmt = $conn->prepare($deleteSql);
        $stmt->execute($params);
        
        return ['deleted' => $count, 'error' => null];
    } catch (Exception $e) {
        return ['deleted' => 0, 'error' => $e->getMessage()];
    }
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    $deletedCount = 0;
    $tables = [];
    $errors = [];
    $businessId = defined('ACTIVE_BUSINESS_ID') ? ACTIVE_BUSINESS_ID : null;
    
    switch ($resetType) {
        case 'accounting':
            // Reset cash_book table (business database)
            if ($businessId && tableExists($conn, 'cash_book') && columnExists($conn, 'cash_book', 'business_id')) {
                $result = safeDelete($conn, 'cash_book', 'business_id = :business_id', ['business_id' => $businessId]);
            } else {
                $result = safeDelete($conn, 'cash_book');
            }
            $deletedCount = $result['deleted'];
            if ($result['error']) $errors[] = $result['error'];
            $tables[] = 'cash_book';
            
            // Also reset cash accounting tables in MASTER database
            try {
                $masterDb = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
                $masterDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                // Get business database ID from identifier
                $businessIdentifier = ACTIVE_BUSINESS_ID;
                $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
                $businessDbId = $businessMapping[$businessIdentifier] ?? 1;
                
                // Delete cash_account_transactions for this business
                $stmt = $masterDb->prepare("DELETE FROM cash_account_transactions WHERE cash_account_id IN (SELECT id FROM cash_accounts WHERE business_id = ?)");
                $stmt->execute([$businessDbId]);
                $deletedTransactions = $stmt->rowCount();
                $deletedCount += $deletedTransactions;
                
                // Reset current_balance to 0 for all accounts
                $stmt = $masterDb->prepare("UPDATE cash_accounts SET current_balance = 0 WHERE business_id = ?");
                $stmt->execute([$businessDbId]);
                $updatedAccounts = $stmt->rowCount();
                
                $message = "Data accounting berhasil direset. {$deletedCount} transaksi dihapus, {$updatedAccounts} akun kas di-reset.";
                
            } catch (Exception $e) {
                $errors[] = "Error reset cash accounts: " . $e->getMessage();
                $message = "Data accounting berhasil direset. {$deletedCount} transaksi dihapus.";
            }
            
            break;
            
        case 'bookings':
            // Reset bookings/reservations
            $bookingTables = ['bookings', 'reservations', 'booking_extras', 'booking_payments'];
            foreach ($bookingTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data booking/reservasi berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'invoices':
            // Reset invoices
            $invoiceTables = ['invoices', 'invoice_items', 'invoice_payments'];
            foreach ($invoiceTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data invoice berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'procurement':
            // Reset PO & Procurement
            $poTables = ['purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items'];
            foreach ($poTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data procurement (PO) berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'inventory':
            // Reset inventory/stock
            $invTables = ['inventory', 'stock_movements', 'stock_adjustments'];
            foreach ($invTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data inventory berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'employees':
            // Reset employees
            if ($businessId && tableExists($conn, 'employees') && columnExists($conn, 'employees', 'business_id')) {
                $result = safeDelete($conn, 'employees', 'business_id = :business_id', ['business_id' => $businessId]);
            } else {
                $result = safeDelete($conn, 'employees');
            }
            $deletedCount = $result['deleted'];
            if ($result['error']) $errors[] = $result['error'];
            $tables[] = 'employees';
            $message = "Data karyawan berhasil direset. {$deletedCount} karyawan dihapus.";
            break;
            
        case 'users':
            // Reset users (except admin)
            $result = safeDelete($conn, 'users', "role != 'admin'");
            $deletedCount = $result['deleted'];
            if ($result['error']) $errors[] = $result['error'];
            $tables[] = 'users';
            $message = "Data user berhasil direset. {$deletedCount} user (selain admin) dihapus.";
            break;
            
        case 'guests':
            // Reset guests
            if ($businessId && tableExists($conn, 'guests') && columnExists($conn, 'guests', 'business_id')) {
                $result = safeDelete($conn, 'guests', 'business_id = :business_id', ['business_id' => $businessId]);
            } else {
                $result = safeDelete($conn, 'guests');
            }
            $deletedCount = $result['deleted'];
            if ($result['error']) $errors[] = $result['error'];
            $tables[] = 'guests';
            $message = "Data tamu berhasil direset. {$deletedCount} tamu dihapus.";
            break;
            
        case 'menu':
            // Reset menu items (for cafe/restaurant)
            $menuTables = ['menu_items', 'menu_categories'];
            foreach ($menuTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data menu berhasil direset. {$deletedCount} item dihapus.";
            break;
            
        case 'orders':
            // Reset orders (for cafe/restaurant)
            $orderTables = ['orders', 'order_items'];
            foreach ($orderTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data orders berhasil direset. {$deletedCount} order dihapus.";
            break;
            
        case 'reports':
            // Reset shift reports
            $reportTables = ['shift_reports', 'daily_reports', 'breakfast_records'];
            foreach ($reportTables as $table) {
                if ($businessId && tableExists($conn, $table) && columnExists($conn, $table, 'business_id')) {
                    $result = safeDelete($conn, $table, 'business_id = :business_id', ['business_id' => $businessId]);
                } else {
                    $result = safeDelete($conn, $table);
                }
                $deletedCount += $result['deleted'];
                if ($result['error'] && strpos($result['error'], 'tidak ditemukan') === false) {
                    $errors[] = $result['error'];
                }
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data reports berhasil direset. {$deletedCount} record dihapus.";
            break;
            
        case 'logs':
            // Reset activity logs
            $logTables = ['activity_logs', 'audit_logs', 'system_logs'];
            foreach ($logTables as $table) {
                $result = safeDelete($conn, $table);
                $deletedCount += $result['deleted'];
                if (tableExists($conn, $table)) $tables[] = $table;
            }
            $message = "Data logs berhasil direset. {$deletedCount} log dihapus.";
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tipe reset tidak valid: ' . $resetType]);
            exit;
    }
    
    // Filter out empty tables
    $tables = array_filter($tables, function($t) use ($conn) {
        return tableExists($conn, $t);
    });
    
    $response = [
        'success' => true,
        'message' => $message,
        'deleted_count' => $deletedCount,
        'tables' => array_values($tables)
    ];
    
    if (!empty($errors)) {
        $response['warnings'] = $errors;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saat reset data: ' . $e->getMessage()
    ]);
}
