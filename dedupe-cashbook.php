<?php
/**
 * DEDUPLICATE: Keep only ONE entry per booking code
 * Removes duplicate cash_book entries, keeps the OLDEST one
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
echo '<!DOCTYPE html><html><head><title>Deduplicate Cash Book</title>';
echo '<style>
body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
h1, h2 { color: #333; }
table { border-collapse: collapse; width: 100%; margin: 10px 0; background: white; }
th, td { border: 1px solid #ddd; padding: 6px; text-align: left; font-size: 11px; }
th { background: #333; color: white; }
.keep { background: #d4edda; }
.delete { background: #f8d7da; }
.btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; color: white; }
.btn-danger { background: #dc3545; }
.btn-info { background: #17a2b8; }
</style></head><body>';

echo '<h1>🔄 Deduplicate: Remove Duplicate Entries</h1>';
echo '<p>Date: ' . date('Y-m-d H:i:s') . '</p>';

echo '<div style="margin:10px 0">';
echo '<a class="btn btn-info" href="?action=view">👁️ View Duplicates</a> ';
echo '<a class="btn btn-danger" href="?action=dedupe" onclick="return confirm(\'HAPUS semua duplicate, sisakan 1 per booking?\')">🗑️ DEDUPLICATE</a>';
echo '</div>';

// Group entries by booking code
$entries = $conn->query("
    SELECT id, transaction_date, transaction_time, amount, description
    FROM cash_book 
    WHERE transaction_type = 'income'
    AND description LIKE '%BK-%'
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Extract and group by booking code
$grouped = [];
foreach ($entries as $e) {
    if (preg_match('/BK-\d+-\d+/', $e['description'], $m)) {
        $code = $m[0];
        if (!isset($grouped[$code])) {
            $grouped[$code] = [];
        }
        $grouped[$code][] = $e;
    }
}

// Find duplicates
$duplicates = [];
$toDelete = [];
$toKeep = [];

foreach ($grouped as $code => $items) {
    if (count($items) > 1) {
        $duplicates[$code] = $items;
        // Keep first (oldest ID), delete rest
        $toKeep[] = $items[0]['id'];
        for ($i = 1; $i < count($items); $i++) {
            $toDelete[] = $items[$i]['id'];
        }
    }
}

// VIEW
if ($action === 'view') {
    echo '<h2>📋 Duplicate Analysis</h2>';
    echo '<p>Total booking codes with entries: ' . count($grouped) . '</p>';
    echo '<p style="color:red">Booking codes with DUPLICATES: ' . count($duplicates) . '</p>';
    echo '<p>Entries to DELETE: ' . count($toDelete) . '</p>';
    
    if (count($duplicates) === 0) {
        echo '<div style="background:#d4edda;padding:15px;border-radius:8px">✅ Tidak ada duplicate!</div>';
    } else {
        echo '<h3>Duplicates Found:</h3>';
        echo '<table>';
        echo '<tr><th>Booking Code</th><th>Count</th><th>IDs</th><th>Keep</th><th>Delete</th></tr>';
        
        foreach ($duplicates as $code => $items) {
            $ids = array_column($items, 'id');
            $keepId = $ids[0];
            $deleteIds = array_slice($ids, 1);
            
            echo '<tr>';
            echo '<td><strong>' . $code . '</strong></td>';
            echo '<td>' . count($items) . '</td>';
            echo '<td>' . implode(', ', $ids) . '</td>';
            echo '<td class="keep">' . $keepId . '</td>';
            echo '<td class="delete">' . implode(', ', $deleteIds) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        echo '<h3>Detail per Entry:</h3>';
        echo '<table>';
        echo '<tr><th>ID</th><th>Date</th><th>Time</th><th>Amount</th><th>Description</th><th>Action</th></tr>';
        
        foreach ($duplicates as $code => $items) {
            $first = true;
            foreach ($items as $e) {
                $class = $first ? 'keep' : 'delete';
                $action_text = $first ? '✅ KEEP' : '❌ DELETE';
                echo '<tr class="' . $class . '">';
                echo '<td>' . $e['id'] . '</td>';
                echo '<td>' . $e['transaction_date'] . '</td>';
                echo '<td>' . $e['transaction_time'] . '</td>';
                echo '<td>Rp ' . number_format($e['amount'], 0, ',', '.') . '</td>';
                echo '<td style="font-size:10px;max-width:300px">' . htmlspecialchars(substr($e['description'], 0, 80)) . '</td>';
                echo '<td>' . $action_text . '</td>';
                echo '</tr>';
                $first = false;
            }
        }
        echo '</table>';
    }
}

// DEDUPE
if ($action === 'dedupe') {
    echo '<h2>🗑️ Deduplicating...</h2>';
    
    if (count($toDelete) === 0) {
        echo '<div style="background:#d4edda;padding:15px;border-radius:8px">✅ Tidak ada yang perlu dihapus!</div>';
    } else {
        $deletedCount = 0;
        $deletedAmount = 0;
        
        foreach ($toDelete as $id) {
            // Get amount before delete
            $entry = $conn->query("SELECT amount FROM cash_book WHERE id = " . (int)$id)->fetch(PDO::FETCH_ASSOC);
            if ($entry) {
                $deletedAmount += $entry['amount'];
            }
            
            $result = $conn->exec("DELETE FROM cash_book WHERE id = " . (int)$id);
            if ($result !== false) {
                echo "<p>✅ Deleted ID={$id}</p>";
                $deletedCount++;
            }
        }
        
        // Reset sync flags
        $conn->exec("UPDATE booking_payments SET synced_to_cashbook = 0, cashbook_id = NULL");
        
        echo '<div style="background:#d4edda;padding:15px;border-radius:8px;margin-top:15px">';
        echo "<p><strong>✅ Deleted {$deletedCount} duplicate entries</strong></p>";
        echo "<p>Total Amount Removed: Rp " . number_format($deletedAmount, 0, ',', '.') . "</p>";
        echo "<p>Sync flags reset.</p>";
        echo '</div>';
    }
    
    echo '<p><a class="btn btn-info" href="?action=view">👁️ Verify</a></p>';
}

echo '</body></html>';
