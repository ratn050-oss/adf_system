<?php
/**
 * Agent Tool: hotel_context
 * Kembalikan semua informasi hotel yang dibutuhkan AI untuk menjawab pertanyaan tamu.
 * Method: GET
 * Header: X-Agent-Key: <key>
 */

header('Content-Type: application/json');
error_reporting(0); ini_set('display_errors', 0);

define('APP_ACCESS', true);
require_once '_auth.php';

$pdo = agent_auth_check();

try {
    // Ambil semua setting web_* dari hotel DB
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_%' ORDER BY setting_key");
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $settings[$r['setting_key']] = $r['setting_value'];
    }

    // Ambil tipe kamar + harga
    $stmt2 = $pdo->query("SELECT type_name, base_price, description, max_occupancy, bed_type FROM room_types ORDER BY base_price ASC");
    $roomTypes = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // Jumlah kamar tersedia
    $stmt3 = $pdo->query("SELECT COUNT(*) as total FROM rooms WHERE status != 'maintenance'");
    $totalRooms = $stmt3->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'hotel' => [
            'name'           => $settings['web_site_name']    ?? 'Narayana Karimunjawa',
            'tagline'        => $settings['web_tagline']       ?? '',
            'description'    => $settings['web_description']  ?? '',
            'address'        => $settings['web_address']       ?? 'Karimunjawa, Jepara, Central Java',
            'whatsapp'       => $settings['web_whatsapp']      ?? '',
            'phone'          => $settings['web_phone']         ?? '',
            'email'          => $settings['web_email']         ?? '',
            'instagram'      => $settings['web_instagram']     ?? '',
            'checkin_time'   => $settings['web_checkin_time']  ?? '14:00',
            'checkout_time'  => $settings['web_checkout_time'] ?? '12:00',
            'booking_notice' => $settings['web_booking_notice'] ?? '',
            'min_stay_nights'=> $settings['web_min_stay_nights'] ?? '1',
            'max_advance_days'=> $settings['web_max_advance_days'] ?? '365',
        ],
        'room_types' => $roomTypes,
        'total_rooms' => (int)($totalRooms['total'] ?? 0),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
