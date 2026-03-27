<?php
/**
 * CLEAN STATE SETUP
 * Hapus semua business databases
 * Keep hanya: adf_system (master)
 */

define('APP_ACCESS', true);
require_once '../config/config.php';
require_once '../config/database.php';

$db = Database::getInstance();

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Clean State Setup</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        .warning { background: #fff3cd; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .success { background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #f5f5f5; }
        button { padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #c82333; }
    </style>
</head>
<body>
    <h1>🗑️ Clean State Setup</h1>
    
    <div class="warning">
        <strong>⚠️ WARNING!</strong> Operasi ini akan menghapus semua database bisnis kecuali adf_system.
        Data tidak bisa di-recover!
    </div>

    <?php
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action == 'delete_all') {
            if (!isset($_POST['confirm']) || $_POST['confirm'] != 'yes') {
                echo '<div class="error">❌ Konfirmasi tidak ditemukan!</div>';
            } else {
                try {
                    $databases = [
                        'adf_benscafe',
                        'adf_eat_meet',
                        'adf_furniture',
                        'adf_karimunjawa',
                        'adf_narayana_hotel',
                        'adf_pabrik_kapal'
                    ];
                    
                    foreach ($databases as $dbName) {
                        $db->getConnection()->exec("DROP DATABASE IF EXISTS `$dbName`");
                        echo '<div class="success">✅ Hapus database: <strong>' . $dbName . '</strong></div>';
                    }
                    
                    echo '<div class="success"><strong>✅ SELESAI!</strong><br>Semua business database sudah dihapus.<br>Hanya adf_system yang tersisa.</div>';
                    
                } catch (Exception $e) {
                    echo '<div class="error">❌ Error: ' . $e->getMessage() . '</div>';
                }
            }
        }
    }
    ?>

    <h2>📊 Database yang akan dihapus:</h2>
    <table>
        <tr>
            <th>Database</th>
            <th>Status</th>
        </tr>
        <tr>
            <td>adf_benscafe</td>
            <td><span style="color: red;">❌ HAPUS</span></td>
        </tr>
        <tr>
            <td>adf_eat_meet</td>
            <td><span style="color: red;">❌ HAPUS</span></td>
        </tr>
        <tr>
            <td>adf_furniture</td>
            <td><span style="color: red;">❌ HAPUS</span></td>
        </tr>
        <tr>
            <td>adf_karimunjawa</td>
            <td><span style="color: red;">❌ HAPUS</span></td>
        </tr>
        <tr>
            <td>adf_narayana_hotel</td>
            <td><span style="color: red;">❌ HAPUS</span></td>
        </tr>
        <tr>
            <td>adf_pabrik_kapal</td>
            <td><span style="color: red;">❌ HAPUS</span></td>
        </tr>
        <tr>
            <td>adf_system</td>
            <td><span style="color: green;">✅ KEEP (Master)</span></td>
        </tr>
    </table>

    <h2>🚀 Jalankan Cleanup</h2>
    <form method="POST">
        <label>
            <input type="checkbox" name="confirm" value="yes" required>
            Saya yakin ingin menghapus semua database bisnis (tidak bisa di-recover!)
        </label>
        <br><br>
        <input type="hidden" name="action" value="delete_all">
        <button type="submit">🗑️ Hapus Semua Database</button>
    </form>

    <hr>
    <p><a href="developer-panel.php">← Kembali ke Developer Panel</a></p>
</body>
</html>
