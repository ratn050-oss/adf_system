<?php
define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));
require_once $base_path . '/config/config.php';

$pageTitle = 'Manajemen Investor';
include '../../includes/header.php';
?>

<main class="main-content">
    <div style="padding: 2rem;">
        <h2>✅ Halaman Investor Berhasil Dimuat</h2>
        <p>Jika Anda bisa melihat ini, berarti masalah sudah selesai.</p>
        <p>Session Data:</p>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>
</main>

<?php include '../../includes/footer.php'; ?>
