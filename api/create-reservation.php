<?php
/**
 * API: CREATE RESERVATION
 * Handles reservation creation from calendar
 */

// Start output buffering FIRST before any includes
ob_start();

// Suppress all errors/warnings
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_ACCESS', true);

// Capture output from includes
ob_start();
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';
ob_end_clean();

// Clear ALL buffered output before sending JSON
while (ob_get_level()) {
    ob_end_clean();
}

// Set JSON header AFTER clearing buffers
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

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

try {
    // Validate required fields
    $required = ['guest_name', 'check_in_date', 'check_out_date', 'room_id', 'room_price', 'booking_source'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required");
        }
    }
    
    // Get form data
    $guestName = trim($_POST['guest_name']);
    $guestPhone = trim($_POST['guest_phone'] ?? '');
    $guestEmail = trim($_POST['guest_email'] ?? '');
    $guestIdNumber = trim($_POST['guest_id_number'] ?? '');
    
    $checkInDate = $_POST['check_in_date'];
    $checkOutDate = $_POST['check_out_date'];
    $roomId = (int)($_POST['room_id'] ?? 0);
    $roomPrice = (float)($_POST['room_price'] ?? 0);
    $totalNights = (int)($_POST['total_nights'] ?? 0);
    $adultCount = (int)($_POST['adult_count'] ?? 1);
    $childrenCount = (int)($_POST['children_count'] ?? 0);
    $bookingSource = $_POST['booking_source'];
    $discount = (float)($_POST['discount'] ?? 0);
    $totalPrice = (float)($_POST['total_price'] ?? 0);
    $finalPrice = (float)($_POST['final_price'] ?? 0);
    $specialRequest = trim($_POST['special_request'] ?? '');
    $paymentStatus = $_POST['payment_status'] ?? 'unpaid';
    $paidAmount = (float)($_POST['paid_amount'] ?? 0);
    $paymentMethodRaw = $_POST['payment_method'] ?? 'cash';
    $paymentMethod = strtolower(trim($paymentMethodRaw));
    if ($paymentMethod === 'qr') {
        $paymentMethod = 'qris';
    }
    $allowedMethods = ['cash', 'card', 'transfer', 'qris', 'ota', 'bank_transfer', 'other'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        $paymentMethod = 'cash';
    }
    
    // Save original booking source for OTA fee calculation
    $originalBookingSource = $bookingSource;
    
    // Map booking source to database enum values
    $sourceMap = [
        'walk_in' => 'walk_in',
        'phone' => 'phone',
        'online' => 'online',
        'agoda' => 'ota',
        'booking' => 'ota',
        'tiket' => 'ota',
        'airbnb' => 'ota',
        'ota' => 'ota'
    ];
    $bookingSource = $sourceMap[$bookingSource] ?? 'walk_in';
    
    // Validate dates
    $checkIn = new DateTime($checkInDate);
    $checkOut = new DateTime($checkOutDate);
    
    if ($checkOut <= $checkIn) {
        throw new Exception("Check-out date must be after check-in date");
    }
    
    // Calculate nights if not provided
    if ($totalNights == 0) {
        $interval = $checkIn->diff($checkOut);
        $totalNights = $interval->days;
    }
    
    // Check room availability
    $conflicts = $db->fetchAll("
        SELECT id FROM bookings 
        WHERE room_id = ? 
        AND status != 'cancelled'
        AND (
            (check_in_date < ? AND check_out_date > ?)
            OR (check_in_date >= ? AND check_in_date < ?)
        )
    ", [$roomId, $checkOutDate, $checkInDate, $checkInDate, $checkOutDate]);
    
    if (!empty($conflicts)) {
        throw new Exception("Room is not available for selected dates");
    }
    
    $db->beginTransaction();
    
    // ALWAYS CREATE NEW GUEST for each reservation
    // This prevents name changes when same phone/email is used
    $idCardNumber = !empty($guestIdNumber) ? $guestIdNumber : 'TEMP-' . date('YmdHis') . '-' . rand(1000, 9999);
    $db->query("
        INSERT INTO guests (guest_name, phone, email, id_card_number, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ", [$guestName, $guestPhone, $guestEmail, $idCardNumber]);
    $guestId = $db->getConnection()->lastInsertId();
    
    // Generate booking code
    $bookingCode = 'BK-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Check if booking code exists
    $exists = $db->fetchOne("SELECT id FROM bookings WHERE booking_code = ?", [$bookingCode]);
    if ($exists) {
        $bookingCode = 'BK-' . date('YmdHis') . '-' . rand(100, 999);
    }
    
    // Ensure payment status matches paid amount
    if ($paidAmount <= 0) {
        $paymentStatus = 'unpaid';
    } elseif ($paidAmount >= $finalPrice) {
        $paymentStatus = 'paid';
    } else {
        $paymentStatus = 'partial';
    }

    // Create booking (remove guest_name from INSERT as it doesn't exist in table)
    $bookingStmt = $db->query("
        INSERT INTO bookings (
            booking_code, guest_id, room_id, 
            check_in_date, check_out_date, total_nights,
            adults, children,
            room_price, total_price, discount, final_price,
            booking_source, status, payment_status, paid_amount,
            special_request, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, NOW())
    ", [
        $bookingCode, $guestId, $roomId,
        $checkInDate, $checkOutDate, $totalNights,
        $adultCount, $childrenCount,
        $roomPrice, $totalPrice, $discount, $finalPrice,
        $bookingSource, $paymentStatus, $paidAmount,
        $specialRequest
    ]);

    if (!$bookingStmt) {
        throw new Exception('Failed to create booking');
    }
    
    $bookingId = $db->getConnection()->lastInsertId();
    
    // ==========================================
    // Initialize variables before payment check
    // ==========================================
    $cashbookInserted = false;
    $cashbookMessage = '';
    $cashAccountName = '';
    $otaFeePercent = 0;
    $otaFeeAmount = 0;
    $netAmount = $paidAmount; // Use paidAmount for calculations
    
    // Create initial payment record if paid amount exists
    if ($paidAmount > 0) {
        $columnInfo = $db->fetchOne("SHOW COLUMNS FROM booking_payments LIKE 'payment_method'");
        if (!empty($columnInfo['Type']) && preg_match("/^enum\((.*)\)$/i", $columnInfo['Type'], $matches)) {
            $enumValues = array_map(function ($value) {
                return trim($value, "'\"");
            }, explode(',', $matches[1]));
            if (!in_array($paymentMethod, $enumValues, true)) {
                $paymentMethod = $enumValues[0] ?? 'cash';
            }
        }

        $paymentStmt = $db->query("
            INSERT INTO booking_payments (booking_id, amount, payment_method)
            VALUES (?, ?, ?)
        ", [$bookingId, $paidAmount, $paymentMethod]);

        if (!$paymentStmt) {
            throw new Exception('Failed to create payment record');
        }
        $newPaymentId = $db->getConnection()->lastInsertId();
        
        // ==========================================
        // AUTO-INSERT TO CASHBOOK SYSTEM
        // ==========================================
        
        try {
            // Get master database name (handles hosting vs local)
            $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
            $masterDb = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Get business ID from session
            $businessId = $_SESSION['business_id'] ?? 1;

            // Validate created_by user exists in business DB (login uses master DB)
            $cbUserId = $_SESSION['user_id'] ?? 1;
            $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ? LIMIT 1", [$cbUserId]);
            if (!$userExists) {
                $firstUser = $db->fetchOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $cbUserId = $firstUser['id'] ?? 1;
            }
            
            // ==========================================
            // OTA FEE CALCULATION
            // ==========================================
            // Check if booking source is OTA (use original source before mapping)
            $otaSources = ['agoda', 'booking', 'tiket', 'airbnb', 'ota'];
            if (in_array($originalBookingSource, $otaSources)) {
                // Map booking source to settings key
                $settingKeyMap = [
                    'agoda' => 'ota_fee_agoda',
                    'booking' => 'ota_fee_booking_com',
                    'tiket' => 'ota_fee_tiket_com',
                    'airbnb' => 'ota_fee_airbnb',
                    'ota' => 'ota_fee_other_ota'
                ];
                
                $settingKey = $settingKeyMap[$originalBookingSource] ?? 'ota_fee_other_ota';
                
                // Get OTA fee from settings (use masterDb, not business db)
                $feeStmt = $masterDb->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                $feeStmt->execute([$settingKey]);
                $feeQuery = $feeStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($feeQuery) {
                    $otaFeePercent = (float)($feeQuery['setting_value'] ?? 0);
                    if ($otaFeePercent > 0) {
                        $otaFeeAmount = ($paidAmount * $otaFeePercent) / 100;
                        $netAmount = $paidAmount - $otaFeeAmount;
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
                
                // Get room info for description
                $roomInfo = $db->fetchOne("SELECT room_number FROM rooms WHERE id = ?", [$roomId]);
                $roomNumber = $roomInfo['room_number'] ?? '';
                
                // Prepare description
                $description = "Pembayaran Reservasi - {$guestName}";
                if ($roomNumber) {
                    $description .= " (Room {$roomNumber})";
                }
                $description .= " - {$bookingCode}";
                
                // Determine payment status label
                $paymentLabel = '';
                if ($paidAmount >= $finalPrice) {
                    $paymentLabel = ' [LUNAS]';
                } else {
                    $paymentLabel = ' [DP]';
                }
                $description .= $paymentLabel;
                
                // Map payment_method to valid ENUM values for cash_book
                $pmMap = ['bank_transfer'=>'transfer','credit_card'=>'debit','credit'=>'debit'];
                $cbMethod = strtolower($paymentMethod ?? 'cash');
                $cbMethod = $pmMap[$cbMethod] ?? $cbMethod;
                $validMethods = ['cash','debit','transfer','qr','bank_transfer','ota','agoda','booking','other'];
                if (!in_array($cbMethod, $validMethods)) $cbMethod = 'other';

                // Check if cash_account_id column exists (may not exist on hosting)
                $hasCashAccountId = false;
                try {
                    $colChk = $db->getConnection()->query("SHOW COLUMNS FROM cash_book LIKE 'cash_account_id'");
                    $hasCashAccountId = $colChk && $colChk->rowCount() > 0;
                } catch (Exception $e) {}

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
                    
                    // Insert into master cash_account_transactions
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
                        $bookingCode,
                        $cbUserId
                    ]);
                    
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
                    } catch (Exception $syncFlagErr) {
                        error_log("Failed to set sync flag: " . $syncFlagErr->getMessage());
                    }
                }
            } else {
                $cashbookMessage = "Warning: Akun kas tidak ditemukan untuk payment method '{$paymentMethod}'";
                error_log($cashbookMessage);
            }
        } catch (Exception $cashbookError) {
            // Log error but don't fail the reservation
            $cashbookMessage = "Error mencatat ke buku kas: " . $cashbookError->getMessage();
            error_log("Cashbook auto-insert error: " . $cashbookError->getMessage());
        }
    }

    $db->commit();
    
    // Prepare success message
    $successMessage = 'Reservation created successfully';
    if ($paidAmount > 0) {
        if ($cashbookInserted) {
            $successMessage .= "\n\n✅ Payment tercatat di Buku Kas!";
            if ($otaFeePercent > 0) {
                $successMessage .= "\nGross: Rp " . number_format($paidAmount, 0, ',', '.');
                $successMessage .= "\nOTA Fee ({$otaFeePercent}%): -Rp " . number_format($otaFeeAmount, 0, ',', '.');
                $successMessage .= "\nNet: Rp " . number_format($netAmount, 0, ',', '.') . " → {$cashAccountName}";
            } else {
                $successMessage .= "\nRp " . number_format($paidAmount, 0, ',', '.') . " → {$cashAccountName}";
            }
            if ($paidAmount >= $finalPrice) {
                $successMessage .= "\nStatus: LUNAS";
            } else {
                $remaining = $finalPrice - $paidAmount;
                $successMessage .= "\nStatus: DP (Sisa: Rp " . number_format($remaining, 0, ',', '.') . ")";
            }
        } else {
            $successMessage .= "\n\n⚠️ " . $cashbookMessage;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'booking_id' => $bookingId,
        'booking_code' => $bookingCode,
        'cashbook_inserted' => $cashbookInserted,
        'cash_account' => $cashAccountName,
        'paid_amount' => $paidAmount,
        // DEBUG: Add OTA fee info
        'debug' => [
            'ota_fee_percent' => $otaFeePercent,
            'ota_fee_amount' => $otaFeeAmount,
            'net_amount' => $netAmount,
            'original_booking_source' => $originalBookingSource,
            'mapped_booking_source' => $bookingSource
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log("Create Reservation Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
exit;
