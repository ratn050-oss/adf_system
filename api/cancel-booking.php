<?php
/**
 * CANCEL BOOKING API
 * Change booking status to 'cancelled' with refund calculation
 * 
 * Refund Policy:
 * - H+7 (> 7 days before check-in): 100% refund
 * - H-7 (1-7 days before check-in): 50% refund  
 * - H-1/No show (0-1 day before or same day): 0% refund
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();
$currentUser = $auth->getCurrentUser();

// Get business_id from user or session
$businessId = $currentUser['business_id'] ?? $_SESSION['business_id'] ?? 1;

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = $input['booking_id'] ?? null;
$refundAmount = isset($input['refund_amount']) ? floatval($input['refund_amount']) : null;
$refundReason = $input['refund_reason'] ?? 'Pembatalan reservasi';

if (!$bookingId) {
    echo json_encode(['success' => false, 'message' => 'Booking ID required']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Get booking info with guest name
    $stmt = $pdo->prepare("
        SELECT b.*, g.guest_name 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }
    
    // Cannot cancel if already checked in or checked out
    if (in_array($booking['status'], ['checked_in', 'checked_out'])) {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel booking that is already checked in or checked out']);
        exit;
    }
    
    // Cannot cancel if already cancelled
    if ($booking['status'] === 'cancelled') {
        echo json_encode(['success' => false, 'message' => 'Booking is already cancelled']);
        exit;
    }
    
    // Calculate days until check-in
    $today = new DateTime('today');
    $checkInDate = new DateTime($booking['check_in_date']);
    $interval = $today->diff($checkInDate);
    $daysUntilCheckin = $interval->invert ? -$interval->days : $interval->days;
    
    // Determine refund percentage based on policy
    $refundPercentage = 0;
    $refundPolicy = '';
    
    if ($daysUntilCheckin > 7) {
        // More than 7 days before check-in: 100% refund
        $refundPercentage = 100;
        $refundPolicy = 'H+7 (> 7 hari sebelum check-in): Refund 100%';
    } elseif ($daysUntilCheckin >= 2 && $daysUntilCheckin <= 7) {
        // 2-7 days before check-in: 50% refund
        $refundPercentage = 50;
        $refundPolicy = 'H-7 (2-7 hari sebelum check-in): Refund 50%';
    } else {
        // 0-1 day or same day: 0% refund
        $refundPercentage = 0;
        $refundPolicy = 'H-1/No Show (≤1 hari): Tidak ada refund';
    }
    
    // Calculate refund amount based on paid amount
    $paidAmount = floatval($booking['paid_amount'] ?? 0);
    $calculatedRefund = ($paidAmount * $refundPercentage) / 100;
    
    // Use provided refund amount if valid, otherwise use calculated
    if ($refundAmount !== null && $refundAmount >= 0 && $refundAmount <= $paidAmount) {
        $finalRefundAmount = $refundAmount;
    } else {
        $finalRefundAmount = $calculatedRefund;
    }
    
    // Update booking status to cancelled with refund info
    $stmt = $pdo->prepare("
        UPDATE bookings 
        SET status = 'cancelled', 
            updated_at = NOW(),
            special_request = CONCAT(COALESCE(special_request, ''), '\n[CANCELLED] ', ?, ' - Refund: Rp ', ?)
        WHERE id = ?
    ");
    $stmt->execute([$refundPolicy, number_format($finalRefundAmount, 0, '', ''), $bookingId]);
    
    // Record refund in cash_book if there's refund amount
    $refundRecorded = false;
    $refundError = '';
    
    if ($finalRefundAmount > 0) {
        try {
            // Get master database name (handles hosting vs local)
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';

            // Validate created_by user exists in business DB (login uses master DB)
            $cbUserId = $currentUser['id'] ?? 1;
            $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$cbUserId]);
            if (!$userExists) {
                $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $cbUserId = $firstUser['id'] ?? 1;
            }
            
            error_log("REFUND DEBUG: Starting refund process. Amount: {$finalRefundAmount}, BusinessID: {$businessId}, MasterDB: {$masterDbName}");
            
            // Create separate connection to master database for balance update
            $masterPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Get default cash account (petty cash or owner capital)
            $accountStmt = $masterPdo->prepare("
                SELECT id, account_name, current_balance, account_type
                FROM cash_accounts 
                WHERE business_id = ? AND account_type IN ('cash', 'owner_capital')
                ORDER BY current_balance DESC
                LIMIT 1
            ");
            $accountStmt->execute([$businessId]);
            $cashAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
            
            error_log("REFUND DEBUG: Cash account query result: " . json_encode($cashAccount));
            
            if ($cashAccount) {
                $oldBalance = $cashAccount['current_balance'];
                
                // Insert expense record for refund in business database
                $refundDesc = "Refund pembatalan {$booking['booking_code']} - {$booking['guest_name']} ({$refundPolicy})";
                
                // Get Hotel/Front Desk division ID (ID 5 = Hotel)
                $divisionStmt = $pdo->prepare("
                    SELECT id FROM divisions 
                    WHERE LOWER(division_name) LIKE '%hotel%' 
                       OR LOWER(division_name) LIKE '%front%' 
                       OR LOWER(division_name) LIKE '%reserv%'
                    LIMIT 1
                ");
                $divisionStmt->execute();
                $divisionId = $divisionStmt->fetchColumn() ?: 5; // Default to Hotel (ID 5)
                
                // Get or create Refund category
                $categoryStmt = $pdo->prepare("
                    SELECT id FROM categories 
                    WHERE LOWER(category_name) LIKE '%refund%' 
                      AND category_type = 'expense'
                    LIMIT 1
                ");
                $categoryStmt->execute();
                $categoryId = $categoryStmt->fetchColumn();
                
                if (!$categoryId) {
                    // Create Refund category if not exists
                    $createCatStmt = $pdo->prepare("
                        INSERT INTO categories (branch_id, division_id, category_name, category_type, description, is_active, created_at)
                        VALUES ('narayana-hotel', ?, 'Refund Booking', 'expense', 'Refund untuk pembatalan booking', 1, NOW())
                    ");
                    $createCatStmt->execute([$divisionId]);
                    $categoryId = $pdo->lastInsertId();
                    error_log("REFUND DEBUG: Created new Refund category ID: {$categoryId}");
                }
                
                error_log("REFUND DEBUG: Using Division ID: {$divisionId}, Category ID: {$categoryId}");
                
                // Check if cash_account_id column exists (may not exist on hosting)
                $hasCashAccountId = false;
                try {
                    $colChk = $pdo->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                    $hasCashAccountId = $colChk && $colChk->rowCount() > 0;
                } catch (Exception $e) {}

                if ($hasCashAccountId) {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO cash_book (
                            transaction_date, transaction_time, transaction_type, 
                            division_id, category_id, description, amount, payment_method,
                            cash_account_id, created_by, created_at
                        ) VALUES (
                            CURDATE(), CURTIME(), 'expense',
                            ?, ?, ?, ?, 'cash',
                            ?, ?, NOW()
                        )
                    ");
                    $insertStmt->execute([
                        $divisionId, $categoryId, $refundDesc,
                        $finalRefundAmount, $cashAccount['id'], $cbUserId
                    ]);
                } else {
                    $insertStmt = $pdo->prepare("
                        INSERT INTO cash_book (
                            transaction_date, transaction_time, transaction_type, 
                            division_id, category_id, description, amount, payment_method,
                            created_by, created_at
                        ) VALUES (
                            CURDATE(), CURTIME(), 'expense',
                            ?, ?, ?, ?, 'cash',
                            ?, NOW()
                        )
                    ");
                    $insertStmt->execute([
                        $divisionId, $categoryId, $refundDesc,
                        $finalRefundAmount, $cbUserId
                    ]);
                }
                
                $cashBookId = $pdo->lastInsertId();
                error_log("REFUND DEBUG: cash_book insert OK, ID: {$cashBookId}");
                
                // Update cash account balance in master database using DIRECT connection
                $updateBalanceStmt = $masterPdo->prepare("
                    UPDATE cash_accounts 
                    SET current_balance = current_balance - ?
                    WHERE id = ?
                ");
                $updateResult = $updateBalanceStmt->execute([$finalRefundAmount, $cashAccount['id']]);
                $rowsAffected = $updateBalanceStmt->rowCount();
                
                error_log("REFUND DEBUG: Balance update - Result: " . ($updateResult ? 'true' : 'false') . ", Rows: {$rowsAffected}");
                
                // Verify the balance was updated
                $verifyStmt = $masterPdo->prepare("SELECT current_balance FROM cash_accounts WHERE id = ?");
                $verifyStmt->execute([$cashAccount['id']]);
                $newBalance = $verifyStmt->fetchColumn();
                
                $expectedBalance = $oldBalance - $finalRefundAmount;
                
                error_log("REFUND DEBUG: Balance verification - Old: {$oldBalance}, Expected: {$expectedBalance}, New: {$newBalance}");
                
                if ($newBalance == $expectedBalance) {
                    $refundRecorded = true;
                    error_log("REFUND SUCCESS: Booking {$booking['booking_code']}, Amount: {$finalRefundAmount}, Account: {$cashAccount['account_name']} (ID:{$cashAccount['id']}), Balance: {$oldBalance} -> {$newBalance}");
                } else {
                    $refundError = "Balance mismatch after update";
                    error_log("REFUND ERROR: Balance mismatch! Old: {$oldBalance}, Expected: {$expectedBalance}, Got: {$newBalance}");
                }
            } else {
                $refundError = "No cash account found for business_id {$businessId}";
                error_log("REFUND WARNING: " . $refundError);
            }
        } catch (Exception $refundEx) {
            $refundError = $refundEx->getMessage();
            error_log("REFUND EXCEPTION: " . $refundError);
        }
    }
    
    // Log activity (optional - wrapped in try-catch to handle FK constraint issues)
    $logDesc = "Cancelled booking {$booking['booking_code']} - {$booking['guest_name']}. {$refundPolicy}";
    if ($finalRefundAmount > 0) {
        $logDesc .= " Refund: Rp " . number_format($finalRefundAmount, 0, ',', '.');
    }
    
    try {
        // Check if activity_logs table exists and user is valid
        $checkUser = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $checkUser->execute([$currentUser['id']]);
        
        if ($checkUser->fetch()) {
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $logStmt->execute([
                $currentUser['id'],
                'cancel_booking',
                $logDesc
            ]);
        }
    } catch (Exception $logError) {
        // Activity logging failed but don't fail the whole transaction
        error_log("Activity log insert failed: " . $logError->getMessage());
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Booking cancelled successfully',
        'data' => [
            'booking_code' => $booking['booking_code'],
            'guest_name' => $booking['guest_name'],
            'check_in_date' => $booking['check_in_date'],
            'days_until_checkin' => $daysUntilCheckin,
            'paid_amount' => $paidAmount,
            'refund_percentage' => $refundPercentage,
            'refund_policy' => $refundPolicy,
            'refund_amount' => $finalRefundAmount,
            'refund_recorded' => $refundRecorded,
            'refund_error' => $refundError
        ]
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Cancel Booking Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
?>
