<?php
/**
 * Setup Demo User with 30-day Trial
 * Run this once to set up demo account
 */

require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance();

// Update demo user to have 1 month trial
$expiryDate = date('Y-m-d H:i:s', strtotime('+30 days'));

$result = $db->query(
    "UPDATE users SET 
        is_trial = 1,
        trial_expires_at = ?
    WHERE username = 'demo'",
    [$expiryDate]
);

if ($result) {
    echo "✅ Demo user berhasil di-setup dengan trial 30 hari!\n";
    echo "Trial berakhir: " . $expiryDate . "\n";
} else {
    echo "❌ Gagal setup demo user\n";
}
