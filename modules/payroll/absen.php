<?php
/**
 * Staff Attendance Mobile Page
 * Dapat diakses tanpa login — staff absen via HP dengan GPS verification
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';

$pageTitle = 'Absen Karyawan';
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0d1f3c">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Absen Karyawan</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
/* ══════════════════════════════════════════
   ABSEN MOBILE — Navy Gold Premium
   ══════════════════════════════════════════ */
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

:root {
    --navy: #0d1f3c;
    --navy-light: #1a3a5c;
    --gold: #f0b429;
    --gold-dark: #d4960d;
    --green: #059669;
    --red: #dc2626;
    --orange: #ea580c;
    --bg: #f0f4f8;
    --card: #ffffff;
    --muted: #64748b;
    --border: #e2e8f0;
}

html, body { height: 100%; background: var(--bg); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }

/* ─ App Shell ─ */
#app { max-width: 480px; margin: 0 auto; min-height: 100vh; background: var(--bg); position: relative; }

/* ─ Top Bar ─ */
.topbar {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
    padding: 16px 20px 20px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 4px 20px rgba(13,31,60,0.3);
}
.topbar-logo { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.topbar-logo .logo-circle {
    width: 36px; height: 36px; border-radius: 10px;
    background: var(--gold); display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.topbar-logo h1 { color: #fff; font-size: 15px; font-weight: 700; letter-spacing: -0.3px; }
.topbar-logo .subtitle { color: rgba(255,255,255,0.6); font-size: 11px; margin-top: 1px; }
.topbar-date {
    background: rgba(255,255,255,0.1); border-radius: 8px; padding: 8px 12px;
    display: flex; justify-content: space-between; align-items: center;
}
.topbar-date .date-text { color: #fff; font-size: 12px; font-weight: 600; }
.topbar-date .time-text { color: var(--gold); font-size: 18px; font-weight: 800; font-variant-numeric: tabular-nums; }

/* ─ Screens ─ */
.screen { display: none; padding: 16px; animation: fadeUp 0.3s ease; }
.screen.active { display: block; }
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ─ Card ─ */
.card {
    background: var(--card); border-radius: 14px; padding: 18px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07); margin-bottom: 14px;
    border: 1px solid var(--border);
}
.card-title { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }

/* ─ Form ─ */
.form-group { margin-bottom: 14px; }
.form-label { display: block; font-size: 12px; font-weight: 600; color: var(--navy); margin-bottom: 6px; }
.form-input {
    width: 100%; padding: 13px 14px; border: 1.5px solid var(--border); border-radius: 10px;
    font-size: 15px; background: #fff; color: var(--navy); transition: all 0.2s;
    -webkit-appearance: none;
}
.form-input:focus { border-color: var(--gold); outline: none; box-shadow: 0 0 0 3px rgba(240,180,41,0.15); }
.form-input.pin-input { letter-spacing: 8px; font-size: 20px; font-weight: 800; text-align: center; }

/* ─ Buttons ─ */
.btn {
    width: 100%; padding: 15px; border: none; border-radius: 12px; font-size: 15px;
    font-weight: 700; cursor: pointer; transition: all 0.2s; display: flex;
    align-items: center; justify-content: center; gap: 8px; letter-spacing: -0.2px;
}
.btn:active { transform: scale(0.97); }
.btn-primary { background: var(--navy); color: #fff; box-shadow: 0 4px 14px rgba(13,31,60,0.3); }
.btn-primary:hover { background: var(--navy-light); }
.btn-checkin { background: linear-gradient(135deg, var(--green) 0%, #10b981 100%); color: #fff; box-shadow: 0 4px 14px rgba(5,150,105,0.35); font-size: 16px; padding: 18px; }
.btn-checkout { background: linear-gradient(135deg, var(--orange) 0%, #f97316 100%); color: #fff; box-shadow: 0 4px 14px rgba(234,88,12,0.35); font-size: 16px; padding: 18px; }
.btn-disabled { background: #e2e8f0; color: #94a3b8; cursor: not-allowed; box-shadow: none; }
.btn-sm { width: auto; padding: 8px 16px; font-size: 12px; border-radius: 8px; }
.btn-ghost { background: transparent; color: var(--muted); border: 1.5px solid var(--border); width: 100%; }

/* ─ Employee Card ─ */
.emp-card {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 100%);
    border-radius: 14px; padding: 18px; color: #fff; margin-bottom: 14px;
    position: relative; overflow: hidden;
}
.emp-card::after {
    content: ''; position: absolute; right: -20px; top: -20px;
    width: 100px; height: 100px; border-radius: 50%;
    background: rgba(240,180,41,0.12);
}
.emp-avatar {
    width: 54px; height: 54px; border-radius: 14px; background: var(--gold);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 800; color: var(--navy); margin-bottom: 12px;
}
.emp-name { font-size: 17px; font-weight: 800; letter-spacing: -0.3px; }
.emp-meta { color: rgba(255,255,255,0.7); font-size: 12px; margin-top: 3px; }
.emp-status-row { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.emp-badge {
    background: rgba(255,255,255,0.15); border-radius: 20px; padding: 4px 10px;
    font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.9);
    display: flex; align-items: center; gap: 4px;
}
.emp-badge.green { background: rgba(16,185,129,0.25); color: #6ee7b7; }
.emp-badge.orange { background: rgba(234,88,12,0.25); color: #fdba74; }
.emp-badge.red { background: rgba(220,38,38,0.25); color: #fca5a5; }

/* ─ GPS Card ─ */
.gps-card {
    background: var(--card); border-radius: 14px; overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,0.07); margin-bottom: 14px;
    border: 1.5px solid var(--border);
}
.gps-header { padding: 12px 16px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border); }
.gps-indicator {
    width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0;
    transition: background 0.3s;
}
.gps-indicator.loading { background: var(--gold); animation: gpsFlash 0.8s infinite; }
.gps-indicator.ok { background: var(--green); }
.gps-indicator.error { background: var(--red); }
@keyframes gpsFlash { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
.gps-status-text { font-size: 13px; font-weight: 600; color: var(--navy); flex: 1; }
.gps-accuracy { font-size: 11px; color: var(--muted); }
#map { height: 220px; background: #e2e8f0; }

/* ─ Radius Ring ─ */
.distance-bar-wrap { padding: 14px 16px; }
.distance-row { display: flex; justify-content: space-between; margin-bottom: 6px; }
.distance-label { font-size: 12px; color: var(--muted); font-weight: 600; }
.distance-value { font-size: 12px; font-weight: 700; }
.distance-value.inside { color: var(--green); }
.distance-value.outside { color: var(--red); }
.distance-bar-track {
    height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden;
}
.distance-bar-fill {
    height: 100%; border-radius: 4px; transition: width 0.5s ease, background 0.3s;
    background: var(--green);
}
.distance-bar-fill.outside { background: var(--red); }

/* ─ Attendance Status ─ */
.att-row { display: flex; gap: 10px; margin-bottom: 14px; }
.att-box {
    flex: 1; background: var(--card); border-radius: 12px; padding: 14px;
    text-align: center; border: 1.5px solid var(--border);
    box-shadow: 0 1px 6px rgba(0,0,0,0.05);
}
.att-box .att-icon { font-size: 20px; margin-bottom: 4px; }
.att-box .att-label { font-size: 10px; color: var(--muted); font-weight: 700; text-transform: uppercase; margin-bottom: 3px; }
.att-box .att-time { font-size: 20px; font-weight: 800; color: var(--navy); font-variant-numeric: tabular-nums; }
.att-box .att-time.empty { color: var(--border); font-size: 16px; }
.att-box.has-data { border-color: var(--gold); background: rgba(240,180,41,0.04); }

/* ─ History ─ */
.hist-item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 0; border-bottom: 1px solid var(--border);
}
.hist-item:last-child { border: none; }
.hist-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.hist-dot.present { background: var(--green); }
.hist-dot.late { background: var(--orange); }
.hist-dot.absent { background: var(--red); }
.hist-dot.leave { background: #6366f1; }
.hist-date { font-size: 12px; color: var(--muted); min-width: 70px; }
.hist-info { flex: 1; }
.hist-hours { font-size: 13px; font-weight: 700; color: var(--navy); }
.hist-times { font-size: 11px; color: var(--muted); margin-top: 1px; }

/* ─ Toast ─ */
#toast {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(80px);
    max-width: 340px; width: 90%; background: var(--navy); color: #fff;
    padding: 14px 18px; border-radius: 12px; font-size: 13px; font-weight: 600;
    box-shadow: 0 8px 30px rgba(0,0,0,0.25); z-index: 9999;
    transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1); display: flex; align-items: center; gap: 10px;
}
#toast.show { transform: translateX(-50%) translateY(0); }
#toast.success { background: linear-gradient(135deg, #059669, #10b981); }
#toast.error { background: linear-gradient(135deg, #dc2626, #ef4444); }
#toast.warning { background: linear-gradient(135deg, #ea580c, #f97316); }

/* ─ Loading Spinner ─ */
.spinner { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,0.3); border-top-color: #fff; border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ─ Bottom Nav ─ */
.bottom-nav {
    position: fixed; bottom: 0; left: 50%; transform: translateX(-50%);
    width: 100%; max-width: 480px; background: var(--card);
    border-top: 1px solid var(--border); display: flex;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.08); z-index: 100;
}
.nav-item {
    flex: 1; padding: 10px 8px 12px; display: flex; flex-direction: column;
    align-items: center; gap: 3px; cursor: pointer; transition: all 0.15s;
    border: none; background: none;
}
.nav-item .nav-icon { font-size: 20px; }
.nav-item .nav-label { font-size: 10px; font-weight: 600; color: var(--muted); }
.nav-item.active .nav-label { color: var(--navy); }
.nav-item.active .nav-icon { transform: scale(1.1); }
.nav-item:active { background: var(--bg); }

/* Content bottom padding for nav */
#screens-container { padding-bottom: 70px; }

/* ─ Sign Out ─ */
.logout-btn {
    position: absolute; top: 16px; right: 16px;
    background: rgba(255,255,255,0.1); border: none; color: rgba(255,255,255,0.7);
    border-radius: 8px; width: 34px; height: 34px; cursor: pointer; font-size: 16px;
    display: none; align-items: center; justify-content: center;
}
.logout-btn.visible { display: flex; }
.logout-btn:active { background: rgba(255,255,255,0.2); }

/* ─ Alert boxes ─ */
.alert { border-radius: 10px; padding: 12px 14px; font-size: 12px; font-weight: 600; margin-bottom: 12px; display: flex; align-items: flex-start; gap: 8px; }
.alert-warning { background: #fff7ed; color: #9a3412; border: 1px solid #fed7aa; }
.alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

/* ─ PIN Dots ─ */
.pin-dots { display: flex; gap: 10px; justify-content: center; margin-bottom: 16px; }
.pin-dot { width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--border); transition: all 0.2s; }
.pin-dot.filled { background: var(--navy); border-color: var(--navy); }

/* ─ Pulsar ─ */
.radar-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; margin: 8px 0; }
.radar-pulse {
    position: absolute; width: 60px; height: 60px; border-radius: 50%;
    background: rgba(240,180,41,0.2); animation: pulse 2s infinite;
}
.radar-pulse:nth-child(2) { animation-delay: 0.5s; width: 80px; height: 80px; }
@keyframes pulse { 0% { transform: scale(0.5); opacity: 1; } 100% { transform: scale(2); opacity: 0; } }
.radar-icon { font-size: 28px; position: relative; z-index: 1; }
</style>
</head>
<body>
<div id="app">

    <!-- TOP BAR -->
    <div class="topbar">
        <div class="topbar-logo">
            <div class="logo-circle">📍</div>
            <div>
                <h1>Absen Karyawan</h1>
                <div class="subtitle" id="topbarBusinessName">Sistem Absensi GPS</div>
            </div>
        </div>
        <button class="logout-btn" id="logoutBtn" onclick="doLogout()" title="Keluar">🚪</button>
        <div class="topbar-date">
            <div class="date-text" id="todayDate"></div>
            <div class="time-text" id="liveClock">00:00</div>
        </div>
    </div>

    <div id="screens-container">

    <!-- ════════════════════════════════════
         SCREEN 1: LOGIN / VERIFY EMPLOYEE
         ════════════════════════════════════ -->
    <div class="screen active" id="screenLogin">
        <div style="text-align:center; padding: 24px 0 18px;">
            <div class="radar-wrap">
                <div class="radar-pulse"></div>
                <div class="radar-pulse"></div>
                <div class="radar-icon">🧑‍💼</div>
            </div>
            <div style="font-size: 18px; font-weight: 800; color: var(--navy); margin-top: 10px;">Selamat Datang</div>
            <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">Masukkan kode & PIN untuk absen</div>
        </div>

        <div class="card">
            <div class="card-title">Identitas Karyawan</div>
            <div class="form-group">
                <label class="form-label">Kode Karyawan</label>
                <input type="text" id="inputCode" class="form-input" placeholder="Contoh: EMP-001" autocomplete="off" autocapitalize="characters" style="text-transform:uppercase">
            </div>
            <div class="form-group">
                <label class="form-label">PIN Absen</label>
                <div class="pin-dots" id="pinDots">
                    <div class="pin-dot" id="pd0"></div>
                    <div class="pin-dot" id="pd1"></div>
                    <div class="pin-dot" id="pd2"></div>
                    <div class="pin-dot" id="pd3"></div>
                    <div class="pin-dot" id="pd4"></div>
                    <div class="pin-dot" id="pd5"></div>
                </div>
                <input type="tel" id="inputPin" class="form-input pin-input" placeholder="••••" maxlength="6" onkeyup="updatePinDots()" inputmode="numeric">
                <div style="font-size: 11px; color: var(--muted); text-align:center; margin-top:6px;">PIN default: <strong>1234</strong> (ubah di menu admin)</div>
            </div>
            <button class="btn btn-primary" id="btnVerify" onclick="doVerify()">
                <span id="verifyBtnText">Masuk & Absen</span>
            </button>
        </div>

        <div class="alert alert-info" style="margin-top: 8px;">
            <span>💡</span>
            <div>Absensi harus dilakukan dari <strong>lokasi kantor</strong>. GPS akan diverifikasi otomatis.</div>
        </div>
    </div>

    <!-- ════════════════════════════════════
         SCREEN 2: MAIN ABSEN
         ════════════════════════════════════ -->
    <div class="screen" id="screenAbsen">

        <!-- Employee Info -->
        <div class="emp-card" id="empCard">
            <div class="emp-avatar" id="empAvatar">A</div>
            <div class="emp-name" id="empName">Nama Karyawan</div>
            <div class="emp-meta" id="empMeta">Jabatan • Departemen</div>
            <div class="emp-status-row" id="empStatusRow">
                <div class="emp-badge" id="empCodeBadge">EMP-001</div>
                <div class="emp-badge" id="empStatusBadge">Belum Absen</div>
            </div>
        </div>

        <!-- Attendance Status Row -->
        <div class="att-row">
            <div class="att-box" id="checkinBox">
                <div class="att-icon">☀️</div>
                <div class="att-label">Check-In</div>
                <div class="att-time empty" id="checkinTime">--:--</div>
            </div>
            <div class="att-box" id="checkoutBox">
                <div class="att-icon">🌙</div>
                <div class="att-label">Check-Out</div>
                <div class="att-time empty" id="checkoutTime">--:--</div>
            </div>
            <div class="att-box" id="workhourBox">
                <div class="att-icon">⏱️</div>
                <div class="att-label">Jam Kerja</div>
                <div class="att-time empty" id="workhoursDisplay">-</div>
            </div>
        </div>

        <!-- GPS Map Card -->
        <div class="gps-card">
            <div class="gps-header">
                <div class="gps-indicator loading" id="gpsIndicator"></div>
                <div class="gps-status-text" id="gpsStatusText">Mengambil lokasi GPS...</div>
                <div class="gps-accuracy" id="gpsAccuracy"></div>
            </div>
            <div id="map"></div>
            <div class="distance-bar-wrap">
                <div class="distance-row">
                    <span class="distance-label" id="officeNameLabel">📍 Kantor</span>
                    <span class="distance-value" id="distanceValue">Menghitung...</span>
                </div>
                <div class="distance-bar-track">
                    <div class="distance-bar-fill" id="distanceFill" style="width:0%"></div>
                </div>
                <div style="display:flex; justify-content:space-between; margin-top:4px;">
                    <span style="font-size:10px; color:var(--muted);">0m</span>
                    <span style="font-size:10px; color:var(--muted);" id="radiusLabel">Radius: 200m</span>
                </div>
            </div>
        </div>

        <!-- GPS Alert -->
        <div id="gpsAlert" style="display:none;"></div>

        <!-- Action Button -->
        <button class="btn btn-disabled" id="btnAction" onclick="doClockAction()" disabled>
            <span class="spinner" id="actionSpinner" style="display:none;"></span>
            <span id="btnActionText">⏳ Mengambil GPS...</span>
        </button>

        <div style="margin-top: 10px; text-align: center;">
            <button class="btn btn-ghost btn-sm" onclick="refreshGPS()" style="display:inline-flex; gap:6px; align-items:center;">
                🔄 Perbarui Lokasi
            </button>
        </div>
    </div>

    <!-- ════════════════════════════════════
         SCREEN 3: HISTORY
         ════════════════════════════════════ -->
    <div class="screen" id="screenHistory">
        <div class="card">
            <div class="card-title">📅 Riwayat Absen — 7 Hari Terakhir</div>
            <div id="historyList">
                <div style="text-align:center; padding:20px; color:var(--muted); font-size:13px;">
                    Login terlebih dahulu untuk melihat riwayat.
                </div>
            </div>
        </div>
        <div class="card" id="monthlySummaryCard" style="display:none;">
            <div class="card-title">📊 Ringkasan Bulan Ini</div>
            <div id="monthlySummary"></div>
        </div>
    </div>

    </div><!-- /screens-container -->

    <!-- Bottom Nav (visible after login) -->
    <div class="bottom-nav" id="bottomNav" style="display:none;">
        <button class="nav-item active" id="navAbsen" onclick="switchTab('absen')">
            <span class="nav-icon">📍</span>
            <span class="nav-label">Absen</span>
        </button>
        <button class="nav-item" id="navHistory" onclick="switchTab('history')">
            <span class="nav-icon">📅</span>
            <span class="nav-label">Riwayat</span>
        </button>
    </div>

</div><!-- /app -->

<!-- Toast -->
<div id="toast">
    <span id="toastIcon">✅</span>
    <span id="toastMsg">Pesan</span>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ───────────────────────────────────────
// GLOBAL STATE
// ───────────────────────────────────────
const API_URL = '<?php echo $baseUrl; ?>/modules/payroll/attendance-clock.php';
let currentEmp = null;
let todayAtt = null;
let officeConfig = null;
let myLat = null, myLng = null;
let leafletMap = null, myMarker = null, officeMarker = null, circleOverlay = null;
let gpsWatcher = null;
let mapInitialized = false;

// ───────────────────────────────────────
// LIVE CLOCK + DATE
// ───────────────────────────────────────
function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    document.getElementById('liveClock').textContent = h + ':' + m;
    
    const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];
    document.getElementById('todayDate').textContent =
        days[now.getDay()] + ', ' + now.getDate() + ' ' + months[now.getMonth()] + ' ' + now.getFullYear();
}
setInterval(updateClock, 1000);
updateClock();

// ───────────────────────────────────────
// PIN DOTS
// ───────────────────────────────────────
function updatePinDots() {
    const val = document.getElementById('inputPin').value;
    for (let i = 0; i < 6; i++) {
        const dot = document.getElementById('pd' + i);
        dot.classList.toggle('filled', i < val.length);
    }
}

// ───────────────────────────────────────
// VERIFY EMPLOYEE
// ───────────────────────────────────────
async function doVerify() {
    const code = document.getElementById('inputCode').value.trim().toUpperCase();
    const pin = document.getElementById('inputPin').value.trim();
    const btn = document.getElementById('btnVerify');
    const text = document.getElementById('verifyBtnText');

    if (!code || !pin) { showToast('Kode karyawan dan PIN wajib diisi.', 'error'); return; }

    btn.disabled = true;
    text.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px;display:inline-block;"></span> Memverifikasi...';

    try {
        const fd = new FormData();
        fd.append('action', 'verify');
        fd.append('employee_code', code);
        fd.append('pin', pin);
        const res = await fetch(API_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            showToast(data.message, 'error');
        } else {
            currentEmp = data.employee;
            todayAtt = data.today;
            officeConfig = data.config;
            onLoginSuccess();
        }
    } catch (e) {
        showToast('Koneksi gagal. Cek internet.', 'error');
    } finally {
        btn.disabled = false;
        text.textContent = 'Masuk & Absen';
    }
}

// Enter key
document.getElementById('inputPin').addEventListener('keydown', e => {
    if (e.key === 'Enter') doVerify();
});
document.getElementById('inputCode').addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('inputPin').focus();
});

// ───────────────────────────────────────
// AFTER LOGIN
// ───────────────────────────────────────
function onLoginSuccess() {
    // Fill employee card
    document.getElementById('empAvatar').textContent = currentEmp.name.charAt(0);
    document.getElementById('empName').textContent = currentEmp.name;
    document.getElementById('empMeta').textContent =
        (currentEmp.position || '-') + (currentEmp.department ? ' • ' + currentEmp.department : '');
    document.getElementById('empCodeBadge').textContent = currentEmp.code;

    // Fill attendance times
    fillAttendanceTimes();

    // Show bottom nav + logout button
    document.getElementById('bottomNav').style.display = 'flex';
    document.getElementById('logoutBtn').classList.add('visible');

    // Show office name
    document.getElementById('officeNameLabel').textContent = '📍 ' + (officeConfig.office_name || 'Kantor');
    document.getElementById('radiusLabel').textContent = 'Radius: ' + officeConfig.radius + 'm';

    // Switch to absen screen
    switchTab('absen');
    showScreen('screenAbsen');

    // Start GPS
    initMap();
    startGPS();

    // Load history
    loadHistory();
}

function fillAttendanceTimes() {
    if (!todayAtt) return;
    if (todayAtt.check_in_time) {
        const inT = todayAtt.check_in_time.substring(0, 5);
        const el = document.getElementById('checkinTime');
        el.textContent = inT;
        el.classList.remove('empty');
        document.getElementById('checkinBox').classList.add('has-data');
    }
    if (todayAtt.check_out_time) {
        const outT = todayAtt.check_out_time.substring(0, 5);
        const el = document.getElementById('checkoutTime');
        el.textContent = outT;
        el.classList.remove('empty');
        document.getElementById('checkoutBox').classList.add('has-data');
    }
    if (todayAtt.work_hours) {
        const el = document.getElementById('workhoursDisplay');
        el.textContent = parseFloat(todayAtt.work_hours).toFixed(1) + 'j';
        el.classList.remove('empty');
        document.getElementById('workhourBox').classList.add('has-data');
    }

    // Badge
    const badge = document.getElementById('empStatusBadge');
    if (todayAtt.check_out_time) {
        badge.textContent = '✅ Sudah Absen';
        badge.classList.add('green');
    } else if (todayAtt.check_in_time) {
        badge.textContent = '☀️ Sudah Masuk';
        badge.classList.add('orange');
    }

    // Update action button
    updateActionButton();
}

// ───────────────────────────────────────
// MAP + GPS
// ───────────────────────────────────────
function initMap() {
    if (mapInitialized) return;
    mapInitialized = true;
    const lat = officeConfig.office_lat || -6.2;
    const lng = officeConfig.office_lng || 106.82;

    leafletMap = L.map('map', { zoomControl: false, attributionControl: false }).setView([lat, lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
    }).addTo(leafletMap);

    // Office marker
    const officeIcon = L.divIcon({
        html: '<div style="background:#0d1f3c;color:#f0b429;border-radius:50%;width:34px;height:34px;display:flex;align-items:center;justify-content:center;font-size:16px;border:3px solid #f0b429;box-shadow:0 2px 8px rgba(0,0,0,0.3);">🏢</div>',
        className: '', iconAnchor: [17, 17]
    });
    officeMarker = L.marker([lat, lng], { icon: officeIcon }).addTo(leafletMap);
    officeMarker.bindPopup(officeConfig.office_name || 'Kantor');

    // Radius circle
    circleOverlay = L.circle([lat, lng], {
        radius: officeConfig.radius,
        color: '#f0b429', fillColor: '#f0b429', fillOpacity: 0.08, weight: 2, dashArray: '6,4'
    }).addTo(leafletMap);
}

function startGPS() {
    if (!navigator.geolocation) {
        setGPSStatus('error', 'GPS tidak didukung.', '');
        return;
    }
    setGPSStatus('loading', 'Mengambil lokasi...', '');

    gpsWatcher = navigator.geolocation.watchPosition(
        onGPSSuccess, onGPSError,
        { enableHighAccuracy: true, timeout: 20000, maximumAge: 10000 }
    );
}

function onGPSSuccess(pos) {
    myLat = pos.coords.latitude;
    myLng = pos.coords.longitude;
    const acc = Math.round(pos.coords.accuracy);

    setGPSStatus('ok', 'Lokasi terdeteksi', '±' + acc + 'm');

    // Update user marker on map
    const myIcon = L.divIcon({
        html: '<div style="background:#059669;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:14px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);">🧑</div>',
        className: '', iconAnchor: [14, 14]
    });
    if (myMarker) leafletMap.removeLayer(myMarker);
    myMarker = L.marker([myLat, myLng], { icon: myIcon }).addTo(leafletMap);
    myMarker.bindPopup('Lokasi Anda');

    // Fit map to show both markers
    if (officeConfig) {
        const bounds = L.latLngBounds(
            [officeConfig.office_lat, officeConfig.office_lng],
            [myLat, myLng]
        );
        leafletMap.fitBounds(bounds.pad(0.3));
    }

    // Distance calculation
    const dist = haversineDistance(myLat, myLng, officeConfig.office_lat, officeConfig.office_lng);
    updateDistanceBar(dist);
    updateActionButton(dist);
}

function onGPSError(err) {
    const msgs = {
        1: 'Izin GPS ditolak. Aktifkan di pengaturan browser.',
        2: 'GPS tidak tersedia. Coba di area terbuka.',
        3: 'GPS timeout. Coba lagi.'
    };
    setGPSStatus('error', msgs[err.code] || 'GPS error.', '');
    updateActionButton(99999);
}

function refreshGPS() {
    if (gpsWatcher !== null) { navigator.geolocation.clearWatch(gpsWatcher); gpsWatcher = null; }
    myLat = null; myLng = null;
    setGPSStatus('loading', 'Memperbarui lokasi...', '');
    updateActionButton(99999);
    startGPS();
}

function setGPSStatus(type, text, accuracy) {
    document.getElementById('gpsIndicator').className = 'gps-indicator ' + type;
    document.getElementById('gpsStatusText').textContent = text;
    document.getElementById('gpsAccuracy').textContent = accuracy;
}

function haversineDistance(lat1, lng1, lat2, lng2) {
    const R = 6371000;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2)*Math.sin(dLat/2) +
              Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*
              Math.sin(dLng/2)*Math.sin(dLng/2);
    return Math.round(2 * R * Math.asin(Math.sqrt(a)));
}

function updateDistanceBar(dist) {
    const radius = officeConfig ? officeConfig.radius : 200;
    const isInside = dist <= radius;
    const pct = Math.min(100, Math.round((dist / radius) * 100));

    const fill = document.getElementById('distanceFill');
    fill.style.width = pct + '%';
    fill.className = 'distance-bar-fill ' + (isInside ? '' : 'outside');

    const valEl = document.getElementById('distanceValue');
    valEl.textContent = dist + 'm dari kantor ' + (isInside ? '✅' : '❌');
    valEl.className = 'distance-value ' + (isInside ? 'inside' : 'outside');

    // GPS Alert
    const alertEl = document.getElementById('gpsAlert');
    if (!isInside) {
        alertEl.style.display = 'flex';
        alertEl.className = 'alert alert-warning';
        alertEl.innerHTML = '<span>⚠️</span><div>Anda berada <strong>' + dist + 'm</strong> dari kantor. Harap berpindah ke dalam radius <strong>' + radius + 'm</strong> untuk absen.</div>';
    } else {
        alertEl.style.display = 'flex';
        alertEl.className = 'alert alert-success';
        alertEl.innerHTML = '<span>✅</span><div>Anda berada dalam area kantor (<strong>' + dist + 'm</strong>). Siap untuk absen!</div>';
    }
}

function updateActionButton(dist) {
    const btn = document.getElementById('btnAction');
    const txt = document.getElementById('btnActionText');
    const hasCheckin = todayAtt && todayAtt.check_in_time;
    const hasCheckout = todayAtt && todayAtt.check_out_time;

    if (hasCheckin && hasCheckout) {
        btn.className = 'btn btn-disabled';
        btn.disabled = true;
        txt.textContent = '✅ Absen Selesai Hari Ini';
        return;
    }

    if (myLat === null) {
        btn.className = 'btn btn-disabled';
        btn.disabled = true;
        txt.textContent = '⏳ Menunggu GPS...';
        return;
    }

    const isInside = dist !== undefined ? dist <= (officeConfig ? officeConfig.radius : 200) : false;
    const allow = isInside || (officeConfig && officeConfig.allow_outside);

    if (!hasCheckin) {
        if (allow) {
            btn.className = 'btn btn-checkin';
            btn.disabled = false;
            txt.textContent = '☀️ Check-In Sekarang';
        } else {
            btn.className = 'btn btn-disabled';
            btn.disabled = true;
            txt.textContent = '📍 Di Luar Radius Kantor';
        }
    } else {
        if (allow) {
            btn.className = 'btn btn-checkout';
            btn.disabled = false;
            txt.textContent = '🌙 Check-Out Sekarang';
        } else {
            btn.className = 'btn btn-disabled';
            btn.disabled = true;
            txt.textContent = '📍 Di Luar Radius Kantor';
        }
    }
}

// ───────────────────────────────────────
// CLOCK ACTION
// ───────────────────────────────────────
async function doClockAction() {
    if (!currentEmp || myLat === null) return;
    const hasCheckin = todayAtt && todayAtt.check_in_time;
    const action = hasCheckin ? 'checkout' : 'checkin';

    const btn = document.getElementById('btnAction');
    const txt = document.getElementById('btnActionText');
    const spinner = document.getElementById('actionSpinner');

    btn.disabled = true;
    spinner.style.display = 'inline-block';
    txt.textContent = action === 'checkin' ? 'Menyimpan check-in...' : 'Menyimpan check-out...';

    try {
        // Reverse geocode (OpenStreetMap Nominatim)
        let address = '';
        try {
            const geoRes = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${myLat}&lon=${myLng}&zoom=16`, {
                headers: { 'Accept-Language': 'id' }
            });
            const geoData = await geoRes.json();
            address = geoData.display_name ? geoData.display_name.substring(0, 200) : '';
        } catch(e) {}

        const fd = new FormData();
        fd.append('action', action);
        fd.append('employee_id', currentEmp.id);
        fd.append('lat', myLat);
        fd.append('lng', myLng);
        fd.append('address', address);

        const res = await fetch(API_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            const now = new Date();
            const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');

            if (action === 'checkin') {
                if (!todayAtt) todayAtt = {};
                todayAtt.check_in_time = now.toTimeString().substring(0,8);
                const el = document.getElementById('checkinTime');
                el.textContent = timeStr;
                el.classList.remove('empty');
                document.getElementById('checkinBox').classList.add('has-data');
                document.getElementById('empStatusBadge').textContent = '☀️ Sudah Masuk';
                document.getElementById('empStatusBadge').classList.add('orange');
                updateActionButton(haversineDistance(myLat, myLng, officeConfig.office_lat, officeConfig.office_lng));
            } else {
                todayAtt.check_out_time = now.toTimeString().substring(0,8);
                const el = document.getElementById('checkoutTime');
                el.textContent = timeStr;
                el.classList.remove('empty');
                document.getElementById('checkoutBox').classList.add('has-data');
                if (data.work_hours) {
                    const whEl = document.getElementById('workhoursDisplay');
                    whEl.textContent = parseFloat(data.work_hours).toFixed(1) + 'j';
                    whEl.classList.remove('empty');
                    document.getElementById('workhourBox').classList.add('has-data');
                }
                document.getElementById('empStatusBadge').textContent = '✅ Sudah Absen';
                document.getElementById('empStatusBadge').classList.remove('orange');
                document.getElementById('empStatusBadge').classList.add('green');
                updateActionButton(0);
            }
            loadHistory();
        } else {
            showToast(data.message, 'error');
            updateActionButton(haversineDistance(myLat, myLng, officeConfig.office_lat, officeConfig.office_lng));
        }
    } catch(e) {
        showToast('Koneksi gagal. Coba lagi.', 'error');
    } finally {
        spinner.style.display = 'none';
    }
}

// ───────────────────────────────────────
// LOAD HISTORY
// ───────────────────────────────────────
async function loadHistory() {
    if (!currentEmp) return;
    try {
        const fd = new FormData();
        fd.append('action', 'history');
        fd.append('employee_id', currentEmp.id);
        const res = await fetch(API_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        let html = '';
        if (!data.history || data.history.length === 0) {
            html = '<div style="text-align:center; padding:20px; color:var(--muted); font-size:13px;">Belum ada riwayat absen.</div>';
        } else {
            data.history.forEach(row => {
                const dotClass = row.status === 'late' ? 'late' : (row.status === 'absent' ? 'absent' : 'present');
                const inT = row.check_in_time ? row.check_in_time.substring(0,5) : '--:--';
                const outT = row.check_out_time ? row.check_out_time.substring(0,5) : '--:--';
                const wh = row.work_hours ? parseFloat(row.work_hours).toFixed(1) + ' jam' : '-';
                const statusLabel = { present: 'Hadir', late: 'Terlambat', absent: 'Absen', leave: 'Izin', holiday: 'Libur', half_day: 'Setengah Hari' };
                html += `<div class="hist-item">
                    <div class="hist-dot ${dotClass}"></div>
                    <div class="hist-date">${row.attendance_date}</div>
                    <div class="hist-info">
                        <div class="hist-hours">${wh} <span style="font-size:10px;color:var(--muted);">${statusLabel[row.status]||row.status}</span></div>
                        <div class="hist-times">${inT} → ${outT}</div>
                    </div>
                </div>`;
            });
        }
        document.getElementById('historyList').innerHTML = html;

        // Monthly summary
        if (data.summary) {
            const s = data.summary;
            document.getElementById('monthlySummary').innerHTML = `
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                    <div style="text-align:center;"><div style="font-size:22px;font-weight:800;color:var(--navy);">${s.present}</div><div style="font-size:11px;color:var(--muted);">Hadir</div></div>
                    <div style="text-align:center;"><div style="font-size:22px;font-weight:800;color:var(--orange);">${s.late}</div><div style="font-size:11px;color:var(--muted);">Terlambat</div></div>
                    <div style="text-align:center;"><div style="font-size:22px;font-weight:800;color:var(--green);">${parseFloat(s.avg_hours||0).toFixed(1)}j</div><div style="font-size:11px;color:var(--muted);">Avg Jam/hari</div></div>
                </div>`;
            document.getElementById('monthlySummaryCard').style.display = 'block';
        }
    } catch(e) {}
}

// ───────────────────────────────────────
// TABS + SCREENS
// ───────────────────────────────────────
function switchTab(tab) {
    ['navAbsen','navHistory'].forEach(id => document.getElementById(id).classList.remove('active'));
    if (tab === 'absen') {
        document.getElementById('navAbsen').classList.add('active');
        showScreen('screenAbsen');
        // Resize map after tab switch
        setTimeout(() => { if (leafletMap) leafletMap.invalidateSize(); }, 200);
    } else {
        document.getElementById('navHistory').classList.add('active');
        showScreen('screenHistory');
    }
}

function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

// ───────────────────────────────────────
// LOGOUT
// ───────────────────────────────────────
function doLogout() {
    currentEmp = null; todayAtt = null; officeConfig = null;
    myLat = null; myLng = null;
    if (gpsWatcher !== null) { navigator.geolocation.clearWatch(gpsWatcher); gpsWatcher = null; }
    document.getElementById('bottomNav').style.display = 'none';
    document.getElementById('logoutBtn').classList.remove('visible');
    document.getElementById('inputPin').value = '';
    document.getElementById('inputCode').value = '';
    updatePinDots();
    showScreen('screenLogin');
    mapInitialized = false;
    if (leafletMap) { leafletMap.remove(); leafletMap = null; myMarker = null; officeMarker = null; circleOverlay = null; }
}

// ───────────────────────────────────────
// TOAST
// ───────────────────────────────────────
let toastTimer = null;
function showToast(msg, type = 'success') {
    const el = document.getElementById('toast');
    const icons = { success: '✅', error: '❌', warning: '⚠️' };
    document.getElementById('toastIcon').textContent = icons[type] || '💬';
    document.getElementById('toastMsg').textContent = msg;
    el.className = type;
    el.classList.add('show');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), 4000);
}
</script>
</body>
</html>
