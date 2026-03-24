<?php
/**
 * API: Update Reservation
 * Edit reservation details (dates, room, guest info, price)
 */

// LOG ALL ERRORS
$logFile = __DIR__ . '/../api_debug.log';
ini_set('log_errors', 1);
ini_set('error_log', $logFile);

header('Content-Type: application/json');
if (ob_get_level() === 0) ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

define('APP_ACCESS', true);

try {
    error_log("=== update-reservation.php START ===");
    
    require_once '../config/config.php';
    error_log("config.php loaded");
    
    require_once '../config/database.php';
    error_log("database.php loaded");
    
    require_once '../includes/auth.php';
    error_log("auth.php loaded");
    
    error_reporting(0);
    ini_set('display_errors', 0);
    
    $auth = new Auth();
    error_log("Auth instantiated");
    
    if (!$auth->isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (!$auth->hasPermission('frontdesk')) {
        echo json_encode(['success' => false, 'message' => 'No permission']);
        exit;
    }

    $db = Database::getInstance();
    error_log("Database instance obtained");
    $conn = $db->getConnection();

    $bookingId = intval($_POST['booking_id'] ?? 0);
    if (!$bookingId) {
        throw new Exception('Booking ID is required');
    }

    // Get current booking
    $stmt = $conn->prepare("SELECT b.*, g.id as gid FROM bookings b LEFT JOIN guests g ON b.guest_id = g.id WHERE b.id = ?");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception('Booking not found');
    }

    // Only allow editing confirmed/pending bookings
    if (!in_array($booking['status'], ['confirmed', 'pending'])) {
        throw new Exception('Can only edit confirmed or pending reservations');
    }

    // Update guest info in guests table
    if ($booking['gid']) {
        $guestUpdates = [];
        $guestParams = [];

        if (!empty($_POST['guest_name'])) {
            $guestUpdates[] = 'guest_name = ?';
            $guestParams[] = trim($_POST['guest_name']);
        }
        if (isset($_POST['guest_phone'])) {
            $guestUpdates[] = 'phone = ?';
            $guestParams[] = trim($_POST['guest_phone']);
        }
        if (isset($_POST['guest_email'])) {
            $guestUpdates[] = 'email = ?';
            $guestParams[] = trim($_POST['guest_email']);
        }
        if (isset($_POST['guest_id_number'])) {
            $guestUpdates[] = 'id_card_number = ?';
            $guestParams[] = trim($_POST['guest_id_number']);
        }

        if (!empty($guestUpdates)) {
            $guestParams[] = $booking['gid'];
            $sql = "UPDATE guests SET " . implode(', ', $guestUpdates) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute($guestParams);
        }
    }

    // Build booking update fields
    $updates = [];
    $params = [];

    if (isset($_POST['special_requests'])) {
        $updates[] = 'special_request = ?';
        $params[] = trim($_POST['special_requests']);
    }
    if (isset($_POST['num_guests'])) {
        $updates[] = 'adults = ?';
        $params[] = intval($_POST['num_guests']);
    }
    if (isset($_POST['booking_source'])) {
        $src = trim($_POST['booking_source']);
        if (!$src) $src = $booking['booking_source']; // Keep existing if empty
        if ($src === 'other') $src = 'ota';
        $updates[] = 'booking_source = ?';
        $params[] = $src;
        error_log("booking_source will be updated to: " . $src);
    }

    // Date changes
    $checkIn = !empty($_POST['check_in_date']) ? trim($_POST['check_in_date']) : $booking['check_in_date'];
    $checkOut = !empty($_POST['check_out_date']) ? trim($_POST['check_out_date']) : $booking['check_out_date'];
    $newRoomId = !empty($_POST['room_id']) ? intval($_POST['room_id']) : $booking['room_id'];
    $roomId = $newRoomId;

    $ciDate = new DateTime($checkIn);
    $coDate = new DateTime($checkOut);
    if ($coDate <= $ciDate) {
        throw new Exception('Check-out must be after check-in');
    }
    $nights = $ciDate->diff($coDate)->days;

    // Check availability if dates or room changed
    if ($checkIn !== $booking['check_in_date'] || $checkOut !== $booking['check_out_date'] || $roomId !== intval($booking['room_id'])) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM bookings 
            WHERE room_id = ? AND id != ? 
            AND status NOT IN ('cancelled', 'checked_out')
            AND check_in_date < ? AND check_out_date > ?
        ");
        $stmt->execute([$roomId, $bookingId, $checkOut, $checkIn]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Room is not available for selected dates');
        }
    }

    // Handle room change
    if ($roomId !== intval($booking['room_id'])) {
        $updates[] = 'room_id = ?';
        $params[] = $roomId;
    }

    // Get room price
    $roomPrice = floatval($_POST['room_price'] ?? 0);
    if (!$roomPrice) {
        $roomPrice = floatval($booking['room_price']);
    }
    if (!$roomPrice) {
        // Fallback to room_types base_price
        $stmt = $conn->prepare("SELECT rt.base_price FROM rooms r JOIN room_types rt ON r.room_type_id = rt.id WHERE r.id = ?");
        $stmt->execute([$roomId]);
        $rtRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $roomPrice = $rtRow ? $rtRow['base_price'] : 0;
    }

    $totalPrice = $roomPrice * $nights;
    
    // Discount handling
    $discountType = $_POST['discount_type'] ?? 'rp';
    $discountValue = floatval($_POST['discount_value'] ?? 0);
    if ($discountType === 'percent' && $discountValue > 0) {
        $discount = round($totalPrice * $discountValue / 100);
    } else {
        $discount = $discountValue;
    }
    
    $afterDiscount = $totalPrice - $discount;
    
    // OTA fee calculation
    $bookingSource = trim($_POST['booking_source'] ?? $booking['booking_source']);
    $otaFeePercent = 0;
    try {
        $feeStmt = $conn->prepare("SELECT fee_percent FROM booking_sources WHERE source_key = ? AND is_active = 1 LIMIT 1");
        $feeStmt->execute([$bookingSource]);
        $feeRow = $feeStmt->fetch(PDO::FETCH_ASSOC);
        if ($feeRow) {
            $otaFeePercent = (float)$feeRow['fee_percent'];
        }
    } catch (Exception $e) {
        // fallback: no fee
    }
    
    $otaFeeAmount = 0;
    if ($otaFeePercent > 0) {
        $otaFeeAmount = round($afterDiscount * $otaFeePercent / 100);
    }
    
    $finalPrice = $afterDiscount - $otaFeeAmount;

    // Add date/price fields to booking update
    $updates[] = 'check_in_date = ?';
    $params[] = $checkIn;
    $updates[] = 'check_out_date = ?';
    $params[] = $checkOut;
    $updates[] = 'total_nights = ?';
    $params[] = $nights;
    $updates[] = 'room_price = ?';
    $params[] = $roomPrice;
    $updates[] = 'total_price = ?';
    $params[] = $totalPrice;
    $updates[] = 'discount = ?';
    $params[] = $discount;
    $updates[] = 'final_price = ?';
    $params[] = $finalPrice;
    $updates[] = 'updated_at = NOW()';

    // Execute booking update
    $params[] = $bookingId;
    $sql = "UPDATE bookings SET " . implode(', ', $updates) . " WHERE id = ?";
    error_log("SQL: " . $sql);
    error_log("Params: " . json_encode($params));
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $mainRows = $stmt->rowCount();
    error_log("Rows affected: " . $mainRows);

    // NUCLEAR FIX: Separate standalone update for booking_source to guarantee it saves
    $intendedSource = trim($_POST['booking_source'] ?? $booking['booking_source']);
    $standaloneRows = -1;
    $standaloneError = '';
    if (!empty($intendedSource)) {
        try {
            $srcSql = "UPDATE bookings SET booking_source = '{$intendedSource}' WHERE id = {$bookingId}";
            error_log("STANDALONE RAW SQL: " . $srcSql);
            $srcStmt = $conn->exec($srcSql);
            $standaloneRows = $srcStmt;
            error_log("STANDALONE rows: " . $standaloneRows);
        } catch (Exception $se) {
            $standaloneError = $se->getMessage();
            error_log("STANDALONE ERROR: " . $standaloneError);
        }
    }

    // VERIFY: Re-read FULL row from database
    $verifyStmt = $conn->prepare("SELECT id, booking_source, status, room_id FROM bookings WHERE id = ?");
    $verifyStmt->execute([$bookingId]);
    $verifyRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    $verifiedSource = $verifyRow ? $verifyRow['booking_source'] : '__ROW_NOT_FOUND__';
    error_log("VERIFIED row: " . json_encode($verifyRow));
    
    // Also check current database name
    $dbNameStmt = $conn->query("SELECT DATABASE()");
    $currentDb = $dbNameStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Reservation updated successfully',
        'data' => [
            'booking_id' => $bookingId,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'nights' => $nights,
            'total_price' => $totalPrice,
            'final_price' => $finalPrice,
            'booking_source' => $verifiedSource,
            'intended_source' => $intendedSource
        ],
        'debug' => [
            'main_update_rows' => $mainRows,
            'standalone_rows' => $standaloneRows,
            'standalone_error' => $standaloneError,
            'verified_row' => $verifyRow,
            'current_db' => $currentDb,
            'post_booking_source' => $_POST['booking_source'] ?? '__NOT_SET__',
            'original_source' => $booking['booking_source']
        ]
    ]);

} catch (Exception $e) {
    error_log("ERROR: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    error_log("Stack: " . $e->getTraceAsString());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $t) {
    error_log("FATAL: " . $t->getMessage() . " at " . $t->getFile() . ":" . $t->getLine());
    error_log("Stack: " . $t->getTraceAsString());
    echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $t->getMessage()]);
}
error_log("=== update-reservation.php END ===");
ob_end_flush();
