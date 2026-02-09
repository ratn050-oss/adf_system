<?php
/**
 * Auto-fix: Assign semua menu aktif Narayana Hotel ke Sandra di MASTER database
 */
echo "<h2>🔧 Auto-Fix: Assign Permissions Sandra untuk Narayana Hotel</h2>";
$pdo = new PDO("mysql:host=localhost;dbname=adf_system", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get Sandra user id
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'sandra'");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "<b style='color:red'>Sandra tidak ditemukan di users</b>"; exit; }
$sandraId = $user['id'];

// Get Narayana Hotel business id
$biz = $pdo->query("SELECT id FROM businesses WHERE business_code = 'NARAYANAHOTEL'")->fetch(PDO::FETCH_ASSOC);
if (!$biz) { echo "<b style='color:red'>Narayana Hotel tidak ditemukan di businesses</b>"; exit; }
$bizId = $biz['id'];

// Get all enabled menus for Narayana
$menus = $pdo->prepare("SELECT m.id FROM menu_items m JOIN business_menu_config bmc ON m.id = bmc.menu_id WHERE bmc.business_id = ? AND bmc.is_enabled = 1");
$menus->execute([$bizId]);
$menuList = $menus->fetchAll(PDO::FETCH_ASSOC);
if (empty($menuList)) { echo "<b style='color:red'>Tidak ada menu aktif untuk Narayana Hotel</b>"; exit; }

// Hapus permission lama Sandra untuk Narayana
$pdo->prepare("DELETE FROM user_menu_permissions WHERE user_id = ? AND business_id = ?")->execute([$sandraId, $bizId]);

// Assign semua menu
$ins = $pdo->prepare("INSERT INTO user_menu_permissions (user_id, business_id, menu_id, can_view, can_create, can_edit, can_delete) VALUES (?, ?, ?, 1, 1, 1, 1)");
$count = 0;
foreach ($menuList as $m) {
    $ins->execute([$sandraId, $bizId, $m['id']]);
    $count++;
}
echo "<b style='color:green'>✅ Berhasil assign $count menu ke Sandra untuk Narayana Hotel!</b><br>";
echo "<a href='check-sandra-permissions.php' style='color:blue'>[Lihat hasil di sini]</a>";
?>