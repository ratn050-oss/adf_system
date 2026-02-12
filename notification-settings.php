<?php
/**
 * Notification Settings - Enable Push Notifications
 */
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$pageTitle = '🔔 Pengaturan Notifikasi';
require_once 'includes/header.php';
?>

<style>
.notification-card {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(168, 85, 247, 0.1));
    border: 1px solid rgba(139, 92, 246, 0.3);
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 1.5rem;
}
.notification-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, #6366f1, #a855f7);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    margin: 0 auto 1.5rem;
    color: white;
    box-shadow: 0 10px 30px rgba(99, 102, 241, 0.4);
}
.status-badge {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
}
.status-enabled {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}
.status-disabled {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}
.status-unsupported {
    background: rgba(156, 163, 175, 0.2);
    color: #9ca3af;
}
.enable-btn {
    background: linear-gradient(135deg, #6366f1, #a855f7);
    color: white;
    border: none;
    padding: 15px 40px;
    border-radius: 12px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
}
.enable-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.5);
}
.enable-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}
.feature-list {
    list-style: none;
    padding: 0;
    margin: 2rem 0;
}
.feature-list li {
    padding: 12px 0;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    display: flex;
    align-items: center;
    gap: 12px;
}
.feature-list li:last-child {
    border-bottom: none;
}
.feature-icon {
    width: 40px;
    height: 40px;
    background: rgba(16, 185, 129, 0.2);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.test-btn {
    background: rgba(59, 130, 246, 0.2);
    color: #3b82f6;
    border: 1px solid rgba(59, 130, 246, 0.3);
    padding: 10px 25px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}
.test-btn:hover {
    background: rgba(59, 130, 246, 0.3);
}
</style>

<div class="container" style="max-width: 800px; margin: 0 auto;">
    <div class="notification-card text-center">
        <div class="notification-icon">🔔</div>
        <h2 style="margin-bottom: 0.5rem;">Push Notifications</h2>
        <p style="color: var(--text-muted); margin-bottom: 1.5rem;">
            Terima notifikasi langsung di layar HP/Komputer Anda
        </p>
        
        <div id="notification-status" style="margin-bottom: 1.5rem;">
            <span class="status-badge status-disabled">Memeriksa...</span>
        </div>
        
        <button id="enable-notifications" class="enable-btn" onclick="requestNotificationPermission()">
            🔔 Aktifkan Notifikasi
        </button>
        
        <div id="status-message" style="margin-top: 1rem; display: none;"></div>
    </div>
    
    <div class="notification-card">
        <h4 style="margin-bottom: 1rem;">📋 Jenis Notifikasi yang Akan Diterima:</h4>
        <ul class="feature-list">
            <li>
                <div class="feature-icon">📊</div>
                <div>
                    <strong>End Shift Report</strong>
                    <br><small style="color: var(--text-muted);">Kasir selesai shift dengan laporan harian</small>
                </div>
            </li>
            <li>
                <div class="feature-icon">🏨</div>
                <div>
                    <strong>Reservasi Baru</strong>
                    <br><small style="color: var(--text-muted);">Ada tamu yang melakukan booking</small>
                </div>
            </li>
            <li>
                <div class="feature-icon">✅</div>
                <div>
                    <strong>Check-In / Check-Out</strong>
                    <br><small style="color: var(--text-muted);">Tamu check-in atau check-out dari hotel</small>
                </div>
            </li>
            <li>
                <div class="feature-icon">💰</div>
                <div>
                    <strong>Pembayaran Diterima</strong>
                    <br><small style="color: var(--text-muted);">Ada pembayaran baru masuk</small>
                </div>
            </li>
        </ul>
    </div>
    
    <div class="notification-card">
        <h4 style="margin-bottom: 1rem;">🧪 Test Notifikasi</h4>
        <p style="color: var(--text-muted); margin-bottom: 1rem;">
            Klik tombol di bawah untuk mengirim notifikasi test
        </p>
        <button class="test-btn" onclick="sendTestNotification()">
            📤 Kirim Notifikasi Test
        </button>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>/assets/js/notifications.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    updateNotificationStatus();
});

function updateNotificationStatus() {
    const status = window.NotificationManager.getStatus();
    const statusEl = document.getElementById('notification-status');
    const btn = document.getElementById('enable-notifications');
    
    if (!status.supported) {
        statusEl.innerHTML = '<span class="status-badge status-unsupported">Browser Tidak Mendukung</span>';
        btn.disabled = true;
        btn.textContent = '❌ Tidak Tersedia';
    } else if (status.permission === 'granted') {
        statusEl.innerHTML = '<span class="status-badge status-enabled">✅ Notifikasi Aktif</span>';
        btn.textContent = '✓ Sudah Aktif';
        btn.disabled = true;
        btn.style.background = 'rgba(16, 185, 129, 0.2)';
        btn.style.color = '#10b981';
    } else if (status.permission === 'denied') {
        statusEl.innerHTML = '<span class="status-badge status-disabled">⛔ Diblokir oleh Browser</span>';
        btn.textContent = '⚙️ Buka Pengaturan Browser';
        btn.onclick = showHowToEnable;
    } else {
        statusEl.innerHTML = '<span class="status-badge status-disabled">Belum Diaktifkan</span>';
    }
}

async function requestNotificationPermission() {
    const btn = document.getElementById('enable-notifications');
    btn.disabled = true;
    btn.textContent = 'Meminta izin...';
    
    const result = await window.NotificationManager.requestPermission();
    
    showMessage(result.message, result.success ? 'success' : 'error');
    updateNotificationStatus();
}

function showHowToEnable() {
    alert('Untuk mengaktifkan notifikasi:\n\n' +
          '1. Klik ikon gembok/info di address bar\n' +
          '2. Cari pengaturan "Notifications"\n' +
          '3. Ubah ke "Allow"\n' +
          '4. Refresh halaman ini');
}

async function sendTestNotification() {
    if (!window.NotificationManager.isEnabled()) {
        showMessage('Aktifkan notifikasi terlebih dahulu!', 'error');
        return;
    }
    
    await window.NotificationManager.showNotification('🧪 Test Notifikasi', {
        body: 'Ini adalah notifikasi test dari ADF System.\nNotifikasi berfungsi dengan baik!',
        icon: '<?php echo BASE_URL; ?>/assets/img/logo.png'
    });
    
    showMessage('Notifikasi test terkirim!', 'success');
}

function showMessage(text, type) {
    const msgEl = document.getElementById('status-message');
    msgEl.style.display = 'block';
    msgEl.style.padding = '10px 20px';
    msgEl.style.borderRadius = '8px';
    msgEl.style.background = type === 'success' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(239, 68, 68, 0.2)';
    msgEl.style.color = type === 'success' ? '#10b981' : '#ef4444';
    msgEl.textContent = text;
    
    setTimeout(() => { msgEl.style.display = 'none'; }, 5000);
}
</script>

<?php require_once 'includes/footer.php'; ?>
