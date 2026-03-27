<?php
$pdo = new PDO('mysql:host=localhost;dbname=adf_narayana_hotel', 'root', '');
$rows = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_hero_background', 'web_room_gallery_king', 'web_room_primary_king')");

echo "-- Copy and run this SQL in phpMyAdmin on hosting database: adfb2574_narayana_hotel --\n\n";

foreach($rows as $r) {
    $v = $pdo->quote($r['setting_value']);
    echo "UPDATE settings SET setting_value = {$v} WHERE setting_key = '{$r['setting_key']}';\n\n";
}
