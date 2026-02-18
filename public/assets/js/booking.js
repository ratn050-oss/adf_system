/**
 * BOOKING PAGE - JavaScript Logic
 */

let selectedRoom = null;
let searchResults = null;

document.addEventListener('DOMContentLoaded', function() {
    // Set minimum checkout date
    const checkInInput = document.getElementById('bookingCheckIn');
    const checkOutInput = document.getElementById('bookingCheckOut');
    
    checkInInput.addEventListener('change', function() {
        const minCheckOut = new Date(this.value);
        minCheckOut.setDate(minCheckOut.getDate() + 1);
        checkOutInput.min = minCheckOut.toISOString().split('T')[0];
        checkOutInput.value = '';
    });
});

/**
 * Search available rooms
 */
function searchAvailableRooms() {
    const checkIn = document.getElementById('bookingCheckIn').value;
    const checkOut = document.getElementById('bookingCheckOut').value;
    const guests = document.getElementById('bookingGuests').value;
    
    // Validate
    if (!checkIn || !checkOut) {
        showNotification('Please select check-in and check-out dates', 'error');
        return;
    }
    
    // Show loader
    const searchBtn = event.target;
    const loader = searchBtn.querySelector('#searchLoader');
    searchBtn.disabled = true;
    loader.style.display = 'inline';
    
    // Call API
    apiCall(`./api/get-available-rooms.php?check_in=${checkIn}&check_out=${checkOut}&guests=${guests}`)
        .then(response => {
            if (!response.success) {
                showNotification(response.error || 'Search failed', 'error');
                return;
            }
            
            searchResults = response.data;
            displayAvailableRooms(response.data);
            
        }).catch(error => {
            console.error(error);
            showNotification('Error searching for availability', 'error');
        }).finally(() => {
            searchBtn.disabled = false;
            loader.style.display = 'none';
        });
}

/**
 * Display available rooms
 */
function displayAvailableRooms(data) {
    const container = document.getElementById('roomsContainer');
    const listDiv = document.getElementById('availableRoomsList');
    const noRoomsDiv = document.getElementById('noRoomsMessage');
    
    container.innerHTML = '';
    selectedRoom = null;
    
    if (!data.available_rooms || data.available_rooms.length === 0) {
        listDiv.style.display = 'none';
        noRoomsDiv.style.display = 'block';
        document.getElementById('bookingSummary').style.display = 'none';
        document.getElementById('selectRoomMessage').style.display = 'block';
        return;
    }
    
    listDiv.style.display = 'block';
    noRoomsDiv.style.display = 'none';
    
    data.available_rooms.forEach((room, index) => {
        const roomCard = document.createElement('div');
        roomCard.className = 'room-option';
        roomCard.innerHTML = `
            <div class="room-option-header">
                <div class="room-option-name">${room.type_name}</div>
                <div class="room-option-price">${formatCurrency(room.total_price)}</div>
            </div>
            <div class="room-option-description">
                ${room.description || 'Room with complete facilities'}
            </div>
            <div class="room-option-availability">
                ✓ ${data.total_nights} nights @ ${formatCurrency(room.base_price)} per night
            </div>
        `;
        
        roomCard.addEventListener('click', function() {
            selectRoom(room, data);
        });
        
        container.appendChild(roomCard);
    });
}

/**
 * Select a room
 */
function selectRoom(room, searchData) {
    // Remove previous selection
    document.querySelectorAll('.room-option').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Mark this room as selected
    event.currentTarget.classList.add('selected');
    selectedRoom = room;
    
    // Update summary
    updateBookingSummary(room, searchData);
    
    // Show form
    document.getElementById('bookingSummary').style.display = 'block';
    document.getElementById('selectRoomMessage').style.display = 'none';
    
    // Scroll to form
    document.getElementById('bookingSummary').scrollIntoView({ behavior: 'smooth' });
}

/**
 * Update booking summary
 */
function updateBookingSummary(room, searchData) {
    document.getElementById('summaryRoom').textContent = room.type_name;
    document.getElementById('summaryCheckIn').textContent = formatDate(searchData.check_in);
    document.getElementById('summaryCheckOut').textContent = formatDate(searchData.check_out);
    document.getElementById('summaryNights').textContent = searchData.total_nights;
    document.getElementById('summaryRoomPrice').textContent = formatCurrency(room.base_price);
    document.getElementById('summaryTotalPrice').textContent = formatCurrency(room.total_price);
}

/**
 * Proceed to payment
 */
function proceedToPayment() {
    if (!selectedRoom || !searchResults) {
        showNotification('Please select a room first', 'error');
        return;
    }
    
    // Get guest info
    const guestName = document.getElementById('guestName').value.trim();
    const guestEmail = document.getElementById('guestEmail').value.trim();
    const guestPhone = document.getElementById('guestPhone').value.trim();
    const specialRequest = document.getElementById('guestRequest').value.trim();
    
    // Validate
    if (!guestName) {
        showNotification('Guest name is required', 'error');
        return;
    }
    
    if (!isValidEmail(guestEmail)) {
        showNotification('Invalid email address', 'error');
        return;
    }
    
    if (!isValidPhone(guestPhone)) {
        showNotification('Invalid phone number', 'error');
        return;
    }
    
    // Show loading
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i data-feather="loader" style="display: inline; margin-right: 0.5rem;"></i>Processing...';
    feather.replace();
    
    // Create booking
    const bookingData = {
        guest_name: guestName,
        guest_email: guestEmail,
        guest_phone: guestPhone,
        room_id: selectedRoom.id,
        check_in: searchResults.check_in,
        check_out: searchResults.check_out,
        guests: document.getElementById('bookingGuests').value,
        special_request: specialRequest
    };
    
    apiCall('./api/create-booking.php', 'POST', bookingData)
        .then(response => {
            if (!response.success) {
                showNotification(response.error || 'Failed to create booking', 'error');
                btn.disabled = false;
                btn.innerHTML = originalText;
                feather.replace();
                return;
            }
            
            // Store booking data for payment
            window.bookingData = response.data;
            
            // Proceed to payment
            proceedToMidtransPayment(response.data);
            
        }).catch(error => {
            console.error(error);
            showNotification('Error creating booking', 'error');
            btn.disabled = false;
            btn.innerHTML = originalText;
            feather.replace();
        });
}

/**
 * Process payment with Midtrans
 */
function proceedToMidtransPayment(bookingData) {
    // This will be implemented with Midtrans SDK
    // For now, we'll show a success message
    
    showNotification('Booking successfully created! Booking code: ' + bookingData.booking_code, 'success');
    
    // In production, redirect to payment gateway
    // Example: window.location.href = './api/payment-process.php?booking_id=' + bookingData.booking_id;
    
    // Log data for development
    console.log('Booking created:', bookingData);
}
