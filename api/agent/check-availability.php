<?php
/**
 * Agent Tool: check_availability
 * Cek kamar tersedia untuk tanggal tertentu.
 * Method: GET
 * Params: check_in (YYYY-MM-DD), check_out (YYYY-MM-DD)
 * Header: X-Agent-Key: <key>
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '_auth.php';

$db = Database::getInstance();
agent_auth_check($db);

try {
    $checkIn  = trim($_GET['check_in']  ?? '');
    $checkOut = trim($_GET['check_out'] ?? '');

    if (!$checkIn || !$checkOut) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Parameter check_in dan check_out wajib diisi (format YYYY-MM-DD)']);
        exit;
    }

    // Validasi format
    $ciDate = DateTime::createFromFormat('Y-m-d', $checkIn);
    $coDate = DateTime::createFromFormat('Y-m-d', $checkOut);
    if (!$ciDate || !$coDate || $coDate <= $ciDate) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Tanggal tidak valid atau check_out harus setelah check_in']);
        exit;
    }

    $totalNights = $ciDate->diff($coDate)->days;

    // Kamar yang sudah dipesan di rentang ini
    $booked = $db->fetchAll(
        "SELECT DISTINCT room_id FROM bookings
         WHERE check_in_date < ? AND check_out_date > ?
         AND status IN ('pending','confirmed','checked_in')",
        [$checkOut, $checkIn]
    );
    $bookedIds = array_column($booked, 'room_id');

    // Semua kamar aktif beserta tipe
    $allRooms = $db->fetchAll(
        "SELECT r.id, r.room_number, r.floor_number,
                rt.type_name, rt.base_price, rt.max_occupancy, rt.bed_type,
                rt.description
         FROM rooms r
         LEFT JOIN room_types rt ON r.room_type_id = rt.id
         WHERE r.status != 'maintenance'
         ORDER BY rt.base_price ASC, r.room_number ASC",
        []
    );

    $available = [];
    foreach ($allRooms as $room) {
        if (!in_array($room['id'], $bookedIds)) {
            $available[] = [
                'room_id'      => (int)$room['id'],
                'room_number'  => $room['room_number'],
                'floor'        => $room['floor_number'],
                'type'         => $room['type_name'],
                'bed_type'     => $room['bed_type'],
                'max_occupancy'=> (int)$room['max_occupancy'],
                'price_per_night' => (int)$room['base_price'],
                'total_price'  => (int)$room['base_price'] * $totalNights,
                'description'  => $room['description'],
            ];
        }
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

    echo json_encode([
        'success'       => true,
        'check_in'      => $checkIn,
        'check_out'     => $checkOut,
        'total_nights'  => $totalNights,
        'available_count' => count($available),
        'summary_by_type' => array_values($summary),
        'rooms'         => $available,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
