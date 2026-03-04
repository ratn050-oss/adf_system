<?php
/**
 * Absensi via Face Detection - Halaman Mobile Staff
 * Public page — verifikasi lewat pengenalan wajah (face-api.js)
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

// ── Resolve Business Context (public page — no session) ──────────────────
$bizSlug      = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizConfigDir = __DIR__ . '/../../config/businesses/';
$bizConfig    = null;

if ($bizSlug) {
    $file = $bizConfigDir . $bizSlug . '.php';
    if (file_exists($file)) {
        $bizConfig = require $file;
    }
}

// If no slug provided, auto-detect: use first available business
if (!$bizConfig) {
    $files = glob($bizConfigDir . '*.php');
    if ($files) {
        $bizConfig = require $files[0];
        $bizSlug   = $bizConfig['business_id'];
    }
}

if (!$bizConfig) {
    die('<p style="font-family:sans-serif;padding:40px;color:#dc2626;">❌ Business tidak ditemukan. Hubungi admin.</p>');
}

// Define so Database::getInstance() connects to correct DB
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizConfig['business_id']);
if (!defined('BUSINESS_TYPE'))      define('BUSINESS_TYPE',      $bizConfig['business_type'] ?? 'other');

$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$apiUrl  = $baseUrl . '/modules/payroll/attendance-clock.php?b=' . urlencode($bizSlug);

// Load employee list for name picker — force correct business DB directly
$db = Database::switchDatabase($bizConfig['database']);
$empList = $db->fetchAll("SELECT id, employee_code, full_name, position, department FROM payroll_employees WHERE is_active = 1 ORDER BY full_name") ?: [];
$bizName = htmlspecialchars($bizConfig['name'] ?? 'Absensi');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0d1f3c">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Absensi">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Absensi · <?php echo $bizName; ?></title>
<link rel="manifest" href="absen-manifest.json?b=<?php echo urlencode($bizSlug); ?>">
<link rel="apple-touch-icon" href="../../assets/icons/absen-icon-192.svg">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent;}
body{font-family:'Inter',sans-serif;background:#0d1f3c;min-height:100vh;overflow:hidden;}
.screen{display:none;height:100vh;overflow-y:auto;}
.screen.active{display:flex;flex-direction:column;}

/* ── LOADING ── */
#loadingScreen{position:fixed;inset:0;background:#0d1f3c;z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;}
.load-icon{font-size:48px;animation:pulse 1.2s infinite;}
.load-title{color:#f0b429;font-size:18px;font-weight:800;margin:16px 0 6px;}
.load-sub{color:rgba(255,255,255,0.5);font-size:12px;margin-bottom:20px;text-align:center;}
.load-bar{width:200px;height:4px;background:rgba(255,255,255,0.15);border-radius:2px;overflow:hidden;}
.load-fill{height:100%;background:#f0b429;border-radius:2px;transition:width 0.4s;width:0%;}
@keyframes pulse{0%,100%{transform:scale(1);}50%{transform:scale(1.1);}}

/* ── SCREEN 1: NAME PICKER ── */
#screenCode{background:#0d1f3c;align-items:flex-start;justify-content:flex-start;padding:0;}
.logo-area{text-align:center;padding:28px 24px 12px;width:100%;}
.logo-area .icon{font-size:44px;margin-bottom:6px;}
.logo-area h1{color:#fff;font-size:20px;font-weight:800;}
.logo-area p{color:rgba(255,255,255,0.5);font-size:12px;margin-top:3px;}
.search-wrap{padding:0 16px 10px;width:100%;}
.search-box{width:100%;background:rgba(255,255,255,0.1);border:1.5px solid rgba(255,255,255,0.2);border-radius:10px;padding:12px 14px 12px 38px;color:#fff;font-size:14px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='rgba(255,255,255,0.4)' stroke-width='2' viewBox='0 0 24 24'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:12px center;}
.search-box::placeholder{color:rgba(255,255,255,0.35);}
.search-box:focus{outline:none;border-color:#f0b429;}
.emp-list{width:100%;padding:0 16px 24px;overflow-y:auto;flex:1;display:flex;flex-direction:column;gap:8px;}
.emp-pick-card{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:14px 16px;cursor:pointer;transition:all 0.15s;width:100%;text-align:left;}
.emp-pick-card:active{background:rgba(240,180,41,0.15);border-color:rgba(240,180,41,0.5);transform:scale(0.98);}
.emp-pick-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#f0b429,#e09000);display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;color:#0d1f3c;flex-shrink:0;}
.emp-pick-info{flex:1;}
.emp-pick-name{color:#fff;font-size:14px;font-weight:700;margin-bottom:2px;}
.emp-pick-pos{color:rgba(255,255,255,0.5);font-size:11px;}
.emp-pick-arrow{color:rgba(255,255,255,0.3);font-size:18px;}
.emp-empty{text-align:center;color:rgba(255,255,255,0.4);font-size:13px;padding:30px 0;}
.err-msg{background:rgba(220,38,38,0.15);border:1px solid rgba(220,38,38,0.3);border-radius:8px;color:#fca5a5;font-size:12px;padding:10px 12px;margin-top:12px;display:none;}

/* ── SCREEN 2: FACE SCAN ── */
#screenFace{background:#000;position:relative;}
.face-topbar{position:absolute;top:0;left:0;right:0;z-index:10;display:flex;align-items:center;gap:10px;padding:12px 16px;background:linear-gradient(to bottom,rgba(0,0,0,0.7),transparent);}
.btn-back{background:rgba(255,255,255,0.15);border:none;color:#fff;padding:7px 14px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;}
.face-emp-badge{background:rgba(240,180,41,0.2);border:1px solid rgba(240,180,41,0.4);border-radius:20px;padding:5px 12px;color:#f0b429;font-size:12px;font-weight:700;}
#faceVideo{width:100%;height:100vh;object-fit:cover;transform:scaleX(-1);}/* mirror */
#faceCanvas{position:absolute;inset:0;width:100%;height:100%;transform:scaleX(-1);}
.face-overlay-ring{position:absolute;top:50%;left:50%;transform:translate(-50%,-60%);width:220px;height:220px;border-radius:50%;border:3px solid rgba(240,180,41,0.7);box-shadow:0 0 0 4px rgba(240,180,41,0.15);pointer-events:none;}
.face-bottom{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(to top, rgba(0,0,0,0.95) 60%, transparent);padding:20px 20px 40px;text-align:center;}
.face-status-text{color:#fff;font-size:15px;font-weight:600;margin-bottom:14px;min-height:22px;}
.match-meter{height:8px;background:rgba(255,255,255,0.15);border-radius:4px;overflow:hidden;margin:0 auto 8px;max-width:240px;display:none;}
.match-fill{height:100%;border-radius:4px;transition:width 0.3s,background 0.3s;}
.match-label{color:rgba(255,255,255,0.7);font-size:13px;font-weight:600;}
.btn-capture{padding:15px 32px;background:#f0b429;color:#0d1f3c;border:none;border-radius:12px;font-size:15px;font-weight:800;cursor:pointer;margin-top:8px;width:100%;max-width:280px;display:none;}
.register-hint{color:rgba(255,255,255,0.6);font-size:12px;margin-bottom:12px;line-height:1.4;}

/* ── SCREEN 3: ABSEN ── */
#screenAbsen{background:#f0f4f8;}
.absen-header{background:linear-gradient(135deg,#0d1f3c,#1a3a5c);padding:20px 16px 16px;color:#fff;}
.emp-card{display:flex;align-items:center;gap:12px;}
.emp-avatar{width:46px;height:46px;border-radius:50%;background:#f0b429;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;color:#0d1f3c;flex-shrink:0;}
.emp-info h3{font-size:16px;font-weight:700;margin-bottom:2px;}
.emp-info p{font-size:12px;color:rgba(255,255,255,0.65);}
.time-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 16px;}
.time-box{background:#fff;border-radius:10px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,0.06);}
.time-box .tb-label{font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;}
.time-box .tb-val{font-size:22px;font-weight:800;color:#0d1f3c;margin-top:2px;}
.time-box .tb-val.dim{color:#cbd5e1;font-size:18px;}
#mapContainer{margin:0 16px 12px;border-radius:12px;overflow:hidden;height:180px;border:1px solid #e2e8f0;}
.dist-wrap{margin:0 16px 14px;background:#fff;border-radius:10px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,0.06);}
.dist-label-row{display:flex;justify-content:space-between;font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;}
.dist-bar{height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;}
.dist-fill{height:100%;border-radius:4px;transition:width 0.5s,background 0.3s;}
.gps-note{font-size:11px;color:#94a3b8;margin-top:5px;}
.btn-clock{margin:0 16px 16px;padding:16px;width:calc(100% - 32px);border:none;border-radius:12px;font-size:16px;font-weight:800;cursor:pointer;transition:all 0.2s;}
.btn-clock:active{transform:scale(0.98);}
.btn-clock.checkin{background:#059669;color:#fff;}
.btn-clock.checkout{background:#ea580c;color:#fff;}
.btn-clock.outside{background:#94a3b8;color:#fff;cursor:not-allowed;opacity:0.85;}
.btn-clock.done{background:#e2e8f0;color:#94a3b8;cursor:not-allowed;}

/* ── SCREEN 4: HISTORY ── */
#screenHistory{background:#f0f4f8;}
.hist-header{background:linear-gradient(135deg,#0d1f3c,#1a3a5c);padding:20px 16px;color:#fff;}
.hist-header h2{font-size:16px;font-weight:700;}
.summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:12px 16px;}
.sum-box{background:#fff;border-radius:10px;padding:12px 8px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,0.05);}
.sum-box .sv{font-size:22px;font-weight:800;color:#0d1f3c;}
.sum-box .sl{font-size:10px;color:#64748b;text-transform:uppercase;font-weight:700;margin-top:2px;}
.hist-list{padding:0 16px 80px;}
.hist-item{background:#fff;border-radius:10px;padding:12px 14px;margin-bottom:8px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,0.05);}
.hist-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.hist-dot.present{background:#059669;}
.hist-dot.late{background:#ea580c;}
.hist-dot.absent{background:#dc2626;}
.hist-dot.leave{background:#6366f1;}
.hist-dot.notyet{background:#cbd5e1;}
.hist-date{font-size:12px;font-weight:700;color:#0d1f3c;min-width:80px;}
.hist-times{flex:1;font-size:11px;color:#64748b;}
.hist-badge{font-size:10px;font-weight:700;padding:3px 8px;border-radius:5px;}
.hb-present{background:#dcfce7;color:#166534;}
.hb-late{background:#ffedd5;color:#9a3412;}
.hb-absent{background:#fee2e2;color:#991b1b;}
.hb-leave{background:#e0e7ff;color:#3730a3;}

/* ── BOTTOM NAV ── */
#bottomNav{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e2e8f0;display:none;z-index:100;box-shadow:0 -4px 12px rgba(0,0,0,0.08);}
#bottomNav.visible{display:flex;}
.nav-btn{flex:1;padding:12px 8px 10px;border:none;background:none;cursor:pointer;font-size:11px;font-weight:700;color:#94a3b8;display:flex;flex-direction:column;align-items:center;gap:3px;transition:color 0.15s;}
.nav-btn .nav-icon{font-size:20px;}
.nav-btn.active{color:#f0b429;}

/* Spinner */
.spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(0,0,0,0.2);border-top-color:#0d1f3c;border-radius:50%;animation:spin 0.7s linear infinite;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg);}}
.toast{position:fixed;bottom:90px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:12px 20px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;opacity:0;transition:opacity 0.3s;pointer-events:none;max-width:85%;text-align:center;}
.toast.show{opacity:1;}
/* ── PWA Install Banner ── */
#installBanner{position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#1a3a5c,#0d1f3c);border-top:2px solid rgba(240,180,41,0.4);padding:14px 16px;display:none;align-items:center;gap:12px;z-index:9998;box-shadow:0 -4px 20px rgba(0,0,0,0.4);animation:slideUp 0.4s ease;}
#installBanner.show{display:flex;}
.ib-icon{font-size:36px;flex-shrink:0;}
.ib-text{flex:1;}
.ib-text b{display:block;color:#f0b429;font-size:14px;font-weight:800;margin-bottom:2px;}
.ib-text span{color:rgba(255,255,255,0.65);font-size:11px;line-height:1.3;}
.ib-btns{display:flex;flex-direction:column;gap:6px;flex-shrink:0;}
.ib-install{background:#f0b429;color:#0d1f3c;border:none;border-radius:8px;padding:8px 16px;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;}
.ib-dismiss{background:transparent;color:rgba(255,255,255,0.45);border:none;font-size:11px;cursor:pointer;text-align:center;padding:2px;}
@keyframes slideUp{from{transform:translateY(100%);}to{transform:translateY(0);}}
</style>
</head>
<body>

<!-- Loading Screen -->
<div id="loadingScreen">
    <div class="load-icon">👁️</div>
    <div class="load-title">Face ID Absensi</div>
    <div class="load-sub" id="loadText">Memuat model pengenalan wajah...<br>Harap tunggu sebentar</div>
    <div class="load-bar"><div class="load-fill" id="loadFill"></div></div>
</div>

<!-- Screen 1: Pilih Nama -->
<div id="screenCode" class="screen">
    <div class="logo-area">
        <div class="icon">🏢</div>
        <h1>Absensi Karyawan</h1>
        <p><?php echo $bizName; ?></p>
    </div>
    <div class="search-wrap">
        <input type="text" id="searchEmp" class="search-box" placeholder="Cari nama karyawan..." oninput="filterEmp()">
    </div>
    <div class="emp-list" id="empList">
        <?php if (empty($empList)): ?>
        <div class="emp-empty">Belum ada karyawan terdaftar.<br>Tambahkan karyawan di menu Payroll → Employee Data.</div>
        <?php else: ?>
        <?php foreach ($empList as $emp): ?>
        <button class="emp-pick-card" onclick="selectEmployee(<?php echo $emp['id']; ?>, '<?php echo addslashes(htmlspecialchars($emp['full_name'])); ?>')" data-name="<?php echo strtolower(htmlspecialchars($emp['full_name'])); ?>">
            <div class="emp-pick-avatar"><?php echo mb_strtoupper(mb_substr($emp['full_name'],0,1)); ?></div>
            <div class="emp-pick-info">
                <div class="emp-pick-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                <div class="emp-pick-pos"><?php echo htmlspecialchars($emp['position']); ?><?php echo $emp['department'] ? ' · '.$emp['department'] : ''; ?></div>
            </div>
            <span class="emp-pick-arrow">›</span>
        </button>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="err-msg" id="codeError" style="margin:0 16px 16px;"></div>
</div>

<!-- PWA Install Banner -->
<div id="installBanner">
    <div class="ib-icon">📲</div>
    <div class="ib-text">
        <b>Install Aplikasi Absensi</b>
        <span>Tambahkan ke homescreen HP<br>untuk akses cepat tanpa buka browser</span>
    </div>
    <div class="ib-btns">
        <button class="ib-install" id="btnInstall">⬇️ Install</button>
        <button class="ib-dismiss" onclick="dismissInstall()">Nanti saja</button>
    </div>
</div>

<!-- Screen 2: Face Scan -->
<div id="screenFace" class="screen" style="background:#000; position:relative; overflow:hidden;">
    <div class="face-topbar">
        <button class="btn-back" onclick="backToCode()">← Kembali</button>
        <div class="face-emp-badge" id="faceEmpBadge">...</div>
    </div>
    <video id="faceVideo" autoplay muted playsinline></video>
    <canvas id="faceCanvas" style="display:none;"></canvas>
    <div class="face-overlay-ring" id="faceRing"></div>
    <div class="face-bottom">
        <div class="face-status-text" id="faceStatus">Mendeteksi wajah...</div>
        <div class="match-meter" id="matchMeter">
            <div class="match-fill" id="matchFill" style="width:0%;background:#f0b429;"></div>
        </div>
        <div class="match-label" id="matchLabel"></div>
        <div class="register-hint" id="registerHint" style="display:none;">
            Wajah Anda belum terdaftar di sistem.<br>Posisikan wajah dalam lingkaran lalu ambil foto.
        </div>
        <button class="btn-capture" id="btnCapture" onclick="captureSelfie()">
            📸 Daftarkan Wajah Saya
        </button>
    </div>
</div>

<!-- Screen 3: Absen -->
<div id="screenAbsen" class="screen">
    <div class="absen-header">
        <div class="emp-card">
            <div class="emp-avatar" id="empAvatarLetter">?</div>
            <div class="emp-info">
                <h3 id="empName">—</h3>
                <p id="empPosition">—</p>
            </div>
        </div>
        <div style="margin-top:10px; font-size:11px; color:rgba(255,255,255,0.5); text-align:right;" id="todayDateLabel"></div>
    </div>
    <div class="time-grid">
        <div class="time-box">
            <div class="tb-label">Check-In</div>
            <div class="tb-val" id="displayCheckIn"><span class="dim">—</span></div>
        </div>
        <div class="time-box">
            <div class="tb-label">Check-Out</div>
            <div class="tb-val" id="displayCheckOut"><span class="dim">—</span></div>
        </div>
        <div class="time-box">
            <div class="tb-label">Jam Kerja</div>
            <div class="tb-val" id="displayHours"><span class="dim">—</span></div>
        </div>
        <div class="time-box">
            <div class="tb-label">Status</div>
            <div class="tb-val" id="displayStatus" style="font-size:13px;"><span class="dim">—</span></div>
        </div>
    </div>
    <div id="mapContainer"></div>
    <div class="dist-wrap">
        <div class="dist-label-row">
            <span>📍 Jarak ke Kantor</span>
            <span id="distText">Mengambil GPS...</span>
        </div>
        <div class="dist-bar"><div class="dist-fill" id="distFill" style="width:0%;background:#e2e8f0;"></div></div>
        <div class="gps-note" id="gpsAccuracy"></div>
    </div>
    <button class="btn-clock done" id="btnClock" onclick="doClock()" disabled>
        ⌛ Mengambil GPS...
    </button>
</div>

<!-- Screen 4: History -->
<div id="screenHistory" class="screen">
    <div class="hist-header">
        <h2>📋 Riwayat Absen</h2>
        <p style="font-size:12px; color:rgba(255,255,255,0.5); margin-top:4px;" id="histEmpName"></p>
    </div>
    <div class="summary-grid" id="summaryGrid"></div>
    <div class="hist-list" id="histList"></div>
</div>

<!-- Bottom Nav -->
<nav id="bottomNav">
    <button class="nav-btn active" id="navAbsen" onclick="goAbsen()">
        <span class="nav-icon">📍</span>Absen
    </button>
    <button class="nav-btn" id="navHistory" onclick="goHistory()">
        <span class="nav-icon">📋</span>Riwayat
    </button>
</nav>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script src="https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/dist/face-api.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const API_URL = '<?php echo $apiUrl; ?>';
<?php
$localWeights = __DIR__ . '/../../assets/face-weights/tiny_face_detector_model-weights_manifest.json';
$modelPath = file_exists($localWeights)
    ? $baseUrl . '/assets/face-weights'
    : 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
?>
const MODEL_URL = '<?php echo $modelPath; ?>';

let currentEmployee  = null;
let storedDescriptor = null; // Float32Array
let cameraStream     = null;
let verifyInterval   = null;
let verifyMode       = false; // false=register, true=verify
let faceDetected     = false;
let captureReady     = false;

let leafletMap = null, officeMarker, radiusCircle, userMarker;
let gpsWatcher = null;
let currentGPS = null;
let officeConfig = null;

// ────────────────────────────────────────────────────────
//  1. LOAD FACE-API MODELS
// ────────────────────────────────────────────────────────
async function loadModels() {
    const fill = id => document.getElementById('loadFill').style.width = id + '%';
    try {
        document.getElementById('loadText').textContent = 'Memuat model deteksi wajah... (1/3)';
        fill(15);
        await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
        fill(45);
        document.getElementById('loadText').textContent = 'Memuat model titik wajah... (2/3)';
        await faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL);
        fill(75);
        document.getElementById('loadText').textContent = 'Memuat model pengenalan wajah... (3/3)';
        await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
        fill(100);
        setTimeout(() => {
            document.getElementById('loadingScreen').style.display = 'none';
            showScreen('screenCode');
        }, 400);
    } catch (err) {
        document.getElementById('loadText').innerHTML =
            '❌ Gagal memuat model.<br><small>' + err.message + '</small><br><button onclick="loadModels()" style="margin-top:12px;padding:8px 20px;background:#f0b429;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Coba Lagi</button>';
    }
}

// ────────────────────────────────────────────────────────
//  2. SCREEN NAVIGATION
// ────────────────────────────────────────────────────────
function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
}

function goAbsen() {
    showScreen('screenAbsen');
    document.getElementById('navAbsen').classList.add('active');
    document.getElementById('navHistory').classList.remove('active');
    if (leafletMap) leafletMap.invalidateSize();
}

function goHistory() {
    showScreen('screenHistory');
    document.getElementById('navAbsen').classList.remove('active');
    document.getElementById('navHistory').classList.add('active');
    loadHistory();
}

// ────────────────────────────────────────────────────────
//  SEARCH FILTER
// ────────────────────────────────────────────────────────
function filterEmp() {
    const q = document.getElementById('searchEmp').value.toLowerCase();
    document.querySelectorAll('.emp-pick-card').forEach(card => {
        card.style.display = card.dataset.name.includes(q) ? '' : 'none';
    });
}

// ────────────────────────────────────────────────────────
//  3. SELECT EMPLOYEE BY ID
// ────────────────────────────────────────────────────────
async function selectEmployee(empId, displayName) {
    showToast('⏳ Memuat data ' + displayName + '...');
    try {
        const fd = new FormData();
        fd.append('action', 'get_employee');
        fd.append('employee_id', empId);
        const res  = await fetch(API_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) { showToast('❌ ' + data.message); return; }

        currentEmployee = data.employee;
        officeConfig    = data.config;
        currentEmployee._today = data.today;

        if (data.employee.has_face && data.employee.face_descriptor) {
            storedDescriptor = new Float32Array(data.employee.face_descriptor);
            verifyMode = true;
        } else {
            storedDescriptor = null;
            verifyMode = false;
        }

        showScreen('screenFace');
        document.getElementById('faceEmpBadge').textContent = currentEmployee.name;
        await startCamera();

    } catch (err) {
        showToast('❌ Jaringan error: ' + err.message);
    }
}

function backToCode() {
    stopCamera();
    clearInterval(verifyInterval);
    showScreen('screenCode');
}

// ────────────────────────────────────────────────────────
//  4. CAMERA & FACE DETECTION
// ────────────────────────────────────────────────────────
async function startCamera() {
    try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
            audio: false
        });
        const video = document.getElementById('faceVideo');
        video.srcObject = cameraStream;
        await video.play();
        video.addEventListener('loadedmetadata', () => {
            // Resize canvas to match video
            const canvas = document.getElementById('faceCanvas');
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
        });
        // Show UI based on mode
        if (verifyMode) {
            setFaceStatus('Arahkan wajah ke kamera untuk verifikasi...');
            document.getElementById('matchMeter').style.display = 'block';
        } else {
            setFaceStatus('Posisikan wajah dalam lingkaran');
            document.getElementById('registerHint').style.display = 'block';
            document.getElementById('btnCapture').style.display = 'block';
        }
        startDetectionLoop();
    } catch (err) {
        setFaceStatus('❌ Kamera tidak dapat diakses: ' + err.message);
    }
}

function stopCamera() {
    clearInterval(verifyInterval);
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
}

function startDetectionLoop() {
    clearInterval(verifyInterval);
    verifyInterval = setInterval(detectLoop, 800);
}

async function detectLoop() {
    const video = document.getElementById('faceVideo');
    if (!video.readyState || video.readyState < 2) return;
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
    const detection = await faceapi.detectSingleFace(video, options)
        .withFaceLandmarks(true)
        .withFaceDescriptor();

    faceDetected = !!detection;
    captureReady = faceDetected;

    // Draw bounding box
    drawBox(detection, video);

    if (!detection) {
        setFaceStatus('😐 Wajah tidak terdeteksi — hadap kamera');
        document.getElementById('faceRing').style.borderColor = 'rgba(240,180,41,0.5)';
        if (verifyMode) { hideMeter(); }
        return;
    }

    document.getElementById('faceRing').style.borderColor = '#f0b429';

    if (verifyMode) {
        // Compare with stored
        const dist = faceapi.euclideanDistance(storedDescriptor, detection.descriptor);
        const score = Math.max(0, Math.min(100, Math.round((1 - dist / 0.6) * 100)));
        updateMeter(score);

        if (dist < 0.45) {
            setFaceStatus('✅ Wajah terkenali! Masuk...');
            clearInterval(verifyInterval);
            document.getElementById('faceRing').style.borderColor = '#059669';
            setTimeout(onFaceVerified, 700);
        } else if (dist < 0.6) {
            setFaceStatus('🔄 Hampir cocok, posisikan wajah lebih baik');
        } else {
            setFaceStatus('⚠️ Wajah tidak cocok — coba lagi');
        }
    } else {
        // Register mode - just show ready
        setFaceStatus('✅ Wajah terdeteksi — siap ambil foto');
    }
}

function drawBox(detection, video) {
    const canvas = document.getElementById('faceCanvas');
    if (!canvas.width) return;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (!detection) return;
    const dims = { width: video.videoWidth, height: video.videoHeight };
    const resized = faceapi.resizeResults(detection, dims);
    const box = resized.detection.box;
    ctx.strokeStyle = faceDetected ? '#f0b429' : 'rgba(255,255,255,0.4)';
    ctx.lineWidth = 2;
    ctx.strokeRect(box.x, box.y, box.width, box.height);
}

function setFaceStatus(msg) { document.getElementById('faceStatus').textContent = msg; }

function updateMeter(score) {
    const fill  = document.getElementById('matchFill');
    const label = document.getElementById('matchLabel');
    fill.style.width = score + '%';
    fill.style.background = score > 70 ? '#059669' : score > 45 ? '#f0b429' : '#dc2626';
    label.textContent = 'Kecocokan: ' + score + '%';
}

function hideMeter() {
    document.getElementById('matchFill').style.width = '0%';
    document.getElementById('matchLabel').textContent = '';
}

// ────────────────────────────────────────────────────────
//  5. REGISTER FACE (selfie)
// ────────────────────────────────────────────────────────
async function captureSelfie() {
    if (!captureReady || !faceDetected) {
        showToast('⚠️ Pastikan wajah terdeteksi terlebih dahulu'); return;
    }
    const btn = document.getElementById('btnCapture');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Mengambil foto...';
    clearInterval(verifyInterval);
    const video = document.getElementById('faceVideo');
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320 });
    const detection = await faceapi.detectSingleFace(video, options)
        .withFaceLandmarks(true)
        .withFaceDescriptor();

    if (!detection) {
        showToast('❌ Gagal mendeteksi wajah. Coba lagi.');
        btn.disabled = false;
        btn.innerHTML = '📸 Daftarkan Wajah Saya';
        startDetectionLoop();
        return;
    }

    const descriptorArr = Array.from(detection.descriptor);
    const fd = new FormData();
    fd.append('action', 'register_face');
    fd.append('employee_id', currentEmployee.id);
    fd.append('face_descriptor', JSON.stringify(descriptorArr));

    try {
        const res  = await fetch(API_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            setFaceStatus('✅ Wajah berhasil didaftarkan!');
            storedDescriptor = new Float32Array(descriptorArr);
            verifyMode = true;
            document.getElementById('registerHint').style.display = 'none';
            document.getElementById('btnCapture').style.display = 'none';
            document.getElementById('matchMeter').style.display = 'block';
            showToast('✅ Wajah terdaftar! Silakan scan untuk masuk.');
            setTimeout(() => {
                setFaceStatus('Arahkan wajah untuk verifikasi...');
                startDetectionLoop();
            }, 1500);
        } else {
            showToast('❌ ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '📸 Daftarkan Wajah Saya';
            startDetectionLoop();
        }
    } catch (err) {
        showToast('❌ Jaringan error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '📸 Daftarkan Wajah Saya';
        startDetectionLoop();
    }
}

// ────────────────────────────────────────────────────────
//  6. ON FACE VERIFIED → Show Absen Screen
// ────────────────────────────────────────────────────────
function onFaceVerified() {
    stopCamera();
    document.getElementById('bottomNav').classList.add('visible');
    // Fill employee info
    const emp = currentEmployee;
    document.getElementById('empName').textContent = emp.name;
    document.getElementById('empPosition').textContent = emp.position + (emp.department ? ' · ' + emp.department : '');
    document.getElementById('empAvatarLetter').textContent = emp.name.charAt(0).toUpperCase();
    document.getElementById('todayDateLabel').textContent = new Date().toLocaleDateString('id-ID', { weekday:'long', day:'numeric', month:'long', year:'numeric' });
    document.getElementById('histEmpName').textContent = emp.name;
    // Fill today data
    const today = emp._today;
    if (today) {
        if (today.check_in_time)  document.getElementById('displayCheckIn').textContent  = today.check_in_time.substring(0,5);
        if (today.check_out_time) document.getElementById('displayCheckOut').textContent = today.check_out_time.substring(0,5);
        if (today.work_hours)     document.getElementById('displayHours').textContent    = today.work_hours + 'j';
        if (today.status)         document.getElementById('displayStatus').textContent   = statusLabel(today.status);
    }
    updateClockButton();
    goAbsen();
    initMap();
    startGPS();
}

// ────────────────────────────────────────────────────────
//  7. MAP + GPS
// ────────────────────────────────────────────────────────
function initMap() {
    if (leafletMap) { leafletMap.invalidateSize(); return; }
    const cfg = officeConfig;
    leafletMap = L.map('mapContainer', { zoomControl: false }).setView([cfg.office_lat, cfg.office_lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'© OSM' }).addTo(leafletMap);
    officeMarker = L.marker([cfg.office_lat, cfg.office_lng]).addTo(leafletMap)
        .bindPopup(cfg.office_name).openPopup();
    radiusCircle = L.circle([cfg.office_lat, cfg.office_lng], {
        radius: cfg.radius, color:'#f0b429', fillOpacity:0.1, weight:2
    }).addTo(leafletMap);
}

function startGPS() {
    if (!navigator.geolocation) { document.getElementById('gpsAccuracy').textContent = 'GPS tidak didukung browser ini.'; return; }
    gpsWatcher = navigator.geolocation.watchPosition(onGPS, onGPSErr, { enableHighAccuracy:true, maximumAge:5000 });
}

function onGPS(pos) {
    currentGPS = pos;
    const lat = pos.coords.latitude, lng = pos.coords.longitude;
    const acc = Math.round(pos.coords.accuracy);
    document.getElementById('gpsAccuracy').textContent = '±' + acc + 'm akurasi GPS';
    // Update map
    if (userMarker) userMarker.setLatLng([lat, lng]);
    else userMarker = L.circleMarker([lat, lng], { radius:8, color:'#2563eb', fillOpacity:0.8, weight:2 }).addTo(leafletMap);
    // Calculate distance
    const dist = haversine(lat, lng, officeConfig.office_lat, officeConfig.office_lng);
    const maxD = officeConfig.radius;
    const pct  = Math.min(100, (dist / maxD) * 100);
    document.getElementById('distText').textContent = dist + 'm dari kantor (maks ' + maxD + 'm)';
    const fill = document.getElementById('distFill');
    fill.style.width    = pct + '%';
    fill.style.background = dist <= maxD ? '#059669' : '#dc2626';
    updateClockButton();
}

function onGPSErr(err) {
    document.getElementById('gpsAccuracy').textContent = 'GPS error: ' + err.message;
}

function haversine(lat1, lng1, lat2, lng2) {
    const R = 6371000, dLat = (lat2-lat1)*Math.PI/180, dLng = (lng2-lng1)*Math.PI/180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2;
    return Math.round(2*R*Math.asin(Math.sqrt(a)));
}

// ────────────────────────────────────────────────────────
//  8. CLOCK IN / OUT
// ────────────────────────────────────────────────────────
function updateClockButton() {
    const btn  = document.getElementById('btnClock');
    const today = currentEmployee?._today;
    if (!currentGPS) { btn.className='btn-clock done'; btn.textContent='⌛ Mengambil GPS...'; btn.disabled=true; return; }

    // ── Radius check ──
    const dist = haversine(currentGPS.coords.latitude, currentGPS.coords.longitude, officeConfig.office_lat, officeConfig.office_lng);
    const inRadius = dist <= officeConfig.radius;
    if (!inRadius && !officeConfig.allow_outside) {
        btn.className='btn-clock outside'; btn.textContent='📍 Di luar radius kantor (' + dist + 'm)'; btn.disabled=true; return;
    }

    if (!today || !today.check_in_time) {
        btn.className='btn-clock checkin'; btn.textContent='✅ Check-In Sekarang'; btn.disabled=false;
    } else if (!today.check_out_time) {
        btn.className='btn-clock checkout'; btn.textContent='🚪 Check-Out Sekarang'; btn.disabled=false;
    } else {
        btn.className='btn-clock done'; btn.textContent='✔️ Sudah Absen Lengkap'; btn.disabled=true;
    }
}

async function doClock() {
    if (!currentGPS) { showToast('GPS belum siap.'); return; }
    const btn   = document.getElementById('btnClock');
    const today = currentEmployee._today;
    const isIn  = !today || !today.check_in_time;
    const action= isIn ? 'checkin' : 'checkout';

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-top-color:#fff;"></span> Memproses...';

    // Reverse geocode (optional)
    let address = '';
    try {
        const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${currentGPS.coords.latitude}&lon=${currentGPS.coords.longitude}&format=json`);
        const g = await r.json();
        address = g.display_name || '';
    } catch(_){}

    const fd = new FormData();
    fd.append('action', action);
    fd.append('employee_id', currentEmployee.id);
    fd.append('lat', currentGPS.coords.latitude);
    fd.append('lng', currentGPS.coords.longitude);
    fd.append('address', address);

    try {
        const res  = await fetch(API_URL, { method:'POST', body:fd });
        const data = await res.json();
        if (data.success) {
            showToast(data.message);
            // Update local state
            if (!currentEmployee._today) currentEmployee._today = {};
            if (action === 'checkin') {
                currentEmployee._today.check_in_time = data.time + ':00';
                currentEmployee._today.status = data.status;
                document.getElementById('displayCheckIn').textContent = data.time;
                document.getElementById('displayStatus').textContent  = statusLabel(data.status);
            } else {
                currentEmployee._today.check_out_time = data.time + ':00';
                currentEmployee._today.work_hours = data.work_hours;
                document.getElementById('displayCheckOut').textContent = data.time;
                document.getElementById('displayHours').textContent    = data.work_hours + 'j';
            }
            updateClockButton();
        } else {
            showToast('❌ ' + data.message);
            updateClockButton();
        }
    } catch(err) {
        showToast('❌ ' + err.message);
        updateClockButton();
    }
}

// ────────────────────────────────────────────────────────
//  9. HISTORY
// ────────────────────────────────────────────────────────
async function loadHistory() {
    const fd = new FormData();
    fd.append('action', 'history');
    fd.append('employee_id', currentEmployee.id);
    try {
        const res  = await fetch(API_URL, { method:'POST', body:fd });
        const data = await res.json();
        if (!data.success) return;
        const s = data.summary || {};
        document.getElementById('summaryGrid').innerHTML = `
            <div class="sum-box"><div class="sv" style="color:#059669">${s.present||0}</div><div class="sl">Hadir</div></div>
            <div class="sum-box"><div class="sv" style="color:#ea580c">${s.late||0}</div><div class="sl">Terlambat</div></div>
            <div class="sum-box"><div class="sv" style="color:#64748b">${s.avg_hours ? parseFloat(s.avg_hours).toFixed(1)+'j' : '—'}</div><div class="sl">Avg/hari</div></div>`;
        const list = document.getElementById('histList');
        list.innerHTML = '';
        (data.history || []).forEach(row => {
            const st = row.status || 'notyet';
            const stMap = { present:'Hadir', late:'Terlambat', absent:'Absen', leave:'Izin', holiday:'Libur', half_day:'½', notyet:'—' };
            const dt = new Date(row.attendance_date).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'2-digit' });
            list.innerHTML += `
                <div class="hist-item">
                    <div class="hist-dot ${st}"></div>
                    <div class="hist-date">${dt}</div>
                    <div class="hist-times">
                        ${row.check_in_time ? row.check_in_time.substring(0,5) : '—'} →
                        ${row.check_out_time ? row.check_out_time.substring(0,5) : '—'}
                        ${row.work_hours ? ' · '+row.work_hours+'j' : ''}
                    </div>
                    <span class="hist-badge hb-${st}">${stMap[st]||st}</span>
                </div>`;
        });
        if (!data.history?.length) list.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8;">Belum ada data absen</div>';
    } catch(e) {}
}

// ────────────────────────────────────────────────────────
//  HELPERS
// ────────────────────────────────────────────────────────
function statusLabel(s) {
    const m = { present:'✅ Hadir', late:'⚠️ Terlambat', absent:'❌ Absen', leave:'💼 Izin', holiday:'🎉 Libur', half_day:'½ Hari' };
    return m[s] || s;
}

function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

// ────────────────────────────────────────────────────────
//  INIT
// ────────────────────────────────────────────────────────
loadModels();

// ────────────────────────────────────────────────────────
//  PWA: Service Worker + Install Prompt
// ────────────────────────────────────────────────────────
let deferredInstallPrompt = null;

// Register service worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('./sw.js')
            .then(reg => console.log('[SW] Registered, scope:', reg.scope))
            .catch(err => console.warn('[SW] Registration failed:', err));
    });
}

// Capture the install prompt before it fires
window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredInstallPrompt = e;
    // Only show if user hasn't dismissed before
    if (!sessionStorage.getItem('installDismissed')) {
        setTimeout(() => {
            document.getElementById('installBanner').classList.add('show');
        }, 3000); // Show after 3s so page loads first
    }
});

// Install button click
document.getElementById('btnInstall').addEventListener('click', async () => {
    if (!deferredInstallPrompt) return;
    document.getElementById('installBanner').classList.remove('show');
    deferredInstallPrompt.prompt();
    const { outcome } = await deferredInstallPrompt.userChoice;
    console.log('[PWA] Install outcome:', outcome);
    deferredInstallPrompt = null;
});

// Dismiss button
function dismissInstall() {
    document.getElementById('installBanner').classList.remove('show');
    sessionStorage.setItem('installDismissed', '1');
}

// Hide banner if already installed (standalone mode)
if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
    document.getElementById('installBanner').style.display = 'none';
}

// App installed event
window.addEventListener('appinstalled', () => {
    document.getElementById('installBanner').classList.remove('show');
    showToast('✅ Aplikasi berhasil diinstall!');
    deferredInstallPrompt = null;
});
</script>
</body>
</html>
