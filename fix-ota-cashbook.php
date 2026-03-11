<?php
/**
 * Cleanup OTA Cashbook Entries
 * 
 * Menghapus entri buku kas dari booking OTA yang tamunya belum check-in.
 * Jalankan via browser: http://localhost/adf_system/fix-ota-cashbook.php
 * 
 * SAFE: Hanya menghapus entri yang sumber bookingnya OTA dan status booking BUKAN checked_in/checked_out
 */

require_once __DIR__ . '/config/database.php';

// Require login
if (empty($_SESSION['user_id'])) {
    die('Login required');
}

// Only admin can run this
if (($_SESSION['role'] ?? '') !== 'admin') {
    die('Admin access required');
}

try {
    $db = new Database();
    $businessId = $_SESSION['business_id'] ?? 1;
    
    // Master DB connection
    $masterDbName = defined('MASTER_DB_NAME') ? MASTER_DB_NAME : 'adf_system';
    try {
        $masterDb = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . $masterDbName . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        $masterDb = $db->getConnection();
    }

    // OTA keywords
    $otaKeywords = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'expedia', 'pegipegi', 'ota'];

    // Find cashbook entries from OTA bookings where guest has NOT checked in
    $wrongEntries = $db->fetchAll("
        SELECT cb.id, cb.transaction_date, cb.amount, cb.description, cb.payment_method, cb.cash_account_id,
               b.booking_code, b.booking_source, b.status as booking_status
        FROM cash_book cb
        JOIN bookings b ON cb.description LIKE CONCAT('%', b.booking_code, '%')
        WHERE cb.transaction_type = 'income'
        AND b.status NOT IN ('checked_in', 'checked_out')
        AND (
            LOWER(COALESCE(b.booking_source,'')) LIKE '%agoda%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%booking%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%tiket%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%traveloka%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%airbnb%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%expedia%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%pegipegi%'
            OR LOWER(COALESCE(b.booking_source,'')) LIKE '%ota%'
        )
        ORDER BY cb.id DESC
    ");

    $dryRun = !isset($_GET['confirm']);

    echo "<html><head><title>Fix OTA Cashbook</title>";
    echo "<style>body{font-family:sans-serif;margin:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f4f4f4}.deleted{background:#ffe0e0}.safe{color:green;font-weight:bold}.warn{color:red;font-weight:bold}</style>";
    echo "</head><body>";
    echo "<h2>🔧 Fix OTA Cashbook - Hapus Entri OTA Sebelum Check-in</h2>";

    if (empty($wrongEntries)) {
        echo "<p class='safe'>✅ Tidak ada entri OTA yang salah. Semua sudah benar!</p>";
        echo "</body></html>";
        exit;
    }

    echo "<p class='warn'>⚠️ Ditemukan " . count($wrongEntries) . " entri OTA yang masuk sebelum tamu check-in:</p>";

    echo "<table><tr><th>ID</th><th>Tanggal</th><th>Jumlah</th><th>Deskripsi</th><th>Method</th><th>Source</th><th>Status Booking</th><th>Aksi</th></tr>";

    $deletedCount = 0;
    foreach ($wrongEntries as $entry) {
        $class = $dryRun ? '' : 'class="deleted"';
        echo "<tr {$class}>";
        echo "<td>{$entry['id']}</td>";
        echo "<td>{$entry['transaction_date']}</td>";
        echo "<td>Rp " . number_format($entry['amount']) . "</td>";
        echo "<td>" . htmlspecialchars(substr($entry['description'], 0, 80)) . "</td>";
        echo "<td>{$entry['payment_method']}</td>";
        echo "<td>{$entry['booking_source']}</td>";
        echo "<td>{$entry['booking_status']}</td>";

        if (!$dryRun) {
            // Delete from cash_book
            $db->query("DELETE FROM cash_book WHERE id = ?", [$entry['id']]);
            
            // Reverse cash account balance
            if ($entry['cash_account_id']) {
                try {
                    $masterDb->prepare("UPDATE cash_accounts SET current_balance = current_balance - ? WHERE id = ?")->execute([$entry['amount'], $entry['cash_account_id']]);
                } catch (Throwable $e) {}
            }
            
            // Reset synced_to_cashbook flag so it can re-sync properly at check-in
            $bookingCode = $entry['booking_code'] ?? '';
            if ($bookingCode) {
                try {
                    $db->query("UPDATE booking_payments bp 
                        JOIN bookings b ON bp.booking_id = b.id
                        SET bp.synced_to_cashbook = 0, bp.cashbook_id = NULL 
                        WHERE b.booking_code = ?", [$bookingCode]);
                } catch (Throwable $e) {}
            }
            
            echo "<td class='safe'>✅ Dihapus</td>";
            $deletedCount++;
        } else {
            echo "<td>Preview</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    if ($dryRun) {
        echo "<br>";
        echo "<p>Ini adalah <b>PREVIEW</b>. Klik tombol di bawah untuk menghapus entri yang salah:</p>";
        echo "<a href='?confirm=1' style='display:inline-block;padding:12px 24px;background:#dc3545;color:white;text-decoration:none;border-radius:6px;font-size:16px' onclick=\"return confirm('Yakin hapus " . count($wrongEntries) . " entri OTA yang salah?')\">🗑️ Hapus " . count($wrongEntries) . " Entri yang Salah</a>";
        echo "<br><br><p><small>Entri akan dihapus dari buku kas dan saldo kas dikembalikan. Flag sync direset agar bisa masuk kembali saat tamu check-in.</small></p>";
    } else {
        echo "<br><p class='safe'>✅ Berhasil menghapus {$deletedCount} entri. Saldo kas sudah dikembalikan. Data akan masuk kembali otomatis saat tamu check-in.</p>";
        echo "<p><a href='/adf_system/modules/cashbook/'>← Kembali ke Buku Kas</a></p>";
    }

    echo "</body></html>";

} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}
