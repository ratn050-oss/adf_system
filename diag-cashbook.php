<?php
/**
 * DIAGNOSTIC: Check cash_book entries vs booking status
 * Direct query to find WHY entries are still there
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
echo '<!DOCTYPE html><html><head><title>Diagnostic Cash Book</title>';
echo '<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h1, h2 { color: #333; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 6px; text-align: left; font-size: 11px; }
th { background: #333; color: white; }
.red { background: #f8d7da; }
.green { background: #d4edda; }
.btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; color: white; }
.btn-danger { background: #dc3545; }
.btn-info { background: #17a2b8; }
</style></head><body>';

echo '<h1>🔍 Diagnostic: Cash Book vs Booking Status</h1>';
echo '<p>Date: ' . date('Y-m-d H:i:s') . '</p>';

// Get ALL income entries
$entries = $conn->query("
    SELECT id, transaction_date, amount, description
    FROM cash_book 
    WHERE transaction_type = 'income'
    ORDER BY transaction_date DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

echo '<h2>All Income Entries (' . count($entries) . ')</h2>';

echo '<div style="margin:10px 0">';
echo '<a class="btn btn-danger" href="?action=delete_invalid" onclick="return confirm(\'DELETE all entries where booking is NOT checked_in/checked_out?\')">🗑️ DELETE Non-Checked-In Entries</a>';
echo '</div>';

echo '<table>';
echo '<tr><th>CB ID</th><th>Date</th><th>Amount</th><th>Description</th><th>Booking Code</th><th>Booking Status</th><th>Action</th></tr>';

$toDelete = [];

foreach ($entries as $e) {
    // Extract booking code
    $bookingCode = null;
    if (preg_match('/BK-\d+-\d+/', $e['description'], $m)) {
        $bookingCode = $m[0];
    } elseif (preg_match('/BK-[A-Z0-9-]+/i', $e['description'], $m)) {
        $bookingCode = $m[0];
    }
    
    $bookingStatus = '-';
    $rowClass = '';
    $isInvalid = false;
    
    if ($bookingCode) {
        // Lookup booking
        $booking = $conn->query("SELECT status FROM bookings WHERE booking_code = " . $conn->quote($bookingCode))->fetch(PDO::FETCH_ASSOC);
        if ($booking) {
            $bookingStatus = strtoupper($booking['status']);
            if (in_array($booking['status'], ['checked_in', 'checked_out'])) {
                $rowClass = 'green';
            } else {
                $rowClass = 'red';
                $isInvalid = true;
                $toDelete[] = $e['id'];
            }
        } else {
            $bookingStatus = 'NOT FOUND';
            $rowClass = 'red';
            $isInvalid = true;
            $toDelete[] = $e['id'];
        }
    }
    
    echo '<tr class="' . $rowClass . '">';
    echo '<td>' . $e['id'] . '</td>';
    echo '<td>' . $e['transaction_date'] . '</td>';
    echo '<td>Rp ' . number_format($e['amount'], 0, ',', '.') . '</td>';
    echo '<td style="max-width:300px;overflow:hidden;font-size:10px">' . htmlspecialchars($e['description']) . '</td>';
    echo '<td>' . ($bookingCode ?? '-') . '</td>';
    echo '<td><strong>' . $bookingStatus . '</strong></td>';
    echo '<td>';
    if ($isInvalid) {
        echo '<a href="?action=delete_one&id=' . $e['id'] . '" onclick="return confirm(\'Delete this?\')">❌ Delete</a>';
    } else {
        echo '✅ OK';
    }
    echo '</td>';
    echo '</tr>';
}
echo '</table>';

echo '<h3>Summary</h3>';
echo '<p>Total entries: ' . count($entries) . '</p>';
echo '<p style="color:red">Invalid (to delete): ' . count($toDelete) . '</p>';
echo '<p>IDs to delete: ' . implode(', ', $toDelete) . '</p>';

// DELETE ONE
if ($action === 'delete_one' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $conn->exec("DELETE FROM cash_book WHERE id = " . $id);
    echo '<script>alert("Deleted ID ' . $id . '"); window.location="?action=view";</script>';
}

// DELETE INVALID
if ($action === 'delete_invalid') {
    echo '<h2>🗑️ Deleting Invalid...</h2>';
    
    // Re-calculate toDelete
    $deleteIds = [];
    foreach ($entries as $e) {
        $bookingCode = null;
        if (preg_match('/BK-\d+-\d+/', $e['description'], $m)) {
            $bookingCode = $m[0];
        } elseif (preg_match('/BK-[A-Z0-9-]+/i', $e['description'], $m)) {
            $bookingCode = $m[0];
        }
        
        if ($bookingCode) {
            $booking = $conn->query("SELECT status FROM bookings WHERE booking_code = " . $conn->quote($bookingCode))->fetch(PDO::FETCH_ASSOC);
            if (!$booking || !in_array($booking['status'], ['checked_in', 'checked_out'])) {
                $deleteIds[] = $e['id'];
            }
        }
    }
    
    if (count($deleteIds) > 0) {
        $idList = implode(',', array_map('intval', $deleteIds));
        $count = $conn->exec("DELETE FROM cash_book WHERE id IN (" . $idList . ")");
        echo "<p>✅ Deleted {$count} entries</p>";
        
        // Reset sync
        $conn->exec("UPDATE booking_payments SET synced_to_cashbook = 0, cashbook_id = NULL");
        echo "<p>✅ Reset sync flags</p>";
    } else {
        echo "<p>No invalid entries to delete</p>";
    }
    
    echo '<p><a href="?action=view">🔄 Refresh</a></p>';
}

echo '</body></html>';
