<?php
// SUPER SIMPLE TEST - NO AUTH, NO NOTHING
echo "<!DOCTYPE html><html><head><title>Investor Test</title></head><body>";
echo "<h1>✅ INVESTOR PAGE LOADED SUCCESSFULLY</h1>";
echo "<p>Jika Anda bisa melihat ini, berarti file investor bisa diakses.</p>";
echo "<p>Current URL: " . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p>File: " . __FILE__ . "</p>";
echo "</body></html>";
?>
