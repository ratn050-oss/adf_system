<?php
/**
 * Cek isi user_menu_permissions untuk Sandra
 */
echo "<h2>🔑 Cek user_menu_permissions untuk Sandra</h2>";
$pdo = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get Sandra user id
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'sandra'");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "<b style='color:red'>Sandra tidak ditemukan di users</b>";
    exit;
}
$sandraId = $user['id'];

// Get permissions
$perm = $pdo->prepare("SELECT p.*, b.business_name FROM user_menu_permissions p JOIN businesses b ON p.business_id = b.id WHERE p.user_id = ?");
$perm->execute([$sandraId]);
$rows = $perm->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "<b style='color:red'>Sandra TIDAK punya akses ke bisnis manapun!</b>";
} else {
    echo "<table border=1 cellpadding=6><tr><th>Business</th><th>Menu ID</th><th>View</th><th>Create</th><th>Edit</th><th>Delete</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>{$r['business_name']}</td><td>{$r['menu_id']}</td><td>{$r['can_view']}</td><td>{$r['can_create']}</td><td>{$r['can_edit']}</td><td>{$r['can_delete']}</td></tr>";
    }
    echo "</table>";
}
?>