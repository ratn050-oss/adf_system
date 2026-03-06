<?php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!$auth->hasPermission('frontdesk') && !$auth->hasPermission('admin') && !$auth->hasPermission('manager')) {
    header('Location: ' . BASE_URL . '/403.php');
    exit;
}

$pageTitle = "Kelola Pembayaran OTA";
require_once '../../includes/header.php';
?>

<style>
.pending-ota-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1rem;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1.5rem;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.summary-card h3 {
    margin: 0 0 0.5rem 0;
    font-size: 2rem;
    font-weight: bold;
}

.summary-card p {
    margin: 0;
    opacity: 0.9;
    font-size: 0.9rem;
}

.platform-card {
    background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
}

.filters {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 2rem;
    display: flex;
    gap: 1rem;
    align-items: end;
    flex-wrap: wrap;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.filter-group label {
    font-weight: 600;
    color: #374151;
    font-size: 0.875rem;
}

.filter-group input, .filter-group select {
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    min-width: 140px;
}

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-success {
    background: #10b981;
    color: white;
}

.btn-warning {
    background: #f59e0b;
    color: white;
}

.btn-small {
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
}

.pending-table {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

.pending-table table {
    width: 100%;
    border-collapse: collapse;
}

.pending-table th {
    background: #f8fafc;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}

.pending-table td {
    padding: 1rem;
    border-bottom: 1px solid #f3f4f6;
}

.pending-table tr:hover {
    background: #f8fafc;
}

.booking-code {
    font-weight: 600;
    color: #1f2937;
}

.platform-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    background: #dbeafe;
    color: #1e40af;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.amount {
    font-weight: 600;
    color: #059669;
}

.days-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
    font-weight: 600;
}

.days-new { background: #dcfce7; color: #166534; }
.days-medium { background: #fef3c7; color: #92400e; }
.days-old { background: #fee2e2; color: #991b1b; }

.loading {
    text-align: center;
    padding: 3rem;
    color: #6b7280;
}

/* Receive Payment Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
}

.modal-overlay.active {
    display: flex;
}

.modal {
    background: white;
    border-radius: 12px;
    box-shadow: 0 25px 50px rgba(0,0,0,0.3);
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header h3 {
    margin: 0;
    color: #1f2937;
    font-size: 1.25rem;
}

.modal-body {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #374151;
}

.form-group input, .form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.booking-info {
    background: #f3f4f6;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
}

.booking-info p {
    margin: 0.25rem 0;
    font-size: 0.9rem;
}

.modal-actions {
    padding: 1.5rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 0.5rem;
    justify-content: flex-end;
}

.btn-cancel {
    background: #6b7280;
    color: white;
}
</style>

<div class="pending-ota-container">
    <div class="page-header">
        <h1>💳 Kelola Pembayaran OTA</h1>
        <p>Pantau dan terima pembayaran dari platform OTA yang sudah check-in tapi belum transfer ke hotel</p>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards" id="summaryCards">
        <div class="summary-card">
            <h3 id="totalBookings">-</h3>
            <p>Booking Pending</p>
        </div>
        <div class="summary-card platform-card">
            <h3 id="totalAmount">Rp -</h3>
            <p>Total Nilai</p>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div class="filter-group">
            <label>Dari Tanggal</label>
            <input type="date" id="filterFrom" value="<?php echo date('Y-m-01'); ?>">
        </div>
        <div class="filter-group">
            <label>Sampai Tanggal</label>
            <input type="date" id="filterTo" value="<?php echo date('Y-m-t'); ?>">
        </div>
        <div class="filter-group">
            <label>Platform</label>
            <select id="filterPlatform">
                <option value="">Semua Platform</option>
                <option value="booking">Booking.com</option>
                <option value="agoda">Agoda</option>
                <option value="traveloka">Traveloka</option>
                <option value="tiket">Tiket.com</option>
                <option value="pegipegi">PegiPegi</option>
                <option value="expedia">Expedia</option>
            </select>
        </div>
        <div class="filter-group">
            <button class="btn btn-primary" onclick="loadPendingPayments()">
                🔍 Filter
            </button>
        </div>
        <div class="filter-group">
            <button class="btn btn-success" onclick="loadPendingPayments(true)">
                🔄 Refresh
            </button>
        </div>
    </div>

    <!-- Pending Payments Table -->
    <div class="pending-table">
        <table>
            <thead>
                <tr>
                    <th>Booking</th>
                    <th>Tamu</th>
                    <th>Platform</th>
                    <th>Check-in</th>
                    <th>Jumlah</th>
                    <th>Hari</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody id="pendingTableBody">
                <tr>
                    <td colspan="7" class="loading">Memuat data...</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Receive Payment Modal -->
<div id="receivePaymentModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h3>💰 Terima Pembayaran OTA</h3>
        </div>
        <div class="modal-body">
            <div class="booking-info" id="bookingInfo">
                <!-- Booking info will be populated here -->
            </div>
            
            <div class="form-group">
                <label>Tanggal Diterima</label>
                <input type="date" id="receivedDate" value="<?php echo date('Y-m-d'); ?>">
            </div>
            
            <div class="form-group">
                <label>Jumlah Diterima (Rp)</label>
                <input type="number" id="actualAmount" placeholder="Kosongkan jika sama dengan jumlah booking">
                <small style="color: #6b7280;">Kosongkan jika jumlah sama dengan yang diharapkan</small>
            </div>
            
            <div class="form-group">
                <label>Catatan</label>
                <textarea id="receiveNotes" placeholder="Catatan tambahan (opsional)"></textarea>
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-cancel" onclick="closeReceiveModal()">Batal</button>
            <button class="btn btn-success" id="btnConfirmReceive" onclick="confirmReceivePayment()">
                ✅ Terima Pembayaran
            </button>
        </div>
    </div>
</div>

<script>
let currentBookingData = null;

// Load on page load
document.addEventListener('DOMContentLoaded', function() {
    loadPendingPayments();
});

function loadPendingPayments(clearCache = false) {
    const from = document.getElementById('filterFrom').value;
    const to = document.getElementById('filterTo').value;
    const platform = document.getElementById('filterPlatform').value;
    
    const url = `../../api/get-pending-ota-payments.php?from=${from}&to=${to}&platform=${platform}${clearCache ? '&_=' + Date.now() : ''}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPendingPayments(data.data);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading data');
        });
}

function displayPendingPayments(data) {
    // Update summary
    document.getElementById('totalBookings').textContent = data.summary.total_bookings;
    document.getElementById('totalAmount').textContent = 'Rp ' + formatRupiah(data.summary.total_amount);
    
    // Update table
    const tbody = document.getElementById('pendingTableBody');
    
    if (data.pending_payments.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #6b7280;">Tidak ada pembayaran OTA yang pending</td></tr>';
        return;
    }
    
    tbody.innerHTML = data.pending_payments.map(payment => {
        const daysSince = parseInt(payment.days_since_checkin);
        let daysBadge = 'days-new';
        let daysText = 'Baru';
        
        if (daysSince > 7) {
            daysBadge = 'days-old';
            daysText = `${daysSince} hari`;
        } else if (daysSince > 3) {
            daysBadge = 'days-medium';
            daysText = `${daysSince} hari`;
        } else if (daysSince > 0) {
            daysText = `${daysSince} hari`;
        }
        
        return `
            <tr>
                <td>
                    <div class="booking-code">${payment.booking_code}</div>
                    <small>Room ${payment.room_number}</small>
                </td>
                <td>${payment.guest_name}</td>
                <td>
                    <span class="platform-badge">${payment.booking_source}</span>
                </td>
                <td>${formatDateID(payment.check_in_date)}</td>
                <td class="amount">Rp ${formatRupiah(payment.pending_amount)}</td>
                <td>
                    <span class="days-badge ${daysBadge}">${daysText}</span>
                </td>
                <td>
                    <button class="btn btn-success btn-small" onclick="openReceiveModal(${payment.booking_id})">
                        💰 Terima
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

function openReceiveModal(bookingId) {
    // Find booking data
    fetch(`../../api/get-pending-ota-payments.php?from=${document.getElementById('filterFrom').value}&to=${document.getElementById('filterTo').value}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const booking = data.data.pending_payments.find(p => p.booking_id == bookingId);
                if (booking) {
                    currentBookingData = booking;
                    
                    document.getElementById('bookingInfo').innerHTML = `
                        <p><strong>Booking:</strong> ${booking.booking_code}</p>
                        <p><strong>Tamu:</strong> ${booking.guest_name}</p>
                        <p><strong>Platform:</strong> ${booking.booking_source}</p>
                        <p><strong>Room:</strong> ${booking.room_number}</p>
                        <p><strong>Check-in:</strong> ${formatDateID(booking.check_in_date)}</p>
                        <p><strong>Jumlah Pending:</strong> <span class="amount">Rp ${formatRupiah(booking.pending_amount)}</span></p>
                    `;
                    
                    // Clear form
                    document.getElementById('actualAmount').value = '';
                    document.getElementById('receiveNotes').value = '';
                    
                    document.getElementById('receivePaymentModal').classList.add('active');
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

function closeReceiveModal() {
    document.getElementById('receivePaymentModal').classList.remove('active');
    currentBookingData = null;
}

function confirmReceivePayment() {
    if (!currentBookingData) return;
    
    const btn = document.getElementById('btnConfirmReceive');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    const requestData = {
        booking_id: currentBookingData.booking_id,
        received_date: document.getElementById('receivedDate').value,
        notes: document.getElementById('receiveNotes').value
    };
    
    const actualAmount = document.getElementById('actualAmount').value;
    if (actualAmount) {
        requestData.actual_amount = parseFloat(actualAmount);
    }
    
    fetch('../../api/receive-ota-payment.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(requestData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            closeReceiveModal();
            loadPendingPayments(true); // Refresh data
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error processing payment');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = '✅ Terima Pembayaran';
    });
}

function formatRupiah(amount) {
    return parseFloat(amount).toLocaleString('id-ID');
}

function formatDateID(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', { 
        day: 'numeric', 
        month: 'short', 
        year: 'numeric' 
    });
}

// Close modal on outside click
document.getElementById('receivePaymentModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeReceiveModal();
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>