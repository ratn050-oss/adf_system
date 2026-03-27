<?php
$pdo = new PDO('mysql:host=localhost;dbname=adf_narayana_hotel', 'root', '');
$rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero%' OR setting_key LIKE 'web_room_primary%' OR setting_key LIKE 'web_room_gallery%' OR setting_key LIKE 'web_logo%' OR setting_key LIKE 'web_favicon%'");
echo "=== Image Settings in Database ===\n\n";
foreach($rows as $r) {
    $val = strlen($r['setting_value']) > 120 ? substr($r['setting_value'], 0, 120) . '...' : $r['setting_value'];
    echo $r['setting_key'] . ":\n  " . ($val ?: '(empty)') . "\n\n";
}
