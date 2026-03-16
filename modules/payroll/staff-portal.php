<?php
/**
 * Staff Portal - Login/Register + Dashboard
 * PWA single-page app for staff: Absen, Monitoring, Occupancy, Breakfast
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';

// ── Resolve Business ──
$bizSlug = preg_replace('/[^a-z0-9\-_]/', '', strtolower(trim($_GET['b'] ?? '')));
$bizFile = __DIR__ . '/../../config/businesses/' . $bizSlug . '.php';
if (!$bizSlug || !file_exists($bizFile)) {
    $avail = array_map(fn($f) => basename($f, '.php'), glob(__DIR__ . '/../../config/businesses/*.php') ?: []);
    die('<div style="font-family:sans-serif;padding:40px;max-width:400px;margin:60px auto;background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.1);text-align:center"><h2 style="color:#dc2626">❌ Link Tidak Valid</h2><p style="color:#64748b">Gunakan link dari admin. Contoh: <code>?b=narayana-hotel</code></p></div>');
}
$bizConfig = require $bizFile;
if (!defined('ACTIVE_BUSINESS_ID')) define('ACTIVE_BUSINESS_ID', $bizConfig['business_id']);
$db = Database::switchDatabase($bizConfig['database']);
$baseUrl = defined('BASE_URL') ? BASE_URL : '';
$apiUrl = $baseUrl . '/modules/payroll/staff-api.php?b=' . urlencode($bizSlug);
$absenUrl = $baseUrl . '/modules/payroll/absen.php?b=' . urlencode($bizSlug);
$bizName = htmlspecialchars($bizConfig['name'] ?? 'Staff Portal');

// Logo
$absenConfig = $db->fetchOne("SELECT app_logo FROM payroll_attendance_config WHERE id=1") ?: [];
$appLogo = null;
if (!empty($absenConfig['app_logo'])) {
    $appLogo = (str_starts_with($absenConfig['app_logo'], 'http')) ? $absenConfig['app_logo'] : $baseUrl . '/' . ltrim($absenConfig['app_logo'], '/');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0d1f3c">
    <title>Staff Portal - <?php echo $bizName; ?></title>
    <link rel="manifest" href="absen-manifest.php?b=<?php echo urlencode($bizSlug); ?>">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root { --navy:#0d1f3c; --gold:#f0b429; --green:#059669; --red:#dc2626; --orange:#ea580c; --blue:#2563eb; --purple:#7c3aed; --bg:#f1f5f9; --card:#fff; --border:#e2e8f0; --muted:#64748b; --text:#1e293b; }
        body { font-family:'Inter','Segoe UI',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; -webkit-font-smoothing:antialiased; }

        /* ── Auth Screen ── */
        .auth-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; background:linear-gradient(135deg,#0d1f3c 0%,#1a3a5c 100%); }
        .auth-card { background:#fff; border-radius:20px; padding:32px 28px; width:100%; max-width:380px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .auth-logo { text-align:center; margin-bottom:20px; }
        .auth-logo img { height:50px; max-width:180px; object-fit:contain; }
        .auth-logo h1 { font-size:18px; color:var(--navy); margin-top:8px; }
        .auth-logo p { font-size:12px; color:var(--muted); }
        .auth-tabs { display:flex; background:var(--bg); border-radius:10px; padding:3px; margin-bottom:20px; }
        .auth-tab { flex:1; padding:9px; border:none; background:transparent; border-radius:8px; font-size:13px; font-weight:600; color:var(--muted); cursor:pointer; transition:.15s; }
        .auth-tab.active { background:var(--gold); color:var(--navy); font-weight:700; }
        .auth-form { display:none; } .auth-form.active { display:block; }
        .fg { margin-bottom:14px; }
        .fl { font-size:11px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.4px; margin-bottom:4px; display:block; }
        .fi { width:100%; padding:10px 12px; border:1.5px solid var(--border); border-radius:8px; font-size:13px; color:var(--text); background:#fff; transition:.15s; }
        .fi:focus { border-color:var(--gold); outline:none; box-shadow:0 0 0 3px rgba(240,180,41,.15); }
        .btn-auth { width:100%; padding:12px; background:var(--navy); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; transition:.15s; }
        .btn-auth:hover { opacity:.9; } .btn-auth:disabled { opacity:.5; cursor:not-allowed; }
        .auth-msg { padding:8px 12px; border-radius:8px; font-size:12px; margin-bottom:12px; display:none; }
        .auth-msg.err { display:block; background:#fef2f2; color:var(--red); border:1px solid #fca5a5; }
        .auth-msg.ok { display:block; background:#f0fdf4; color:var(--green); border:1px solid #86efac; }
        .fi-hint { font-size:10px; color:var(--muted); margin-top:3px; }

        /* ── App Shell ── */
        .app-wrap { display:none; min-height:100vh; padding-bottom:70px; background:var(--bg); }
        .app-header { background:var(--navy); padding:14px 16px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:100; }
        .app-header .logo { height:30px; border-radius:6px; }
        .app-header .title { color:#fff; font-size:14px; font-weight:700; flex:1; }
        .app-header .user-badge { background:rgba(255,255,255,.15); color:var(--gold); padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .app-header .logout-btn { background:none; border:1px solid rgba(255,255,255,.2); color:#fff; padding:5px 10px; border-radius:6px; font-size:11px; cursor:pointer; }

        /* Bottom Nav */
        .bottom-nav { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--border); display:flex; z-index:100; box-shadow:0 -2px 10px rgba(0,0,0,.08); }
        .nav-item { flex:1; padding:8px 4px 6px; text-align:center; cursor:pointer; transition:.15s; border-top:2px solid transparent; }
        .nav-item.active { border-top-color:var(--gold); }
        .nav-item .nav-icon { font-size:18px; display:block; }
        .nav-item .nav-label { font-size:9px; font-weight:600; color:var(--muted); margin-top:2px; }
        .nav-item.active .nav-label { color:var(--navy); font-weight:700; }

        /* Pages */
        .page { display:none; padding:16px; animation:fadeIn .2s ease; }
        .page.active { display:block; }
        @keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

        /* Stat Cards */
        .stat-row { display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:14px; }
        .stat-card { background:#fff; border-radius:12px; padding:14px; border:1px solid var(--border); }
        .stat-card .sl { font-size:10px; color:var(--muted); font-weight:600; text-transform:uppercase; }
        .stat-card .sv { font-size:22px; font-weight:800; margin-top:2px; }
        .stat-card .ss { font-size:10px; color:var(--muted); margin-top:1px; }

        /* Cards */
        .card { background:#fff; border-radius:12px; padding:16px; border:1px solid var(--border); margin-bottom:12px; }
        .card-title { font-size:13px; font-weight:700; color:var(--navy); margin-bottom:10px; }

        /* Table */
        .tbl { width:100%; border-collapse:collapse; font-size:11px; }
        .tbl th { background:var(--bg); padding:8px; text-align:left; font-weight:600; color:var(--muted); font-size:10px; text-transform:uppercase; border-bottom:1px solid var(--border); }
        .tbl td { padding:8px; border-bottom:1px solid #f1f5f9; }
        .tbl tr:last-child td { border:none; }

        /* Badges */
        .badge { padding:2px 7px; border-radius:4px; font-size:9px; font-weight:700; text-transform:uppercase; }
        .b-hadir { background:#dcfce7; color:#166534; } .b-late { background:#ffedd5; color:#9a3412; }
        .b-absent { background:#fee2e2; color:#991b1b; } .b-available { background:#dcfce7; color:#166534; }
        .b-occupied { background:#fee2e2; color:#991b1b; }

        /* Room Grid */
        .room-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(70px,1fr)); gap:6px; }
        .room-box { padding:10px 6px; border-radius:8px; text-align:center; font-weight:700; font-size:12px; border:1px solid var(--border); }
        .room-box.avail { background:#f0fdf4; color:var(--green); border-color:#bbf7d0; }
        .room-box.occ { background:#fef2f2; color:var(--red); border-color:#fca5a5; }
        .room-box .room-type { font-size:8px; color:var(--muted); font-weight:400; margin-top:1px; }
        .room-box .room-guest { font-size:8px; color:var(--red); font-weight:500; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        /* Breakfast */
        .menu-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
        .menu-item { background:var(--bg); border:2px solid var(--border); border-radius:10px; padding:12px; cursor:pointer; transition:.15s; text-align:center; }
        .menu-item:hover { border-color:var(--gold); } .menu-item.selected { border-color:var(--gold); background:#fffbeb; }
        .menu-item .mi-name { font-size:12px; font-weight:600; color:var(--text); }
        .menu-item .mi-cat { font-size:9px; color:var(--muted); text-transform:uppercase; margin-top:2px; }

        /* Absen button */
        .absen-link { display:block; background:linear-gradient(135deg,var(--navy),#1a3a5c); color:#fff; text-decoration:none; border-radius:14px; padding:20px; text-align:center; margin-bottom:14px; }
        .absen-link .al-icon { font-size:36px; margin-bottom:6px; }
        .absen-link .al-title { font-size:16px; font-weight:700; }
        .absen-link .al-sub { font-size:11px; color:rgba(255,255,255,.7); margin-top:4px; }

        /* Loading */
        .loading { text-align:center; padding:30px; color:var(--muted); font-size:12px; }
        .spin { display:inline-block; width:20px; height:20px; border:2px solid var(--border); border-top-color:var(--gold); border-radius:50%; animation:spin .6s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }

        /* Progress bar */
        .progress { height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; margin-top:4px; }
        .progress-bar { height:100%; border-radius:4px; transition:width .3s; }

        @media(min-width:500px) {
            .stat-row { grid-template-columns:repeat(4,1fr); }
            .room-grid { grid-template-columns:repeat(auto-fill,minmax(80px,1fr)); }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════ -->
<!-- AUTH SCREEN                            -->
<!-- ═══════════════════════════════════════ -->
<div class="auth-wrap" id="authScreen">
    <div class="auth-card">
        <div class="auth-logo">
            <?php if ($appLogo): ?><img src="<?php echo $appLogo; ?>" alt="Logo"><?php endif; ?>
            <h1><?php echo $bizName; ?></h1>
            <p>Staff Portal — Login atau Daftar</p>
        </div>

        <div class="auth-tabs">
            <button class="auth-tab active" onclick="switchAuth('login')">Login</button>
            <button class="auth-tab" onclick="switchAuth('register')">Daftar Baru</button>
        </div>

        <div id="authMsg" class="auth-msg"></div>

        <!-- Login Form -->
        <form class="auth-form active" id="loginForm" onsubmit="return handleLogin(event)">
            <div class="fg">
                <label class="fl">Username / Email</label>
                <input type="text" class="fi" name="email" placeholder="nama atau email" required>
            </div>
            <div class="fg">
                <label class="fl">Password</label>
                <input type="password" class="fi" name="password" placeholder="••••••" required>
            </div>
            <button type="submit" class="btn-auth" id="loginBtn">🔐 Login</button>
        </form>

        <!-- Register Form -->
        <form class="auth-form" id="registerForm" onsubmit="return handleRegister(event)">
            <div class="fg">
                <label class="fl">Nomor Karyawan</label>
                <input type="number" class="fi" name="employee_code" placeholder="1" min="1" required style="font-size:18px; text-align:center; letter-spacing:2px;">
                <div class="fi-hint">Masukkan angka saja, misal: 1, 2, 3 (lihat di slip gaji)</div>
            </div>
            <div class="fg">
                <label class="fl">Username / Email</label>
                <input type="text" class="fi" name="email" placeholder="nama atau email" required>
            </div>
            <div class="fg">
                <label class="fl">Password</label>
                <input type="password" class="fi" name="password" placeholder="Min 6 karakter" minlength="6" required>
            </div>
            <button type="submit" class="btn-auth" id="regBtn">📝 Daftar</button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════ -->
<!-- APP SHELL (after login)               -->
<!-- ═══════════════════════════════════════ -->
<div class="app-wrap" id="appShell">
    <!-- Header -->
    <div class="app-header">
        <?php if ($appLogo): ?><img src="<?php echo $appLogo; ?>" class="logo"><?php endif; ?>
        <span class="title"><?php echo $bizName; ?></span>
        <span class="user-badge" id="headerName">Staff</span>
        <button class="logout-btn" onclick="doLogout()">Keluar</button>
    </div>

    <!-- ═══ PAGE: ABSEN ═══ -->
    <div class="page active" id="page-absen">
        <a href="<?php echo htmlspecialchars($absenUrl); ?>" class="absen-link" target="_self">
            <div class="al-icon">👁️</div>
            <div class="al-title">Scan Wajah — Absen Sekarang</div>
            <div class="al-sub">Tap untuk buka halaman scan wajah</div>
        </a>

        <div class="card">
            <div class="card-title">📋 Status Absen Hari Ini</div>
            <div id="todayStatus"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>

        <div class="card">
            <div class="card-title">📊 Ringkasan Bulan Ini</div>
            <div id="monthlySummary"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
    </div>

    <!-- ═══ PAGE: MONITORING ═══ -->
    <div class="page" id="page-monitoring">
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
            <input type="month" id="monitorMonth" class="fi" style="width:160px;" value="<?php echo date('Y-m'); ?>" onchange="loadMonitoring()">
        </div>
        <div id="monitorStats"></div>
        <div class="card">
            <div class="card-title">📅 Detail Harian</div>
            <div id="monitorTable"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
    </div>

    <!-- ═══ PAGE: OCCUPANCY ═══ -->
    <div class="page" id="page-occupancy">
        <div id="occStats"></div>
        <div class="card">
            <div class="card-title">🏨 Status Kamar</div>
            <div id="roomGrid"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
    </div>

    <!-- ═══ PAGE: BREAKFAST ═══ -->
    <div class="page" id="page-breakfast">
        <div class="card">
            <div class="card-title">☕ Pesanan Hari Ini</div>
            <div id="bfToday"><div class="loading"><span class="spin"></span></div></div>
        </div>
        <div class="card">
            <div class="card-title">🍽️ Pilih Menu Breakfast</div>
            <div id="bfMenu"><div class="loading"><span class="spin"></span> Memuat menu...</div></div>
        </div>
        <div style="text-align:center; margin-top:12px;">
            <button onclick="submitBreakfast()" class="btn-auth" style="max-width:300px;border-radius:12px;" id="bfSubmitBtn" disabled>🍽️ Pesan Breakfast</button>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <div class="nav-item active" data-page="absen"><span class="nav-icon">📋</span><span class="nav-label">Absen</span></div>
        <div class="nav-item" data-page="monitoring"><span class="nav-icon">📊</span><span class="nav-label">Monitoring</span></div>
        <div class="nav-item" data-page="occupancy"><span class="nav-icon">🏨</span><span class="nav-label">Occupancy</span></div>
        <div class="nav-item" data-page="breakfast"><span class="nav-icon">☕</span><span class="nav-label">Breakfast</span></div>
    </div>
</div>

<script>
const API = '<?php echo $apiUrl; ?>';
let selectedBfMenu = null;

// ═══ AUTH ═══
function switchAuth(tab) {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
    if (tab === 'login') {
        document.querySelectorAll('.auth-tab')[0].classList.add('active');
        document.getElementById('loginForm').classList.add('active');
    } else {
        document.querySelectorAll('.auth-tab')[1].classList.add('active');
        document.getElementById('registerForm').classList.add('active');
    }
    document.getElementById('authMsg').className = 'auth-msg';
}

function showMsg(msg, type) {
    const el = document.getElementById('authMsg');
    el.textContent = msg;
    el.className = 'auth-msg ' + (type === 'error' ? 'err' : 'ok');
}

async function handleLogin(e) {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    btn.disabled = true; btn.textContent = '⏳ Loading...';
    const fd = new FormData(e.target);
    fd.append('action', 'login');
    try {
        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            localStorage.setItem('staff_name', data.name);
            showApp(data.name);
        } else { showMsg(data.message, 'error'); }
    } catch (err) { showMsg('Koneksi gagal', 'error'); }
    btn.disabled = false; btn.textContent = '🔐 Login';
    return false;
}

async function handleRegister(e) {
    e.preventDefault();
    const btn = document.getElementById('regBtn');
    btn.disabled = true; btn.textContent = '⏳ Loading...';
    const fd = new FormData(e.target);
    fd.append('action', 'register');
    try {
        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        showMsg(data.message, data.success ? 'ok' : 'error');
        if (data.success) { setTimeout(() => switchAuth('login'), 1500); }
    } catch (err) { showMsg('Koneksi gagal', 'error'); }
    btn.disabled = false; btn.textContent = '📝 Daftar';
    return false;
}

function doLogout() {
    fetch(API, { method: 'POST', body: new URLSearchParams({ action: 'logout' }) });
    localStorage.removeItem('staff_name');
    document.getElementById('authScreen').style.display = 'flex';
    document.getElementById('appShell').style.display = 'none';
}

// ═══ APP ═══
function showApp(name) {
    document.getElementById('authScreen').style.display = 'none';
    document.getElementById('appShell').style.display = 'block';
    document.getElementById('headerName').textContent = name || 'Staff';
    loadAbsen();
}

// ── Navigation ──
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => {
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        item.classList.add('active');
        const page = item.dataset.page;
        document.getElementById('page-' + page).classList.add('active');
        if (page === 'absen') loadAbsen();
        if (page === 'monitoring') loadMonitoring();
        if (page === 'occupancy') loadOccupancy();
        if (page === 'breakfast') loadBreakfast();
    });
});

// ═══ ABSEN PAGE ═══
async function loadAbsen() {
    // Today status
    try {
        const res = await fetch(API + '&action=attendance_today');
        const data = await res.json();
        if (!data.success && data.auth === false) { doLogout(); return; }
        const a = data.data;
        if (a) {
            const s1 = a.check_in_time ? a.check_in_time.substring(0,5) : '—';
            const s2 = a.check_out_time ? a.check_out_time.substring(0,5) : '—';
            const s3 = a.scan_3 ? a.scan_3.substring(0,5) : '—';
            const s4 = a.scan_4 ? a.scan_4.substring(0,5) : '—';
            const wh = parseFloat(a.work_hours) || 0;
            const statusMap = { present: '✅ Hadir', late: '⏰ Terlambat', absent: '❌ Absen', leave: '📝 Izin' };
            document.getElementById('todayStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <span class="badge ${a.status==='present'?'b-hadir':a.status==='late'?'b-late':'b-absent'}">${statusMap[a.status]||a.status}</span>
                    <span style="font-size:11px;color:var(--muted);">${wh > 0 ? wh.toFixed(1) + ' jam' : ''}</span>
                </div>
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;text-align:center;">
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 1</div>
                        <div style="font-size:14px;font-weight:700;color:var(--green);">${s1}</div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 2</div>
                        <div style="font-size:14px;font-weight:700;color:var(--navy);">${s2}</div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 3</div>
                        <div style="font-size:14px;font-weight:700;color:var(--green);">${s3}</div>
                    </div>
                    <div style="background:var(--bg);border-radius:8px;padding:8px;">
                        <div style="font-size:9px;color:var(--muted);">Scan 4</div>
                        <div style="font-size:14px;font-weight:700;color:var(--navy);">${s4}</div>
                    </div>
                </div>`;
        } else {
            document.getElementById('todayStatus').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">⏳ Belum absen hari ini. Tap "Scan Wajah" di atas.</div>';
        }
    } catch(e) { document.getElementById('todayStatus').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat data</div>'; }

    // Monthly summary
    try {
        const m = new Date().toISOString().substring(0,7);
        const res = await fetch(API + '&action=attendance_history&month=' + m);
        const data = await res.json();
        const s = data.summary || {};
        const pct = s.target > 0 ? Math.min(Math.round(s.total_hours / s.target * 100), 100) : 0;
        const barColor = pct >= 90 ? 'var(--green)' : pct >= 60 ? 'var(--orange)' : 'var(--red)';
        document.getElementById('monthlySummary').innerHTML = `
            <div class="stat-row" style="margin-bottom:8px;">
                <div class="stat-card"><div class="sl">Hadir</div><div class="sv" style="color:var(--green);">${s.days_present||0}</div><div class="ss">hari</div></div>
                <div class="stat-card"><div class="sl">Total Jam</div><div class="sv" style="color:var(--navy);">${(s.total_hours||0).toFixed(1)}</div><div class="ss">dari ${s.target||200}j target</div></div>
                <div class="stat-card"><div class="sl">Reguler</div><div class="sv" style="color:var(--blue);">${(s.regular_hours||0).toFixed(1)}j</div><div class="ss">max 8j/hari</div></div>
                <div class="stat-card"><div class="sl">🔥 Lembur</div><div class="sv" style="color:var(--purple);">${(s.overtime_hours||0).toFixed(1)}j</div><div class="ss">×45 menit</div></div>
            </div>
            <div style="font-size:10px;color:var(--muted);margin-bottom:4px;">Progress Target ${pct}%</div>
            <div class="progress"><div class="progress-bar" style="width:${pct}%;background:${barColor};"></div></div>`;
    } catch(e) {}
}

// ═══ MONITORING PAGE ═══
async function loadMonitoring() {
    const month = document.getElementById('monitorMonth').value || new Date().toISOString().substring(0,7);
    try {
        const res = await fetch(API + '&action=attendance_history&month=' + month);
        const data = await res.json();
        const s = data.summary || {};
        const pct = s.target > 0 ? Math.min(Math.round(s.total_hours / s.target * 100), 100) : 0;
        const barColor = pct >= 90 ? 'var(--green)' : pct >= 60 ? 'var(--orange)' : 'var(--red)';

        document.getElementById('monitorStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">Hadir</div><div class="sv" style="color:var(--green);">${s.days_present||0}</div></div>
                <div class="stat-card"><div class="sl">Terlambat</div><div class="sv" style="color:var(--orange);">${s.days_late||0}</div></div>
                <div class="stat-card"><div class="sl">Total Jam</div><div class="sv">${(s.total_hours||0).toFixed(1)}</div></div>
                <div class="stat-card"><div class="sl">🔥 Lembur</div><div class="sv" style="color:var(--purple);">${(s.overtime_hours||0).toFixed(1)}j</div></div>
            </div>
            <div class="card" style="margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px;">
                    <span style="color:var(--muted);">Target ${s.target||200} jam</span>
                    <span style="font-weight:700;color:${barColor};">${pct}%</span>
                </div>
                <div class="progress"><div class="progress-bar" style="width:${pct}%;background:${barColor};"></div></div>
            </div>`;

        const rows = data.data || [];
        if (rows.length === 0) {
            document.getElementById('monitorTable').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Belum ada data absensi.</div>';
            return;
        }
        let html = '<div style="overflow-x:auto;"><table class="tbl"><thead><tr><th>Tanggal</th><th>Scan 1</th><th>Scan 2</th><th>Scan 3</th><th>Scan 4</th><th>Total</th><th>Status</th></tr></thead><tbody>';
        const statusMap = { present:'Hadir', late:'Terlambat', absent:'Absen', leave:'Izin', holiday:'Libur', half_day:'½ Hari' };
        rows.forEach(r => {
            const dt = new Date(r.attendance_date);
            const day = dt.toLocaleDateString('id-ID',{weekday:'short',day:'numeric',month:'short'});
            const s1 = r.check_in_time ? r.check_in_time.substring(0,5) : '—';
            const s2 = r.check_out_time ? r.check_out_time.substring(0,5) : '—';
            const s3 = r.scan_3 ? r.scan_3.substring(0,5) : '—';
            const s4 = r.scan_4 ? r.scan_4.substring(0,5) : '—';
            const wh = parseFloat(r.work_hours)||0;
            const bc = r.status==='present'?'b-hadir':r.status==='late'?'b-late':'b-absent';
            html += `<tr><td style="white-space:nowrap;">${day}</td><td style="font-weight:600;color:var(--green);">${s1}</td><td>${s2}</td><td style="color:var(--green);">${s3}</td><td>${s4}</td><td style="font-weight:700;">${wh>0?wh.toFixed(1)+'j':'—'}</td><td><span class="badge ${bc}">${statusMap[r.status]||r.status}</span></td></tr>`;
        });
        html += '</tbody></table></div>';
        document.getElementById('monitorTable').innerHTML = html;
    } catch(e) { document.getElementById('monitorTable').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>'; }
}

// ═══ OCCUPANCY PAGE ═══
async function loadOccupancy() {
    try {
        const res = await fetch(API + '&action=occupancy');
        const data = await res.json();
        const d = data.data || {};

        document.getElementById('occStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">🟢 Available</div><div class="sv" style="color:var(--green);">${d.available||0}</div></div>
                <div class="stat-card"><div class="sl">🔴 Occupied</div><div class="sv" style="color:var(--red);">${d.occupied||0}</div></div>
                <div class="stat-card"><div class="sl">📊 Occupancy</div><div class="sv" style="color:var(--blue);">${d.occupancy_rate||0}%</div></div>
                <div class="stat-card"><div class="sl">🏨 Total</div><div class="sv">${d.total_rooms||0}</div></div>
            </div>
            <div class="stat-row" style="grid-template-columns:repeat(2,1fr);">
                <div class="stat-card" style="border-left:3px solid var(--green);"><div class="sl">✈️ Arrivals Today</div><div class="sv" style="color:var(--green);">${d.arrivals_today||0}</div></div>
                <div class="stat-card" style="border-left:3px solid var(--orange);"><div class="sl">🚪 Departures Today</div><div class="sv" style="color:var(--orange);">${d.departures_today||0}</div></div>
            </div>`;

        const rooms = d.rooms || [];
        if (rooms.length === 0) {
            document.getElementById('roomGrid').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Tidak ada data kamar.</div>';
            return;
        }
        let html = '<div class="room-grid">';
        rooms.forEach(r => {
            const isOcc = r.status === 'occupied';
            html += `<div class="room-box ${isOcc?'occ':'avail'}">
                ${r.room_number}
                <div class="room-type">${r.room_type||''}</div>
                ${isOcc ? `<div class="room-guest">${r.guest_name||''}</div>` : ''}
            </div>`;
        });
        html += '</div>';
        document.getElementById('roomGrid').innerHTML = html;
    } catch(e) { document.getElementById('roomGrid').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>'; }
}

// ═══ BREAKFAST PAGE ═══
async function loadBreakfast() {
    selectedBfMenu = null;
    document.getElementById('bfSubmitBtn').disabled = true;

    // Today's order
    try {
        const res = await fetch(API + '&action=breakfast_today');
        const data = await res.json();
        if (data.data) {
            document.getElementById('bfToday').innerHTML = `
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:36px;height:36px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;">☕</div>
                    <div>
                        <div style="font-weight:700;font-size:13px;">${data.data.menu_name}</div>
                        <div style="font-size:10px;color:var(--muted);">Status: ${data.data.status||'pending'}</div>
                    </div>
                </div>`;
        } else {
            document.getElementById('bfToday').innerHTML = '<div style="text-align:center;padding:10px;color:var(--muted);font-size:12px;">Belum pesan hari ini.</div>';
        }
    } catch(e) {}

    // Menu list
    try {
        const res = await fetch(API + '&action=breakfast_menu');
        const data = await res.json();
        const menus = data.data || [];
        if (menus.length === 0) {
            document.getElementById('bfMenu').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Menu belum tersedia.</div>';
            return;
        }
        let html = '<div class="menu-grid">';
        menus.forEach(m => {
            const catEmoji = {western:'🍳',indonesian:'🍲',asian:'🥡',drinks:'☕',beverages:'🧃',extras:'🍞'}[m.category] || '🍽️';
            html += `<div class="menu-item" data-id="${m.id}" onclick="selectMenu(this, ${m.id})">
                <div style="font-size:20px;">${catEmoji}</div>
                <div class="mi-name">${m.menu_name}</div>
                <div class="mi-cat">${m.category} ${m.is_free=='1'?'• FREE':'• 💰'}</div>
            </div>`;
        });
        html += '</div>';
        document.getElementById('bfMenu').innerHTML = html;
    } catch(e) { document.getElementById('bfMenu').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat menu</div>'; }
}

function selectMenu(el, id) {
    document.querySelectorAll('.menu-item').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    selectedBfMenu = id;
    document.getElementById('bfSubmitBtn').disabled = false;
}

async function submitBreakfast() {
    if (!selectedBfMenu) return;
    const btn = document.getElementById('bfSubmitBtn');
    btn.disabled = true; btn.textContent = '⏳ Memesan...';
    try {
        const fd = new FormData();
        fd.append('action', 'breakfast_submit');
        fd.append('menu_id', selectedBfMenu);
        fd.append('date', new Date().toISOString().substring(0,10));
        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            btn.textContent = '✅ ' + data.message;
            setTimeout(() => { btn.textContent = '🍽️ Pesan Breakfast'; loadBreakfast(); }, 2000);
        } else {
            btn.textContent = '❌ ' + data.message;
            setTimeout(() => { btn.textContent = '🍽️ Pesan Breakfast'; btn.disabled = false; }, 2000);
        }
    } catch(e) { btn.textContent = '❌ Gagal'; setTimeout(() => { btn.textContent = '🍽️ Pesan Breakfast'; btn.disabled = false; }, 2000); }
}

// ═══ AUTO-LOGIN CHECK ═══
(async function checkSession() {
    try {
        const res = await fetch(API + '&action=profile');
        const data = await res.json();
        if (data.success && data.data) {
            showApp(data.data.full_name);
        }
    } catch(e) { /* stay on auth screen */ }
})();
</script>

</body>
</html>
