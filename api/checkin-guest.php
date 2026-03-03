<?php
/**
 * API: Check-in Guest
 * Update booking status to 'checked_in' and record check-in time
 */

// Suppress all errors and warnings to prevent non-JSON output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any unexpected output
ob_start();

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/auth.php';

// Clean any output that might have been generated
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

// Validate user exists in database - with fallback
$validUserId = null;
if ($currentUser && !empty($currentUser['id'])) {
    $userExists = $db->fetchOne("SELECT id FROM users WHERE id = ?", [$currentUser['id']]);
    if ($userExists) {
        $validUserId = $currentUser['id'];
    }
}

// If current user not found, fallback to first active admin user
if (!$validUserId) {
    $fallbackUser = $db->fetchOne("SELECT id FROM users WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
    if ($fallbackUser) {
        $validUserId = $fallbackUser['id'];
        error_log("Check-in: Current user invalid, using fallback user ID " . $validUserId);
    }
}

try {
    // Handle JSON input if $_POST is empty
    if (empty($_POST)) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($jsonInput)) {
            $_POST = $jsonInput;
        }
    }

    // Get booking ID from request
    $bookingId = $_POST['booking_id'] ?? null;
    
    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }
    
    $createInvoice = ($_POST['create_invoice'] ?? '0') == '1';

    // Get booking details
    $booking = $db->fetchOne("
        SELECT b.*, g.guest_name, g.phone as guest_phone, g.email as guest_email, r.room_number 
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        WHERE b.id = ?
    ", [$bookingId]);
    
    if (!$booking) {
        throw new Exception('Booking not found');
    }
    
    // Check if already checked in
    if ($booking['status'] === 'checked_in') {
        throw new Exception('Guest sudah check-in sebelumnya');
    }
    
    // Check if booking is confirmed or pending
    if (!in_array($booking['status'], ['confirmed', 'pending'])) {
        throw new Exception('Booking status tidak valid untuk check-in');
    }
    
    // Calculate remaining payment
    $payment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as paid FROM booking_payments WHERE booking_id = ?", [$bookingId]);
    $totalPaid = max((float)$payment['paid'], (float)$booking['paid_amount']);
    $remaining = max(0, (float)$booking['final_price'] - $totalPaid);

    // ==========================================
    // NEW LOGIC: OTA vs Direct Booking Check-in
    // ==========================================
    $otaSources = ['agoda', 'booking', 'tiket', 'airbnb', 'ota', 'traveloka', 'pegipegi', 'expedia'];
    $isOTA = in_array(strtolower($booking['booking_source']), $otaSources);

    // Start transaction
    $db->beginTransaction();

    if ($isOTA) {
        // LOGIC 1: OTA Booking -> Auto-settle payment (LUNAS)
        if ($remaining > 0) {
            // Add automated payment record for OTA
            $db->insert('booking_payments', [
                'booking_id' => $bookingId,
                'amount' => $remaining,
                'payment_date' => date('Y-m-d H:i:s'),
                'payment_method' => 'ota', // Assume payment via OTA
                'notes' => 'Auto-payment upon check-in (OTA Source: ' . $booking['booking_source'] . ')',
                'processed_by' => $validUserId  // Can be NULL if user not found
            ]);
        }
    } else {
        // LOGIC 2: Direct Booking -> Allow check-in but create invoice for debt
        if ($remaining > 0) {
            // Force create invoice for the remaining amount
            $createInvoice = true; 
        }
    }

    // Original Payment Check (Now effectively bypassed by above logic)
    if ($remaining > 0 && !$createInvoice) {
        throw new Exception('Pembayaran belum lunas. Silakan bayar atau buat invoice sisa.');
    }

    // Create invoice for remaining balance if required
    $invoiceNumber = null;
    if ($remaining > 0 && $createInvoice) {
        // Priority 1: Exact match for 'Hotel' or 'Front Desk'
        $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 AND (division_name = 'Hotel' OR division_name = 'Front Desk' OR division_name = 'Room Sell') LIMIT 1");
        
        // Priority 2: Contains 'Hotel' or 'Front'
        if (!$division) {
            $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 AND (division_name LIKE '%Hotel%' OR division_name LIKE '%Front%') ORDER BY id ASC LIMIT 1");
        }
        
        // Priority 3: Fallback to any division
        if (!$division) {
            $division = $db->fetchOne("SELECT id FROM divisions WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        }
        
        if (!$division) {
            throw new Exception('Divisi tidak ditemukan untuk invoice');
        }

        $prefix = 'INV-' . date('Ym') . '-';
        $lastInvoice = $db->fetchOne("SELECT invoice_number FROM sales_invoices_header WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1", [$prefix . '%']);
        $newNumber = 1;
        if ($lastInvoice) {
            $lastNumber = (int)substr($lastInvoice['invoice_number'], -4);
            $newNumber = $lastNumber + 1;
        }
        $invoiceNumber = $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);

        $invoiceId = $db->insert('sales_invoices_header', [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => date('Y-m-d'),
            'customer_name' => $booking['guest_name'],
            'customer_phone' => $booking['guest_phone'] ?? null,
            'customer_email' => $booking['guest_email'] ?? null,
            'customer_address' => null,
            'division_id' => $division['id'],
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'subtotal' => $remaining,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $remaining,
            'paid_amount' => 0,
            'notes' => 'Auto invoice from check-in. Booking #' . $booking['booking_code'],
            'created_by' => $validUserId
        ]);

        $db->insert('sales_invoices_detail', [
            'invoice_header_id' => $invoiceId,
            'item_name' => 'Room Revenue - ' . $booking['booking_code'],
            'item_description' => 'Room ' . $booking['room_number'] . ' ' . $booking['check_in_date'] . ' - ' . $booking['check_out_date'],
            'category' => 'Room Revenue',
            'quantity' => 1,
            'unit_price' => $remaining,
            'total_price' => $remaining,
            'notes' => null
        ]);
    }
    
    // Update booking status to checked_in
    $db->query("
        UPDATE bookings 
        SET status = 'checked_in',
            actual_checkin_time = NOW(),
            checked_in_by = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [$validUserId, $bookingId]);
    
    // Update room status to occupied
    $db->query("
        UPDATE rooms 
        SET status = 'occupied',
            current_guest_id = ?,
            updated_at = NOW()
        WHERE id = ?
    ", [$booking['guest_id'], $booking['room_id']]);
    
    // Log activity
    if ($validUserId) {
        $db->query("
            INSERT INTO activity_logs (user_id, action, description, created_at)
            VALUES (?, ?, ?, NOW())
        ", [
            $validUserId,
            'check_in',
            "Check-in guest: {$booking['guest_name']} - Room {$booking['room_number']} - Booking #{$booking['booking_code']}"
        ]);
    }
    
    // ==========================================
    // SYNC OTA PAYMENT TO CASHBOOK AT CHECK-IN
    // OTA payment tercatat saat check-in (bisa diedit untuk cocokkan dgn aktual)
    // ==========================================
    $cashbookSynced = false;
    if ($isOTA) {
        try {
            require_once '../includes/CashbookHelper.php';
            $cashbookHelper = new CashbookHelper($db, $_SESSION['business_id'] ?? 1, $validUserId ?? 1);
            
            // Get total amount paid for this booking
            $totalPayment = $db->fetchOne("SELECT COALESCE(SUM(amount), 0) as total FROM booking_payments WHERE booking_id = ?", [$bookingId]);
            $otaAmount = (float)$totalPayment['total'];
            if ($otaAmount <= 0) {
                $otaAmount = (float)$booking['final_price'];
            }
            
            // Sync to cashbook with is_editable = true (for OTA reconciliation later)
            $syncResult = $cashbookHelper->syncPaymentToCashbook([
                'payment_id' => null,  // OTA payment grouped
                'booking_id' => $bookingId,
                'amount' => $otaAmount,
                'payment_method' => strtolower($booking['booking_source']),  // agoda, booking, etc.
                'guest_name' => $booking['guest_name'],
                'booking_code' => $booking['booking_code'],
                'room_number' => $booking['room_number'],
                'booking_source' => $booking['booking_source'],
                'final_price' => $booking['final_price'],
                'total_paid' => $otaAmount,
                'is_new_reservation' => false,
                'is_ota_checkin' => true  // Flag for editable OTA entry
            ]);
            
            $cashbookSynced = $syncResult['success'];
            
            // Mark all booking_payments as synced
            if ($cashbookSynced && $syncResult['transaction_id']) {
                try {
                    $db->query("UPDATE booking_payments SET synced_to_cashbook = 1, cashbook_id = ? WHERE booking_id = ?", [$syncResult['transaction_id'], $bookingId]);
                } catch (\Throwable $e) {
                    // Ignore if column doesn't exist
                }
            }
            
        } catch (\Throwable $e) {
            error_log("OTA Cashbook sync error at check-in: " . $e->getMessage());
        }
    }
    
    $db->commit();
    
    // Build success message
    $successMessage = "Check-in berhasil! {$booking['guest_name']} - Room {$booking['room_number']}";
    if ($isOTA && $cashbookSynced) {
        $successMessage .= "\n\n✅ Pembayaran OTA ({$booking['booking_source']}) tercatat di Buku Kas";
        $successMessage .= "\n⚠️ Sebagai ESTIMASI - dapat diedit saat rekonsiliasi akhir bulan";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'booking_id' => $bookingId,
        'guest_name' => $booking['guest_name'],
        'room_number' => $booking['room_number'],
        'invoice_number' => $invoiceNumber,
        'is_ota' => $isOTA,
        'cashbook_synced' => $cashbookSynced ?? false
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log("Check-in Error: " . $e->getMessage());
    
    // Clean output buffer before sending JSON
    ob_clean();
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

// Flush output buffer
ob_end_flush();
