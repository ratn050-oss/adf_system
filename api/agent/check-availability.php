<?php
/**
 * Agent Tool: check_availability
 * Cek kamar tersedia untuk tanggal tertentu. Bisa filter berdasarkan tipe kamar.
 * Method: GET
 * Params: check_in (YYYY-MM-DD), check_out (YYYY-MM-DD), room_type? (opsional, misal: King, Twin, Standard)
 * Header: X-Agent-Key: <key>
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '_auth.php';

$pdo = agent_auth_check();

try {
    $checkIn   = trim($_GET['check_in']  ?? '');
    $checkOut  = trim($_GET['check_out'] ?? '');
    $roomType  = trim($_GET['room_type'] ?? '');

    if (!$checkIn || !$checkOut) {
        echo json_encode(['success' => false, 'error' => 'Parameter check_in dan check_out wajib diisi (format YYYY-MM-DD)']);
        exit;
    }

    // Validasi format
    $ciDate = DateTime::createFromFormat('Y-m-d', $checkIn);
    $coDate = DateTime::createFromFormat('Y-m-d', $checkOut);
    if (!$ciDate || !$coDate || $coDate <= $ciDate) {
        echo json_encode(['success' => false, 'error' => 'Tanggal tidak valid atau check_out harus setelah check_in']);
        exit;
    }

    $totalNights = $ciDate->diff($coDate)->days;

    // Kamar yang sudah dipesan di rentang ini
    $stmt = $pdo->prepare("SELECT DISTINCT room_id FROM bookings
         WHERE check_in_date < ? AND check_out_date > ?
         AND status IN ('pending','confirmed','checked_in')");
    $stmt->execute([$checkOut, $checkIn]);
    $bookedIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'room_id');

    // Semua kamar aktif beserta tipe (defensive COALESCE)
    try {
        $sql = "SELECT r.id, r.room_number, COALESCE(r.floor_number,'') as floor_number,
                    rt.type_name, rt.base_price,
                    COALESCE(rt.max_occupancy, 2) as max_occupancy,
                    COALESCE(rt.bed_type, '') as bed_type,
                    COALESCE(rt.description, '') as description
             FROM rooms r
             LEFT JOIN room_types rt ON r.room_type_id = rt.id
             WHERE r.status != 'maintenance'
             ORDER BY rt.base_price ASC, r.room_number ASC";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute();
    } catch (Exception $e) {
        $sql = "SELECT r.id, r.room_number, '' as floor_number,
                    rt.type_name, rt.base_price,
                    2 as max_occupancy, '' as bed_type, '' as description
             FROM rooms r
             LEFT JOIN room_types rt ON r.room_type_id = rt.id
             WHERE r.status != 'maintenance'
             ORDER BY rt.base_price ASC, r.room_number ASC";
        $stmt2 = $pdo->prepare($sql);
        $stmt2->execute();
    }
    $allRooms = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $available = [];
    foreach ($allRooms as $room) {
        if (in_array($room['id'], $bookedIds)) continue;

        // Filter berdasarkan tipe kamar jika diminta
        if ($roomType && stripos($room['type_name'], $roomType) === false) continue;

        $available[] = [
            'room_id'         => (int)$room['id'],
            'room_number'     => $room['room_number'],
            'floor'           => $room['floor_number'],
            'type'            => $room['type_name'],
            'bed_type'        => $room['bed_type'],
            'max_occupancy'   => (int)$room['max_occupancy'],
            'price_per_night' => (int)$room['base_price'],
            'total_price'     => (int)$room['base_price'] * $totalNights,
            'description'     => $room['description'],
        ];
    }

    // Ringkasan per tipe kamar
    $summary = [];
    foreach ($available as $r) {
        $type = $r['type'];
        if (!isset($summary[$type])) {
            $summary[$type] = [
                'type'            => $type,
                'available_count' => 0,
                'price_per_night' => $r['price_per_night'],
                'total_price'     => $r['total_price'],
                'bed_type'        => $r['bed_type'],
                'max_occupancy'   => $r['max_occupancy'],
            ];
        }
        $summary[$type]['available_count']++;
    }

    // Buat ringkasan teks untuk AI
    $textParts = [];
    $textParts[] = "Ketersediaan kamar tanggal {$checkIn} s/d {$checkOut} ({$totalNights} malam):";
    if (count($available) === 0) {
        $filterNote = $roomType ? " tipe \"{$roomType}\"" : "";
        $textParts[] = "Tidak ada kamar{$filterNote} yang tersedia untuk tanggal tersebut.";
    } else {
        foreach (array_values($summary) as $s) {
            $textParts[] = "- {$s['type']}: {$s['available_count']} kamar tersedia, Rp " . number_format($s['price_per_night'], 0, ',', '.') . "/malam (total {$totalNights} malam = Rp " . number_format($s['total_price'], 0, ',', '.') . ")";
        }
    }

    echo json_encode([
        'success'          => true,
        'text_summary'     => implode("\n", $textParts),
        'check_in'         => $checkIn,
        'check_out'        => $checkOut,
        'total_nights'     => $totalNights,
        'room_type_filter' => $roomType ?: null,
        'available_count'  => count($available),
        'summary_by_type'  => array_values($summary),
        'rooms'            => $available,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
