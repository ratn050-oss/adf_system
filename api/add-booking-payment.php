<?php
/**
 * API: Add Booking Payment
 * Insert payment record and update booking payment status
 */

error_reporting(0);
ini_set('display_errors', 0);
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

ob_clean();
header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!$auth->hasPermission('frontdesk')) {
    echo json_encode(['success' => false, 'message' => 'No permission']);
    exit;
}

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

try {
    $bookingId = $_POST['booking_id'] ?? null;
    $amount = (float)($_POST['amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'cash';

    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }
    if ($amount <= 0) {
        throw new Exception('Amount must be greater than 0');
    }

    $validMethods = ['cash', 'card', 'transfer', 'qris', 'ota', 'bank_transfer', 'other', 'edc'];
    if (!in_array($paymentMethod, $validMethods, true)) {
        $paymentMethod = 'cash';
    }

    $booking = $db->fetchOne("SELECT id, final_price, paid_amount FROM bookings WHERE id = ?", [$bookingId]);
    if (!$booking) {
        throw new Exception('Booking not found');
    }

    $db->beginTransaction();

    $db->query("INSERT INTO booking_payments (booking_id, amount, payment_method, processed_by, payment_date, created_at) VALUES (?, ?, ?, ?, NOW(), NOW())", [
        $bookingId,
        $amount,
        $paymentMethod,
        $currentUser['id']
    ]);
    $newPaymentId = $db->getConnection()->lastInsertId();

    $payment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
    $totalPaid = max((float)$payment['paid'], (float)$booking['paid_amount']);
    $remaining = max(0, (float)$booking['final_price'] - $totalPaid);

    if ($totalPaid <= 0) {
        $paymentStatus = 'unpaid';
    } elseif ($remaining <= 0) {
        $paymentStatus = 'paid';
    } else {
        $paymentStatus = 'partial';
    }

    $db->query("UPDATE bookings SET paid_amount = ?, payment_status = ?, updated_at = NOW() WHERE id = ?", [
        $totalPaid,
        $paymentStatus,
        $bookingId
    ]);

    // ==========================================
    // AUTO-INSERT TO CASHBOOK SYSTEM
    // ==========================================
    $cashbookInserted = false;
    $cashbookMessage = '';
    $cashAccountName = '';
    
    // Initialize OTA fee variables OUTSIDE try block for accessibility
    $otaFeePercent = 0;
    $otaFeeAmount = 0;
    $netAmount = $amount;
    
    try {
        // Get master database name - Smart Detection for Hosting
        $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
        $masterDb = null;
        
        try {
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\Throwable $mErr) {
             // Fallback: Use current DB as master for Single-DB Hosting
             if (defined('DB_NAME')) {
                 try {
                     $masterDb = new PDO(
                         "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET,
                         DB_USER,
                         DB_PASS,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                     );
                 } catch (\Throwable $e2) {
                     $masterDb = $db->getConnection();
                 }
             } else {
                 $masterDb = $db->getConnection();
             }
        }
        
        // Get business ID from session
        $businessId = $_SESSION['business_id'] ?? 1;

        // Validate created_by user exists in business DB (login uses master DB)
        $cbUserId = $currentUser['id'] ?? 1;
        $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$cbUserId]);
        if (!$userExists) {
            $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
            $cbUserId = $firstUser['id'] ?? 1;
        }
        
        // ==========================================
        // OTA FEE CALCULATION
        // ==========================================
        // Get booking source to check if OTA
        $bookingInfo = $db->fetchOne(
            "SELECT booking_source FROM bookings WHERE id = ?",
            [$bookingId]
        );
        
        if ($bookingInfo) {
            $bookingSource = $bookingInfo['booking_source'];
            
            // For add-payment, booking_source is already mapped to 'ota'
            // We use a default OTA fee since we don't know the specific provider
            if ($bookingSource === 'ota') {
                $settingKeyMap = [
                    'agoda' => 'ota_fee_agoda',
                    'booking' => 'ota_fee_booking_com',
                    'tiket' => 'ota_fee_tiket_com',
                    'airbnb' => 'ota_fee_airbnb',
                    'ota' => 'ota_fee_other_ota' // Default for generic OTA bookings
                ];
                
                $settingKey = $settingKeyMap[$bookingSource] ?? 'ota_fee_other_ota';
                
                // Get OTA fee from settings (use masterDb, not business db)
                $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                $feeStmt->execute([$settingKey]);
                $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($feeQuery) {
                    $otaFeePercent = (float)($feeQuery['setting_value'] ?? 0);
                    if ($otaFeePercent > 0) {
                        $otaFeeAmount = ($amount * $otaFeePercent) / 100;
                        $netAmount = $amount - $otaFeeAmount;
                    }
                }
            }
        }
        
        // Use netAmount for cashbook (after OTA fee deduction)
        $amountToRecord = $netAmount;
        
        // Determine cash account based on payment method
        $accountType = ($paymentMethod === 'cash') ? 'cash' : 'bank';
        
        // Get appropriate cash account
        $cashAccountQuery = $masterDb->prepare("
            SELECT id, account_name, current_balance 
            FROM cash_accounts 
            WHERE business_id = ? 
            AND account_type = ?
            AND is_active = 1 
            ORDER BY is_default_account DESC
            LIMIT 1
        ");
        $cashAccountQuery->execute([$businessId, $accountType]);
        $account = $cashAccountQuery->fetch(PDO::FETCH_ASSOC);
        
        // FALLBACK: If no specific account type found, get ANY active account
        if (!$account) {
            $fallbackQuery = $masterDb->prepare("
                SELECT id, account_name, current_balance 
                FROM cash_accounts 
                WHERE business_id = ? 
                AND is_active = 1 
                ORDER BY is_default_account DESC
                LIMIT 1
            ");
            $fallbackQuery->execute([$businessId]);
            $account = $fallbackQuery->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($account) {
            $accountId = $account['id'];
            $cashAccountName = $account['account_name'];
            
            // Get default division and category for frontdesk
            $division = $db->fetchOne("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%hotel%' OR LOWER(division_name) LIKE '%frontdesk%' ORDER BY id ASC LIMIT 1");
            if (!$division) {
                $division = $db->fetchOne("SELECT id FROM divisions ORDER BY id ASC LIMIT 1");
            }
            $divisionId = $division['id'] ?? 1;
            
            // Get category for ROOM SALES specifically
            $category = $db->fetchOne("
                SELECT id FROM categories 
                WHERE category_type = 'income' 
                AND (
                    LOWER(category_name) LIKE '%room%' 
                    OR LOWER(category_name) LIKE '%kamar%'
                    OR LOWER(category_name) LIKE '%penjualan kamar%'
                )
                ORDER BY id ASC 
                LIMIT 1
            ");
            
            // Fallback to any income category
            if (!$category) {
                $category = $db->fetchOne("SELECT id FROM categories WHERE category_type = 'income' ORDER BY id ASC LIMIT 1");
            }
            $categoryId = $category['id'] ?? 1;
            
            // Get booking details for description
            $bookingDetails = $db->fetchOne("
                SELECT b.booking_code, g.guest_name, r.room_number
                FROM bookings b
                LEFT JOIN guests g ON b.guest_id = g.id
                LEFT JOIN rooms r ON b.room_id = r.id
                WHERE b.id = ?
            ", [$bookingId]);
            
            $guestName = $bookingDetails['guest_name'] ?? 'Guest';
            $bookingCode = $bookingDetails['booking_code'] ?? '';
            $roomNumber = $bookingDetails['room_number'] ?? '';
            
            // Prepare description
            $description = "Pembayaran Reservasi - {$guestName}";
            if ($roomNumber) {
                $description .= " (Room {$roomNumber})";
            }
            $description .= " - {$bookingCode}";
            
            // Determine payment status label
            if ($paymentStatus === 'paid') {
                $description .= ' [PELUNASAN]';
            } else {
                $description .= ' [CICILAN]';
            }
            
            // Map payment_method to valid ENUM values for cash_book
            $pmMap = ['bank_transfer'=>'transfer','credit_card'=>'debit','credit'=>'debit'];
            $cbMethod = strtolower($paymentMethod ?? 'cash');
            $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
            // Detect ENUM on cash_book.payment_method (hosting may have restrictive ENUM)
            $allowedPaymentMethods = null;
            try {
                $pmColInfo = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'payment_method'")->fetch(PDO::FETCH_ASSOC);
                if ($pmColInfo && strpos($pmColInfo['Type'], 'enum') === 0) {
                    preg_match_all("/'([^']+)'/", $pmColInfo['Type'], $enumMatches);
                    $allowedPaymentMethods = $enumMatches[1] ?? ['cash'];
                }
            } catch (\Throwable $e) {}
            if ($allowedPaymentMethods !== null && !in_array($cbMethod, $allowedPaymentMethods)) {
                $cbMethod = in_array('other', $allowedPaymentMethods) ? 'other' :
                           (in_array('cash', $allowedPaymentMethods) ? 'cash' : $allowedPaymentMethods[0]);
            } elseif ($allowedPaymentMethods === null) {
                $validMethods = ['cash','debit','transfer','qr','bank_transfer','ota','agoda','booking','other'];
                if (!in_array($cbMethod, $validMethods)) $cbMethod = 'other';
            }

            // Check if cash_account_id column exists (may not exist on hosting)
            $hasCashAccountId = false;
            try {
                $colChk = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                $hasCashAccountId = $colChk && $colChk->rowCount() > 0;
            } catch (\Throwable $e) {}

            // Insert into business cash_book table (dynamic based on schema)
            if ($hasCashAccountId) {
                $cashBookInsert = $db->getConnection()->prepare("
                    INSERT INTO cash_book (
                        transaction_date, transaction_time, division_id, category_id,
                        description, transaction_type, amount, payment_method,
                        cash_account_id, created_by, created_at
                    ) VALUES (NOW(), NOW(), ?, ?, ?, 'income', ?, ?, ?, ?, NOW())
                ");
                $cashBookSuccess = $cashBookInsert->execute([
                    $divisionId, $categoryId, $description,
                    $amountToRecord, $cbMethod, $accountId, $cbUserId
                ]);
            } else {
                $cashBookInsert = $db->getConnection()->prepare("
                    INSERT INTO cash_book (
                        transaction_date, transaction_time, division_id, category_id,
                        description, transaction_type, amount, payment_method,
                        created_by, created_at
                    ) VALUES (NOW(), NOW(), ?, ?, ?, 'income', ?, ?, ?, NOW())
                ");
                $cashBookSuccess = $cashBookInsert->execute([
                    $divisionId, $categoryId, $description,
                    $amountToRecord, $cbMethod, $cbUserId
                ]);
            }
            
            if ($cashBookSuccess) {
                $transactionId = $db->getConnection()->lastInsertId();
                
                // SMART FIX: Check if transaction_id column exists
                $hasTransIdCol = false;
                try {
                    $chk = $masterDb->query("SHOW COLUMNS FROM cash_account_transactions LIKE 'transaction_id'");
                    $hasTransIdCol = $chk && $chk->rowCount() > 0;
                } catch (\Throwable $e) {}

                if ($hasTransIdCol) {
                    // Standard Insert
                    $masterTransInsert = $masterDb->prepare("
                        INSERT INTO cash_account_transactions (
                            cash_account_id, transaction_id, transaction_date,
                            description, amount, transaction_type,
                            reference_number, created_by, created_at
                        ) VALUES (?, ?, NOW(), ?, ?, 'income', ?, ?, NOW())
                    ");
                    
                    $masterTransInsert->execute([
                        $accountId,
                        $transactionId,
                        $description,
                        $amountToRecord,
                        $bookingCode, // Use $bookingCode variable which exists in scope
                        $cbUserId
                    ]);
                } else {
                    // Hosting Fallback (no transaction_id)
                    $masterTransInsert = $masterDb->prepare("
                        INSERT INTO cash_account_transactions (
                            cash_account_id, transaction_date,
                            description, amount, transaction_type,
                            reference_number, created_by, created_at
                        ) VALUES (?, NOW(), ?, ?, 'income', ?, ?, NOW())
                    ");
                    
                    $masterTransInsert->execute([
                        $accountId,
                        $description,
                        $amountToRecord,
                        $bookingCode, // Use $bookingCode variable which exists in scope
                        $cbUserId
                    ]);
                }
                
                // Update current_balance in master cash_accounts
                $newBalance = $account['current_balance'] + $amountToRecord;
                $updateBalance = $masterDb->prepare("
                    UPDATE cash_accounts 
                    SET current_balance = ? 
                    WHERE id = ?
                ");
                $updateBalance->execute([$newBalance, $accountId]);
                
                $cashbookInserted = true;
                $cashbookMessage = "Berhasil tercatat di Buku Kas - {$cashAccountName}";

                // Mark payment as synced to cashbook
                try {
                    $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE id = ?", [$transactionId, $newPaymentId]);
                } catch (\Throwable $syncFlagErr) {
                    error_log("Failed to set sync flag: " . $syncFlagErr->getMessage());
                }
            }
        } else {
            $cashbookMessage = "Warning: Akun kas tidak ditemukan untuk payment method '{$paymentMethod}'";
            error_log($cashbookMessage);
        }
    } catch (\Throwable $cashbookError) {
        // Log error but don't fail the payment
        $cashbookMessage = "Error mencatat ke buku kas: " . $cashbookError->getMessage();
        error_log("Cashbook auto-insert error: " . $cashbookError->getMessage());
    }

    $db->commit();
    
    // Prepare success message
    $successMessage = 'Payment saved';
    if ($cashbookInserted) {
        $successMessage .= "\n\n✅ Payment tercatat di Buku Kas!";
        if ($otaFeePercent > 0) {
            $successMessage .= "\nGross: Rp " . number_format($amount, 0, ',', '.');
            $successMessage .= "\nOTA Fee ({$otaFeePercent}%): -Rp " . number_format($otaFeeAmount, 0, ',', '.');
            $successMessage .= "\nNet: Rp " . number_format($netAmount, 0, ',', '.') . " → {$cashAccountName}";
        } else {
            $successMessage .= "\nRp " . number_format($amount, 0, ',', '.') . " → {$cashAccountName}";
        }
        if ($paymentStatus === 'paid') {
            $successMessage .= "\nStatus: LUNAS";
        } else {
            $successMessage .= "\nStatus: PARTIAL (Sisa: Rp " . number_format($remaining, 0, ',', '.') . ")";
        }
    } else {
        $successMessage .= "\n\n⚠️ " . $cashbookMessage;
    }

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'total_paid' => $totalPaid,
        'remaining' => $remaining,
        'payment_status' => $paymentStatus,
        'cashbook_inserted' => $cashbookInserted,
        'cash_account' => $cashAccountName
    ]);

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

ob_end_flush();
