<?php

/**
 * API: Fix OTA Bookings Hotfix
 * Safe token-based endpoint for fixing OTA bookings
 * 
 * Access: https://adfsystem.online/api/fix-ota-bookings.php?token=YOUR_TOKEN
 */

header('Content-Type: application/json; charset=utf-8');

// ==========================================
// Token Validation
// ==========================================
$tokenFromRequest = $_GET['token'] ?? $_POST['token'] ?? '';

// Accept multiple tokens for flexibility
$validTokens = [
    'adf_fix_ota_2026',      // Primary token
    'adf-hotfix-2026',       // Alternative
    'adf_deploy_2025_secure' // Deployment token
];

if (!in_array($tokenFromRequest, $validTokens, true)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Invalid or missing token'
    ]);
    exit;
}

// ==========================================
// Start fixing
// ==========================================
try {
    ob_start(); // Capture any notices/warnings

    define('APP_ACCESS', true);
    require_once '../config/config.php';
    require_once '../config/database.php';

    ob_end_clean(); // Clear captured output

    $db = Database::getInstance();
    $fixes = [];

    // ==========================================
    // FIX 1: Empty booking_source
    // ==========================================
    $emptyByteSource = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM bookings 
         WHERE (booking_source = '' OR booking_source IS NULL)
         AND status IN ('confirmed', 'pending')"
    );
    $emptyCount = $emptyByteSource['cnt'] ?? 0;

    if ($emptyCount > 0) {
        $result1 = $db->query(
            "UPDATE bookings 
             SET booking_source = 'walk_in'
             WHERE (booking_source = '' OR booking_source IS NULL)
             AND status IN ('confirmed', 'pending')"
        );

        $fixes[] = [
            'type' => 'empty_source',
            'count' => $emptyCount,
            'status' => $result1 ? 'success' : 'failed',
            'message' => $result1 ? "Fixed $emptyCount bookings with empty source → set to 'walk_in'" : 'Failed to fix empty sources'
        ];
    }

    // ==========================================
    // FIX 2: OTA bookings dengan paid_amount = 0
    // ==========================================
    $otaSources = ['agoda', 'booking', 'tiket', 'traveloka', 'airbnb', 'ota', 'expedia', 'pegipegi'];

    $otaCheck = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM bookings 
         WHERE booking_source IN (?, ?, ?, ?, ?, ?, ?, ?)
         AND status IN ('confirmed', 'pending')
         AND (paid_amount = 0 OR paid_amount IS NULL)",
        $otaSources
    );
    $otaCount = $otaCheck['cnt'] ?? 0;

    if ($otaCount > 0) {
        $result2 = $db->query(
            "UPDATE bookings 
             SET paid_amount = final_price, 
                 payment_status = 'paid',
                 updated_at = NOW()
             WHERE booking_source IN (?, ?, ?, ?, ?, ?, ?, ?)
             AND status IN ('confirmed', 'pending')
             AND (paid_amount = 0 OR paid_amount IS NULL)",
            $otaSources
        );

        $fixes[] = [
            'type' => 'ota_paid_amount',
            'count' => $otaCount,
            'status' => $result2 ? 'success' : 'failed',
            'message' => $result2 ? "Fixed $otaCount OTA bookings → paid_amount = final_price" : 'Failed to fix OTA payments'
        ];
    }

    // ==========================================
    // Response
    // ==========================================
    $allSuccess = true;
    foreach ($fixes as $fix) {
        if ($fix['status'] !== 'success') {
            $allSuccess = false;
            break;
        }
    }

    $totalFixed = 0;
    foreach ($fixes as $fix) {
        $totalFixed += $fix['count'];
    }

    echo json_encode([
        'success' => $allSuccess,
        'total_fixed' => $totalFixed,
        'fixes' => $fixes,
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $allSuccess
            ? "✅ Hotfix completed! Fixed $totalFixed bookings"
            : "⚠️ Hotfix completed with issues"
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server Error',
        'message' => $e->getMessage()
    ]);
}
