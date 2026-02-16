<?php
/**
 * FRONT DESK - CALENDAR BOOKING VIEW
 * Interactive Calendar like CloudBeds
 * Horizontal: Dates | Vertical: Room Numbers + Guest Names
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

$pageTitle = 'Calendar Booking';

// ============================================
// GET OTA FEES (For Frontend Logic)
// ============================================
$otaFees = [
    'direct' => 0,
    'agoda' => 15,
    'booking' => 12,
    'tiket' => 10,
    'traveloka' => 15,
    'airbnb' => 3
];
try {
    // Attempt to fetch from settings table if exists
    $fees = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'ota_fee_%'");
    if ($fees) {
        foreach ($fees as $fee) {
            $key = str_replace(['ota_fee_', '_'], ['', ' '], $fee['setting_key']); // e.g., 'ota_fee_agoda' -> 'agoda'
            // Normalize key for JS mapping
            $normalizedKey = strtolower(str_replace(['.com', ' '], ['', '_'], $key));
            $otaFees[$normalizedKey] = (float)$fee['setting_value'];
        }
    }
} catch (Exception $e) {
    // Keep defaults
}

// ============================================
// GET CALENDAR DATE RANGE (Include Past Dates for History)
// ============================================
$startDate = $_GET['start'] ?? date('Y-m-d');
$daysBefore = 60; // Show 60 days before for history/checkout bookings
$daysAfter = 365; // Show 365 days after for future bookings
$dates = [];

// Add past dates (for history view)
for ($i = $daysBefore; $i > 0; $i--) {
    $dates[] = date('Y-m-d', strtotime($startDate . " -{$i} days"));
}

// Add current and future dates
for ($i = 0; $i < $daysAfter; $i++) {
    $dates[] = date('Y-m-d', strtotime($startDate . " +{$i} days"));
}

// ============================================
// GET ALL ROOMS WITH TYPES
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
// GET BOOKINGS FOR DATE RANGE
// ============================================
try {
    // Calculate actual date range (including past dates)
    $actualStartDate = date('Y-m-d', strtotime($startDate . " -{$daysBefore} days"));
    $actualEndDate = date('Y-m-d', strtotime($startDate . " +{$daysAfter} days"));
    
    // Fetch all bookings that overlap with date range (including history)
    $bookings = $db->fetchAll("
        SELECT 
            b.id, 
            b.booking_code, 
            b.room_id, 
            b.check_in_date, 
            b.check_out_date,
            b.status, 
            b.room_price, 
            b.booking_source,
            b.payment_status,
            g.guest_name, 
            g.phone
        FROM bookings b
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.check_in_date < ? 
        AND b.check_out_date > ?
        AND b.status IN ('pending', 'confirmed', 'checked_in', 'checked_out')
        ORDER BY b.check_in_date ASC, b.room_id ASC
    ", [$actualEndDate, $actualStartDate]);
    
    echo "<!-- DEBUG: Found " . count($bookings) . " bookings -->\n";
    
} catch (Exception $e) {
    error_log("Bookings Error: " . $e->getMessage());
    $bookings = [];
}

// ============================================
// BUILD BOOKING MATRIX
// ============================================
$bookingMatrix = [];
foreach ($bookings as $booking) {
    $roomId = $booking['room_id'];
    if (!isset($bookingMatrix[$roomId])) {
        $bookingMatrix[$roomId] = [];
    }
    $bookingMatrix[$roomId][$booking['booking_code']] = $booking;
}

// ============================================
// BOOKING COLORS - SIMPLE: Default vs Checked-In vs Checked-Out
// ============================================
$defaultColor = ['bg' => '#3b82f6', 'text' => 'white'];        // Blue for pending/confirmed bookings
$checkedInColor = ['bg' => '#10b981', 'text' => 'white'];      // Green for checked-in guests (active)
$checkedOutColor = ['bg' => '#9ca3af', 'text' => '#6b7280'];   // Gray transparent for checked-out (history)

include '../../includes/header.php';
?>

<style>
/* ============================================
   CLOUDBEDS STYLE CALENDAR - SYSTEM THEME
   ============================================ */

.calendar-container {
    width: 100%;
    padding: 0.3rem 0.1rem;
    overflow: visible;
    box-sizing: border-box;
    max-width: 100%;
    position: relative;
    z-index: 1;
}

/* Scroll Container - MUST BE CONSTRAINED */
.calendar-scroll-wrapper {
    width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    position: relative;
    box-sizing: border-box;
    display: block;
    white-space: nowrap;
    cursor: grab !important;
    background: transparent;
    user-select: none;
    -webkit-user-select: none;
    padding-bottom: 5px; /* Space for scrollbar */
}

.calendar-scroll-wrapper::-webkit-scrollbar {
    height: 8px;
}

.calendar-scroll-wrapper::-webkit-scrollbar-thumb {
    background: rgba(99, 102, 241, 0.3);
    border-radius: 4px;
}

.calendar-scroll-wrapper:active {
    cursor: grabbing !important;
}

.calendar-scroll-wrapper.dragging {
    cursor: grabbing !important;
}

.calendar-header {
    display: flex;
    flex-direction: column;
    margin-bottom: 0.5rem;
    gap: 0.5rem;
}

.calendar-header h1 {
    font-size: 1rem;
    font-weight: 800;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    margin: 0;
}

.calendar-header h1 .icon {
    -webkit-text-fill-color: #6366f1;
    background: none;
}

.calendar-controls {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    flex-wrap: wrap;
}

.btn-nav {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: #ffffff !important;
    border: none;
    padding: 0.4rem 0.7rem;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.75rem;
    transition: all 0.3s ease;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    line-height: 1.2;
    text-decoration: none;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.btn-nav:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    color: #ffffff !important;
}

.btn-nav:visited,
.btn-nav:active,
.btn-nav:focus {
    color: #ffffff !important;
}

.date-display {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-primary);
}

.nav-date-input {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid var(--border-color);
    color: var(--text-primary);
    padding: 0.5rem 0.8rem;
    border-radius: 6px;
    font-weight: 600;
    font-size: 0.8rem;
}

/* Navigation Bar */
.calendar-nav {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
    background: var(--card-bg);
    backdrop-filter: blur(30px);
    border: 0.5px solid var(--border-color);
    border-radius: 8px;
    padding: 0.4rem;
}

.nav-btn {
    background: rgba(99, 102, 241, 0.2);
    color: #6366f1;
    border: 1px solid rgba(99, 102, 241, 0.3);
    padding: 0.4rem 0.6rem;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.75rem;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.nav-btn:hover {
    background: rgba(99, 102, 241, 0.4);
    border-color: rgba(99, 102, 241, 0.6);
    color: white;
}

.nav-date-input {
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: var(--text-primary);
    padding: 0.6rem 1rem;
    border-radius: 8px;
    font-weight: 600;
}

/* Calendar Wrapper */
.calendar-wrapper {
    background: var(--card-bg);
    backdrop-filter: blur(30px);
    border: 0.5px solid var(--border-color);
    border-radius: 8px;
    overflow: visible !important;
    padding: 0.3rem;
    user-select: none;
    -webkit-user-select: none;
    -webkit-touch-callout: none;
    cursor: grab !important;
    width: fit-content;
    min-width: 100%;
    display: inline-block;
    box-sizing: border-box;
    position: relative;
    scroll-behavior: smooth;
    overscroll-behavior: contain;
}

.calendar-wrapper.dragging {
    cursor: grabbing !important;
}

.calendar-wrapper.dragging,
.calendar-wrapper.dragging * {
    user-select: none !important;
}

body.calendar-dragging {
    user-select: none;
    cursor: grabbing !important;
}

/* Light Theme - Make borders more visible */
body[data-theme="light"] .calendar-wrapper,
body[data-theme="light"] .calendar-nav,
body[data-theme="light"] .legend {
    border: 1px solid rgba(51, 65, 85, 0.2);
}
body[data-theme="light"] .calendar-wrapper,
body[data-theme="light"] .calendar-nav,
body[data-theme="light"] .legend {
    border: 1px solid rgba(51, 65, 85, 0.2);
}

body[data-theme="light"] .grid-header-date,
body[data-theme="light"] .grid-date-cell,
body[data-theme="light"] .grid-room-label,
body[data-theme="light"] .grid-room-type-header,
body[data-theme="light"] .grid-header-room {
    border-color: rgba(51, 65, 85, 0.15);
}

body[data-theme="light"] .grid-header-date,
body[data-theme="light"] .grid-date-cell {
    border-right-width: 1px;
    border-bottom-width: 1px;
}

body[data-theme="light"] .grid-room-label,
body[data-theme="light"] .grid-room-type-header {
    border-right-width: 1px;
    border-bottom-width: 1px;
}

.calendar-wrapper::-webkit-scrollbar {
    height: 12px;
}

.calendar-wrapper::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.calendar-wrapper::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.7), rgba(139, 92, 246, 0.7));
    border-radius: 10px;
    border: 2px solid rgba(255, 255, 255, 0.2);
}

.calendar-wrapper::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.9), rgba(139, 92, 246, 0.9));
}

.calendar-grid {
    display: grid;
    gap: 0;
    grid-template-columns: 100px repeat(<?php echo count($dates); ?>, 90px);
    width: fit-content;
    min-width: fit-content;
    max-width: none;
}

/* Header Row */
.calendar-grid-header {
    display: contents;
}

.grid-header-room {
    background: #e2e8f0; /* Match sidebar */
    border-right: 2px solid #94a3b8;
    backdrop-filter: none;
    border-bottom: 0.5px solid var(--border-color);
    padding: 0.1rem 0.08rem;
    font-weight: 800;
    text-align: center;
    position: sticky;
    left: 0;
    z-index: 40;
    font-size: 0.7rem;
    color: var(--text-primary);
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
    letter-spacing: 0.1px;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 26px;
}

/* Light theme - better header visibility */
body[data-theme="light"] .grid-header-room {
    background: #cbd5e1;
    font-weight: 900;
    border: 1px solid #94a3b8;
    color: #0f172a;
}

.grid-header-date {
    background: linear-gradient(180deg, rgba(99, 102, 241, 0.15), rgba(99, 102, 241, 0.05));
    border-right: 0.5px solid var(--border-color);
    border-bottom: 0.5px solid var(--border-color);
    padding: 0.08rem 0.04rem;
    text-align: center;
    font-weight: 600;
    font-size: 0.65rem;
    color: var(--text-primary);
    position: relative;
    min-height: 26px;
}

/* Light theme - visible borders */
body[data-theme="light"] .grid-header-date {
    border-right: 1px solid rgba(51, 65, 85, 0.15);
    border-bottom: 1px solid rgba(51, 65, 85, 0.15);
    background: transparent;
}

/* TODAY HIGHLIGHT - SIMPLE & ELEGANT */
.grid-header-date.today {
    background: rgba(99, 102, 241, 0.1) !important;
}

.grid-header-date.today .grid-header-date-num {
    color: #6366f1;
    font-weight: 900;
}

.grid-date-cell.today {
    background: rgba(99, 102, 241, 0.05) !important;
}

/* Light theme - more visible today highlight */
body[data-theme="light"] .grid-header-date.today {
    background: rgba(99, 102, 241, 0.15) !important;
}

body[data-theme="light"] .grid-date-cell.today {
    background: rgba(99, 102, 241, 0.08) !important;
}

.grid-header-date-day {
    display: block;
    font-size: 0.65rem;
    text-transform: uppercase;
    letter-spacing: 0.15px;
    margin-bottom: 0.03rem;
    color: var(--text-secondary);
    font-weight: 600;
}

.grid-header-date-num {
    display: block;
    font-size: 0.8rem;
    font-weight: 900;
    margin-bottom: 0.03rem;
    line-height: 1;
    color: var(--text-primary);
}

.grid-header-price {
    display: block;
    font-size: 0.6rem;
    color: #6366f1;
    font-weight: 800;
    margin-top: 0.1rem;
    line-height: 1;
    background: rgba(99, 102, 241, 0.1);
    padding: 0.1rem 0.2rem;
    border-radius: 3px;
}

/* Room Row */
.grid-room-label {
    background: #f1f5f9; /* Solid background for sidebar effect */
    border-right: 2px solid #cbd5e1; /* Stronger border */
    border-bottom: 1px solid #cbd5e1;
    padding: 0.08rem 0.12rem;
    font-weight: 800;
    color: var(--text-primary);
    position: sticky;
    left: 0;
    z-index: 30;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    gap: 0.02rem;
    min-width: 100px;
    cursor: grab;
    font-size: 0.85rem;
    min-height: 26px;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1); /* Shadow to separate sidebar */
    white-space: normal;
    word-break: break-word;
}

/* Light theme - better room label contrast */
body[data-theme="light"] .grid-room-label {
    background: #e2e8f0; /* Darker slate for better contrast in light mode */
    color: #1e293b;
    font-weight: 900;
    border-right: 2px solid #94a3b8;
    border-bottom: 1px solid #cbd5e1;
}

.grid-room-type-header {
    background: #f1f5f9;
    border-right: 2px solid #cbd5e1;
    border-bottom: 1px solid #cbd5e1;
    padding: 0.2rem 0.25rem;
    font-weight: 800;
    color: var(--text-primary);
    position: sticky;
    left: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    font-size: 0.75rem;
    gap: 0.2rem;
    min-width: 100px;
    min-height: 26px;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
}

/* Light theme - better type header visibility */
body[data-theme="light"] .grid-room-type-header {
    background: #e2e8f0;
    color: #1e293b;
    font-weight: 900;
    border-right: 2px solid #94a3b8;
    border-bottom: 1px solid #cbd5e1;
}

/* Ensure booking bar text stays white in light theme */
body[data-theme="light"] .booking-bar,
body[data-theme="light"] .booking-bar span,
body[data-theme="light"] .booking-bar * {
    color: #ffffff !important;
}

/* Maximum specificity - force white text in all scenarios */
.booking-bar,
.booking-bar span,
.booking-bar > span,
body .booking-bar,
body .booking-bar span,
body .booking-bar > span {
    color: #fff !important;
    -webkit-text-fill-color: #fff !important;
}

.grid-room-number {
    font-size: 0.75rem;
    color: var(--text-primary);
    font-weight: 800;
    line-height: 1;
    letter-spacing: 0.1px;
}

.grid-room-type {
    font-size: 0.75rem;
    color: var(--text-secondary);
    font-weight: 600;
    line-height: 1;
}

.grid-room-price {
    display: none;
}

/* Date Cells */
.grid-date-cell {
    border-right: 0.5px solid var(--border-color);
    border-bottom: 0.5px solid var(--border-color);
    padding: 0.05rem 0.03rem;
    min-height: 32px;
    position: relative;
    background: transparent;
    cursor: pointer;
    transition: background 0.2s ease;
}

/* Same-day turnover divider (checkout left, checkin right) */
.grid-date-cell.has-turnover::before {
    content: '';
    position: absolute;
    left: 50%;
    top: 0;
    bottom: 0;
    width: 3px;
    background: linear-gradient(180deg, #ef4444, #f97316, #ef4444);
    z-index: 5;
    box-shadow: 0 0 6px rgba(239, 68, 68, 0.6);
    transform: translateX(-50%);
}

/* Light theme - visible cell borders */
body[data-theme="light"] .grid-date-cell {
    border-right: 1px solid rgba(51, 65, 85, 0.15);
    border-bottom: 1px solid rgba(51, 65, 85, 0.15);
}

.grid-date-cell:last-child {
    border-right: none;
}

.grid-date-cell:hover {
    background: rgba(99, 102, 241, 0.05);
}

/* Booking Bars - CLOUDBED STYLE (Noon to Noon) */
.booking-bar-container {
    position: absolute;
    top: 1px;
    left: 1px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    overflow: visible;
    pointer-events: auto;
    z-index: 10;
}

.booking-bar {
    width: 100%;
    height: 24px;
    padding: 0 0.25rem;
    cursor: pointer;
    overflow: visible;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    transition: all 0.3s ease;
    box-shadow: 0 3px 6px rgba(0, 0, 0, 0.2), 0 2px 3px rgba(0, 0, 0, 0.15);
    font-weight: 700;
    font-size: 0.7rem;
    line-height: 1.2;
    position: relative;
    pointer-events: auto;
    border-radius: 4px;
    white-space: nowrap;
    transform: skewX(-20deg);
    background: linear-gradient(135deg, #06b6d4, #22d3ee) !important;
    color: #ffffff !important;
}

.booking-bar > span {
    transform: skewX(20deg);
    color: #ffffff !important;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.7);
    font-weight: 800;
    display: block;
}

.booking-bar *,
.booking-bar > * {
    color: #ffffff !important;
}

.booking-bar::before {
    content: '';
    position: absolute;
    left: -12px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-top: 12px solid transparent;
    border-bottom: 12px solid transparent;
    border-right: 8px solid;
    border-right-color: inherit;
}

.booking-bar::after {
    content: '';
    position: absolute;
    right: -12px;
    top: 50%;
    transform: translateY(-50%);
    width: 0;
    height: 0;
    border-top: 12px solid transparent;
    border-bottom: 12px solid transparent;
    border-left: 8px solid;
    border-left-color: inherit;
}

.booking-bar:hover {
    transform: skewX(-20deg) scaleY(1.15);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    z-index: 20;
}

/* Past Booking Styling - Samar-samar Abu-abu Transparan */
.booking-bar.booking-past {
    opacity: 0.4 !important;
    background: linear-gradient(135deg, #9ca3af, #d1d5db) !important;
    border-right-color: #9ca3af !important;
    border-left-color: #d1d5db !important;
}

.booking-bar.booking-past > span {
    color: #6b7280 !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1) !important;
}

.booking-bar.booking-past:hover {
    opacity: 0.6 !important;
    transform: skewX(-20deg) scaleY(1.1);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
}

/* Status specific bars */
.booking-confirmed {
    background: linear-gradient(135deg, #06b6d4, #22d3ee) !important;
    border-right-color: #06b6d4;
    border-left-color: #22d3ee;
}

.booking-pending {
    background: linear-gradient(135deg, #0ea5e9, #38bdf8) !important;
    border-right-color: #0ea5e9;
    border-left-color: #38bdf8;
}

.booking-checked-in {
    background: linear-gradient(135deg, #0284c7, #0ea5e9) !important;
    border-right-color: #0284c7;
    border-left-color: #0ea5e9;
}

.booking-bar-guest,
.booking-bar-code,
.booking-bar-status {
    color: #ffffff !important;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
    font-weight: 800;
}

/* Action buttons on booking bars */
.bar-action-btn {
    position: absolute;
    right: 4px;
    top: 50%;
    transform: skewX(20deg) translateY(-50%);
    width: 18px;
    height: 18px;
    border-radius: 50%;
    border: 1.5px solid rgba(255,255,255,0.7);
    background: rgba(255,255,255,0.2);
    color: #fff;
    font-size: 0.65rem;
    font-weight: 900;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: all 0.2s ease;
    z-index: 15;
    line-height: 1;
    padding: 0;
}

.booking-bar:hover .bar-action-btn {
    opacity: 1;
}

.bar-action-btn:hover {
    background: rgba(255,255,255,0.5);
    transform: skewX(20deg) translateY(-50%) scale(1.15);
    border-color: #fff;
}

.bar-extend-btn {
    background: rgba(16, 185, 129, 0.5);
    border-color: rgba(255,255,255,0.8);
}

.bar-edit-btn {
    background: rgba(99, 102, 241, 0.5);
    border-color: rgba(255,255,255,0.8);
}

/* Drag & Drop Styles */
.booking-bar-container[draggable="true"] {
    cursor: grab;
}

.booking-bar-container[draggable="true"]:active {
    cursor: grabbing;
}

.booking-bar-container.dragging {
    opacity: 0.4;
    z-index: 50;
}

.grid-date-cell.drag-over {
    background: rgba(99, 102, 241, 0.15) !important;
    outline: 2px dashed #6366f1;
    outline-offset: -2px;
}

.grid-date-cell.drag-over-valid {
    background: rgba(16, 185, 129, 0.15) !important;
    outline: 2px dashed #10b981;
}

.grid-date-cell.drag-over-invalid {
    background: rgba(239, 68, 68, 0.15) !important;
    outline: 2px dashed #ef4444;
}

/* Extend Stay Modal */
.extend-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.extend-modal-overlay.active {
    display: flex;
}

.extend-modal {
    background: var(--card-bg, #fff);
    border-radius: 12px;
    padding: 1.5rem;
    width: 360px;
    max-width: 90vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.extend-modal h3 {
    margin: 0 0 1rem;
    font-size: 1rem;
    color: var(--text-primary);
}

.extend-modal .form-group {
    margin-bottom: 0.75rem;
}

.extend-modal label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.extend-modal input, .extend-modal select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    background: var(--card-bg, #fff);
    color: var(--text-primary);
}

.extend-modal .modal-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    margin-top: 1rem;
}

.extend-modal .btn-cancel {
    padding: 0.45rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 0.8rem;
}

.extend-modal .btn-confirm {
    padding: 0.45rem 1rem;
    border: none;
    border-radius: 6px;
    background: #10b981;
    color: #fff;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.8rem;
}

.extend-modal .btn-confirm:hover {
    background: #059669;
}

/* Edit Reservation Modal */
.edit-res-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 10000;
    justify-content: center;
    align-items: center;
}

.edit-res-overlay.active {
    display: flex;
}

.edit-res-modal {
    background: var(--card-bg, #fff);
    border-radius: 12px;
    padding: 1.5rem;
    width: 480px;
    max-width: 95vw;
    max-height: 85vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.edit-res-modal h3 {
    margin: 0 0 1rem;
    font-size: 1rem;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.4rem;
}

.edit-res-modal .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.edit-res-modal .form-group {
    margin-bottom: 0.75rem;
}

.edit-res-modal label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.25rem;
}

.edit-res-modal input, .edit-res-modal select, .edit-res-modal textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    background: var(--card-bg, #fff);
    color: var(--text-primary);
    box-sizing: border-box;
}

.edit-res-modal textarea {
    resize: vertical;
    min-height: 60px;
}

.edit-res-modal .modal-actions {
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
    margin-top: 1rem;
}

.edit-res-modal .btn-cancel {
    padding: 0.45rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    font-size: 0.8rem;
}

.edit-res-modal .btn-save {
    padding: 0.45rem 1rem;
    border: none;
    border-radius: 6px;
    background: #6366f1;
    color: #fff;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.8rem;
}

.edit-res-modal .btn-save:hover {
    background: #4f46e5;
}

/* Legend */
.legend {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
    margin-top: 0.4rem;
    padding: 0.4rem 0.5rem;
    background: var(--card-bg);
    backdrop-filter: blur(30px);
    border: 0.5px solid var(--border-color);
    border-radius: 8px;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.legend-color {
    width: 20px;
    height: 20px;
    border-radius: 3px;
    border: 0.5px solid var(--border-color);
}

.legend-label {
    font-weight: 600;
    font-size: 0.65rem;
    color: var(--text-primary);
}

/* Responsive */
@media (max-width: 768px) {
    .calendar-container {
        padding: 0.75rem 0.25rem;
    }

    .calendar-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .calendar-header h1 {
        font-size: 1.75rem;
    }

    .grid-header-date {
        padding: 0.55rem 0.25rem;
        font-size: 0.72rem;
    }

    .grid-header-date-num {
        font-size: 1rem;
    }

    .grid-room-label {
        padding: 0.6rem 0.35rem;
        min-width: 80px;
    }

    .grid-date-cell {
        min-height: 85px;
    }

    .booking-bar {
        height: 80px;
        font-size: 0.65rem;
    }

    .booking-bar-guest {
        font-size: 0.7rem;
    }

    .calendar-nav {
        flex-direction: column;
        gap: 1rem;
    }

    .form-row-3 {
        grid-template-columns: 1fr 1fr;
    }

    .form-row-3 .form-group:last-child {
        grid-column: 1 / -1;
    }
}

@media (max-width: 480px) {
    .calendar-wrapper {
        padding: 0.5rem;
    }

    .grid-room-label {
        padding: 0.5rem;
        font-size: 0.72rem;
        min-width: 60px;
    }

    .grid-date-cell {
        min-height: 75px;
        padding: 0.25rem;
    }

    .booking-bar {
        height: 70px;
        font-size: 0.6rem;
        padding: 0.2rem;
        color: #ffffff !important;
    }

    .form-row-3 {
        grid-template-columns: 1fr;
    }

    .form-row-3 .form-group:last-child {
        grid-column: 1;
    }

    .booking-bar-guest {
        font-size: 0.65rem;
    }

    .grid-header-date-num {
        font-size: 0.9rem;
    }

    .legend {
        flex-direction: column;
        gap: 1rem;
    }
}

/* ============================================
   MODAL POPUP STYLES
   ============================================ */
.modal-overlay {
    display: none !important;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(5px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.modal-overlay.active {
    display: flex !important;
}

.modal-content {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
    animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    z-index: 10000;
}

body[data-theme="light"] .modal-content {
    background: white;
    border: 1px solid rgba(51, 65, 85, 0.15);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    margin-bottom: 1rem;
    text-align: center;
}

.modal-header h2 {
    color: var(--text-primary);
    font-size: 1.25rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.modal-header p {
    color: var(--text-secondary);
    font-size: 0.8rem;
}

.modal-close {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    background: rgba(239, 68, 68, 0.15);
    border: 2px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.5rem;
    font-weight: 700;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    z-index: 1000;
}

.modal-close:hover {
    background: rgba(239, 68, 68, 0.25);
    border-color: rgba(239, 68, 68, 0.5);
    color: #dc2626;
    transform: rotate(90deg) scale(1.1);
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.modal-actions {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.modal-btn {
    padding: 1rem;
    border: none;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    font-size: 1rem;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.modal-btn-primary {
    background: linear-gradient(135deg, #10b981, #34d399);
    color: white;
}

.modal-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(16, 185, 129, 0.3);
}

.modal-btn-secondary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.modal-btn-secondary:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px rgba(99, 102, 241, 0.3);
}

.modal-date-info {
    background: rgba(99, 102, 241, 0.15);
    border: 1px solid rgba(99, 102, 241, 0.3);
    border-radius: 10px;
    padding: 1rem;
    margin-bottom: 1rem;
    text-align: center;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

/* RESERVATION FORM STYLES */
.modal-content-large {
    max-width: 650px;
    height: 90vh; /* Fixed height for flex container */
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden; /* Header/Footer static, body triggers scroll */
}

#reservationForm {
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    overflow: hidden;
}

.modal-content-medium {
    max-width: 500px;
    max-height: 80vh;
    overflow-y: auto;
}

/* Booking Details Modal Styles */
.booking-details-content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    margin: 1rem 0;
}

.detail-section {
    background: rgba(99, 102, 241, 0.05);
    border: 1px solid rgba(99, 102, 241, 0.15);
    border-radius: 8px;
    padding: 0.75rem;
}

body[data-theme="light"] .detail-section {
    background: rgba(248, 250, 252, 0.8);
    border: 1px solid rgba(51, 65, 85, 0.15);
}

.detail-section h3 {
    color: var(--text-primary);
    font-size: 0.8rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.4rem;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.3rem 0;
}

.detail-label {
    color: var(--text-secondary);
    font-size: 0.75rem;
    font-weight: 600;
}

.detail-value {
    color: var(--text-primary);
    font-size: 0.8rem;
    font-weight: 700;
    text-align: right;
}

.status-badge {
    padding: 0.25rem 0.6rem;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-badge.status-confirmed {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.status-badge.status-pending {
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
}

.status-badge.status-checked_in {
    background: rgba(59, 130, 246, 0.2);
    color: #3b82f6;
}

.status-badge.status-paid {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.status-badge.status-unpaid {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.status-badge.status-partial {
    background: rgba(245, 158, 11, 0.2);
    color: #f59e0b;
}

/* Booking Action Buttons */
.booking-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

.btn-action {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.85rem 1rem;
    border: none;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn-checkin {
    background: linear-gradient(135deg, #10b981, #34d399);
    color: white;
}

.btn-checkin:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
}

.btn-checkout {
    background: linear-gradient(135deg, #3b82f6, #60a5fa);
    color: white;
}

.btn-checkout:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
}

.btn-move {
    background: linear-gradient(135deg, #f59e0b, #fbbf24);
    color: white;
}

.btn-move:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
}

.form-grid {
    display: grid;
    gap: 1rem;
}

.form-section {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 0.85rem;
}

body[data-theme="light"] .form-section {
    background: rgba(248, 250, 252, 0.5);
    border: 1px solid rgba(51, 65, 85, 0.15);
}

.form-section h3 {
    color: var(--text-primary);
    font-size: 0.8rem;
    font-weight: 700;
    margin-bottom: 0.65rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
}

.form-row-3 {
    grid-template-columns: repeat(3, 1fr);
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.form-group label {
    color: var(--text-secondary);
    font-size: 0.75rem;
    font-weight: 600;
}

.form-group input,
.form-group select,
.form-group textarea {
    background: var(--sidebar-bg);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.6rem 0.75rem;
    color: var(--text-primary);
    font-size: 0.85rem;
    transition: all 0.2s ease;
}

.readonly-input {
    background: rgba(99, 102, 241, 0.1) !important;
    cursor: not-allowed;
    font-weight: 600;
    color: #6366f1 !important;
}

body[data-theme="light"] .readonly-input {
    background: rgba(99, 102, 241, 0.08) !important;
}

body[data-theme="light"] .form-group input,
body[data-theme="light"] .form-group select,
body[data-theme="light"] .form-group textarea {
    background: white;
    border: 1px solid rgba(51, 65, 85, 0.2);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.15);
}

.form-group textarea {
    resize: vertical;
    font-family: inherit;
    min-height: 70px;
}

.payment-method-group,
.dp-percent-group {
    display: flex;
    gap: 0.4rem;
    flex-wrap: wrap;
}

.payment-method-btn,
.dp-percent-btn {
    border: 1px solid var(--border-color);
    background: var(--sidebar-bg);
    color: var(--text-primary);
    padding: 0.35rem 0.6rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.payment-method-btn:hover,
.dp-percent-btn:hover {
    border-color: #6366f1;
    color: #6366f1;
}

.payment-method-btn.active,
.dp-percent-btn.active {
    background: #6366f1;
    color: white;
    border-color: #6366f1;
}

.price-summary {
    background: var(--sidebar-bg);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    padding: 0.75rem;
    margin-top: 0.5rem;
}

body[data-theme="light"] .price-summary {
    background: rgba(16, 185, 129, 0.05);
    border: 1px solid rgba(16, 185, 129, 0.2);
}

.price-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.4rem 0;
    color: var(--text-secondary);
    font-size: 0.8rem;
}

.price-row strong {
    color: var(--text-primary);
    font-size: 0.85rem;
    font-weight: 600;
}

.price-row-total {
    border-top: 1px solid var(--border-color);
    margin-top: 0.4rem;
    padding-top: 0.6rem;
    font-size: 0.9rem;
    font-weight: 700;
}

body[data-theme="light"] .price-row-total {
    border-top-color: rgba(16, 185, 129, 0.3);
}

.price-row-total strong {
    color: #10b981;
    font-size: 1rem;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

.btn-primary,
.btn-secondary {
    padding: 0.6rem 1.25rem;
    border: none;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s ease;
}

.btn-primary {
    background: linear-gradient(135deg, #10b981, #34d399);
    color: white !important;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-secondary {
    background: var(--sidebar-bg);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

body[data-theme="light"] .btn-secondary {
    background: white;
    border: 1px solid rgba(51, 65, 85, 0.2);
}

.btn-secondary:hover {
    background: rgba(99, 102, 241, 0.1);
    border-color: #6366f1;
}

.modal-date-info strong {
    color: var(--text-primary);
    font-size: 1.1rem;
    display: block;
    margin-top: 0.5rem;
}

/* Dashboard Stats Grid - Elegant Modern Design */
.stats-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1rem;
}

.stats-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(249, 250, 251, 0.9));
    border: 1px solid rgba(99, 102, 241, 0.15);
    border-radius: 10px;
    padding: 1rem;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(99, 102, 241, 0.15);
}

body[data-theme="light"] .stats-card {
    background: linear-gradient(135deg, rgba(255, 255, 255, 0.95), rgba(249, 250, 251, 0.9));
    border-color: rgba(99, 102, 241, 0.15);
}

body[data-theme="dark"] .stats-card {
    background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.9));
    border-color: rgba(99, 102, 241, 0.2);
}

.stats-card h3 {
    font-size: 0.75rem;
    font-weight: 700;
    margin: 0 0 0.75rem 0;
    color: #6366f1;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid rgba(99, 102, 241, 0.15);
}

.stats-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.stats-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.6rem 0;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05);
}

body[data-theme="dark"] .stats-list li {
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.stats-list li:last-child {
    border-bottom: none;
}

.stat-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex: 1;
}

.stat-name {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.85rem;
}

.stat-meta {
    font-size: 0.7rem;
    color: #64748b;
}

.stat-tag {
    font-size: 0.7rem;
    padding: 3px 8px;
    border-radius: 4px;
    font-weight: 600;
    color: white;
    white-space: nowrap;
}

@media (max-width: 1024px) {
    .stats-dashboard-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="calendar-container">
    <!-- Header -->
    <div class="calendar-header">
        <div>
            <h1><span class="icon">📆</span> Calendar Booking</h1>
        </div>
        <div class="calendar-controls">
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/reservasi.php" class="btn-nav">
                📋 List View
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/breakfast.php" class="btn-nav">
                🍽️ Breakfast List
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/settings.php" class="btn-nav">
                ⚙️ Settings
            </a>
            <a href="<?php echo BASE_URL; ?>/modules/frontdesk/dashboard.php" class="btn-nav">
                📊 Dashboard
            </a>
        </div>
    </div>

    <?php
    // DASHBOARD STATS FETCH
    try {
        // 1. RECENT RESERVATIONS (Newest ID)
        $recentBookings = $db->fetchAll("
            SELECT b.booking_code, b.status, b.check_in_date, g.guest_name 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            ORDER BY b.id DESC LIMIT 5
        ");

        // 2. RECENT CHECK-INS
        $recentCheckins = $db->fetchAll("
            SELECT b.booking_code, b.status, b.room_id, b.check_in_date, g.guest_name, r.room_number 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_in'
            ORDER BY b.check_in_date DESC, b.id DESC LIMIT 5
        ");

        // 3. RECENT CHECK-OUTS
        $recentCheckouts = $db->fetchAll("
            SELECT b.booking_code, b.status, b.room_id, b.check_out_date, g.guest_name, r.room_number 
            FROM bookings b
            LEFT JOIN guests g ON b.guest_id = g.id
            LEFT JOIN rooms r ON b.room_id = r.id
            WHERE b.status = 'checked_out'
            ORDER BY b.check_out_date DESC, b.id DESC LIMIT 5
        ");
    } catch (Exception $e) {
        $recentBookings = [];
        $recentCheckins = [];
        $recentCheckouts = [];
    }
    ?>



    <!-- Navigation -->
    <div class="calendar-nav">
        <button class="nav-btn" id="prevMonthBtn" type="button">← Previous 30 Days</button>
        <input type="date" class="nav-date-input" id="dateInput" value="<?php echo $startDate; ?>" onchange="changeDate()">
        <button class="nav-btn" id="nextMonthBtn" type="button">Next 30 Days →</button>
        <span class="date-display">
            <?php echo date('M d', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($startDate . ' +29 days')); ?>
        </span>
        <button class="nav-btn" id="newReservationBtn" type="button" onclick="openNewReservationForm()" style="background: linear-gradient(135deg, #10b981, #34d399); margin-left: auto;">
            ➕ New Reservation
        </button>
    </div>

    <!-- Calendar Grid - WRAPPED IN SCROLL CONTAINER -->
    <div class="calendar-scroll-wrapper" id="drag-container" style="overflow-x: auto; cursor: grab; user-select: none;">
        <div class="calendar-wrapper">
            <div class="calendar-grid">
            <!-- Header Row -->
            <div class="calendar-grid-header">
                <div class="grid-header-room">ROOMS</div>
                <?php foreach ($dates as $date): ?>
                <div class="grid-header-date<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?>">
                    <span class="grid-header-date-day">
                        <?php echo date('D', strtotime($date)); ?>
                    </span>
                    <span class="grid-header-date-num">
                        <?php echo date('d', strtotime($date)); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Room Rows -->
            <?php 
            // Group rooms by type
            $roomsByType = [];
            foreach ($rooms as $room) {
                $typeKey = $room['type_name'];
                if (!isset($roomsByType[$typeKey])) {
                    $roomsByType[$typeKey] = [];
                }
                $roomsByType[$typeKey][] = $room;
            }
            
            // Display rooms grouped by type with type headers
            foreach ($roomsByType as $typeName => $typeRooms): 
                // Get base price from first room of this type
                $typePrice = $typeRooms[0]['base_price'] ?? 0;
            ?>
                <!-- Type Header Row -->
                <div class="grid-room-type-header">
                    📂 <?php echo htmlspecialchars($typeName); ?>
                </div>
                <?php foreach ($dates as $date): ?>
                <div style="background: rgba(99, 102, 241, 0.08); border-right: 2px solid rgba(99, 102, 241, 0.4); border-bottom: 1px solid rgba(255, 255, 255, 0.1); min-height: 30px; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 800; color: rgba(99, 102, 241, 0.9);">
                    Rp<?php echo number_format($typePrice / 1000, 0, ',', '.'); ?>K
                </div>
                <?php endforeach; ?>
                
                <!-- Individual Rooms of This Type -->
                <?php foreach ($typeRooms as $room): ?>
                <div class="grid-room-label">
                    <span class="grid-room-number"><?php echo htmlspecialchars($room['room_number']); ?></span>
                </div>

                <?php foreach ($dates as $date): ?>
                <?php
                // Check for same-day turnover (checkout + checkin on same day)
                $hasTurnover = false;
                if (isset($bookingMatrix[$room['id']])) {
                    $checkouts = 0;
                    $checkins = 0;
                    foreach ($bookingMatrix[$room['id']] as $booking) {
                        $checkinDate = date('Y-m-d', strtotime($booking['check_in_date']));
                        $checkoutDate = date('Y-m-d', strtotime($booking['check_out_date']));
                        if ($checkoutDate === $date) $checkouts++;
                        if ($checkinDate === $date) $checkins++;
                    }
                    $hasTurnover = ($checkouts > 0 && $checkins > 0);
                }
                ?>
                <div class="grid-date-cell<?php echo ($date === date('Y-m-d')) ? ' today' : ''; ?><?php echo $hasTurnover ? ' has-turnover' : ''; ?>" 
                     data-date="<?php echo $date; ?>"
                     data-room-number="<?php echo htmlspecialchars($room['room_number']); ?>"
                     data-room-id="<?php echo $room['id']; ?>"
                     title="<?php echo htmlspecialchars($room['room_number']); ?> - <?php echo date('d M Y', strtotime($date)); ?><?php echo $hasTurnover ? ' (Turnover: CO + CI)' : ''; ?>"
                     onclick="openCellReservation(this)">
                    <?php
                    // Find bookings for this room and date - CLOUDBED STYLE (bar from noon to noon)
                    if (isset($bookingMatrix[$room['id']])) {
                        foreach ($bookingMatrix[$room['id']] as $booking) {
                            $checkinDate = strtotime($booking['check_in_date']);
                            $checkoutDate = strtotime($booking['check_out_date']);
                            $currentDate = strtotime($date);
                            
                            // Only render bar on check-in date
                            if ($currentDate === $checkinDate) {
                                // Calculate nights (days between check-in and check-out)
                                $totalNights = ceil(($checkoutDate - $checkinDate) / 86400);
                                
                                // Calculate width: start from 50% of check-in cell, end at 50% of check-out cell
                                // Width = (nights × 90px) = full span from noon to noon (Matches CSS Grid Column Width)
                                $barWidth = ($totalNights * 90); // 90px per day
                                
                                $statusClass = 'booking-' . str_replace('_', '-', $booking['status']);
                                
                                // Check if booking is past or checked out
                                $today = strtotime(date('Y-m-d'));
                                $isPastBooking = ($checkoutDate < $today);
                                $isCheckedOut = ($booking['status'] === 'checked_out');
                                
                                if ($isPastBooking || $isCheckedOut) {
                                    $statusClass .= ' booking-past';
                                }
                                
                                // Determine color based on status
                                $isCheckedIn = ($booking['status'] === 'checked_in');
                                if ($isCheckedOut || $isPastBooking) {
                                    $bookingColor = $checkedOutColor;
                                } elseif ($isCheckedIn) {
                                    $bookingColor = $checkedInColor;
                                } else {
                                    $bookingColor = $defaultColor;
                                }
                                
                                // Add status icons
                                $statusIcon = $isCheckedIn ? '✓ ' : ($isCheckedOut ? '📭 ' : '');
                                
                                $guestName = htmlspecialchars(substr($booking['guest_name'] ?? 'Guest', 0, 12));
                                $bookingCode = htmlspecialchars($booking['booking_code']);
                                $shortCode = substr($bookingCode, 0, 8); // Show first 8 chars
                                $statusText = ucfirst(str_replace('_', ' ', $booking['status']));
                                ?>
                                <div class="booking-bar-container" style="left: 50%; width: <?php echo $barWidth; ?>px;"
                                     data-booking-id="<?php echo $booking['id']; ?>"
                                     data-room-id="<?php echo $booking['room_id']; ?>"
                                     data-check-in="<?php echo $booking['check_in_date']; ?>"
                                     data-check-out="<?php echo $booking['check_out_date']; ?>"
                                     data-status="<?php echo $booking['status']; ?>"
                                     data-nights="<?php echo $totalNights; ?>"
                                     data-guest="<?php echo $guestName; ?>"
                                     <?php if (!$isPastBooking && !$isCheckedOut): ?>draggable="true"<?php endif; ?>>
                                    <div class="booking-bar <?php echo $statusClass; ?>" 
                                         style="background: linear-gradient(135deg, <?php echo $bookingColor['bg']; ?>, <?php echo $bookingColor['bg']; ?>dd) !important; border-right-color: <?php echo $bookingColor['bg']; ?>; border-left-color: <?php echo $bookingColor['bg']; ?>dd;"
                                         onclick="event.stopPropagation(); viewBooking(<?php echo $booking['id']; ?>, event);"
                                         title="<?php echo $statusIcon . $guestName; ?> (<?php echo $bookingCode; ?>) - <?php echo $statusText; ?><?php echo $isPastBooking ? ' [PAST]' : ''; ?>">
                                        <span><?php echo $statusIcon . $guestName; ?> • <?php echo $shortCode; ?></span>
                                        <?php if ($isCheckedIn && !$isPastBooking): ?>
                                        <button class="bar-action-btn bar-extend-btn" onclick="event.stopPropagation(); openExtendModal(<?php echo $booking['id']; ?>, '<?php echo $guestName; ?>', '<?php echo $booking['check_out_date']; ?>', <?php echo $totalNights; ?>)" title="Extend Stay">+</button>
                                        <?php elseif (!$isCheckedIn && !$isCheckedOut && !$isPastBooking): ?>
                                        <button class="bar-action-btn bar-edit-btn" onclick="event.stopPropagation(); openEditReservationModal(<?php echo $booking['id']; ?>)" title="Edit Reservasi">✎</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php
                                break; // Only one bar per booking
                            }
                        }
                    }
                    ?>
                </div>
                <?php endforeach; // End dates loop for each room ?>
                <?php endforeach; // End individual rooms loop ?>
            <?php endforeach; // End room types loop
            ?>
        </div>
    </div>
    </div>

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #3b82f6, #60a5fa);"></div>
            <span class="legend-label">📋 Booking (Confirmed/Pending)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #10b981, #34d399);"></div>
            <span class="legend-label">✓ Checked In (Active)</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: linear-gradient(135deg, #9ca3af, #d1d5db); opacity: 0.4;"></div>
            <span class="legend-label">📭 Past Booking (History)</span>
        </div>
    </div>

    <!-- DASHBOARD STATS WIDGETS -->
    <div class="stats-dashboard-grid" style="margin-top: 1.5rem;">
        <!-- New Reservations -->
        <div class="stats-card">
            <h3>Reservasi Terbaru</h3>
            <ul class="stats-list">
                <?php if (empty($recentBookings)): ?>
                    <li style="justify-content:center; color:#94a3b8;">Belum ada data</li>
                <?php else: ?>
                    <?php 
                    $displayBookings = array_slice($recentBookings, 0, 5); // Limit 5 items
                    foreach($displayBookings as $rb): 
                        $bName = $rb['guest_name'] ?? 'Guest';
                        $bStats = str_replace('_', ' ', $rb['status']);
                        $bColor = $rb['status'] == 'confirmed' ? '#3b82f6' : ($rb['status'] == 'pending' ? '#f59e0b' : '#10b981');
                        if($rb['status'] == 'checked_out') $bColor = '#ef4444';
                        if($rb['status'] == 'cancelled') $bColor = '#94a3b8';
                    ?>
                    <li>
                        <div class="stat-info">
                            <span class="stat-name"><?php echo htmlspecialchars(substr($bName, 0, 18)); ?></span>
                            <span class="stat-meta"><?php echo htmlspecialchars($rb['booking_code']); ?></span>
                        </div>
                        <span class="stat-tag" style="background:<?php echo $bColor; ?>"><?php echo ucfirst($bStats); ?></span>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Latest Check-ins -->
        <div class="stats-card">
            <h3>Check-in Terbaru</h3>
            <ul class="stats-list">
                <?php if (empty($recentCheckins)): ?>
                    <li style="justify-content:center; color:#94a3b8;">Belum ada data</li>
                <?php else: ?>
                    <?php 
                    $displayCheckins = array_slice($recentCheckins, 0, 5); // Limit 5 items
                    foreach($displayCheckins as $rc): ?>
                    <li>
                        <div class="stat-info">
                            <span class="stat-name"><?php echo htmlspecialchars(substr($rc['guest_name'] ?? '', 0, 18)); ?></span>
                            <span class="stat-meta">Room <?php echo $rc['room_number']; ?> • <?php echo date('d M', strtotime($rc['check_in_date'])); ?></span>
                        </div>
                        <span class="stat-tag" style="background:#10b981">Active</span>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Latest Check-outs -->
        <div class="stats-card">
            <h3>Checkout Terbaru</h3>
            <ul class="stats-list">
                <?php if (empty($recentCheckouts)): ?>
                    <li style="justify-content:center; color:#94a3b8;">Belum ada data</li>
                <?php else: ?>
                    <?php 
                    $displayCheckouts = array_slice($recentCheckouts, 0, 5); // Limit 5 items
                    foreach($displayCheckouts as $rco): ?>
                    <li>
                        <div class="stat-info">
                            <span class="stat-name"><?php echo htmlspecialchars(substr($rco['guest_name'] ?? '', 0, 18)); ?></span>
                            <span class="stat-meta">Room <?php echo $rco['room_number']; ?> • <?php echo date('d M', strtotime($rco['check_out_date'])); ?></span>
                        </div>
                        <span class="stat-tag" style="background:#ef4444">Done</span>
                    </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>

</div>

<script>
// Initialize OTA Fees from PHP - Create global variable (not just window property)
var OTA_FEES = <?php echo json_encode($otaFees); ?>;

// Global variables for reservation form (used across multiple functions)
var currentSource = '';
var currentFees = OTA_FEES;

window.viewBooking = function viewBooking(id, event) {
    event.preventDefault();
    event.stopPropagation();
    
    console.log('📋 Loading booking details:', id);
    
    // Fetch booking details via AJAX - use relative path from modules/frontdesk/
    fetch('../../api/get-booking-details.php?id=' + id)
        .then(response => {
            console.log('📡 API Response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('📥 API Response text:', text);
            try {
                const data = JSON.parse(text);
                console.log('✅ Parsed JSON:', data);
                if (data.success) {
                    console.log('🎯 Showing booking:', data.booking);
                    showBookingQuickView(data.booking);
                } else {
                    console.error('❌ API Error:', data.message);
                    alert('Error: ' + data.message);
                }
            } catch (e) {
                console.error('❌ JSON Parse Error:', e);
                console.error('Raw text:', text);
                alert('Failed to parse response');
            }
        })
        .catch(error => {
            console.error('❌ Fetch Error:', error);
            alert('Failed to load booking details: ' + error.message);
        });
}

let currentPaymentBooking = null;

// Quick view popup - simple and elegant
function showBookingQuickView(booking) {
    currentPaymentBooking = booking;
    console.log('🎯 showBookingQuickView called with:', booking);
    
    const modal = document.getElementById('bookingQuickView');
    console.log('📦 Modal element found:', modal);
    
    if (!modal) {
        console.error('❌ Modal not found!');
        alert('Error: Modal element not found');
        return;
    }
    
    // Format data
    const checkIn = new Date(booking.check_in_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    const checkOut = new Date(booking.check_out_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    const totalPrice = new Intl.NumberFormat('id-ID').format(booking.final_price);
    const paidAmount = new Intl.NumberFormat('id-ID').format(booking.paid_amount);
    const remaining = new Intl.NumberFormat('id-ID').format(booking.final_price - booking.paid_amount);
    
    // Payment status badge color
    let paymentBadge = '';
    if (booking.payment_status === 'paid') {
        paymentBadge = '<span style="background: #10b981; color: white; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">LUNAS</span>';
    } else if (booking.payment_status === 'partial') {
        paymentBadge = '<span style="background: #f59e0b; color: white; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">CICILAN</span>';
    } else {
        paymentBadge = '<span style="background: #ef4444; color: white; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">BELUM BAYAR</span>';
    }
    
    // Booking source
    const sourceMap = {
        'walk_in': 'Walk-in',
        'phone': 'Phone',
        'online': 'Online',
        'ota': 'OTA',
        'agoda': 'Agoda',
        'booking': 'Booking.com',
        'tiket': 'Tiket.com',
        'traveloka': 'Traveloka',
        'airbnb': 'Airbnb'
    };
    // Capitalize source if not in map
    let displaySource = sourceMap[booking.booking_source];
    if (!displaySource) {
        displaySource = booking.booking_source.charAt(0).toUpperCase() + booking.booking_source.slice(1);
    }
    const source = displaySource;
    
    // Override payment status badge for OTA if necessary or make it more detailed
    // Check if it's an OTA booking to show specific status
    if (['ota', 'agoda', 'booking', 'tiket', 'traveloka', 'airbnb'].includes(booking.booking_source)) {
         if (booking.payment_status === 'paid') {
             paymentBadge = '<span style="background: #10b981; color: white; padding: 0.25rem 0.6rem; border-radius: 4px; font-size: 0.7rem; font-weight: 700;">LUNAS (By ' + source + ')</span>';
         }
    }

    // Action Buttons
    let actionButtons = '';
    
    // Check-in / Check-out Logic
    if (booking.status === 'checked_in') {
        actionButtons += '<button type="button" class="qv-btn" onclick="quickViewCheckOut()" style="background:#ef4444; color:white; border:none;">Check-out</button>';
    } else if (booking.status === 'confirmed' || booking.status === 'pending') {
        actionButtons += '<button type="button" class="qv-btn qv-checkin-btn" onclick="quickViewCheckIn()">Check-in</button>';
    }
    
    // Move Room (Always available unless checked out)
    if (booking.status !== 'checked_out') {
        actionButtons += '<button type="button" class="qv-btn qv-move-btn" onclick="quickViewMoveRoom()">Move</button>';
    }
    
    // Payment (Always available if not paid)
    if (booking.payment_status !== 'paid') {
        actionButtons += '<button type="button" class="qv-btn qv-pay-btn" onclick="openBookingPaymentModal()">Pay</button>';
    }

    // Populate modal
    document.getElementById('qv-content').innerHTML = `
        <div style="text-align: center; padding-bottom: 0.75rem; border-bottom: 2px solid rgba(99, 102, 241, 0.2);">
            <div style="font-size: 0.7rem; color: var(--text-secondary); margin-bottom: 0.25rem;">BOOKING CODE</div>
            <div style="font-size: 1.1rem; font-weight: 800; color: #6366f1; font-family: 'Courier New', monospace;">${booking.booking_code}</div>
            <div style="font-size: 0.7rem; font-weight: 700; background:${booking.status === 'checked_in' ? '#10b981' : '#6366f1'}; color:white; display:inline-block; padding:2px 8px; border-radius:4px; margin-top:4px;">${booking.status.toUpperCase().replace('_',' ')}</div>
        </div>
        
        <div style="padding: 0.75rem 0;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <span style="font-size: 1.25rem;">👤</span>
                <div style="flex: 1;">
                    <div style="font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.3px;">Tamu</div>
                    <div style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary);">${booking.guest_name}</div>
                </div>
            </div>
            
            ${booking.guest_phone ? `
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <span style="font-size: 1.25rem;">📞</span>
                <div style="flex: 1;">
                    <div style="font-size: 0.65rem; color: var(--text-secondary);">Phone</div>
                    <div style="font-size: 0.85rem; color: var(--text-primary);">${booking.guest_phone}</div>
                </div>
            </div>
            ` : ''}
            
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <span style="font-size: 1.25rem;">🏠</span>
                <div style="flex: 1;">
                    <div style="font-size: 0.65rem; color: var(--text-secondary);">Room</div>
                    <div style="font-size: 0.85rem; color: var(--text-primary); font-weight: 600;">Room ${booking.room_number} - ${booking.room_type}</div>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                <span style="font-size: 1.25rem;">📅</span>
                <div style="flex: 1;">
                    <div style="font-size: 0.65rem; color: var(--text-secondary);">Check-in / Check-out</div>
                    <div style="font-size: 0.8rem; color: var(--text-primary);">${checkIn} → ${checkOut}</div>
                    <div style="font-size: 0.7rem; color: var(--text-secondary);">${booking.total_nights} malam • ${source}</div>
                </div>
            </div>
        </div>
        
        <div style="background: rgba(99, 102, 241, 0.05); border-radius: 8px; padding: 0.75rem; margin-top: 0.75rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <div style="font-size: 0.7rem; color: var(--text-secondary); font-weight: 600;">STATUS PEMBAYARAN</div>
                ${paymentBadge}
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                <span style="font-size: 0.75rem; color: var(--text-secondary);">Total Harga:</span>
                <span style="font-size: 0.85rem; font-weight: 700; color: var(--text-primary);">Rp ${totalPrice}</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;">
                <span style="font-size: 0.75rem; color: var(--text-secondary);">Sudah Bayar:</span>
                <span style="font-size: 0.85rem; font-weight: 700; color: #10b981;">Rp ${paidAmount}</span>
            </div>
            
            ${booking.payment_status !== 'paid' ? `
            <div style="display: flex; justify-content: space-between; padding-top: 0.35rem; border-top: 1px dashed rgba(99, 102, 241, 0.3);">
                <span style="font-size: 0.75rem; color: var(--text-secondary);">Sisa:</span>
                <span style="font-size: 0.9rem; font-weight: 800; color: #ef4444;">Rp ${remaining}</span>
            </div>
            ` : ''}
        </div>

        <div class="qv-actions">
            ${actionButtons}
        </div>
    `;
    
    console.log('✅ Content populated');
    
    // Add active class to trigger CSS display: flex !important
    modal.classList.add('active');
    console.log('✅ Active class added - modal should be visible now');
    
    // Log for debugging
    console.log({
        modalId: modal.id,
        hasActiveClass: modal.classList.contains('active'),
        computedDisplay: window.getComputedStyle(modal).display,
        zIndex: window.getComputedStyle(modal).zIndex
    });
}

window.closeBookingQuickView = function closeBookingQuickView() {
    const modal = document.getElementById('bookingQuickView');
    modal.classList.remove('active');
    modal.style.display = '';
    modal.style.position = '';
    modal.style.zIndex = '';
}

window.showBookingDetailsModal = function showBookingDetailsModal(booking) {
    const modal = document.getElementById('bookingDetailsModal');
    currentPaymentBooking = booking;
    
    // Populate modal with booking data
    document.getElementById('detailGuestName').textContent = booking.guest_name;
    document.getElementById('detailGuestPhone').textContent = booking.guest_phone || '-';
    document.getElementById('detailGuestEmail').textContent = booking.guest_email || '-';
    document.getElementById('detailRoomNumber').textContent = booking.room_number;
    document.getElementById('detailRoomType').textContent = booking.room_type;
    document.getElementById('detailCheckIn').textContent = formatDateFull(booking.check_in_date);
    document.getElementById('detailCheckOut').textContent = formatDateFull(booking.check_out_date);
    document.getElementById('detailNights').textContent = booking.total_nights + ' night(s)';
    document.getElementById('detailBookingCode').textContent = booking.booking_code;
    document.getElementById('detailPaymentStatus').textContent = booking.payment_status.toUpperCase();
    document.getElementById('detailPaymentStatus').className = 'status-badge status-' + booking.payment_status;
    document.getElementById('detailBookingStatus').textContent = booking.status.toUpperCase().replace('_', ' ');
    document.getElementById('detailBookingStatus').className = 'status-badge status-' + booking.status;
    document.getElementById('detailTotalPrice').textContent = 'Rp ' + formatNumberIDR(booking.final_price);
    
    // Set booking ID for action buttons
    modal.dataset.bookingId = booking.id;
    modal.dataset.bookingStatus = booking.status;
    modal.dataset.paymentStatus = booking.payment_status;
    
    // Show/hide action buttons based on status
    updateActionButtons(booking.status, booking.payment_status);
    
    modal.classList.add('active');
}

function closeBookingDetailsModal() {
    document.getElementById('bookingDetailsModal').classList.remove('active');
}

function formatDateFull(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('id-ID', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

function formatNumberIDR(num) {
    return new Intl.NumberFormat('id-ID').format(num);
}

function updateActionButtons(status, paymentStatus) {
    const checkInBtn = document.getElementById('btnCheckIn');
    const checkOutBtn = document.getElementById('btnCheckOut');
    const moveBtn = document.getElementById('btnMove');
    const payBtn = document.getElementById('btnPay');
    
    // Show/hide buttons based on status
    if (status === 'confirmed' || status === 'pending') {
        checkInBtn.style.display = 'flex';
        checkOutBtn.style.display = 'none';
        moveBtn.style.display = 'flex';
    } else if (status === 'checked_in') {
        checkInBtn.style.display = 'none';
        checkOutBtn.style.display = 'flex';
        moveBtn.style.display = 'flex';
    } else {
        checkInBtn.style.display = 'none';
        checkOutBtn.style.display = 'none';
        moveBtn.style.display = 'none';
    }
    
    // Show Pay button if unpaid or partial
    if (paymentStatus === 'unpaid' || paymentStatus === 'partial') {
        payBtn.style.display = 'flex';
    } else {
        payBtn.style.display = 'none';
    }
}

function doCheckIn() {
    const modal = document.getElementById('bookingDetailsModal');
    const bookingId = modal.dataset.bookingId;
    const guestName = document.getElementById('detailGuestName').textContent;
    const roomNumber = document.getElementById('detailRoomNumber').textContent;
    const paymentStatus = modal.dataset.paymentStatus;

    if (paymentStatus !== 'paid') {
        const proceed = confirm('Pembayaran belum lunas. Lanjut check-in dan buat invoice sisa?');
        if (!proceed) {
            openBookingPaymentModal();
            return;
        }
    }
    
    if (confirm(`Check-in ${guestName} ke Room ${roomNumber} sekarang?`)) {
        // Show loading state
        const checkInBtn = document.getElementById('btnCheckIn');
        const originalText = checkInBtn.innerHTML;
        checkInBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
        checkInBtn.disabled = true;
        
        // Call check-in API
        const createInvoice = paymentStatus !== 'paid' ? 1 : 0;
        fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'include', // Important: Send session cookies
            body: 'booking_id=' + bookingId + '&create_invoice=' + createInvoice
        })
        .then(response => {
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server mengembalikan response non-JSON');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                if (data.invoice_number) {
                    alert('✅ ' + data.message + '\nInvoice: ' + data.invoice_number);
                } else {
                    alert('✅ ' + data.message);
                }
                // Reload page to reflect changes
                window.location.reload();
            } else {
                alert('❌ Error: ' + data.message);
                checkInBtn.innerHTML = originalText;
                checkInBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ Terjadi kesalahan sistem: ' + error.message);
            checkInBtn.innerHTML = originalText;
            checkInBtn.disabled = false;
        });
    }
}

function doCheckOut() {
    const modal = document.getElementById('bookingDetailsModal');
    const bookingId = modal.dataset.bookingId;
    const guestName = document.getElementById('detailGuestName').textContent;
    const roomNumber = document.getElementById('detailRoomNumber').textContent;
    
    if (confirm(`Check-out ${guestName} dari Room ${roomNumber} sekarang?`)) {
        // Show loading state
        const checkOutBtn = document.getElementById('btnCheckOut');
        const originalText = checkOutBtn.innerHTML;
        checkOutBtn.innerHTML = '<span>⏳</span><span>Processing...</span>';
        checkOutBtn.disabled = true;
        
        // Call check-out API
        fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'include', // Important: Send session cookies
            body: 'booking_id=' + bookingId
        })
        .then(response => {
            // Check if response is JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server mengembalikan response non-JSON');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert('✅ ' + data.message);
                // Reload page to reflect changes
                window.location.reload();
            } else {
                alert('❌ Error: ' + data.message);
                checkOutBtn.innerHTML = originalText;
                checkOutBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ Terjadi kesalahan sistem: ' + error.message);
            checkOutBtn.innerHTML = originalText;
            checkOutBtn.disabled = false;
        });
    }
}

function doMoveRoom() {
    const modal = document.getElementById('bookingDetailsModal');
    const bookingId = modal.dataset.bookingId;
    
    // TODO: Implement move room modal
    alert('Move room feature coming soon for booking #' + bookingId);
}

window.doPayment = function doPayment() {
    openBookingPaymentModal();
}

window.quickViewCheckIn = function quickViewCheckIn() {
    if (!currentPaymentBooking) {
        alert('Booking data not found');
        return;
    }

    const booking = currentPaymentBooking;
    const guestName = booking.guest_name;
    const roomNumber = booking.room_number;
    const paymentStatus = booking.payment_status;

    if (paymentStatus !== 'paid') {
        const proceed = confirm('Pembayaran belum lunas. Lanjut check-in dan buat invoice sisa?');
        if (!proceed) {
            openBookingPaymentModal();
            return;
        }
    }

    if (confirm(`Check-in ${guestName} ke Room ${roomNumber} sekarang?`)) {
        const createInvoice = paymentStatus !== 'paid' ? 1 : 0;
        const btnCheckIn = document.querySelector('.qv-checkin-btn');
        const originalText = btnCheckIn.innerHTML;
        btnCheckIn.innerHTML = 'Processing...';
        btnCheckIn.disabled = true;

        fetch('<?php echo BASE_URL; ?>/api/checkin-guest.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'include',
            body: 'booking_id=' + booking.id + '&create_invoice=' + createInvoice
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.invoice_number) {
                    alert('✅ ' + data.message + '\nInvoice: ' + data.invoice_number);
                } else {
                    alert('✅ ' + data.message);
                }
                closeBookingQuickView();
                location.reload();
            } else {
                alert('❌ Error: ' + data.message);
                btnCheckIn.innerHTML = originalText;
                btnCheckIn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ Terjadi kesalahan: ' + error.message);
            btnCheckIn.innerHTML = originalText;
            btnCheckIn.disabled = false;
        });
    }
}

window.quickViewCheckOut = function quickViewCheckOut() {
    if (!currentPaymentBooking) {
        alert('Booking data not found');
        return;
    }

    const booking = currentPaymentBooking;
    const guestName = booking.guest_name;
    const roomNumber = booking.room_number;
    
    // Check payment status before checkout
    if (booking.payment_status !== 'paid') {
        const remaining = (parseFloat(booking.total_price) - parseFloat(booking.amount_paid)).toLocaleString('id-ID');
        const proceed = confirm(`Pembayaran belum lunas (Sisa: Rp ${remaining}). Lanjut check-out?`);
        if (!proceed) {
            openBookingPaymentModal();
            return;
        }
    }

    if (confirm(`Check-out ${guestName} dari Room ${roomNumber} sekarang?`)) {
        // Show loading state
        const checkOutBtn = document.querySelector('.qv-checkout-btn');
        let originalText = 'Check-out';
        
        if (checkOutBtn) {
            originalText = checkOutBtn.innerHTML;
            checkOutBtn.innerHTML = 'Processing...';
            checkOutBtn.disabled = true;
        }
        
        // Call check-out API
        fetch('<?php echo BASE_URL; ?>/api/checkout-guest.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            credentials: 'include',
            body: 'booking_id=' + booking.id
        })
        .then(response => {
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server mengembalikan response non-JSON');
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                alert('✅ ' + data.message);
                closeBookingQuickView();
                window.location.reload();
            } else {
                alert('❌ Error: ' + data.message);
                if (checkOutBtn) {
                    checkOutBtn.innerHTML = originalText;
                    checkOutBtn.disabled = false;
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('❌ Terjadi kesalahan sistem: ' + error.message);
            if (checkOutBtn) {
                checkOutBtn.innerHTML = originalText;
                checkOutBtn.disabled = false;
            }
        });
    }
}

window.quickViewMoveRoom = function quickViewMoveRoom() {
    if (!currentPaymentBooking) {
        alert('Booking data not found');
        return;
    }

    const booking = currentPaymentBooking;
    alert('Move room feature untuk booking ' + booking.booking_code + ' segera hadir!');
    // TODO: Implement move room modal
}

window.openBookingPaymentModal = function openBookingPaymentModal() {
    if (!currentPaymentBooking) {
        alert('Booking data tidak ditemukan. Silakan buka detail booking lagi.');
        return;
    }

    const total = parseFloat(currentPaymentBooking.final_price) || 0;
    const paid = parseFloat(currentPaymentBooking.paid_amount) || 0;
    const remaining = Math.max(0, total - paid);

    document.getElementById('paymentTotal').textContent = 'Rp ' + total.toLocaleString('id-ID');
    document.getElementById('paymentPaid').textContent = 'Rp ' + paid.toLocaleString('id-ID');
    document.getElementById('paymentRemaining').textContent = 'Rp ' + remaining.toLocaleString('id-ID');
    document.getElementById('paymentAmount').value = remaining;
    document.getElementById('paymentModalSubtitle').textContent = currentPaymentBooking.booking_code + ' • ' + (currentPaymentBooking.guest_name || '-');

    const methodInput = document.getElementById('paymentMethodPay');
    const methodButtons = document.querySelectorAll('#bookingPaymentModal .payment-method-btn');
    methodButtons.forEach(btn => btn.classList.remove('active'));
    const defaultBtn = document.querySelector('#bookingPaymentModal .payment-method-btn[data-value="cash"]');
    if (defaultBtn) defaultBtn.classList.add('active');
    if (methodInput) methodInput.value = 'cash';

    const modal = document.getElementById('bookingPaymentModal');
    modal.classList.add('active');
    modal.style.display = 'flex';
    modal.style.position = 'fixed';
    modal.style.zIndex = '99999';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.right = '0';
    modal.style.bottom = '0';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
}

window.closeBookingPaymentModal = function closeBookingPaymentModal() {
    const modal = document.getElementById('bookingPaymentModal');
    modal.classList.remove('active');
    modal.style.display = '';
    modal.style.position = '';
    modal.style.zIndex = '';
}

window.submitBookingPayment = function submitBookingPayment() {
    if (!currentPaymentBooking) return;

    const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
    const method = document.getElementById('paymentMethodPay').value || 'cash';

    if (amount <= 0) {
        alert('Jumlah bayar harus lebih dari 0');
        return;
    }

    fetch('<?php echo BASE_URL; ?>/api/add-booking-payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        credentials: 'include',
        body: 'booking_id=' + encodeURIComponent(currentPaymentBooking.id) +
              '&amount=' + encodeURIComponent(amount) +
              '&payment_method=' + encodeURIComponent(method)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show detailed success message with cashbook info
            let successMsg = '✅ PEMBAYARAN BERHASIL!\n\n';
            successMsg += data.message || 'Payment saved';
            
            alert(successMsg);
            
            closeBookingPaymentModal();
            // Refresh booking details
            return fetch('../../api/get-booking-details.php?id=' + currentPaymentBooking.id)
                .then(res => res.json())
                .then(updated => {
                    if (updated.success) {
                        showBookingQuickView(updated.booking);
                        const detailsModal = document.getElementById('bookingDetailsModal');
                        if (detailsModal && detailsModal.classList.contains('active')) {
                            showBookingDetailsModal(updated.booking);
                        }
                    }
                });
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Gagal menyimpan pembayaran');
    });
}

window.changeDate = function changeDate() {
    const dateInput = document.getElementById('dateInput');
    if (!dateInput) return;
    window.location.search = '?start=' + dateInput.value;
}

window.openNewReservationForm = function openNewReservationForm() {
    // Open reservation modal with today's date
    const modal = document.getElementById('reservationModal');
    
    // Reset Form First
    const form = document.getElementById('reservationForm');
    if(form) form.reset();

    const checkInInput = document.getElementById('checkInDate');
    const checkOutInput = document.getElementById('checkOutDate');
    
    // Set default dates (today and tomorrow)
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(tomorrow.getDate() + 1);
    
    if (checkInInput) checkInInput.value = today.toISOString().split('T')[0];
    if (checkOutInput) checkOutInput.value = tomorrow.toISOString().split('T')[0];
    
    // Load available rooms for default dates
    loadAvailableRoomsCalendar();
    
    // Reset payment method class
    document.querySelectorAll('#reservationModal .pm-item').forEach(d => d.classList.remove('active'));
    // Set cash active
    const cashBtn = document.querySelector('#reservationModal .pm-item:first-child');
    if(cashBtn) {
        cashBtn.classList.add('active');
        document.getElementById('paymentMethod').value = 'cash';
    }
    
    // Show Modal
    if(modal) {
        modal.classList.add('active');
    }
}

// Store clicked roomId for auto-selection after rooms load
let pendingRoomSelection = null;

window.openCellReservation = function openCellReservation(element) {
    const date = element.getAttribute('data-date');
    const roomId = element.getAttribute('data-room-id');
    
    // Store roomId for auto-selection after rooms load
    pendingRoomSelection = roomId;
    
    // Populate Modal
    const modal = document.getElementById('reservationModal');
    
    // Reset Form First
    const form = document.getElementById('reservationForm');
    if(form) form.reset();

    const checkInInput = document.getElementById('checkInDate');
    const checkOutInput = document.getElementById('checkOutDate');
    
    if (checkInInput) checkInInput.value = date;
    
    // Calculate next day for checkout default
    if (checkInInput && checkOutInput) {
        const nextDay = new Date(date);
        nextDay.setDate(nextDay.getDate() + 1);
        checkOutInput.value = nextDay.toISOString().split('T')[0];
    }
    
    // Load available rooms for selected dates (will auto-select room)
    loadAvailableRoomsCalendar();
    
    // Trigger source update to hide fees by default
    if(typeof updateSourceDetails === 'function') updateSourceDetails();
    
    // Reset payment method class
    document.querySelectorAll('#reservationModal .pm-item').forEach(d => d.classList.remove('active'));
    // Set cash active
    const cashBtn = document.querySelector('#reservationModal .pm-item:first-child');
    if(cashBtn) {
        cashBtn.classList.add('active');
        document.getElementById('paymentMethod').value = 'cash';
    }
    
    // Show Modal
    if(modal) {
        modal.classList.add('active');
    }
}

window.closeReservationModal = function() {
    const modal = document.getElementById('reservationModal');
    if(modal) modal.classList.remove('active');
}

window.updateRoomPrice = function() {
    const select = document.getElementById('roomSelect');
    const priceInput = document.getElementById('roomPrice');
    if(select && priceInput) {
        const option = select.options[select.selectedIndex];
        if(option) {
            priceInput.value = option.getAttribute('data-price') || 0;
            calculateFinalPrice();
        }
    }
}

window.updateStayDetails = function() {
    const checkInEl = document.getElementById('checkInDate');
    const checkOutEl = document.getElementById('checkOutDate');
    if(!checkInEl || !checkOutEl) return;
    
    const checkIn = new Date(checkInEl.value);
    const checkOut = new Date(checkOutEl.value);
    
    if (checkIn && checkOut && checkOut > checkIn) {
        const diffTime = Math.abs(checkOut - checkIn);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)); 
        document.getElementById('totalNights').value = diffDays;
        
        const display = document.getElementById('displayNights');
        if(display) display.innerText = diffDays;
        
        calculateFinalPrice();
    } else {
        document.getElementById('totalNights').value = 0;
        const display = document.getElementById('displayNights');
        if(display) display.innerText = 0;
    }
}

window.updateSourceDetails = function() {
    const sourceSelect = document.getElementById('bookingSource');
    const feeDisplay = document.getElementById('otaFeeDisplay');
    const feeRow = document.getElementById('feeRow');
    currentSource = sourceSelect ? sourceSelect.value : '';
    
    // Default Fees Map (Fallback)
    // Matches the values in the HTML optgroup
    currentFees = (typeof OTA_FEES !== 'undefined') ? OTA_FEES : {
        'agoda': 15, 
        'booking': 12, 
        'tiket': 10, 
        'traveloka': 15, 
        'airbnb': 3, 
        'ota': 10
    };
    
    let feePercent = currentFees[currentSource] || 0;
    
    // AUTO-SELECT PAYMENT METHOD LOGIC
    const pmOtaBtn = document.getElementById('pm-ota'); // The new hidden OTA button
    const otaSources = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota'];
    
    if (otaSources.includes(currentSource)) {
        // Source is an OTA
        if (pmOtaBtn) {
            pmOtaBtn.style.display = 'flex'; // Show the button
            pmOtaBtn.click(); // Auto-click to select it
        }
    } else {
        // Source is NOT an OTA (Direct/Walk-in)
        if (pmOtaBtn) {
            pmOtaBtn.style.display = 'none'; // Hide the button
        }
        // Switch back to Cash if OTA was selected
        const pmElement = document.getElementById('paymentMethod');
        const currentPm = pmElement ? pmElement.value : '';
        if (currentPm === 'ota') {
             // Find cash button and click it
             const cashBtn = document.querySelector('.pm-item[onclick*="cash"]');
             if (cashBtn) cashBtn.click();
        }
    }
    
    if (feePercent > 0) {
        if(feeDisplay) {
            feeDisplay.style.display = 'inline-block';
            const pctEl = document.getElementById('otaFeePercent');
            if(pctEl) pctEl.innerText = feePercent;
        }
        if(feeRow) feeRow.style.display = 'flex';
    } else {
        if(feeDisplay) feeDisplay.style.display = 'none';
        if(feeRow) feeRow.style.display = 'none';
    }
    
    calculateFinalPrice();
}

window.calculateFinalPrice = function() {
    const nightsEl = document.getElementById('totalNights');
    const priceEl = document.getElementById('roomPrice');
    const discountEl = document.getElementById('discount');
    
    const nights = parseInt(nightsEl ? nightsEl.value : 0) || 0;
    const price = parseFloat(priceEl ? priceEl.value : 0) || 0;
    const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;
    
    const total = (nights * price) - discount;
    const final = total > 0 ? total : 0;
    
    // Update both total_price and final_price hidden fields
    const totalPriceEl = document.getElementById('hiddenTotalPrice');
    if(totalPriceEl) totalPriceEl.value = (nights * price);
    
    const finalEl = document.getElementById('finalPriceDisplay');
    if(finalEl) finalEl.innerText = 'Rp ' + final.toLocaleString('id-ID');
    
    const hiddenEl = document.getElementById('hiddenFinalPrice');
    if(hiddenEl) hiddenEl.value = final;
    
    // OTA Source Logic - Updated to auto-set payment method and full payment
    const feeRow = document.getElementById('feeRow');
    const pmOta = document.getElementById('pm-ota');
    const paymentMethodInput = document.getElementById('paymentMethod');
    const paidAmountInput = document.getElementById('paidAmount');
    
    if (currentFees[currentSource] && currentFees[currentSource] > 0) {
        if(feeRow) feeRow.style.display = 'flex';
        // Auto select OTA payment for OTA sources
        if(pmOta) {
            pmOta.style.display = 'flex';
            // Trigger click to activate
            if (currentSource !== 'walk_in' && currentSource !== 'phone') {
                pmOta.click();
            }
        }
        
        // Auto-fill paid amount with final price for OTA (Assume prepaid to OTA)
        // Check if it IS an OTA source (logic: has a fee defined usually implies OTA here)
        if (['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota'].includes(currentSource)) {
             if (paidAmountInput) paidAmountInput.value = final;
             
             // Update payment status dropdown logic locally if function exists
             if (typeof updatePaymentStatusFromAmount === 'function') {
                 updatePaymentStatusFromAmount();
             }
             
             const feeInfo = document.getElementById('otaFeeInfo');
             if(feeInfo) feeInfo.style.display = 'block';
        }
        
    } else {
        if(feeRow) feeRow.style.display = 'none';
        if(pmOta) pmOta.style.display = 'none';
        const feeInfo = document.getElementById('otaFeeInfo');
        if(feeInfo) feeInfo.style.display = 'none';
        
        // Revert to cash if OTA was selected but source changed to non-OTA
        if (paymentMethodInput && paymentMethodInput.value === 'ota') {
            const cashBtn = document.querySelector('.pm-item[onclick*="cash"]');
            if (cashBtn) cashBtn.click();
        }
    }
}

window.setPaymentMethod = function(method, btn) {
    document.getElementById('paymentMethod').value = method;
    // Handle both old button style and new pm-item style
    document.querySelectorAll('#reservationModal .payment-method-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('#reservationModal .pm-item').forEach(b => b.classList.remove('active'));
    
    if(btn) btn.classList.add('active');
}

window.payFullAmount = function() {
    const hiddenFinalPrice = document.getElementById('hiddenFinalPrice');
    const paidAmount = document.getElementById('paidAmount');
    
    if (hiddenFinalPrice && paidAmount) {
        const totalAmount = parseFloat(hiddenFinalPrice.value) || 0;
        paidAmount.value = totalAmount;
        
        // Optional: Show confirmation
        if (totalAmount > 0) {
            const formattedAmount = 'Rp ' + totalAmount.toLocaleString('id-ID');
            console.log('Pay All clicked - Amount set to:', formattedAmount);
        }
    }
}

// ============================================
// MULTI-ROOM BOOKING FUNCTIONS FOR CALENDAR
// ============================================

function updateCheckOutMinDateCalendar() {
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

async function loadAvailableRoomsCalendar() {
    const checkIn = document.getElementById('checkInDate').value;
    const checkOut = document.getElementById('checkOutDate').value;
    
    // Update min date for check-out
    updateCheckOutMinDateCalendar();
    
    if (!checkIn || !checkOut) {
        document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Pilih tanggal check-in dan check-out terlebih dahulu</em>';
        return;
    }
    
    // Validate dates
    if (new Date(checkOut) <= new Date(checkIn)) {
        document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">❌ Check-out harus minimal 1 hari setelah check-in</em>';
        document.getElementById('availabilityInfoCalendar').innerHTML = '<small style="color: #ef4444;">Invalid dates</small>';
        return;
    }
    
    // Show loading
    document.getElementById('roomsChecklistCalendar').innerHTML = '<div style="text-align:center; padding: 20px;"><em>Loading available rooms...</em></div>';
    
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
                               onchange="calculateMultiRoomTotalCalendar()"
                               style="margin-right: 8px;">
                        <strong>Room ${room.room_number}</strong> - ${room.type_name}
                        <span style="color: #10b981; font-weight: bold;">(Rp ${parseInt(room.base_price).toLocaleString('id-ID')}/night)</span>
                    </label>
                `;
            });
            document.getElementById('roomsChecklistCalendar').innerHTML = html;
            document.getElementById('availabilityInfoCalendar').innerHTML = `<small style="color: #10b981;">✅ ${result.available_rooms} room(s) available (${result.booked_rooms} booked)</small>`;
            
            // Auto-select room if clicked from calendar cell
            if (pendingRoomSelection) {
                const roomCheckbox = document.querySelector(`input[name="rooms[]"][value="${pendingRoomSelection}"]`);
                if (roomCheckbox) {
                    roomCheckbox.checked = true;
                    roomCheckbox.closest('.room-checkbox-item').style.background = '#dcfce7';
                }
                pendingRoomSelection = null; // Clear after use
                calculateMultiRoomTotalCalendar(); // Update totals
            }
        } else if (result.success && result.rooms.length === 0) {
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">❌ Tidak ada room yang tersedia untuk tanggal ini (semua sudah di-booking)</em>';
            document.getElementById('availabilityInfoCalendar').innerHTML = `<small style="color: #ef4444;">0 rooms available (all ${result.booked_rooms} rooms booked)</small>`;
        } else {
            document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Error loading rooms: ' + (result.message || 'Unknown error') + '</em>';
        }
        
        // Recalculate totals
        calculateMultiRoomTotalCalendar();
        
    } catch (error) {
        console.error('Error loading rooms:', error);
        document.getElementById('roomsChecklistCalendar').innerHTML = '<em style="color: #ef4444;">Error loading rooms. Please try again.</em>';
    }
}

function calculateMultiRoomTotalCalendar() {
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
    document.getElementById('totalRoomsDisplayCalendar').textContent = totalRooms + ' room' + (totalRooms !== 1 ? 's' : '');
    document.getElementById('displayNights').textContent = nights + ' night' + (nights !== 1 ? 's' : '');
    document.getElementById('subtotalDisplayCalendar').textContent = 'Rp ' + subtotal.toLocaleString('id-ID');
    document.getElementById('grandTotalDisplayCalendar').textContent = 'Rp ' + grandTotal.toLocaleString('id-ID');
    
    // Update summary
    if (totalRooms > 0) {
        document.getElementById('selectedRoomsSummaryCalendar').innerHTML = 
            '<strong>Selected:</strong> ' + totalRooms + ' room(s) × ' + nights + ' night(s) = Rp ' + subtotal.toLocaleString('id-ID');
    } else {
        document.getElementById('selectedRoomsSummaryCalendar').innerHTML = '<em style="color: #ef4444;">Belum ada room yang dipilih</em>';
    }
}

function payFullMultiRoomCalendar() {
    const grandTotalText = document.getElementById('grandTotalDisplayCalendar').textContent;
    const grandTotal = parseFloat(grandTotalText.replace(/[^\d]/g, ''));
    document.getElementById('paidAmount').value = grandTotal;
}

window.submitReservation = async function(event) {
    event.preventDefault();
    
    // Validate room selection
    const checkedRooms = document.querySelectorAll('input[name="rooms[]"]:checked');
    if (checkedRooms.length === 0) {
        alert('Silakan pilih minimal 1 room!');
        return;
    }
    
    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerText;
    
    // Get form data
    const guestName = document.getElementById('guestName').value;
    const guestPhone = document.getElementById('guestPhone').value || '';
    const checkIn = document.getElementById('checkInDate').value;
    const checkOut = document.getElementById('checkOutDate').value;
    const bookingSource = document.getElementById('bookingSource').value;
    const paymentMethod = document.getElementById('paymentMethod').value;
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const paidAmount = parseFloat(document.getElementById('paidAmount').value) || 0;
    const adultCount = parseInt(document.getElementById('adultCount').value) || 1;
    
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
    submitBtn.disabled = true;
    submitBtn.textContent = 'Creating bookings...';
    
    let successCount = 0;
    let errorCount = 0;
    const bookingCodes = [];
    const errorMessages = [];
    
    // Create booking for each room
    for (let i = 0; i < checkedRooms.length; i++) {
        const checkbox = checkedRooms[i];
        const roomId = checkbox.value;
        const roomNumber = checkbox.dataset.room;
        const roomPrice = roomPrices[i];
        
        // Calculate proportional payment
        const proportionalPayment = totalPrice > 0 ? (paidAmount * (roomPrice / totalPrice)) : 0;
        
        // Create FormData for API - FIELD NAMES MUST MATCH API EXPECTATIONS
        const roomBasePrice = parseFloat(checkbox.dataset.price);
        const roomTotalPrice = roomBasePrice * nights;
        const roomFinalPrice = roomTotalPrice - discountPerRoom;
        
        const formData = new FormData();
        formData.append('guest_name', guestName);
        formData.append('guest_phone', guestPhone);
        formData.append('room_id', roomId);
        formData.append('check_in_date', checkIn);  // API expects check_in_date
        formData.append('check_out_date', checkOut); // API expects check_out_date
        formData.append('total_nights', nights);
        formData.append('adult_count', adultCount);
        formData.append('children_count', 0);
        formData.append('room_price', roomBasePrice);  // API expects room_price (per night)
        formData.append('total_price', roomTotalPrice); // API expects total_price
        formData.append('discount', discountPerRoom);
        formData.append('final_price', roomFinalPrice);
        formData.append('booking_source', bookingSource);
        formData.append('payment_method', paymentMethod);
        formData.append('paid_amount', proportionalPayment);
        
        try {
            const apiUrl = '<?php echo BASE_URL; ?>/api/create-reservation.php';
            const response = await fetch(apiUrl, {
                method: 'POST',
                body: formData
            });
            
            // Get raw text first for debugging
            const responseText = await response.text();
            let result;
            
            try {
                result = JSON.parse(responseText);
            } catch (parseErr) {
                console.error(`Room ${roomNumber} - Invalid JSON response:`, responseText);
                errorMessages.push(`Room ${roomNumber}: Server error`);
                errorCount++;
                continue;
            }
            
            if (result.success) {
                successCount++;
                bookingCodes.push(result.booking_code);
            } else {
                errorCount++;
                const errMsg = result.message || 'Unknown error';
                errorMessages.push(`Room ${roomNumber}: ${errMsg}`);
                console.error(`Error booking Room ${roomNumber}:`, errMsg);
            }
        } catch (error) {
            errorCount++;
            errorMessages.push(`Room ${roomNumber}: Network error`);
            console.error(`Error booking Room ${roomNumber}:`, error);
        }
    }
    
    // Re-enable submit button
    submitBtn.disabled = false;
    submitBtn.textContent = originalText;
    
    // Show results
    if (successCount > 0) {
        alert(`✅ Berhasil membuat ${successCount} booking!\n\nBooking Codes: ${bookingCodes.join(', ')}\n\n${errorCount > 0 ? `⚠️ ${errorCount} booking gagal:\n${errorMessages.join('\n')}` : ''}`);
        closeReservationModal();
        location.reload(); // Refresh to show new bookings
    } else {
        const errDetail = errorMessages.length > 0 ? `\n\nDetail error:\n${errorMessages.join('\n')}` : '';
        alert('❌ Gagal membuat booking. Silakan coba lagi.' + errDetail);
    }
}

const shiftCalendarDays = (days) => {
    const dateInput = document.getElementById('dateInput');
    if (!dateInput) return;
    const currentDate = new Date(dateInput.value);
    currentDate.setDate(currentDate.getDate() + days);
    dateInput.value = currentDate.toISOString().split('T')[0];
    changeDate();
};

window.prevMonth = function prevMonth() {
    shiftCalendarDays(-30);
}

window.nextMonth = function nextMonth() {
    shiftCalendarDays(30);
}
// ========================================
// COMMENTED OUT - Reservation Form Code
// Will rebuild from scratch
// ========================================

// Store form pre-fill data
let formPreFillData = {

    date: null,
    roomId: null
};

// Expose functions to global scope for onclick handlers
window.showReservationForm = function showReservationForm() {
    // Close any other open modals first
    const bookingPaymentModal = document.getElementById('bookingPaymentModal');
    if (bookingPaymentModal) {
        bookingPaymentModal.classList.remove('active');
    }
    const bookingDetailsModal = document.getElementById('bookingDetailsModal');
    if (bookingDetailsModal) {
        bookingDetailsModal.classList.remove('active');
    }
    const bookingQuickView = document.getElementById('bookingQuickView');
    if (bookingQuickView) {
        bookingQuickView.classList.remove('active');
    }
    
    // Use pre-fill data if available
    const savedDate = formPreFillData.date;
    const savedRoomId = formPreFillData.roomId;
    
    console.log('📅 Opening reservation form with date:', savedDate, 'room:', savedRoomId);
    
    const modal = document.getElementById('reservationModal');
    
    // FIRST: Reset form completely to avoid stale data
    document.getElementById('reservationForm').reset();
    const guestName = document.getElementById('guestName');
    if (guestName) guestName.value = '';
    
    // Pre-fill form with SAVED data (not selectedDate which is now null)
    if (savedDate) {
        console.log('✅ Setting check-in date:', savedDate);
        
        // Set check-in date from selected date
        const checkInInput = document.getElementById('checkInDate');
        checkInInput.value = savedDate;
        
        // Set check-out date to next day
        const checkOut = new Date(savedDate);
        checkOut.setDate(checkOut.getDate() + 1);
        const checkOutDate = checkOut.toISOString().split('T')[0];
        
        const checkOutInput = document.getElementById('checkOutDate');
        checkOutInput.value = checkOutDate;
        checkOutInput.min = checkOutDate;
        
        console.log('Check-in:', checkInInput.value, 'Check-out:', checkOutInput.value);
    } else {
        console.error('❌ No savedDate available!');
    }
    
    if (savedRoomId) {
        console.log('✅ Setting room:', savedRoomId);
        document.getElementById('roomSelect').value = savedRoomId;
        // Trigger change to update price
        document.getElementById('roomSelect').dispatchEvent(new Event('change'));
    }
    
    // Calculate initial nights
    calculateNights();
    
    // Show modal AFTER setting all values
    modal.classList.add('active');
}

window.closeReservationModal = function closeReservationModal() {
    const modal = document.getElementById('reservationModal');
    modal.classList.remove('active');
    
    // Also close other modals if open
    const bookingPaymentModal = document.getElementById('bookingPaymentModal');
    if (bookingPaymentModal) {
        bookingPaymentModal.classList.remove('active');
    }
    const bookingDetailsModal = document.getElementById('bookingDetailsModal');
    if (bookingDetailsModal) {
        bookingDetailsModal.classList.remove('active');
    }
    const bookingQuickView = document.getElementById('bookingQuickView');
    if (bookingQuickView) {
        bookingQuickView.classList.remove('active');
    }
    
    // Clear pre-fill data
    formPreFillData.date = null;
    formPreFillData.roomId = null;
    
    // Remove inline styles to allow CSS to take over
    modal.style.display = '';
    modal.style.position = '';
    modal.style.top = '';
    modal.style.left = '';
    modal.style.right = '';
    modal.style.bottom = '';
    modal.style.zIndex = '';
    modal.style.backgroundColor = '';
    modal.style.alignItems = '';
    modal.style.justifyContent = '';
    
    // Completely reset form
    document.getElementById('reservationForm').reset();
    
    // Explicitly clear guest information fields
    const guestName = document.getElementById('guestName');
    const guestPhone = document.getElementById('guestPhone');
    const guestEmail = document.getElementById('guestEmail');
    const guestId = document.getElementById('guestId');
    if (guestName) guestName.value = '';
    if (guestPhone) guestPhone.value = '';
    if (guestEmail) guestEmail.value = '';
    if (guestId) guestId.value = '';
    
    // Reset dates
    const checkInDate = document.getElementById('checkInDate');
    const checkOutDate = document.getElementById('checkOutDate');
    if (checkInDate) checkInDate.value = '';
    if (checkOutDate) checkOutDate.value = '';
    
    // Reset room and price
    const roomSelect = document.getElementById('roomSelect');
    const roomPrice = document.getElementById('roomPrice');
    if (roomSelect) roomSelect.value = '';
    if (roomPrice) roomPrice.value = '';
    
    // Reset discount
    const discount = document.getElementById('discount');
    if (discount) discount.value = '0';
    
    // Reset special request
    const specialRequest = document.getElementById('specialRequest');
    if (specialRequest) specialRequest.value = '';
    
    // Reset DP/Paid Amount
    const paidAmount = document.getElementById('paidAmount');
    if (paidAmount) {
        paidAmount.value = '0';
        delete paidAmount.dataset.dpPercent;
    }
    
    // Reset payment method to Cash
    const paymentMethod = document.getElementById('paymentMethod');
    if (paymentMethod) paymentMethod.value = 'cash';
    
    // Reset payment status to unpaid
    const paymentStatus = document.getElementById('paymentStatus');
    if (paymentStatus) paymentStatus.value = 'unpaid';
    
    // Reset button states
    const paymentButtons = document.querySelectorAll('#reservationModal .payment-method-btn');
    paymentButtons.forEach((btn, idx) => {
        if (idx === 1) {  // Cash is second button (index 1)
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    const dpButtons = document.querySelectorAll('.dp-percent-btn');
    dpButtons.forEach(btn => btn.classList.remove('active'));
    
    // Reset Total Pax to default values
    const adultInput = document.getElementById('adultCount');
    const childrenInput = document.getElementById('childrenCount');
    if (adultInput) adultInput.value = 1;
    if (childrenInput) childrenInput.value = 0;
    calculateTotalPax();
    
    // Reset price display
    const totalPriceEl = document.getElementById('totalPrice');
    const discountAmountEl = document.getElementById('discountAmount');
    const finalPriceEl = document.getElementById('finalPrice');
    if (totalPriceEl) totalPriceEl.textContent = 'Rp 0';
    if (discountAmountEl) discountAmountEl.textContent = '- Rp 0';
    if (finalPriceEl) finalPriceEl.textContent = 'Rp 0';
    
    selectedDate = null;
    selectedRoom = null;
}

window.calculateNights = function calculateNights() {
    const checkInEl = document.getElementById('checkInDate');
    const checkOutEl = document.getElementById('checkOutDate');
    const checkIn = checkInEl ? checkInEl.value : null;
    const checkOut = checkOutEl ? checkOutEl.value : null;
    
    if (checkIn && checkOut) {
        const start = new Date(checkIn);
        const end = new Date(checkOut);
        const nights = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
        
        const totalNightsEl = document.getElementById('totalNights');
        if (totalNightsEl) totalNightsEl.value = nights > 0 ? nights : 0;
        calculatePrice();
    }
}

window.calculatePrice = function calculatePrice() {
    const roomPriceEl = document.getElementById('roomPrice');
    const totalNightsEl = document.getElementById('totalNights');
    const discountEl = document.getElementById('discount');
    
    const roomPrice = parseFloat(roomPriceEl ? roomPriceEl.value : 0) || 0;
    const nights = parseInt(totalNightsEl ? totalNightsEl.value : 0) || 0;
    const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;
    
    const total = roomPrice * nights;
    const final = total - discount;
    
    const totalPriceEl = document.getElementById('totalPrice');
    const discountAmountEl = document.getElementById('discountAmount');
    const finalPriceDisplayEl = document.getElementById('finalPrice');
    
    if (totalPriceEl) totalPriceEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
    if (discountAmountEl) discountAmountEl.textContent = '- Rp ' + discount.toLocaleString('id-ID');
    if (finalPriceDisplayEl) finalPriceDisplayEl.textContent = 'Rp ' + final.toLocaleString('id-ID');

    // Run Calculate Final Price for the modernized form too
    if (typeof calculateFinalPrice === 'function') calculateFinalPrice();

    // Recalculate DP amount if a percent is selected
    const paidInput = document.getElementById('paidAmount');
    if (paidInput && paidInput.dataset.dpPercent) {
        applyDpPercent(parseFloat(paidInput.dataset.dpPercent));
    }
}

window.getFinalPriceNumber = function getFinalPriceNumber() {
    const roomPriceEl = document.getElementById('roomPrice');
    const totalNightsEl = document.getElementById('totalNights');
    const discountEl = document.getElementById('discount');
    
    const roomPrice = parseFloat(roomPriceEl ? roomPriceEl.value : 0) || 0;
    const nights = parseInt(totalNightsEl ? totalNightsEl.value : 0) || 0;
    const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;
    return (roomPrice * nights) - discount;
}

window.applyDpPercent = function applyDpPercent(percent) {
    const paidInput = document.getElementById('paidAmount');
    if (!paidInput) return;

    const finalPrice = getFinalPriceNumber();
    const amount = Math.round(finalPrice * (percent / 100));
    paidInput.value = amount;
    paidInput.dataset.dpPercent = percent;

    updatePaymentStatusFromAmount();
}

window.updatePaymentStatusFromAmount = function updatePaymentStatusFromAmount() {
    const paidInput = document.getElementById('paidAmount');
    const statusSelect = document.getElementById('paymentStatus');
    if (!paidInput || !statusSelect) return;

    const paid = parseFloat(paidInput.value) || 0;
    const finalPrice = getFinalPriceNumber();

    if (paid <= 0) {
        statusSelect.value = 'unpaid';
    } else if (paid >= finalPrice) {
        statusSelect.value = 'paid';
    } else {
        statusSelect.value = 'partial';
    }
}

// Calculate Total Pax (Adult + Children)
window.calculateTotalPax = function calculateTotalPax() {
    const adultEl = document.getElementById('adultCount');
    const childrenEl = document.getElementById('childrenCount');
    const totalPaxEl = document.getElementById('totalPax');
    
    const adults = parseInt(adultEl ? adultEl.value : 0) || 0;
    const children = parseInt(childrenEl ? childrenEl.value : 0) || 0;
    const totalPax = adults + children;
    
    if (totalPaxEl) totalPaxEl.value = totalPax;
}

// Old submitReservation removed to avoid duplication and syntax error
// The new submitReservation is defined earlier in the file

// Setup form event listeners (removed click-outside-to-close functionality)
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 DOMContentLoaded fired for calendar.php');

    try {
        // ========================================
        // 1. DRAG SCROLL IMPLEMENTATION (PRIORITY)
        // ========================================
        const scroller = document.getElementById('drag-container') || document.querySelector('.calendar-scroll-wrapper');
        
        if (!scroller) {
            console.error('❌ Drag container not found');
        } else {
            console.log('✅ Drag initialized on #drag-container');
            
            let isDown = false;
            let startX;
            let scrollLeft;

            scroller.addEventListener('mousedown', (e) => {
                // Ignore if clicking on interactive elements
                if (e.target.closest('.booking-bar') || 
                    e.target.closest('.nav-btn') || 
                    e.target.closest('input') || 
                    e.target.closest('button')) return;
                
                isDown = true;
                scroller.classList.add('dragging');
                startX = e.pageX - scroller.offsetLeft;
                scrollLeft = scroller.scrollLeft;
                
                console.log('MouseDown: StartX', startX, 'ScrollLeft', scrollLeft);
            });

            // Use window for mousemove/mouseup to handle drags that leave the container
            window.addEventListener('mouseleave', () => {
                isDown = false;
                if(scroller) scroller.classList.remove('dragging');
            });

            window.addEventListener('mouseup', () => {
                isDown = false;
                if(scroller) scroller.classList.remove('dragging');
            });

            window.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault(); // Prevent selection/dragging artifacts
                
                const x = e.pageX - scroller.offsetLeft;
                const walk = (x - startX); // Scroll 1:1
                scroller.scrollLeft = scrollLeft - walk;
                
                // console.log('MouseMove:', { x, walk, newScroll: scroller.scrollLeft }); // Uncomment for debug
            });

            // Touch support
            scroller.addEventListener('touchstart', (e) => {
                if (e.target.closest('.booking-bar')) return;
                const touch = e.touches[0];
                isDown = true;
                startX = touch.pageX - scroller.offsetLeft;
                scrollLeft = scroller.scrollLeft;
            }, { passive: true });

            scroller.addEventListener('touchmove', (e) => {
                if (!isDown) return;
                const touch = e.touches[0];
                const x = touch.pageX - scroller.offsetLeft;
                const walk = (x - startX);
                scroller.scrollLeft = scrollLeft - walk;
            }, { passive: false });

            scroller.addEventListener('touchend', () => {
                isDown = false;
            });
            
            // ========================================
            // AUTO-SCROLL TO TODAY ON PAGE LOAD
            // ========================================
            setTimeout(() => {
                const todayCell = document.querySelector('.grid-date-cell.today');
                if (todayCell && scroller) {
                    const scrollPos = todayCell.offsetLeft - (scroller.offsetWidth / 2) + (todayCell.offsetWidth / 2);
                    scroller.scrollLeft = scrollPos;
                    console.log('✅ Auto-scrolled to today:', scrollPos);
                }
            }, 100);
        }
    } catch (e) {
        console.error('❌ Error in Drag Scroll setup:', e);
    }

    try {
        // ========================================
        // 2. NAVIGATION BUTTONS (PRIORITY)
        // ========================================
        const prevBtn = document.getElementById('prevMonthBtn');
        const nextBtn = document.getElementById('nextMonthBtn');
        
        if (prevBtn) {
            prevBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Prev Month Clicked');
                if (typeof window.prevMonth === 'function') {
                    window.prevMonth();
                } else {
                    console.error('window.prevMonth is not defined');
                }
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Next Month Clicked');
                if (typeof window.nextMonth === 'function') {
                    window.nextMonth();
                } else {
                    console.error('window.nextMonth is not defined');
                }
            });
        }
        console.log('✅ Navigation buttons initialized');
    } catch (e) {
        console.error('❌ Error in Navigation setup:', e);
    }

    try {
        // Form event listeners
        const checkInDate = document.getElementById('checkInDate');
        const checkOutDate = document.getElementById('checkOutDate');
        const roomSelect = document.getElementById('roomSelect');
        const roomPriceInput = document.getElementById('roomPrice');
        const discountInput = document.getElementById('discount');
        const adultInput = document.getElementById('adultCount');
        const childrenInput = document.getElementById('childrenCount');
        const paidAmountInput = document.getElementById('paidAmount');
        const paymentMethodInput = document.getElementById('paymentMethod');
        
        if (checkInDate) checkInDate.addEventListener('change', calculateNights);
        if (checkOutDate) checkOutDate.addEventListener('change', calculateNights);
        if (roomPriceInput) roomPriceInput.addEventListener('input', calculatePrice);
        if (discountInput) discountInput.addEventListener('input', calculatePrice);
        if (adultInput) adultInput.addEventListener('change', calculateTotalPax);
        if (childrenInput) childrenInput.addEventListener('change', calculateTotalPax);
        
        if (paidAmountInput) {
            paidAmountInput.addEventListener('input', function() {
                const dpButtons = document.querySelectorAll('.dp-percent-btn');
                dpButtons.forEach(btn => btn.classList.remove('active'));
                delete paidAmountInput.dataset.dpPercent;
                if (typeof updatePaymentStatusFromAmount === 'function') {
                    updatePaymentStatusFromAmount();
                }
            });
        }

        const paymentButtons = document.querySelectorAll('#reservationModal .payment-method-btn');
        paymentButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                paymentButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                if (paymentMethodInput) {
                    paymentMethodInput.value = this.dataset.value;
                }
            });
        });

        const payModalButtons = document.querySelectorAll('#bookingPaymentModal .payment-method-btn');
        payModalButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                payModalButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const payMethodInput = document.getElementById('paymentMethodPay');
                if (payMethodInput) {
                    payMethodInput.value = this.dataset.value;
                }
            });
        });

        // Setup Booking Source Logic
        const sourceSelect = document.getElementById('bookingSource');
        if (sourceSelect) {
            sourceSelect.addEventListener('change', function() {
                // Trigger the logic to show/hide OTA options
                if (typeof calculateFinalPrice === 'function') calculateFinalPrice();
            });
        }

        const dpButtons = document.querySelectorAll('.dp-percent-btn');
        dpButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                dpButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const percent = parseFloat(this.dataset.percent);
                if (typeof applyDpPercent === 'function') {
                    applyDpPercent(percent);
                }
            });
        });
        
        if (roomSelect) {
            roomSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const price = selectedOption.getAttribute('data-price');
                if (price) {
                    const roomPriceEl = document.getElementById('roomPrice');
                    if (roomPriceEl) roomPriceEl.value = price;
                    if (typeof calculatePrice === 'function') calculatePrice();
                }
            });
        }
    } catch (e) {
        console.error('❌ Error in Form Listener setup:', e);
    }
});
</script>

<!-- RESERVATION MODAL - POPUP SYSTEM 2028 -->
<div id="reservationModal" class="modal-overlay">
    <div class="modal-content modal-compact">
        <button class="modal-close" onclick="closeReservationModal()">×</button>
        
        <div class="modal-header-compact">
            <h2>New Reservation</h2>
        </div>
        
        <form id="reservationForm" onsubmit="submitReservation(event)">
            <input type="hidden" name="action" value="create_reservation">
            <input type="hidden" id="totalNights" name="total_nights" value="1">
            <input type="hidden" id="hiddenTotalPrice" name="total_price" value="0">
            <input type="hidden" id="hiddenFinalPrice" name="final_price" value="0">
            
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
                        <input type="date" id="checkInDate" name="check_in_date" required onchange="loadAvailableRoomsCalendar()">
                    </div>
                    <div class="input-compact">
                        <label>Check Out*</label>
                        <input type="date" id="checkOutDate" name="check_out_date" required onchange="loadAvailableRoomsCalendar()">
                    </div>
                </div>

                <!-- ROOMS SELECTION (MULTI SELECT) -->
                <div class="input-compact">
                    <label>Select Rooms* (dapat pilih lebih dari 1)</label>
                    <div id="availabilityInfoCalendar" style="margin-bottom: 8px; font-size: 0.85rem;"></div>
                    <div id="roomsChecklistCalendar" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px; background: #f9f9f9;">
                        <em style="color: #888;">Pilih tanggal untuk melihat room yang tersedia...</em>
                    </div>
                    <div id="selectedRoomsSummaryCalendar" style="margin-top: 8px; font-size: 0.85rem; color: #6366f1;"></div>
                </div>

                <!-- GUESTS -->
                <div class="input-compact">
                    <label>Guests</label>
                    <div class="guest-inputs">
                        <input type="number" id="adultCount" name="adult_count" value="1" min="1" style="flex:1;">
                        <span style="font-size:0.75rem; color:#888; padding:0 4px;">adult</span>
                    </div>
                </div>

                <!-- SOURCE & PAYMENT METHOD -->
                <div class="form-row-2col">
                    <div class="input-compact">
                        <label>Booking Source</label>
                        <select id="bookingSource" name="booking_source" onchange="updateSourceDetails()">
                            <option value="walk_in">Direct (Walk-in)</option>
                            <option value="phone">Direct (Phone)</option>
                            <option value="agoda">Agoda</option>
                            <option value="booking">Booking.com</option>
                            <option value="tiket">Tiket.com</option>
                        </select>
                    </div>
                    <div class="input-compact">
                        <label>Payment Method</label>
                        <select name="payment_method" id="paymentMethod" onchange="calculateFinalPrice()">
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
                        <strong id="totalRoomsDisplayCalendar">0 rooms</strong>
                    </div>
                    <div class="price-line">
                        <span>Nights:</span>
                        <strong id="displayNights">0</strong>
                    </div>
                    <div class="price-line">
                        <span>Subtotal:</span>
                        <strong id="subtotalDisplayCalendar">Rp 0</strong>
                    </div>
                    <div class="price-line">
                        <span>Discount (Rp):</span>
                        <input type="number" id="discount" name="discount" value="0" onchange="calculateMultiRoomTotalCalendar()" style="text-align:right; width: 150px;">
                    </div>
                    <div class="price-line-total">
                        <span>GRAND TOTAL:</span>
                        <strong id="grandTotalDisplayCalendar" style="color:#10b981; font-size: 1.3rem;">Rp 0</strong>
                    </div>
                </div>

                <!-- PAYMENT -->
                <div class="input-compact">
                    <label>Initial Payment (DP) - Rp</label>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <input type="number" id="paidAmount" name="paid_amount" value="0" placeholder="0" style="flex: 1;">
                        <button type="button" onclick="payFullMultiRoomCalendar()" class="btn-pay-all" title="Pay Full Amount">Pay All</button>
                    </div>
                </div>
            </div>

            <div class="modal-footer-compact">
                <button type="button" class="btn-cancel" onclick="closeReservationModal()">Cancel</button>
                <button type="submit" class="btn-save">Save Reservation</button>
            </div>
        </form>
    </div>
</div>

<style>
/* COMPACT RESERVATION MODAL STYLES */
.modal-compact {
    width: 90%;
    max-width: 600px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    background: white;
    border-radius: 12px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    padding: 0;
    overflow: hidden;
}

body[data-theme="dark"] .modal-compact {
    background: #1e293b;
}

.modal-header-compact {
    padding: 1rem 1.5rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border-bottom: none;
}

.modal-header-compact h2 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
}

.form-compact {
    padding: 1.5rem;
    overflow-y: auto;
    flex: 1;
}

.form-row-2col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1rem;
}

.input-compact {
    display: flex;
    flex-direction: column;
}

.input-compact label {
    font-size: 0.85rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    color: #475569;
}

body[data-theme="dark"] .input-compact label {
    color: #cbd5e1;
}

.input-compact input,
.input-compact select {
    padding: 0.5rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    font-size: 0.9rem;
    font-family: inherit;
}

body[data-theme="dark"] .input-compact input,
body[data-theme="dark"] .input-compact select {
    background: #334155;
    border-color: #475569;
    color: white;
}

.input-compact input:focus,
.input-compact select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
}

.guest-inputs {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.guest-inputs input {
    padding: 0.4rem;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    font-size: 0.85rem;
}

.price-summary-compact {
    background: #f1f5f9;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 1rem;
    font-size: 0.9rem;
}

body[data-theme="dark"] .price-summary-compact {
    background: #334155;
}

.price-line {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    align-items: center;
}

.price-line input {
    width: 150px;
    padding: 0.25rem;
    border: 1px solid #cbd5e1;
    border-radius: 4px;
    font-size: 0.85rem;
    text-align: right;
}

body[data-theme="dark"] .price-line input {
    background: #1e293b;
    border-color: #475569;
    color: white;
}

.price-line-total {
    display: flex;
    justify-content: space-between;
    font-weight: 700;
    font-size: 1rem;
    padding-top: 0.5rem;
    border-top: 2px solid #cbd5e1;
}

body[data-theme="dark"] .price-line-total {
    border-color: #475569;
}

.modal-footer-compact {
    padding: 1rem 1.5rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

body[data-theme="dark"] .modal-footer-compact {
    background: #0f172a;
    border-color: #334155;
}

.btn-cancel {
    padding: 0.6rem 1.5rem;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.2s;
}

body[data-theme="dark"] .btn-cancel {
    background: #334155;
    border-color: #475569;
    color: white;
}

.btn-pay-all {
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s;
    white-space: nowrap;
}

.btn-pay-all:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

body[data-theme="dark"] .btn-pay-all {
    background: linear-gradient(135deg, #10b981, #059669);
}

.btn-cancel:hover {
    background: #f1f5f9;
}

.btn-save {
    padding: 0.6rem 2rem;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.9rem;
    transition: all 0.3s;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 20px rgba(99,102,241,0.3);
}

.btn-save:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Scrollbar styling */
.form-compact::-webkit-scrollbar {
    width: 6px;
}

.form-compact::-webkit-scrollbar-track {
    background: transparent;
}

.form-compact::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.form-compact::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<style>
/* SYSTEM 2028 STYLES */
.glass-panel {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    border-radius: 20px;
    padding: 0 !important;
    overflow: hidden;
}

body[data-theme="dark"] .glass-panel {
    background: rgba(30, 41, 59, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.05);
}

.modal-header {
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
    border-bottom: 1px solid rgba(0,0,0,0.05);
}

.modal-header h2 {
    margin: 0;
    font-size: 1.5rem;
    background: linear-gradient(to right, #6366f1, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.form-grid-2028 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    padding: 2rem;
    overflow-y: auto; /* Enable scroll here */
    flex: 1; /* Take remaining space */
}

/* Scrollbar for form grid */
.form-grid-2028::-webkit-scrollbar {
    width: 6px;
}
.form-grid-2028::-webkit-scrollbar-track {
    background: rgba(99, 102, 241, 0.05);
}
.form-grid-2028::-webkit-scrollbar-thumb {
    background: rgba(99, 102, 241, 0.2);
    border-radius: 3px;
}
.form-grid-2028::-webkit-scrollbar-thumb:hover {
    background: rgba(99, 102, 241, 0.4);
}

@media (max-width: 768px) {
    .form-grid-2028 { grid-template-columns: 1fr; gap: 1rem; padding: 1rem; }
}

.form-section-modern {
    margin-bottom: 2rem;
}

.form-section-modern h3 {
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #64748b;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.input-group-modern {
    margin-bottom: 1rem;
}

.input-group-modern label {
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 0.4rem;
    color: var(--text-secondary);
}

.input-group-modern input,
.input-group-modern select {
    width: 100%;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background: var(--input-bg);
    color: var(--text-primary);
    transition: all 0.2s;
}

.input-group-modern input:focus,
.input-group-modern select:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    outline: none;
}

.date-range-modern {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.price-card-2028 {
    background: var(--bg-secondary);
    border-radius: 16px;
    padding: 1.5rem;
    border: 1px solid var(--border-color);
}

.price-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.price-row input {
    width: 100px;
    text-align: right;
    background: transparent;
    border: 1px solid transparent;
    color: var(--text-primary);
    font-weight: 600;
}

.price-row input:hover {
    border-color: var(--border-color);
    background: var(--input-bg);
    border-radius: 4px;
}

.total-display {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px dashed var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.total-display strong {
    font-size: 1.5rem;
    color: #6366f1;
}

.payment-methods-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 0.5rem;
    margin-top: 1rem;
}

.pm-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 0.25rem;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
    background: var(--card-bg);
}

.pm-item.active {
    background: rgba(99, 102, 241, 0.1);
    border-color: #6366f1;
    color: #6366f1;
}

.pm-icon { font-size: 1.25rem; margin-bottom: 0.25rem; }
.pm-name { font-size: 0.7rem; font-weight: 600; }

.modal-footer-modern {
    padding: 1rem 2rem;
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
    background: white; /* Ensure opaque background */
    border-top: 1px solid var(--border-color);
    flex-shrink: 0;
    z-index: 10;
}

body[data-theme="dark"] .modal-footer-modern {
    background: #1e293b;
}

.modal-header {
    flex-shrink: 0;
}

.btn-glow {
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    border: none;
    padding: 0.75rem 2rem;
    border-radius: 12px;
    font-weight: 600;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
    cursor: pointer;
    transition: all 0.2s;
}

.btn-glow:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
}

.btn-ghost {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    font-weight: 600;
    cursor: pointer;
    padding: 0.75rem 1.5rem;
}

.pax-inputs {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.pax-inputs input {
    width: 60px;
    text-align: center;
    padding: 0.5rem;
    border-radius: 8px;
    border: 1px solid var(--border-color);
    background: var(--input-bg);
    color: var(--text-primary);
}

.fee-badge {
    display: inline-block;
    background: rgba(244, 63, 94, 0.1);
    color: #f43f5e;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 700;
    margin-top: 0.5rem;
}
</style>
<!-- END RESERVATION MODAL -->




<div id="bookingDetailsModal" class="modal-overlay" style="display: none !important;">
    <div class="modal-content modal-content-medium"></div>
</div>

<!-- Quick View Modal - Compact & Elegant -->
<div id="bookingQuickView" class="modal-overlay">
    <div class="quick-view-popup">
        <button class="quick-view-close" onclick="closeBookingQuickView()">×</button>
        <div id="qv-content">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="bookingPaymentModal" class="modal-overlay">
    <div class="payment-modal">
        <button class="payment-modal-close" onclick="closeBookingPaymentModal()">×</button>
        <div class="payment-modal-header">
            <h3>Payment</h3>
            <p id="paymentModalSubtitle">Pembayaran booking</p>
        </div>
        <div class="payment-modal-body">
            <div class="payment-info">
                <div><span>Total:</span> <strong id="paymentTotal">Rp 0</strong></div>
                <div><span>Sudah Bayar:</span> <strong id="paymentPaid">Rp 0</strong></div>
                <div><span>Sisa:</span> <strong id="paymentRemaining">Rp 0</strong></div>
            </div>
            <div class="form-group">
                <label>Metode Pembayaran</label>
                <input type="hidden" id="paymentMethodPay" value="cash">
                <div class="payment-method-group">
                    <button type="button" class="payment-method-btn" data-value="ota">OTA</button>
                    <button type="button" class="payment-method-btn active" data-value="cash">Cash</button>
                    <button type="button" class="payment-method-btn" data-value="transfer">Transfer</button>
                    <button type="button" class="payment-method-btn" data-value="qris">QR</button>
                </div>
            </div>
            <div class="form-group">
                <label>Jumlah Bayar (Rp)</label>
                <input type="number" id="paymentAmount" min="0" value="0">
            </div>
            <div class="payment-modal-actions">
                <button type="button" class="btn-secondary" onclick="closeBookingPaymentModal()">Cancel</button>
                <button type="button" class="btn-primary" onclick="submitBookingPayment()">Pay</button>
            </div>
        </div>
    </div>
</div>

<style>
/* RESERVATION MODAL STYLES */
#reservationModal {
    display: none; /* Changed from none!important to allow flex via JS */
    align-items: center;
    justify-content: center;
    z-index: 9999;
}

#reservationModal.active {
    display: flex !important;
}

.booking-summary-box {
    background: #f8fafc;
    padding: 1rem;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
}

.summary-input {
    width: 120px !important;
    text-align: right;
    padding: 0.25rem 0.5rem !important;
}

.total-row {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px dashed #cbd5e1;
    font-size: 1.1rem;
    color: #6366f1;
}

/* Modal Overlay Base Styles */
.modal-overlay {
    display: none; /* Hidden by default */
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 99999; /* High Z-index */
    align-items: center;
    justify-content: center;
    transition: opacity 0.3s ease;
    opacity: 0;
    pointer-events: none;
}

/* Active State for Modals */
.modal-overlay.active {
    display: flex !important;
    opacity: 1;
    pointer-events: auto;
}

/* Quick View Popup - Simple & Elegant */
.quick-view-popup {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 380px;
    padding: 1.25rem;
    position: relative;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(99, 102, 241, 0.1);
    animation: quickViewIn 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    max-height: 90vh;
    overflow-y: auto;
}

@keyframes quickViewIn {
    from {
        transform: scale(0.9);
        opacity: 0;
    }
    to {
        transform: scale(1);
        opacity: 1;
    }
}

.quick-view-close {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    background: rgba(239, 68, 68, 0.1);
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 1.5rem;
    cursor: pointer;
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    line-height: 1;
    font-weight: 300;
}

.quick-view-close:hover {
    background: #ef4444;
    color: white;
    transform: rotate(90deg);
}

.qv-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.qv-btn {
    border: 1px solid #ddd;
    background: white;
    color: #333;
    padding: 0.45rem 0.75rem;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}

.qv-btn:hover {
    background: #f3f4f6;
    border-color: #999;
}

.qv-pay-btn {
    background: #10b981;
    color: white;
    border-color: #10b981;
}

.qv-pay-btn:hover {
    background: #059669;
    border-color: #059669;
}

.qv-checkin-btn {
    background: #3b82f6;
    color: white;
    border-color: #3b82f6;
}

.qv-checkin-btn:hover {
    background: #2563eb;
    border-color: #2563eb;
}

.qv-move-btn {
    background: #8b5cf6;
    color: white;
    border-color: #8b5cf6;
}

.qv-move-btn:hover {
    background: #7c3aed;
    border-color: #7c3aed;
}

.payment-modal {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 420px;
    padding: 1.25rem;
    position: relative;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(99, 102, 241, 0.1);
}

.payment-modal-close {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    background: rgba(239, 68, 68, 0.1);
    border: none;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 1.5rem;
    cursor: pointer;
    color: #ef4444;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    line-height: 1;
    font-weight: 300;
}

.payment-modal-header {
    text-align: center;
    margin-bottom: 0.75rem;
}

.payment-modal-header h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
}

.payment-modal-header p {
    margin: 0.25rem 0 0;
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.payment-info {
    background: rgba(99, 102, 241, 0.05);
    border-radius: 8px;
    padding: 0.75rem;
    font-size: 0.8rem;
    line-height: 1.5;
    margin-bottom: 0.75rem;
}

.payment-info span {
    color: var(--text-secondary);
}

.payment-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    margin-top: 0.75rem;
}

/* Scrollbar styling for popup */
.quick-view-popup::-webkit-scrollbar {
    width: 6px;
}

.quick-view-popup::-webkit-scrollbar-track {
    background: rgba(99, 102, 241, 0.05);
    border-radius: 3px;
}

.quick-view-popup::-webkit-scrollbar-thumb {
    background: rgba(99, 102, 241, 0.3);
    border-radius: 3px;
}

.quick-view-popup::-webkit-scrollbar-thumb:hover {
    background: rgba(99, 102, 241, 0.5);
}
</style>

<!-- EXTEND STAY MODAL -->
<div id="extendModal" class="extend-modal-overlay" onclick="if(event.target===this)closeExtendModal()">
    <div class="extend-modal">
        <h3>➕ Extend Stay</h3>
        <input type="hidden" id="extendBookingId">
        <div style="background:rgba(16,185,129,0.08); border-radius:8px; padding:0.75rem; margin-bottom:0.75rem; font-size:0.8rem;">
            <div><strong>Guest:</strong> <span id="extendGuestName">-</span></div>
            <div><strong>Current Check-out:</strong> <span id="extendCurrentCO">-</span></div>
        </div>
        <div class="form-group">
            <label>Tambah Malam</label>
            <div style="display:flex; gap:0.5rem; align-items:center;">
                <button type="button" onclick="adjustExtendNights(-1)" style="width:32px;height:32px;border:1px solid var(--border-color);border-radius:6px;background:transparent;cursor:pointer;font-size:1rem;font-weight:700;color:var(--text-primary);">−</button>
                <input type="number" id="extendNights" value="1" min="1" max="30" style="width:60px;text-align:center;">
                <button type="button" onclick="adjustExtendNights(1)" style="width:32px;height:32px;border:1px solid var(--border-color);border-radius:6px;background:transparent;cursor:pointer;font-size:1rem;font-weight:700;color:var(--text-primary);">+</button>
                <span style="font-size:0.8rem;color:var(--text-secondary);margin-left:0.5rem;">New CO: <strong id="extendNewCO">-</strong></span>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeExtendModal()">Batal</button>
            <button class="btn-confirm" onclick="submitExtendStay()">Extend Stay</button>
        </div>
    </div>
</div>

<!-- EDIT RESERVATION MODAL -->
<div id="editResModal" class="edit-res-overlay" onclick="if(event.target===this)closeEditResModal()">
    <div class="edit-res-modal">
        <h3>✏️ Edit Reservasi</h3>
        <input type="hidden" id="editResBookingId">
        <div class="form-row">
            <div class="form-group">
                <label>Nama Tamu</label>
                <input type="text" id="editResGuestName">
            </div>
            <div class="form-group">
                <label>Telepon</label>
                <input type="text" id="editResGuestPhone">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Check-in</label>
                <input type="date" id="editResCheckIn">
            </div>
            <div class="form-group">
                <label>Check-out</label>
                <input type="date" id="editResCheckOut">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="editResEmail">
            </div>
            <div class="form-group">
                <label>No. KTP/Paspor</label>
                <input type="text" id="editResIdNumber">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Jumlah Tamu</label>
                <input type="number" id="editResNumGuests" min="1" max="10" value="1">
            </div>
            <div class="form-group">
                <label>Harga/Malam</label>
                <input type="number" id="editResRoomPrice">
            </div>
        </div>
        <div class="form-group">
            <label>Permintaan Khusus</label>
            <textarea id="editResSpecialRequests"></textarea>
        </div>
        <div id="editResInfo" style="background:rgba(99,102,241,0.06);border-radius:8px;padding:0.6rem;font-size:0.8rem;color:var(--text-secondary);"></div>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeEditResModal()">Batal</button>
            <button class="btn-save" onclick="submitEditReservation()">💾 Simpan</button>
        </div>
    </div>
</div>

<script>
// ===== DRAG & DROP BOOKING BARS =====
(function() {
    let dragData = null;

    // Setup drag events on booking bars
    document.addEventListener('dragstart', function(e) {
        const container = e.target.closest('.booking-bar-container[draggable="true"]');
        if (!container) return;
        
        dragData = {
            bookingId: container.dataset.bookingId,
            roomId: container.dataset.roomId,
            checkIn: container.dataset.checkIn,
            checkOut: container.dataset.checkOut,
            nights: parseInt(container.dataset.nights),
            guest: container.dataset.guest
        };
        
        container.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragData.bookingId);
        
        // Show drop zones
        document.querySelectorAll('.grid-date-cell').forEach(cell => {
            cell.style.transition = 'background 0.15s ease';
        });
    });

    document.addEventListener('dragend', function(e) {
        const container = e.target.closest('.booking-bar-container');
        if (container) container.classList.remove('dragging');
        
        // Clean up all drag styling
        document.querySelectorAll('.grid-date-cell').forEach(cell => {
            cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
        });
        dragData = null;
    });

    document.addEventListener('dragover', function(e) {
        const cell = e.target.closest('.grid-date-cell');
        if (!cell || !dragData) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        // Highlight drop target
        document.querySelectorAll('.grid-date-cell.drag-over').forEach(c => {
            c.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
        });
        cell.classList.add('drag-over');
    });

    document.addEventListener('dragleave', function(e) {
        const cell = e.target.closest('.grid-date-cell');
        if (cell) {
            cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
        }
    });

    document.addEventListener('drop', function(e) {
        const cell = e.target.closest('.grid-date-cell');
        if (!cell || !dragData) return;
        e.preventDefault();
        
        const newDate = cell.dataset.date;
        const newRoomId = cell.dataset.roomId;
        
        if (!newDate || !newRoomId) return;
        
        // Calculate new check-in and check-out
        const newCheckIn = newDate;
        const ciDate = new Date(newCheckIn);
        ciDate.setDate(ciDate.getDate() + dragData.nights);
        const newCheckOut = ciDate.toISOString().split('T')[0];
        
        // Confirm move
        const confirmMsg = `Pindahkan booking ${dragData.guest}?\n\nDari: ${dragData.checkIn} → ${dragData.checkOut}\nKe: ${newCheckIn} → ${newCheckOut}\nRoom: ${cell.dataset.roomNumber || 'Room ' + newRoomId}`;
        
        if (!confirm(confirmMsg)) {
            cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
            return;
        }
        
        // API call to move booking
        const formData = new FormData();
        formData.append('booking_id', dragData.bookingId);
        formData.append('new_check_in', newCheckIn);
        formData.append('new_check_out', newCheckOut);
        formData.append('new_room_id', newRoomId);
        
        fetch('../../api/move-booking.php', {
            method: 'POST',
            body: formData
        })
        .then(r => {
            if (!r.ok) {
                return r.text().then(text => {
                    throw { status: r.status, statusText: r.statusText, body: text };
                });
            }
            return r.json().catch(err => {
                throw { parseError: true, message: 'Response bukan JSON', body: text };
            });
        })
        .then(data => {
            if (data.success) {
                alert('✅ ' + data.message + '\n\nHarga baru: Rp ' + new Intl.NumberFormat('id-ID').format(data.data.final_price));
                location.reload();
            } else {
                alert('❌ ' + data.message);
            }
        })
        .catch(err => {
            if (err.status) {
                console.error('API Error:', err);
                alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
            } else if (err.parseError) {
                console.error('Parse Error:', err);
                alert('❌ Respons server tidak valid:\n' + err.body.substring(0, 300));
            } else {
                alert('❌ Error: ' + err.message);
            }
        });
        
        // Clean up
        cell.classList.remove('drag-over', 'drag-over-valid', 'drag-over-invalid');
    });
})();

// ===== EXTEND STAY FUNCTIONS =====
let extendCurrentCO = '';

window.openExtendModal = function(bookingId, guestName, checkoutDate, nights) {
    document.getElementById('extendBookingId').value = bookingId;
    document.getElementById('extendGuestName').textContent = guestName;
    document.getElementById('extendCurrentCO').textContent = new Date(checkoutDate).toLocaleDateString('id-ID', {day:'numeric', month:'short', year:'numeric'});
    document.getElementById('extendNights').value = 1;
    extendCurrentCO = checkoutDate;
    updateExtendPreview();
    document.getElementById('extendModal').classList.add('active');
};

window.closeExtendModal = function() {
    document.getElementById('extendModal').classList.remove('active');
};

window.adjustExtendNights = function(delta) {
    const input = document.getElementById('extendNights');
    let val = parseInt(input.value) + delta;
    if (val < 1) val = 1;
    if (val > 30) val = 30;
    input.value = val;
    updateExtendPreview();
};

document.addEventListener('change', function(e) {
    if (e.target.id === 'extendNights') updateExtendPreview();
});

function updateExtendPreview() {
    const nights = parseInt(document.getElementById('extendNights').value) || 1;
    const co = new Date(extendCurrentCO);
    co.setDate(co.getDate() + nights);
    document.getElementById('extendNewCO').textContent = co.toLocaleDateString('id-ID', {day:'numeric', month:'short', year:'numeric'});
}

window.submitExtendStay = function() {
    const bookingId = document.getElementById('extendBookingId').value;
    const nights = document.getElementById('extendNights').value;
    
    if (!bookingId || nights < 1) return;
    
    const formData = new FormData();
    formData.append('booking_id', bookingId);
    formData.append('extra_nights', nights);
    
    fetch('../../api/extend-stay.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => {
                throw { status: r.status, statusText: r.statusText, body: text };
            });
        }
        return r.json().catch(err => {
            throw { parseError: true, message: 'Response bukan JSON' };
        });
    })
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message + '\n\nTambahan: Rp ' + new Intl.NumberFormat('id-ID').format(data.data.additional_price));
            closeExtendModal();
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    })
    .catch(err => {
        if (err.status) {
            console.error('API Error:', err);
            alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
        } else if (err.parseError) {
            console.error('Parse Error:', err);
            alert('❌ Respons server tidak valid');
        } else {
            alert('❌ Error: ' + err.message);
        }
    });
};

// ===== EDIT RESERVATION FUNCTIONS =====
window.openEditReservationModal = function(bookingId) {
    // Fetch booking details
    fetch('../../api/get-booking-details.php?id=' + bookingId)
    .then(r => {
        // Check status code
        if (!r.ok) {
            return r.text().then(text => {
                throw { 
                    status: r.status, 
                    statusText: r.statusText, 
                    body: text 
                };
            });
        }
        return r.text();
    })
    .then(text => {
        let data;
        try { data = JSON.parse(text); } catch(e) {
            console.error('❌ JSON Parse Error:', e);
            console.error('Raw response:', text);
            
            // Try to extract error message from HTML error response
            let errorMsg = 'Server Error: Respons bukan JSON';
            if (text.includes('Fatal error') || text.includes('Error')) {
                const match = text.match(/Fatal error.*?:<\/b>\s*(.+?)(?:<br|<\/|$)/i);
                if (match) errorMsg = 'Server Error: ' + match[1];
            }
            
            alert('❌ ' + errorMsg + '\n\nSilakan cek console browser untuk detail lengkap.');
            console.error('Full error response:', text);
            return;
        }
        if (!data.success) {
            alert('❌ ' + (data.message || 'Gagal load data'));
            return;
        }
        const b = data.booking;
        document.getElementById('editResBookingId').value = b.id;
        document.getElementById('editResGuestName').value = b.guest_name || '';
        document.getElementById('editResGuestPhone').value = b.guest_phone || '';
        document.getElementById('editResEmail').value = b.guest_email || '';
        document.getElementById('editResIdNumber').value = b.guest_id_number || '';
        document.getElementById('editResCheckIn').value = b.check_in_date;
        document.getElementById('editResCheckOut').value = b.check_out_date;
        document.getElementById('editResNumGuests').value = b.num_guests || b.adults || 1;
        document.getElementById('editResRoomPrice').value = b.room_price || '';
        document.getElementById('editResSpecialRequests').value = b.special_requests || '';
        
        updateEditResInfo();
        document.getElementById('editResModal').classList.add('active');
    })
    .catch(err => {
        if (err.status) {
            // HTTP Error
            let errorMsg = '❌ Error ' + err.status + ' (' + err.statusText + '):\n' + err.body.substring(0, 200);
            console.error('HTTP Error Response:', err);
            alert(errorMsg);
        } else {
            // Network error
            alert('❌ Network Error: ' + err.message);
        }
    });
};

window.closeEditResModal = function() {
    document.getElementById('editResModal').classList.remove('active');
};

function updateEditResInfo() {
    const ci = document.getElementById('editResCheckIn').value;
    const co = document.getElementById('editResCheckOut').value;
    const price = parseFloat(document.getElementById('editResRoomPrice').value) || 0;
    
    if (ci && co) {
        const nights = Math.ceil((new Date(co) - new Date(ci)) / 86400000);
        const total = price * nights;
        document.getElementById('editResInfo').innerHTML = `
            <strong>${nights} malam</strong> × Rp ${new Intl.NumberFormat('id-ID').format(price)} = <strong>Rp ${new Intl.NumberFormat('id-ID').format(total)}</strong>
        `;
    }
}

// Live update edit form info
['editResCheckIn', 'editResCheckOut', 'editResRoomPrice'].forEach(id => {
    document.addEventListener('input', function(e) {
        if (e.target.id === id) updateEditResInfo();
    });
});

window.submitEditReservation = function() {
    const bookingId = document.getElementById('editResBookingId').value;
    if (!bookingId) return;
    
    const formData = new FormData();
    formData.append('booking_id', bookingId);
    formData.append('guest_name', document.getElementById('editResGuestName').value);
    formData.append('guest_phone', document.getElementById('editResGuestPhone').value);
    formData.append('guest_email', document.getElementById('editResEmail').value);
    formData.append('guest_id_number', document.getElementById('editResIdNumber').value);
    formData.append('check_in_date', document.getElementById('editResCheckIn').value);
    formData.append('check_out_date', document.getElementById('editResCheckOut').value);
    formData.append('num_guests', document.getElementById('editResNumGuests').value);
    formData.append('room_price', document.getElementById('editResRoomPrice').value);
    formData.append('special_requests', document.getElementById('editResSpecialRequests').value);
    
    fetch('../../api/update-reservation.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) {
            return r.text().then(text => {
                throw { status: r.status, statusText: r.statusText, body: text };
            });
        }
        return r.json().catch(err => {
            throw { parseError: true, message: 'Response bukan JSON' };
        });
    })
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            closeEditResModal();
            location.reload();
        } else {
            alert('❌ ' + data.message);
        }
    })
    .catch(err => {
        if (err.status) {
            console.error('API Error:', err);
            alert('❌ Error ' + err.status + ':\n' + err.body.substring(0, 300));
        } else if (err.parseError) {
            console.error('Parse Error:', err);
            alert('❌ Respons server tidak valid');
        } else {
            alert('❌ Error: ' + err.message);
        }
    });
};
</script>

<?php include '../../includes/footer.php'; ?>


