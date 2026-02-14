<?php
/**
 * FRONT DESK - RESERVASI MANAGEMENT
 * Booking Management dengan Direct/OTA + Fee Calculation
 */

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================
// SECURITY & AUTHENTICATION
// ============================================
$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$currentUser = $auth->getCurrentUser();

if (!$auth->hasPermission('frontdesk')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'Reservasi Management - Direct/OTA Bookings';

// ============================================
// OTA CONFIGURATION (Default if not in DB)
// ============================================
$otaProviders = [
    'walk_in' => ['name' => 'Walk-in', 'fee' => 0, 'icon' => '🚶'],
    'phone' => ['name' => 'Phone Booking', 'fee' => 0, 'icon' => '📞'],
    'online' => ['name' => 'Direct Online', 'fee' => 0, 'icon' => '💻'],
    'agoda' => ['name' => 'Agoda', 'fee' => 15, 'icon' => '🏨'],
    'booking' => ['name' => 'Booking.com', 'fee' => 12, 'icon' => '📱'],
    'tiket' => ['name' => 'Tiket.com', 'fee' => 10, 'icon' => '✈️'],
    'airbnb' => ['name' => 'Airbnb', 'fee' => 3, 'icon' => '🏠'],
    'ota' => ['name' => 'OTA Lainnya', 'fee' => 10, 'icon' => '🌐'],
];

// ============================================
// GET BOOKINGS LIST
// ============================================
try {
    $status_filter = $_GET['status'] ?? 'all';
    
    $query = "
        SELECT 
            b.id, b.booking_code, b.check_in_date, b.check_out_date,
            b.room_price, b.total_price, b.final_price, b.discount,
            b.status, b.payment_status, b.booking_source, b.total_nights,
            b.paid_amount, b.special_request, b.adults, b.children,
            g.guest_name, g.phone, g.email,
            r.room_number,
            rt.type_name,
            COALESCE(SUM(bp.amount), b.paid_amount, 0) as total_paid
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        LEFT JOIN rooms r ON b.room_id = r.id
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        LEFT JOIN booking_payments bp ON b.id = bp.booking_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($status_filter !== 'all') {
        $query .= " AND b.status = ?";
        $params[] = $status_filter;
    }
    
    $query .= " GROUP BY b.id
    ORDER BY 
        CASE 
            WHEN b.status = 'confirmed' THEN 1
            WHEN b.status = 'checked_in' THEN 2
            WHEN b.status = 'pending' THEN 3
            ELSE 4
        END,
        b.check_in_date ASC
    LIMIT 50";
    
    $bookings = $db->fetchAll($query, $params);
    
} catch (Exception $e) {
    error_log("Reservasi List Error: " . $e->getMessage());
    $bookings = [];
}

// ============================================
// GET ALL AVAILABLE ROOMS (For Multi-Room Booking)
// ============================================
try {
    $rooms = $db->fetchAll("
        SELECT r.id, r.room_number, r.floor_number, r.status, rt.type_name, rt.base_price, rt.color_code
        FROM rooms r
        LEFT JOIN room_types rt ON r.room_type_id = rt.id
        WHERE r.status != 'maintenance'
        ORDER BY rt.type_name ASC, r.floor_number ASC, r.room_number ASC
    ", []);
} catch (Exception $e) {
    error_log("Rooms Error: " . $e->getMessage());
    $rooms = [];
}

// ============================================
// CALCULATE OTA FEE & NET INCOME
// ============================================
function calculateNetIncome($roomPrice, $otaProvider, $otaProviders) {
    $feePercent = $otaProviders[$otaProvider]['fee'] ?? 0;
    $feeAmount = ($roomPrice * $feePercent) / 100;
    $netIncome = $roomPrice - $feeAmount;
    
    return [
        'gross' => $roomPrice,
        'fee_percent' => $feePercent,
        'fee_amount' => $feeAmount,
        'net' => $netIncome
    ];
}

include '../../includes/header.php';
?>

<style>
/* SIMPLE & ELEGANT DESIGN */

.reservasi-container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 1rem;
}

.reservasi-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    gap: 1rem;
}

.reservasi-header h1 {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0;
    color: var(--text-primary);
}

.header-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-primary {
    background: #6366f1;
    color: white;
    padding: 0.4rem 0.85rem;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.8rem;
}

.btn-primary:hover {
    background: #4f46e5;
    transform: translateY(-1px);
}

.filter-section {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}

.filter-group label {
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-secondary);
}

.filter-group select,
.filter-group input {
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 0.85rem;
    background: var(--bg-secondary);
    color: var(--text-primary);
}

/* Bookings Table */
.bookings-table-wrapper {
    overflow-x: auto;
}

.bookings-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
}

.bookings-table thead {
    border-bottom: 2px solid var(--border-color);
    background: var(--bg-secondary);
}

.bookings-table th {
    padding: 0.5rem 0.65rem;
    text-align: left;
    font-weight: 700;
    color: var(--text-primary);
    text-transform: uppercase;
    font-size: 0.7rem;
    letter-spacing: 0.3px;
}

.bookings-table td {
    padding: 0.5rem 0.65rem;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-primary);
}

.bookings-table tbody tr {
    transition: background 0.2s ease;
}

.bookings-table tbody tr:hover {
    background: var(--bg-secondary);
}

/* Badge */
.badge {
    display: inline-block;
    padding: 0.25rem 0.6rem;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.7rem;
    text-transform: uppercase;
}

.badge-confirmed {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.badge-pending {
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
}

.badge-checked-in {
    background: rgba(59, 130, 246, 0.2);
    color: #3b82f6;
}

.badge-paid {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.badge-unpaid {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

/* Actions */
.row-actions {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.action-btn {
    padding: 0.3rem 0.6rem;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.7rem;
    font-weight: 600;
    transition: all 0.2s ease;
    background: #f3f4f6;
    color: #6366f1;
    border: 1px solid #e5e7eb;
    white-space: nowrap;
}

.action-btn:hover {
    background: #6366f1;
    color: white;
    border-color: #6366f1;
}

.action-btn.action-cancel {
    color: #f59e0b;
    border-color: #fcd34d;
}

.action-btn.action-cancel:hover {
    background: #f59e0b;
    color: white;
    border-color: #f59e0b;
}

.action-btn.action-delete {
    color: #ef4444;
    border-color: #fca5a5;
}

.action-btn.action-delete:hover {
    background: #ef4444;
    color: white;
    border-color: #ef4444;
}

.empty-state {
    text-align: center;
    padding: 3rem;
    color: var(--text-secondary);
}

.room-badge {
    display: inline-block;
    background: #e5e7eb;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.7rem;
    color: #374151;
}

.ota-badge {
    display: inline-block;
    padding: 0.2rem 0.5rem;
    border-radius: 4px;
    font-weight: 600;
    font-size: 0.7rem;
    background: #dbeafe;
    color: #1e40af;
}

.ota-badge .fee {
    color: #dc2626;
    font-weight: 700;
    margin-left: 0.25rem;
}

.price-breakdown {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.price-item {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
}

.price-gross {
    color: var(--text-secondary);
}

.price-fee {
    color: #dc2626;
}

.price-net {
    font-weight: 700;
    color: #059669;
}

/* Responsive */
@media (max-width: 768px) {
    .reservasi-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .bookings-table {
        font-size: 0.7rem;
    }
    
    .bookings-table th,
    .bookings-table td {
        padding: 0.5rem;
    }
}
</style>

<div class="reservasi-container">
    <!-- Header -->
    <div class="reservasi-header">
        <div>
            <h1>Reservasi Management</h1>
        </div>
        <div class="header-actions">
            <button class="btn-primary" onclick="openNewBookingModal()">
                New Booking
            </button>
            <button class="btn-primary" onclick="window.location='calendar.php'">
                Calendar View
            </button>
            <button class="btn-primary" onclick="window.location='breakfast.php'">
                Breakfast List
            </button>
            <button class="btn-primary" onclick="window.location='settings.php'">
                Settings
            </button>
            <button class="btn-primary btn-secondary" onclick="window.location='dashboard.php'">
                Dashboard
            </button>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-group">
            <label>Status Filter</label>
            <select onchange="filterBookings(this.value)">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="checked_in" <?php echo $status_filter === 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                <option value="checked_out" <?php echo $status_filter === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="bookings-table-wrapper">
        <?php if (!empty($bookings)): ?>
        <table class="bookings-table">
            <thead>
                <tr>
                    <th>Booking Code</th>
                    <th>Guest Name</th>
                    <th>Room</th>
                    <th>Check-in / Check-out</th>
                    <th>Nights</th>
                    <th>OTA Source</th>
                    <th>Price Breakdown</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): 
                    $netIncome = calculateNetIncome(
                        $booking['room_price'], 
                        $booking['booking_source'],
                        $otaProviders
                    );
                    $otaIcon = $otaProviders[$booking['booking_source']]['icon'] ?? '🌐';
                    $otaName = $otaProviders[$booking['booking_source']]['name'] ?? 'Other';
                    $otaFee = $otaProviders[$booking['booking_source']]['fee'] ?? 0;
                ?>
                <tr>
                    <!-- Booking Code -->
                    <td>
                        <strong><?php echo htmlspecialchars($booking['booking_code']); ?></strong>
                    </td>

                    <!-- Guest -->
                    <td>
                        <div>
                            <strong><?php echo htmlspecialchars($booking['guest_name']); ?></strong>
                            <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">
                                <?php echo htmlspecialchars($booking['phone'] ?? '-'); ?>
                            </div>
                        </div>
                    </td>

                    <!-- Room -->
                    <td>
                        <span class="room-badge">
                            <?php echo htmlspecialchars($booking['room_number']); ?>
                        </span>
                        <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                            <?php echo htmlspecialchars($booking['type_name']); ?>
                        </div>
                    </td>

                    <!-- Dates -->
                    <td>
                        <div style="font-size: 0.75rem;">
                            <?php echo date('d M', strtotime($booking['check_in_date'])); ?> -
                            <?php echo date('d M', strtotime($booking['check_out_date'])); ?>
                        </div>
                    </td>

                    <!-- Nights -->
                    <td>
                        <strong><?php echo $booking['total_nights']; ?></strong>
                    </td>

                    <!-- OTA Source -->
                    <td>
                        <span class="ota-badge">
                            <?php echo $otaName; ?>
                            <?php if ($otaFee > 0): ?>
                            <span class="fee">-<?php echo $otaFee; ?>%</span>
                            <?php endif; ?>
                        </span>
                    </td>

                    <!-- Price Breakdown -->
                    <td>
                        <div class="price-breakdown" style="font-size: 0.75rem;">
                            <div class="price-item price-gross">
                                <span>Gross:</span>
                                <span>Rp <?php echo number_format($netIncome['gross'], 0, ',', '.'); ?></span>
                            </div>
                            <?php if ($netIncome['fee_percent'] > 0): ?>
                            <div class="price-item price-fee">
                                <span>Fee (<?php echo $netIncome['fee_percent']; ?>%):</span>
                                <span>-Rp <?php echo number_format($netIncome['fee_amount'], 0, ',', '.'); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="price-item price-net">
                                <span>Net:</span>
                                <span>Rp <?php echo number_format($netIncome['net'], 0, ',', '.'); ?></span>
                            </div>
                        </div>
                    </td>

                    <!-- Status -->
                    <td>
                        <span class="badge badge-status badge-<?php echo str_replace('_', '-', $booking['status']); ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $booking['status'])); ?>
                        </span>
                    </td>

                    <!-- Payment Status -->
                    <td>
                        <span class="badge badge-payment-<?php echo str_replace('_', '-', $booking['payment_status']); ?>">
                            <?php echo ucfirst($booking['payment_status']); ?>
                        </span>
                        <div style="font-size: 0.7rem; margin-top: 0.3rem; line-height: 1.4;">
                            <div><span style="color: var(--text-secondary);">Total:</span> <strong>Rp <?php echo number_format($booking['final_price'], 0, ',', '.'); ?></strong></div>
                            <div><span style="color: var(--text-secondary);">Bayar:</span> <strong style="color: #10b981;">Rp <?php echo number_format($booking['total_paid'], 0, ',', '.'); ?></strong></div>
                            <?php 
                                $remaining = $booking['final_price'] - $booking['total_paid'];
                                if ($remaining > 0):
                            ?>
                            <div><span style="color: var(--text-secondary);">Sisa:</span> <strong style="color: #ef4444;">Rp <?php echo number_format($remaining, 0, ',', '.'); ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div class="row-actions">
                            <button class="action-btn" onclick="viewBooking(<?php echo $booking['id']; ?>)">
                                View
                            </button>
                            <button class="action-btn" onclick="editBooking(<?php echo $booking['id']; ?>)">
                                Edit
                            </button>
                            <button class="action-btn" style="background-color: #6366f1; color: white; border-color: #4f46e5;" onclick="printInvoice(<?php echo $booking['id']; ?>)">
                                📄 Invoice
                            </button>
                            
                            <?php 
                            // Calculate remaining balance
                            $remaining = $booking['final_price'] - max($booking['paid_amount'], $booking['total_paid']);
                            if ($remaining > 0 && $booking['status'] !== 'cancelled' && $booking['status'] !== 'checked_out'): 
                            ?>
                            <button class="action-btn" style="background-color: #f59e0b; color: white; border-color: #d97706;" onclick="addPayment(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>', <?php echo $remaining; ?>)">
                                💰 Pay
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] === 'confirmed'): ?>
                            <button class="action-btn action-checkin" style="background-color: #10b981; color: white; border-color: #059669;" onclick="checkinBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">
                                Check-in
                            </button>
                            <?php endif; ?>

                            <?php if ($booking['status'] !== 'checked_in' && $booking['status'] !== 'checked_out'): ?>
                            <button class="action-btn action-cancel" onclick="cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">
                                Cancel
                            </button>
                            <button class="action-btn action-delete" onclick="deleteBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_code']); ?>')">
                                Delete
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <p style="font-size: 1.1rem;">Tidak ada reservasi</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- NEW BOOKING MODAL -->
<div id="newBookingModal" class="modal-overlay" style="display: none;">
    <div class="modal-compact-booking">
        <div class="modal-header-compact">
            <h2>New Reservation - Multiple Rooms</h2>
            <button type="button" class="close-btn" onclick="closeNewBookingModal()">&times;</button>
        </div>
        
        <form id="newBookingForm" onsubmit="submitMultiRoomBooking(event)">
            <div class="form-compact">
                <!-- GUEST INFO -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Guest Name*</label>
                        <input type="text" id="guestName" name="guest_name" required placeholder="Full name">
                    </div>
                    <div class="input-compact">
                        <label>Phone</label>
                        <input type="text" id="guestPhone" name="guest_phone" placeholder="Phone/WA">
                    </div>
                </div>

                <!-- DATES -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Check In*</label>
                        <input type="date" id="checkInDate" name="check_in_date" required onchange="loadAvailableRooms()">
                    </div>
                    <div class="input-compact">
                        <label>Check Out*</label>
                        <input type="date" id="checkOutDate" name="check_out_date" required onchange="loadAvailableRooms()">
                    </div>
                </div>

                <!-- ROOMS SELECTION (MULTI SELECT) -->
                <div class="input-compact">
                    <label>Select Rooms* (dapat pilih lebih dari 1)</label>
                    <div id="availabilityInfo" style="margin-bottom: 8px; font-size: 0.85rem;"></div>
                    <div id="roomsChecklistContainer" class="rooms-checklist" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px; background: #f9f9f9;">
                        <em style="color: #888;">Loading rooms...</em>
                    </div>
                    <div id="selectedRoomsSummary" style="margin-top: 8px; font-size: 0.85rem; color: #6366f1;"></div>
                </div>

                <!-- SOURCE & PAYMENT METHOD -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Booking Source</label>
                        <select id="bookingSource" name="booking_source">
                            <option value="walk_in">Direct (Walk-in)</option>
                            <option value="phone">Direct (Phone)</option>
                            <option value="agoda">Agoda</option>
                            <option value="booking">Booking.com</option>
                            <option value="tiket">Tiket.com</option>
                            <option value="airbnb">Airbnb</option>
                        </select>
                    </div>
                    <div class="input-compact">
                        <label>Payment Method</label>
                        <select name="payment_method" id="paymentMethod">
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                            <option value="ota">OTA</option>
                        </select>
                    </div>
                </div>

                <!-- PRICE SUMMARY -->
                <div class="price-summary-compact">
                    <div class="price-line">
                        <span>Total Rooms:</span>
                        <strong id="totalRoomsDisplay">0 rooms</strong>
                    </div>
                    <div class="price-line">
                        <span>Nights:</span>
                        <strong id="displayNights">0</strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal:</span>
                        <strong id="subtotalDisplay">Rp 0</strong>
                    </div>
                    <div class="price-line">
                        <span>Discount (Rp):</span>
                        <input type="number" id="discount" name="discount" value="0" onchange="calculateMultiRoomTotal()" style="text-align:right; width: 150px;">
                    </div>
                    <div class="price-line-total">
                        <span>GRAND TOTAL:</span>
                        <strong id="grandTotalDisplay" style="color:#10b981; font-size: 1.3rem;">Rp 0</strong>
                    </div>
                 </div>

                <!-- PAYMENT -->
                <div class="input-compact">
                    <label>Initial Payment (DP) - Rp</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" id="paidAmount" name="paid_amount" value="0" placeholder="0" style="flex: 1;">
                        <button type="button" onclick="payFullMultiRoom()" class="btn-pay-all" title="Pay Full Amount">Pay All</button>
                    </div>
                </div>

                <!-- SPECIAL REQUEST -->
                <div class="input-compact">
                    <label>Special Request</label>
                    <textarea name="special_request" id="specialRequest" rows="2" style="width: 100%; padding: 8px; border-radius: 5px; border: 1px solid #ddd;"></textarea>
                </div>
            </div>

            <div class="modal-footer-compact">
                <button type="button" class="btn-cancel" onclick="closeNewBookingModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(3px);
}

.modal-compact-booking {
    width: 90%;
    max-width: 700px;
    max-height: 90vh;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
    overflow-y: auto;
}

.modal-header-compact {
    padding: 1.2rem 1.5rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: sticky;
    top: 0;
    z-index: 10;
}

.modal-header-compact h2 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
}

.close-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    font-size: 1.8rem;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    line-height: 1;
}

.close-btn:hover {
    background: rgba(255,255,255,0.3);
    transform: rotate(90deg);
}

.form-compact {
    padding: 1.5rem;
}

.form-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.input-compact {
    margin-bottom: 1rem;
}

.input-compact label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 0.4rem;
}

.input-compact input,
.input-compact select {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: all 0.2s;
}

.input-compact input:focus,
.input-compact select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.rooms-checklist {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #ddd;
    padding: 10px;
    border-radius: 5px;
    background: #f9f9f9;
}

.room-checkbox-item {
    display: block;
    padding: 8px;
    margin-bottom: 5px;
    background: white;
    border-radius: 3px;
    cursor: pointer;
    transition: all 0.2s;
}

.room-checkbox-item:hover {
    background: #f0f9ff;
    border-left: 3px solid #6366f1;
}

.room-checkbox-item input:checked + * {
    font-weight: bold;
}

.price-summary-compact {
    background: #f9fafb;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.price-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.6rem;
    font-size: 0.9rem;
}

.price-line-total {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 0.8rem;
    margin-top: 0.8rem;
    border-top: 2px solid #e5e7eb;
    font-size: 1.1rem;
    font-weight: 700;
}

.btn-pay-all {
    background: #10b981;
    color: white;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.btn-pay-all:hover {
    background: #059669;
}

.modal-footer-compact {
    padding: 1rem 1.5rem;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
    position: sticky;
    bottom: 0;
}

.btn-cancel {
    padding: 0.7rem 1.5rem;
    background: #6b7280;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-cancel:hover {
    background: #4b5563;
}

.btn-save {
    padding: 0.7rem 2rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
}
</style>

<script>
function filterBookings(value) {
    window.location.search = '?status=' + value;
}

function openNewBookingModal() {
    document.getElementById('newBookingModal').style.display = 'flex';
    // Set default dates
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    
    document.getElementById('checkInDate').value = today.toISOString().split('T')[0];
    document.getElementById('checkOutDate').value = tomorrow.toISOString().split('T')[0];
    
    // Load available rooms for default dates
    loadAvailableRooms();
}

function closeNewBookingModal() {
    document.getElementById('newBookingModal').style.display = 'none';
    document.getElementById('newBookingForm').reset();
}

function updateCheckOutMinDate() {
    const checkInInput = document.getElementById('checkInDate');
    const checkOutInput = document.getElementById('checkOutDate');
    
    if (checkInInput && checkOutInput && checkInInput.value) {
        // Set min check-out to day after check-in
        const checkInDate = new Date(checkInInput.value);
        checkInDate.setDate(checkInDate.getDate() + 1);
        const minCheckOut = checkInDate.toISOString().split('T')[0];
        checkOutInput.min = minCheckOut;
        
        // If current check-out is before min, auto-update it
        if (!checkOutInput.value || checkOutInput.value <= checkInInput.value) {
            checkOutInput.value = minCheckOut;
        }
    }
}

async function loadAvailableRooms() {
    const checkIn = document.getElementById('checkInDate').value;
    const checkOut = document.getElementById('checkOutDate').value;
    
    // Update min date for check-out
    updateCheckOutMinDate();
    
    if (!checkIn || !checkOut) {
        document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">Pilih tanggal check-in dan check-out terlebih dahulu</em>';
        return;
    }
    
    // Validate dates
    if (new Date(checkOut) <= new Date(checkIn)) {
        document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">❌ Check-out harus minimal 1 hari setelah check-in</em>';
        document.getElementById('availabilityInfo').innerHTML = '<small style="color: #ef4444;">Invalid dates</small>';
        return;
    }
    
    // Show loading
    document.getElementById('roomsChecklistContainer').innerHTML = '<div style="text-align:center; padding: 20px;"><em>Loading available rooms...</em></div>';
    
    try {
        const response = await fetch(`../../api/get-available-rooms.php?check_in=${checkIn}&check_out=${checkOut}`);
        
        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success && result.rooms.length > 0) {
            let html = '';
            result.rooms.forEach(room => {
                html += `
                    <label class="room-checkbox-item" style="display: block; padding: 8px; margin-bottom: 5px; background: white; border-radius: 3px; cursor: pointer; transition: all 0.2s;">
                        <input type="checkbox" name="rooms[]" value="${room.id}" 
                               data-price="${room.base_price}"
                               data-room="${room.room_number}"
                               data-type="${room.type_name}"
                               onchange="calculateMultiRoomTotal()"
                               style="margin-right: 8px;">
                        <strong>Room ${room.room_number}</strong> - ${room.type_name}
                        <span style="color: #10b981; font-weight: bold;">(Rp ${parseInt(room.base_price).toLocaleString('id-ID')}/night)</span>
                    </label>
                `;
            });
            document.getElementById('roomsChecklistContainer').innerHTML = html;
            document.getElementById('availabilityInfo').innerHTML = `<small style="color: #10b981;">✅ ${result.available_rooms} room(s) available (${result.booked_rooms} booked)</small>`;
        } else if (result.success && result.rooms.length === 0) {
            document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">❌ Tidak ada room yang tersedia untuk tanggal ini (semua sudah di-booking)</em>';
            document.getElementById('availabilityInfo').innerHTML = `<small style="color: #ef4444;">0 rooms available (all ${result.booked_rooms} rooms booked)</small>`;
        } else {
            document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">Error loading rooms: ' + (result.message || 'Unknown error') + '</em>';
        }
        
        // Recalculate totals
        calculateMultiRoomTotal();
        
    } catch (error) {
        console.error('Error loading rooms:', error);
        document.getElementById('roomsChecklistContainer').innerHTML = '<em style="color: #ef4444;">Error loading rooms. Please try again.</em>';
    }
}

function calculateMultiRoomTotal() {
    const checkInStr = document.getElementById('checkInDate').value;
    const checkOutStr = document.getElementById('checkOutDate').value;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    
    if (!checkInStr || !checkOutStr) {
        return;
    }
    
    const checkIn = new Date(checkInStr);
    const checkOut = new Date(checkOutStr);
    const nights = Math.ceil((checkOut - checkIn) / (1000 * 60 * 60 * 24));
    
    if (nights <= 0) {
        alert('Check-out must be after check-in!');
        return;
    }
    
    // Get all checked rooms
    const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
    const totalRooms = checkedRooms.length;
    
    let subtotal = 0;
    let roomDetails = [];
    
    checkedRooms.forEach(checkbox => {
        const price = parseFloat(checkbox.dataset.price) || 0;
        const roomNumber = checkbox.dataset.room;
        const roomType = checkbox.dataset.type;
        const roomTotal = price * nights;
        subtotal += roomTotal;
        roomDetails.push(`Room ${roomNumber} (${roomType}): Rp ${roomTotal.toLocaleString('id-ID')}`);
    });
    
    const grandTotal = subtotal - discount;
    
    // Update display
    document.getElementById('totalRoomsDisplay').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
    document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
    document.getElementById('subtotalDisplay').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
    document.getElementById('grandTotalDisplay').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');
    
    // Update summary
    if (totalRooms > 0) {
        document.getElementById('selectedRoomsSummary').innerHTML = 
            '<strong>Selected:</strong> ' + totalRooms + ' room(s) × ' + nights + ' night(s) = Rp ' + subtotal.toLocaleString('id-ID');
    } else {
        document.getElementById('selectedRoomsSummary').innerHTML = '<em style="color: #ef4444;">Belum ada room yang dipilih</em>';
    }
}

function payFullMultiRoom() {
    const grandTotalText = document.getElementById('grandTotalDisplay').textContent;
    const grandTotal = parseFloat(grandTotalText.replace(/[^\d]/g, ''));
    document.getElementById('paidAmount').value = grandTotal;
}

async function submitMultiRoomBooking(event) {
    event.preventDefault();
    
    // Validate room selection
    const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
    if (checkedRooms.length === 0) {
        alert('Silakan pilih minimal 1 room!');
        return;
    }
    
    // Get form data
    const guestName = document.getElementById('guestName').value;
    const guestPhone = document.getElementById('guestPhone').value || '';
    const checkIn = document.getElementById('checkInDate').value;
    const checkOut = document.getElementById('checkOutDate').value;
    const bookingSource = document.getElementById('bookingSource').value;
    const paymentMethod = document.getElementById('paymentMethod').value;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
    const specialRequest = document.getElementById('specialRequest').value || '';
    
    // Calculate nights
    const nights = Math.ceil((new Date(checkOut) - new Date(checkIn)) / (1000 * 60 * 60 * 24));
    
    // Calculate discount per room (distribute equally)
    const discountPerRoom = discount / checkedRooms.length;
    
    // Calculate payment per room (distribute proportionally)
    let totalPrice = 0;
    const roomPrices = [];
    checkedRooms.forEach(checkbox => {
        const price = parseFloat(checkbox.dataset.price) * nights - discountPerRoom;
        roomPrices.push(price);
        totalPrice += price;
    });
    
    // Disable submit button
    const submitBtn = event.target.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating bookings...';
    
    let successCount = 0;
    let errorCount = 0;
    const bookingCodes = [];
    
    // Create booking for each room
    for (let i = 0; i < checkedRooms.length; i++) {
        const checkbox = checkedRooms[i];
        const roomId = checkbox.value;
        const roomNumber = checkbox.dataset.room;
        const roomPrice = roomPrices[i];
        
        // Calculate proportional payment
        const proportionalPayment = totalPrice > 0 ? (paidAmount * (roomPrice / totalPrice)) : 0;
        
        // Create FormData for API
        const formData = new FormData();
        formData.append('guest_name', guestName);
        formData.append('guest_phone', guestPhone);
        formData.append('room_id', roomId);
        formData.append('check_in', checkIn);
        formData.append('check_out', checkOut);
        formData.append('adults', 1);
        formData.append('children', 0);
        formData.append('final_price', roomPrice);
        formData.append('booking_source', bookingSource);
        formData.append('payment_method', paymentMethod);
        formData.append('paid_amount', proportionalPayment);
        formData.append('special_request', specialRequest);
        
        try {
            const response = await fetch('api/create-reservation.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                successCount++;
                bookingCodes.push(result.booking_code);
            } else {
                errorCount++;
                console.error(`Error booking Room ${roomNumber}:`, result.message);
            }
        } catch (error) {
            errorCount++;
            console.error(`Error booking Room ${roomNumber}:`, error);
        }
    }
    
    // Re-enable submit button
    submitBtn.disabled = false;
    submitBtn.textContent = 'Create Booking';
    
    // Show results
    if (successCount > 0) {
        alert(`✅ Berhasil membuat ${successCount} booking!\n\nBooking Codes: ${bookingCodes.join(', ')}\n\n${errorCount > 0 ? `⚠️ ${errorCount} booking gagal dibuat.` : ''}`);
        closeNewBookingModal();
        window.location.reload(); // Refresh to show new bookings
    } else {
        alert('❌ Gagal membuat booking. Silakan coba lagi.');
    }
}

function viewBooking(id) {
    alert('Coming Soon: View Booking #' + id);
}

function editBooking(id) {
    window.location.href = 'edit-booking.php?id=' + id;
}

function printInvoice(id) {
    // Open invoice in new window for printing
    window.open('invoice.php?booking_id=' + id, '_blank', 'width=800,height=900');
}

function addPayment(bookingId, bookingCode, remainingAmount) {
    const formattedRemaining = 'Rp ' + remainingAmount.toLocaleString('id-ID');
    
    const amount = prompt(
        `💰 TAMBAH PEMBAYARAN\n\n` +
        `Booking: ${bookingCode}\n` +
        `Sisa Tagihan: ${formattedRemaining}\n\n` +
        `Masukkan jumlah pembayaran:`,
        remainingAmount
    );
    
    if (amount === null) return; // User cancelled
    
    const payAmount = parseFloat(amount);
    if (isNaN(payAmount) || payAmount <= 0) {
        alert('❌ Jumlah pembayaran tidak valid!');
        return;
    }
    
    if (payAmount > remainingAmount) {
        if (!confirm(`⚠️ Jumlah melebihi sisa tagihan!\n\nSisa: ${formattedRemaining}\nInput: Rp ${payAmount.toLocaleString('id-ID')}\n\nLanjutkan?`)) {
            return;
        }
    }
    
    // Ask for payment method
    const method = prompt(
        `Pilih metode pembayaran:\n\n` +
        `1 = Cash\n` +
        `2 = Transfer\n` +
        `3 = QRIS\n` +
        `4 = Card\n\n` +
        `Masukkan nomor pilihan:`,
        '1'
    );
    
    const methodMap = {
        '1': 'cash',
        '2': 'transfer',
        '3': 'qris',
        '4': 'card'
    };
    
    const paymentMethod = methodMap[method] || 'cash';
    
    // Submit payment
    const formData = new FormData();
    formData.append('booking_id', bookingId);
    formData.append('amount', payAmount);
    formData.append('payment_method', paymentMethod);
    
    fetch('../../api/add-booking-payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ PEMBAYARAN BERHASIL!\n\n' + (data.message || 'Payment recorded successfully'));
            location.reload();
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('❌ Error: ' + error.message);
        console.error('Error:', error);
    });
}

function checkinBooking(id, bookingCode) {
    // First, check payment status
    fetch('../../api/get-booking-details.php?id=' + id)
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert('Error: ' + data.message);
            return;
        }
        
        const booking = data.booking;
        const finalPrice = parseFloat(booking.final_price);
        const paidAmount = parseFloat(booking.paid_amount);
        const remaining = finalPrice - paidAmount;
        
        // If not fully paid, ask user what to do
        if (remaining > 0) {
            const formattedRemaining = 'Rp ' + remaining.toLocaleString('id-ID');
            const formattedTotal = 'Rp ' + finalPrice.toLocaleString('id-ID');
            const formattedPaid = 'Rp ' + paidAmount.toLocaleString('id-ID');
            
            const choice = confirm(
                `⚠️ PEMBAYARAN BELUM LUNAS!\n\n` +
                `Booking: ${bookingCode}\n` +
                `Total Tagihan: ${formattedTotal}\n` +
                `Sudah Dibayar: ${formattedPaid}\n` +
                `SISA KURANG: ${formattedRemaining}\n\n` +
                `Klik OK untuk BAYAR SEKARANG\n` +
                `Klik Cancel untuk TETAP CHECK-IN (tagihan masih ada)`
            );
            
            if (choice) {
                // User wants to pay - open payment modal
                openPaymentModal(id, bookingCode, remaining);
            } else {
                // User wants to check-in anyway
                proceedCheckin(id, bookingCode);
            }
        } else {
            // Fully paid, just confirm check-in
            if (confirm(`Konfirmasi Check-in untuk booking ${bookingCode}?\n\n- Status akan berubah menjadi CHECKED IN\n- Kamar akan ditandai TERISI`)) {
                proceedCheckin(id, bookingCode);
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        // Fallback - just proceed with check-in
        if (confirm(`Konfirmasi Check-in untuk booking ${bookingCode}?\n\n- Status akan berubah menjadi CHECKED IN\n- Kamar akan ditandai TERISI`)) {
            proceedCheckin(id, bookingCode);
        }
    });
}

function proceedCheckin(id, bookingCode) {
    fetch('../../api/checkin-guest.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            booking_id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Check-in BERHASIL! Tamu sudah masuk.');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        console.error('Error:', error);
    });
}

function openPaymentModal(bookingId, bookingCode, remainingAmount) {
    const amount = prompt(
        `💰 PEMBAYARAN BOOKING ${bookingCode}\n\n` +
        `Sisa Tagihan: Rp ${remainingAmount.toLocaleString('id-ID')}\n\n` +
        `Masukkan jumlah pembayaran:`,
        remainingAmount
    );
    
    if (amount === null) return; // User cancelled
    
    const payAmount = parseFloat(amount);
    if (isNaN(payAmount) || payAmount <= 0) {
        alert('❌ Jumlah pembayaran tidak valid!');
        return;
    }
    
    // Ask for payment method
    const method = prompt(
        `Pilih metode pembayaran:\n\n` +
        `1 = Cash\n` +
        `2 = Transfer\n` +
        `3 = QRIS\n` +
        `4 = Card\n\n` +
        `Masukkan nomor pilihan:`,
        '1'
    );
    
    const methodMap = {
        '1': 'cash',
        '2': 'transfer',
        '3': 'qris',
        '4': 'card'
    };
    
    const paymentMethod = methodMap[method] || 'cash';
    
    // Submit payment
    const formData = new FormData();
    formData.append('booking_id', bookingId);
    formData.append('amount', payAmount);
    formData.append('payment_method', paymentMethod);
    
    fetch('../../api/add-booking-payment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Pembayaran berhasil!\n\n' + (data.message || ''));
            
            // Now proceed with check-in
            if (confirm(`Lanjutkan Check-in untuk booking ${bookingCode}?`)) {
                proceedCheckin(bookingId, bookingCode);
            } else {
                location.reload();
            }
        } else {
            alert('❌ Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('❌ Error: ' + error.message);
        console.error('Error:', error);
    });
}

function cancelBooking(id, bookingCode) {
    if (!confirm(`Yakin ingin CANCEL reservasi ${bookingCode}?\n\nStatus akan berubah menjadi CANCELLED`)) {
        return;
    }
    
    fetch('../../api/cancel-booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            booking_id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Reservasi berhasil di-CANCEL');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        console.error('Error:', error);
    });
}

function deleteBooking(id, bookingCode) {
    if (!confirm(`PERINGATAN: Yakin ingin menghapus reservasi ${bookingCode}?\n\nAksi ini TIDAK BISA DIBATALKAN!\n\nData akan dihapus permanen dari sistem.`)) {
        return;
    }
    
    fetch('../../api/delete-booking.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            booking_id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Reservasi berhasil dihapus permanen');
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
        console.error('Error:', error);
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
