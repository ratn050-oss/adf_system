<?php
/**
 * CLEANUP: Remove cash_book entries for non-checked-in bookings
 * PHP-based approach to avoid collation issues
 * 
 * RULE: Hanya booking dengan status checked_in atau checked_out yang boleh ada di cash_book
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    die('Please login first');
}

$db = Database::getInstance();
$conn = $db->getConnection();

$action = $_GET['action'] ?? 'view';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Cleanup OTA Entries</title>';
echo '<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h1, h2 { color: #333; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
th { background: #dc3545; color: white; }
.valid th { background: #28a745; }
.btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; color: white; }
.btn-danger { background: #dc3545; }
.btn-info { background: #17a2b8; }
.btn-success { background: #28a745; }
.box { background: white; padding: 15px; margin: 15px 0; border-radius: 8px; }
.danger { background: #f8d7da; }
.success { background: #d4edda; }
</style></head><body>';

echo '<h1>🧹 Cleanup: Remove Invalid Cash Book Entries (OTA & Non-Checked-In)</h1>';
echo '<p>Date: ' . date('Y-m-d H:i:s') . '</p>';

echo '<div class="box">';
echo '<a class="btn btn-info" href="?action=view">👁️ View Invalid Entries</a> ';
echo '<a class="btn btn-danger" href="?action=delete" onclick="return confirm(\'HAPUS semua entry yang TIDAK VALID?\')">🗑️ DELETE Invalid Entries</a> ';
echo '</div>';

// Function to extract booking code from description
function extractBookingCode($description) {
    if (preg_match('/BK-\d+-\d+/', $description, $matches)) {
        return $matches[0];
    }
    if (preg_match('/BK-[A-Z0-9-]+/i', $description, $matches)) {
        return $matches[0];
    }
    return null;
}

// Get ALL cash_book entries with BK- in description
$allEntries = $conn->query("
    SELECT id, transaction_date, amount, payment_method, description, transaction_type
    FROM cash_book 
    WHERE description LIKE '%BK-%'
    AND transaction_type = 'income'
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Analyze each entry
$invalidEntries = [];
$validEntries = [];

foreach ($allEntries as $entry) {
    $bookingCode = extractBookingCode($entry['description']);
    
    if (!$bookingCode) {
        continue; // Skip if can't extract booking code
    }
    
    // Lookup booking by code
    $booking = $conn->query("
        SELECT id, booking_code, status, booking_source, guest_id, final_price
        FROM bookings 
        WHERE booking_code = " . $conn->quote($bookingCode) . "
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        // Booking not found - try partial match
        $booking = $conn->query("
            SELECT id, booking_code, status, booking_source, guest_id, final_price
            FROM bookings 
            WHERE booking_code LIKE " . $conn->quote('%' . $bookingCode . '%') . "
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($booking) {
        // Get guest name
        $guest = $conn->query("SELECT guest_name FROM guests WHERE id = " . (int)$booking['guest_id'])->fetch(PDO::FETCH_ASSOC);
        
        $entry['booking_code'] = $booking['booking_code'];
        $entry['booking_status'] = $booking['status'];
        $entry['booking_source'] = $booking['booking_source'];
        $entry['guest_name'] = $guest['guest_name'] ?? 'Unknown';
        $entry['final_price'] = $booking['final_price'];
        
        // Check if valid: ONLY checked_in or checked_out are valid
        if (in_array($booking['status'], ['checked_in', 'checked_out'])) {
            $validEntries[] = $entry;
        } else {
            $invalidEntries[] = $entry;
        }
    } else {
        // Booking not found at all - mark as invalid
        $entry['booking_code'] = $bookingCode . ' (NOT FOUND)';
        $entry['booking_status'] = 'N/A';
        $entry['booking_source'] = 'N/A';
        $entry['guest_name'] = 'Unknown';
        $invalidEntries[] = $entry;
    }
}

// =========== VIEW ===========
if ($action === 'view') {
    echo '<h2>❌ INVALID Entries (Akan Dihapus)</h2>';
    echo '<p>Entries ini SALAH karena booking belum check-in (status bukan checked_in/checked_out).</p>';
    
    if (count($invalidEntries) === 0) {
        echo '<div class="box success"><strong>✅ Tidak ada entry invalid!</strong></div>';
    } else {
        $totalInvalid = 0;
        echo '<div class="box danger">';
        echo '<p><strong>⚠️ Ditemukan ' . count($invalidEntries) . ' entries yang SALAH</strong></p>';
        echo '<table><tr><th>CB ID</th><th>Date</th><th>Booking</th><th>Status</th><th>Source</th><th>Guest</th><th>Amount</th><th>Description</th></tr>';
        foreach ($invalidEntries as $e) {
            $totalInvalid += $e['amount'];
            echo '<tr>';
            echo '<td>' . $e['id'] . '</td>';
            echo '<td>' . $e['transaction_date'] . '</td>';
            echo '<td>' . htmlspecialchars($e['booking_code']) . '</td>';
            echo '<td><strong style="color:red">' . strtoupper($e['booking_status']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($e['booking_source']) . '</td>';
            echo '<td>' . htmlspecialchars($e['guest_name']) . '</td>';
            echo '<td>Rp ' . number_format($e['amount'], 0, ',', '.') . '</td>';
            echo '<td style="font-size:11px">' . htmlspecialchars(substr($e['description'], 0, 60)) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p><strong>Total Amount Salah: Rp ' . number_format($totalInvalid, 0, ',', '.') . '</strong></p>';
        echo '</div>';
        
        echo '<p><a class="btn btn-danger" href="?action=delete" onclick="return confirm(\'HAPUS ' . count($invalidEntries) . ' entries ini?\')">🗑️ DELETE These ' . count($invalidEntries) . ' Entries</a></p>';
    }
    
    echo '<h2>✅ VALID Entries (Dari Checked-In/Checked-Out)</h2>';
    if (count($validEntries) === 0) {
        echo '<p>Tidak ada entry valid.</p>';
    } else {
        $totalValid = 0;
        echo '<table class="valid"><tr><th>CB ID</th><th>Date</th><th>Booking</th><th>Status</th><th>Source</th><th>Guest</th><th>Amount</th></tr>';
        foreach ($validEntries as $e) {
            $totalValid += $e['amount'];
            echo '<tr>';
            echo '<td>' . $e['id'] . '</td>';
            echo '<td>' . $e['transaction_date'] . '</td>';
            echo '<td>' . htmlspecialchars($e['booking_code']) . '</td>';
            echo '<td><strong style="color:green">' . strtoupper($e['booking_status']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($e['booking_source']) . '</td>';
            echo '<td>' . htmlspecialchars($e['guest_name']) . '</td>';
            echo '<td>Rp ' . number_format($e['amount'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '<p><strong>Total Amount Valid: Rp ' . number_format($totalValid, 0, ',', '.') . '</strong></p>';
    }
}

// =========== DELETE ===========
if ($action === 'delete') {
    echo '<h2>🗑️ Deleting Invalid Entries...</h2>';
    
    if (count($invalidEntries) === 0) {
        echo '<div class="box success">✅ Tidak ada entry yang perlu dihapus!</div>';
    } else {
        $deletedCount = 0;
        $deletedAmount = 0;
        
        foreach ($invalidEntries as $entry) {
            $id = (int)$entry['id'];
            $result = $conn->exec("DELETE FROM cash_book WHERE id = " . $id);
            
            if ($result) {
                echo "<p>✅ Deleted ID={$id} | {$entry['booking_code']} | Status={$entry['booking_status']} | Rp " . number_format($entry['amount'], 0, ',', '.') . "</p>";
                $deletedCount++;
                $deletedAmount += $entry['amount'];
            } else {
                echo "<p>❌ Failed to delete ID={$id}</p>";
            }
        }
        
        // Also reset sync flags on booking_payments for deleted entries
        $conn->exec("UPDATE booking_payments SET synced_to_cashbook = 0, cashbook_id = NULL WHERE synced_to_cashbook = 1");
        
        echo '<div class="box success">';
        echo "<p><strong>✅ Deleted {$deletedCount} entries</strong></p>";
        echo "<p>Total Amount Removed: Rp " . number_format($deletedAmount, 0, ',', '.') . "</p>";
        echo "<p>Sync flags have been reset on booking_payments.</p>";
        echo '</div>';
        
        echo '<p><a class="btn btn-info" href="?action=view">👁️ Verify Remaining Entries</a></p>';
    }
}

echo '</body></html>';
