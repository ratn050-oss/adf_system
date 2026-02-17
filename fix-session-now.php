<?php
session_start();

// Ben's Cafe = ID 2, Narayana Hotel = ID 1
$map = [
    'bens-cafe' => 2,
    'narayana-hotel' => 1
];

$active = $_SESSION['active_business_id'] ?? 'bens-cafe';
$_SESSION['business_id'] = $map[$active] ?? 2;

echo "FIXED! business_id = " . $_SESSION['business_id'] . " untuk " . $active;
echo "<br><br><a href='modules/cashbook/index.php'>Buka Buku Kas</a>";
