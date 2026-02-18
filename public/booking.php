<?php
/**
 * PUBLIC WEBSITE - Booking Page
 * Complete booking flow
 */

define('PUBLIC_ACCESS', true);
require_once './includes/config.php';
require_once './includes/database.php';

$pageTitle = 'Book a Room - ' . BUSINESS_NAME;
$additionalCSS = ['css/booking.css'];
$additionalJS = ['js/booking.js'];

// Get all room types for reference
$db = PublicDatabase::getInstance();
$roomTypes = $db->fetchAll("
    SELECT DISTINCT rt.id, rt.type_name, rt.base_price, rt.description
    FROM room_types rt
    ORDER BY rt.base_price ASC
");

?>
<?php include './includes/header.php'; ?>

<section class="section booking-page">
    <div class="container">
        <h1 style="margin-bottom: 1rem;">Book a Room</h1>
        <p style="color: #64748b; margin-bottom: 2rem;">Safe and easy booking process</p>
        
        <div class="booking-container">
            <!-- Left side: Search & Room Selection -->
            <div class="booking-column booking-search">
                <div class="search-box card">
                    <h3>1. Select Date & Room</h3>
                    
                    <div class="form-group">
                        <label>Check In*</label>
                        <input type="date" id="bookingCheckIn" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Check Out*</label>
                        <input type="date" id="bookingCheckOut" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Number of Guests*</label>
                        <select id="bookingGuests">
                            <option value="1">1 Guest</option>
                            <option value="2" selected>2 Guests</option>
                            <option value="3">3 Guests</option>
                            <option value="4">4+ Guests</option>
                        </select>
                    </div>
                    
                    <button class="btn btn-primary btn-block" onclick="searchAvailableRooms()">
                        <span>Search Availability</span>
                        <span id="searchLoader" style="display: none; margin-left: 0.5rem;">
                            <i data-feather="loader"></i>
                        </span>
                    </button>
                </div>
                
                <!-- Available Rooms List -->
                <div id="availableRoomsList" style="display: none; margin-top: 2rem;">
                    <div class="card">
                        <h3>Available Rooms</h3>
                        <div id="roomsContainer"></div>
                    </div>
                </div>
                
                <!-- No Rooms Message -->
                <div id="noRoomsMessage" style="display: none; margin-top: 2rem;">
                    <div class="alert alert-warning">
                        Sorry, no rooms available for the selected dates. Please try another date.
                    </div>
                </div>
            </div>
            
            <!-- Right side: Guest Info & Summary -->
            <div class="booking-column booking-form">
                <div class="info-box card" id="bookingSummary" style="display: none;">
                    <h3>2. Guest Details</h3>
                    
                    <div class="form-group">
                        <label>Full Name*</label>
                        <input type="text" id="guestName" placeholder="Example: John Doe" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email*</label>
                        <input type="email" id="guestEmail" placeholder="example@email.com" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number*</label>
                        <input type="text" id="guestPhone" placeholder="+62812345678" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Special Requests (Optional)</label>
                        <textarea id="guestRequest" placeholder="Example: Near window, High floor, etc." rows="4"></textarea>
                    </div>
                    
                    <!-- Booking Summary -->
                    <div class="summary-box">
                        <h4 style="margin-bottom: 1rem;">Order Summary</h4>
                        
                        <div class="summary-row">
                            <span>Room:</span>
                            <strong id="summaryRoom">-</strong>
                        </div>
                        
                        <div class="summary-row">
                            <span>Check In:</span>
                            <strong id="summaryCheckIn">-</strong>
                        </div>
                        
                        <div class="summary-row">
                            <span>Check Out:</span>
                            <strong id="summaryCheckOut">-</strong>
                        </div>
                        
                        <div class="summary-row">
                            <span>Nights:</span>
                            <strong id="summaryNights">0</strong>
                        </div>
                        
                        <div class="summary-row">
                            <span>Price per Night:</span>
                            <strong id="summaryRoomPrice">Rp 0</strong>
                        </div>
                        
                        <div style="border-top: 2px solid #e2e8f0; padding-top: 1rem; margin-top: 1rem;" class="summary-row">
                            <span style="font-weight: 600;">Total Price:</span>
                            <strong id="summaryTotalPrice" style="color: #6366f1; font-size: 1.3rem;">Rp 0</strong>
                        </div>
                    </div>
                    
                    <button class="btn btn-primary btn-block" onclick="proceedToPayment()" style="margin-top: 2rem;">
                        Proceed to Payment
                    </button>
                </div>
                
                <!-- No Room Selected Message -->
                <div class="card" id="selectRoomMessage">
                    <p style="text-align: center; color: #94a3b8; padding: 2rem 0;">
                        <i data-feather="arrow-left" style="display: block; margin: 0 auto 1rem;"></i>
                        Select a room from the available list
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Hidden form for payment processing -->
<form id="paymentForm" method="POST" style="display: none;">
    <input type="hidden" id="bookingId" name="booking_id">
    <input type="hidden" id="bookingCode" name="booking_code">
    <input type="hidden" id="paymentAmount" name="amount">
</form>

<?php include './includes/footer.php'; ?>
