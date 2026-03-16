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
$apiUrl = 'staff-api.php?b=' . urlencode($bizSlug);
$absenUrl = 'absen.php?b=' . urlencode($bizSlug);
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
    <link rel="manifest" href="staff-manifest.php?b=<?php echo urlencode($bizSlug); ?>">
    <link rel="apple-touch-icon" href="absen-icon.php?size=192">
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

        /* Password field with eye toggle */
        .pw-wrap { position:relative; }
        .pw-wrap .fi { padding-right:40px; }
        .pw-toggle { position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; font-size:18px; color:var(--muted); padding:4px; line-height:1; }
        .pw-toggle:hover { color:var(--navy); }
        .remember-row { display:flex; align-items:center; gap:6px; margin-bottom:14px; margin-top:-4px; }
        .remember-row input[type=checkbox] { width:16px; height:16px; accent-color:var(--gold); cursor:pointer; }
        .remember-row label { font-size:11px; color:var(--muted); cursor:pointer; user-select:none; }

        /* ── App Shell ── */
        .app-wrap { display:none; min-height:100vh; padding-bottom:70px; background:var(--bg); }
        .app-header { background:var(--navy); padding:14px 16px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:100; }
        .app-header .logo { height:30px; border-radius:6px; }
        .app-header .title { color:#fff; font-size:14px; font-weight:700; flex:1; }
        .app-header .user-badge { background:rgba(255,255,255,.15); color:var(--gold); padding:4px 10px; border-radius:20px; font-size:11px; font-weight:600; }
        .app-header .logout-btn { background:none; border:1px solid rgba(255,255,255,.2); color:#fff; padding:5px 10px; border-radius:6px; font-size:11px; cursor:pointer; }

        /* Bottom Nav - 5 tabs */
        .bottom-nav { position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid var(--border); display:flex; z-index:100; box-shadow:0 -2px 10px rgba(0,0,0,.08); }
        .nav-item { flex:1; padding:6px 2px 5px; text-align:center; cursor:pointer; transition:.15s; border-top:2px solid transparent; }
        .nav-item.active { border-top-color:var(--gold); }
        .nav-item .nav-icon { font-size:16px; display:block; }
        .nav-item .nav-label { font-size:8px; font-weight:600; color:var(--muted); margin-top:1px; }
        .nav-item.active .nav-label { color:var(--navy); font-weight:700; }

        /* Notification bell */
        .notif-bell { position:relative; cursor:pointer; font-size:18px; padding:4px 8px; }
        .notif-dot { position:absolute; top:2px; right:4px; width:8px; height:8px; background:var(--red); border-radius:50%; display:none; }
        .notif-dot.show { display:block; }

        /* Install banner */
        .install-banner { background:linear-gradient(135deg,var(--gold),#e69800); color:var(--navy); padding:12px 16px; border-radius:12px; margin-bottom:12px; display:none; align-items:center; gap:10px; cursor:pointer; }
        .install-banner.show { display:flex; }
        .install-banner .ib-icon { font-size:24px; }
        .install-banner .ib-text { flex:1; }
        .install-banner .ib-title { font-weight:700; font-size:13px; }
        .install-banner .ib-sub { font-size:10px; opacity:.8; }
        .install-banner .ib-close { background:none; border:none; font-size:18px; cursor:pointer; padding:4px; color:var(--navy); }

        /* iOS install guide */
        .install-guide { background:#fff; border:2px solid var(--border); border-radius:12px; padding:14px 16px; margin-bottom:12px; border-left:4px solid #a855f7; }

        /* Cuti form */
        .cuti-type-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; margin-bottom:12px; }
        .cuti-type { background:var(--bg); border:2px solid var(--border); border-radius:10px; padding:10px; cursor:pointer; text-align:center; transition:.15s; }
        .cuti-type:hover { border-color:var(--gold); }
        .cuti-type.selected { border-color:var(--gold); background:#fffbeb; }
        .cuti-type .ct-icon { font-size:20px; }
        .cuti-type .ct-label { font-size:11px; font-weight:600; margin-top:2px; }
        .leave-status { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:4px; font-size:9px; font-weight:700; text-transform:uppercase; }
        .ls-pending { background:#fef3c7; color:#92400e; }
        .ls-approved { background:#dcfce7; color:#166534; }
        .ls-rejected { background:#fee2e2; color:#991b1b; }

        /* Notif popup */
        .notif-popup { position:fixed; top:50px; right:10px; left:10px; max-width:360px; margin:auto; background:#fff; border-radius:14px; box-shadow:0 10px 40px rgba(0,0,0,.2); z-index:200; display:none; max-height:70vh; overflow-y:auto; border:1px solid var(--border); }
        .notif-popup.open { display:block; }
        .notif-popup .np-head { padding:14px 16px; border-bottom:1px solid var(--border); font-weight:700; font-size:14px; color:var(--navy); }
        .notif-popup .np-item { padding:12px 16px; border-bottom:1px solid #f1f5f9; }
        .notif-popup .np-empty { padding:30px; text-align:center; color:var(--muted); font-size:12px; }

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

        /* Booking Calendar */
        .cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:6px; }
        .cal-nav button { background:var(--navy); color:#fff; border:none; border-radius:8px; padding:6px 14px; font-size:11px; font-weight:600; cursor:pointer; transition:.15s; }
        .cal-nav button:active { opacity:.7; transform:scale(.96); }
        .cal-nav .cal-period { font-size:12px; font-weight:700; color:var(--navy); }
        .cal-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid var(--border); border-radius:10px; background:#fff; }
        .cal-table { border-collapse:collapse; width:max-content; }
        .cal-table th { position:sticky; top:0; background:var(--navy); color:#fff; font-size:9px; font-weight:700; text-align:center; padding:8px 0; z-index:3; min-width:70px; white-space:nowrap; border:none; }
        .cal-table th.today-h { background:var(--gold); color:var(--navy); }
        .cal-table th.room-h { text-align:left; padding-left:8px; min-width:60px; width:60px; position:sticky; left:0; z-index:4; background:var(--navy); }
        .cal-table .cal-type-row td { background:#f1f5f9; padding:5px 8px; font-size:10px; font-weight:700; color:var(--navy); border-top:2px solid var(--border); }
        .cal-table .cal-room-cell { background:#fff; font-size:10px; font-weight:800; color:var(--navy); padding:0 6px; position:sticky; left:0; z-index:2; border-right:2px solid var(--border); width:60px; min-width:60px; white-space:nowrap; height:34px; }
        .cal-table .cal-day-cell { position:relative; min-width:70px; height:34px; border-right:1px solid #f0f0f0; border-bottom:1px solid #f0f0f0; padding:0; vertical-align:top; }
        .cal-table .cal-day-cell.today-c { background:rgba(234,179,8,.06); }
        .cal-bar { position:absolute; top:4px; left:2px; right:2px; height:26px; border-radius:6px; display:flex; align-items:center; justify-content:center; padding:0 6px; font-size:9px; font-weight:700; color:#fff; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; z-index:1; cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,.18); transition:transform .15s; }
        .cal-bar:active { transform:scale(.96); }
        .cal-bar.s-confirmed { background:linear-gradient(135deg,#06b6d4,#22d3ee); }
        .cal-bar.s-pending { background:linear-gradient(135deg,#f59e0b,#fbbf24); }
        .cal-bar.s-checked-in { background:linear-gradient(135deg,#0284c7,#0ea5e9); }
        .cal-bar.s-checked-out { background:linear-gradient(135deg,#9ca3af,#d1d5db); opacity:.5; }
        .cal-bar span { text-shadow:0 1px 2px rgba(0,0,0,.3); font-size:8px; letter-spacing:.2px; }
        .cal-legend { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; justify-content:center; }
        .cal-legend-item { display:flex; align-items:center; gap:4px; font-size:9px; color:var(--muted); font-weight:500; }
        .cal-legend-dot { width:12px; height:8px; border-radius:3px; }
        .cal-popup { position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:16px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.3); z-index:1000; width:290px; max-width:90vw; }
        .cal-popup-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:999; }

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
                <input type="text" class="fi" name="email" placeholder="nama atau email" required id="loginEmail">
            </div>
            <div class="fg">
                <label class="fl">Password</label>
                <div class="pw-wrap">
                    <input type="password" class="fi" name="password" placeholder="••••••" required id="loginPass">
                    <button type="button" class="pw-toggle" onclick="togglePw('loginPass',this)">👁️</button>
                </div>
            </div>
            <div class="remember-row">
                <input type="checkbox" id="rememberMe" checked>
                <label for="rememberMe">Simpan login</label>
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
                <div class="pw-wrap">
                    <input type="password" class="fi" name="password" placeholder="Min 6 karakter" minlength="6" required id="regPass">
                    <button type="button" class="pw-toggle" onclick="togglePw('regPass',this)">👁️</button>
                </div>
            </div>
            <div class="remember-row">
                <input type="checkbox" id="rememberReg" checked>
                <label for="rememberReg">Simpan password</label>
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
        <div class="notif-bell" onclick="toggleNotifs()">
            🔔
            <div class="notif-dot" id="notifDot"></div>
        </div>
        <span class="user-badge" id="headerName">Staff</span>
        <button class="logout-btn" onclick="doLogout()">Keluar</button>
    </div>

    <!-- Notification Popup -->
    <div class="notif-popup" id="notifPopup">
        <div class="np-head">🔔 Notifikasi</div>
        <div id="notifList"><div class="np-empty">Memuat...</div></div>
    </div>

    <!-- ═══ PAGE: HOME (Absen + Monitoring + Cuti) ═══ -->
    <div class="page active" id="page-home">
        <!-- Install Banner (Android) -->
        <div class="install-banner" id="installBanner">
            <div class="ib-icon">📲</div>
            <div class="ib-text">
                <div class="ib-title">Install Aplikasi</div>
                <div class="ib-sub">Buka lebih cepat langsung dari home screen</div>
            </div>
            <button class="ib-close" onclick="event.stopPropagation();document.getElementById('installBanner').classList.remove('show');">✕</button>
        </div>

        <!-- iPhone Guide -->
        <div class="install-guide" id="iosGuide" style="display:none;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                <span style="font-size:20px;">🍎</span>
                <div style="font-weight:700;font-size:13px;color:var(--navy);">Install di iPhone / iPad</div>
                <button style="margin-left:auto;background:none;border:none;font-size:16px;cursor:pointer;color:var(--muted);" onclick="this.parentElement.parentElement.style.display='none';localStorage.setItem('ios_guide_dismissed','1');">✕</button>
            </div>
            <div style="font-size:11px;color:var(--text);line-height:1.6;">
                <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;">
                    <span style="background:var(--bg);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">1</span>
                    <span>Tap tombol <strong style="background:#e5e7eb;padding:1px 6px;border-radius:4px;">⬆️ Share</strong> di bagian bawah Safari</span>
                </div>
                <div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:6px;">
                    <span style="background:var(--bg);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">2</span>
                    <span>Scroll ke bawah, pilih <strong style="background:#e5e7eb;padding:1px 6px;border-radius:4px;">➕ Add to Home Screen</strong></span>
                </div>
                <div style="display:flex;align-items:flex-start;gap:8px;">
                    <span style="background:var(--bg);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">3</span>
                    <span>Tap <strong style="background:var(--gold);color:var(--navy);padding:1px 6px;border-radius:4px;">Add</strong> — icon akan muncul di home screen</span>
                </div>
            </div>
        </div>

        <!-- Scan Wajah -->
        <a href="<?php echo htmlspecialchars($absenUrl); ?>" class="absen-link" target="_self">
            <div class="al-icon">👁️</div>
            <div class="al-title">Scan Wajah — Absen Sekarang</div>
            <div class="al-sub">Tap untuk buka halaman scan wajah</div>
        </a>

        <!-- Status Hari Ini -->
        <div class="card">
            <div class="card-title">📋 Status Absen Hari Ini</div>
            <div id="todayStatus"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>

        <!-- Ringkasan Bulan Ini -->
        <div class="card">
            <div class="card-title">📊 Ringkasan Bulan Ini</div>
            <div id="monthlySummary"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>

        <!-- Monitoring Detail -->
        <div class="card">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <div class="card-title" style="margin:0;">📅 Detail Absensi</div>
                <input type="month" id="monitorMonth" class="fi" style="width:140px;padding:5px 8px;font-size:11px;" value="<?php echo date('Y-m'); ?>" onchange="loadMonitoring()">
            </div>
            <div id="monitorStats"></div>
            <div id="monitorTable"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>

        <!-- Ajukan Cuti -->
        <div class="card">
            <div class="card-title">🏖️ Ajukan Cuti / Izin</div>
            <form id="cutiForm" onsubmit="return submitCuti(event)">
                <div style="margin-bottom:10px;">
                    <div class="cuti-type-grid">
                        <div class="cuti-type selected" data-type="cuti" onclick="selectCutiType(this)">
                            <div class="ct-icon">🏖️</div><div class="ct-label">Cuti</div>
                        </div>
                        <div class="cuti-type" data-type="sakit" onclick="selectCutiType(this)">
                            <div class="ct-icon">🩺</div><div class="ct-label">Sakit</div>
                        </div>
                        <div class="cuti-type" data-type="izin" onclick="selectCutiType(this)">
                            <div class="ct-icon">📋</div><div class="ct-label">Izin</div>
                        </div>
                        <div class="cuti-type" data-type="cuti_khusus" onclick="selectCutiType(this)">
                            <div class="ct-icon">⭐</div><div class="ct-label">Khusus</div>
                        </div>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px;">
                    <div>
                        <label class="fl">Tanggal Mulai</label>
                        <input type="date" class="fi" name="start_date" required id="cutiStart" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div>
                        <label class="fl">Tanggal Selesai</label>
                        <input type="date" class="fi" name="end_date" required id="cutiEnd" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="fl">Alasan</label>
                    <textarea class="fi" name="reason" rows="2" placeholder="Jelaskan alasan cuti/izin..." required style="resize:vertical;"></textarea>
                </div>
                <button type="submit" class="btn-auth" id="cutiBtn" style="border-radius:10px;">📨 Kirim Pengajuan</button>
            </form>
        </div>

        <!-- Riwayat Cuti -->
        <div class="card">
            <div class="card-title">📅 Riwayat Cuti</div>
            <div id="cutiStats" style="margin-bottom:10px;"></div>
            <div id="cutiHistory"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
    </div>

    <!-- ═══ PAGE: ROOM ═══ -->
    <div class="page" id="page-occupancy">
        <div id="occStats"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        <div class="card">
            <div class="card-title">🏨 Status Kamar</div>
            <div id="roomGrid"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
        <div class="card">
            <div class="card-title">📅 Booking Calendar</div>
            <div class="cal-nav">
                <button onclick="calNav(-14)">◀ Prev</button>
                <span class="cal-period" id="calPeriod"></span>
                <button onclick="calNav(14)">Next ▶</button>
            </div>
            <div class="cal-scroll" id="calScroll">
                <div id="calGrid"><div class="loading"><span class="spin"></span> Memuat...</div></div>
            </div>
            <div class="cal-legend">
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#06b6d4;"></div>Confirmed</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#f59e0b;"></div>Pending</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#0284c7;"></div>Checked In</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#9ca3af;"></div>Checked Out</div>
            </div>
        </div>
    </div>
    <div id="calPopupOverlay" class="cal-popup-overlay" style="display:none;" onclick="closeCalPopup()"></div>
    <div id="calPopup" class="cal-popup" style="display:none;"></div>

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
        <div class="nav-item active" data-page="home"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></div>
        <div class="nav-item" data-page="occupancy"><span class="nav-icon">🏨</span><span class="nav-label">Room Monitor</span></div>
        <div class="nav-item" data-page="breakfast"><span class="nav-icon">☕</span><span class="nav-label">Breakfast</span></div>
    </div>
</div>

<script>
const API = '<?php echo $apiUrl; ?>';
let selectedBfMenu = null;
const CRED_KEY = 'staff_saved_cred_<?php echo md5($bizSlug); ?>';

// ═══ PASSWORD TOGGLE ═══
function togglePw(inputId, btn) {
    const inp = document.getElementById(inputId);
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.textContent = '🙈';
    } else {
        inp.type = 'password';
        btn.textContent = '👁️';
    }
}

// ═══ SAVE / LOAD CREDENTIALS ═══
function saveCredentials(email, password) {
    try { localStorage.setItem(CRED_KEY, JSON.stringify({ email, password })); } catch(e) {}
}
function loadCredentials() {
    try {
        const saved = JSON.parse(localStorage.getItem(CRED_KEY) || 'null');
        if (saved && saved.email) {
            document.getElementById('loginEmail').value = saved.email;
            document.getElementById('loginPass').value = saved.password || '';
        }
    } catch(e) {}
}
function clearCredentials() {
    try { localStorage.removeItem(CRED_KEY); } catch(e) {}
}

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
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(pe) {
            showMsg('Server error: ' + text.substring(0, 100), 'error');
            btn.disabled = false; btn.textContent = '🔐 Login';
            return false;
        }
        if (data.success) {
            localStorage.setItem('staff_name', data.name);
            if (document.getElementById('rememberMe').checked) {
                saveCredentials(fd.get('email'), fd.get('password'));
            } else { clearCredentials(); }
            showApp(data.name);
        } else { showMsg(data.message, 'error'); }
    } catch (err) { showMsg('Koneksi gagal: ' + err.message, 'error'); }
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
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(pe) {
            showMsg('Server error: ' + text.substring(0, 100), 'error');
            btn.disabled = false; btn.textContent = '📝 Daftar';
            return false;
        }
        showMsg(data.message, data.success ? 'ok' : 'error');
        if (data.success) {
            if (document.getElementById('rememberReg').checked) {
                saveCredentials(fd.get('email'), fd.get('password'));
            }
            setTimeout(() => switchAuth('login'), 1500);
        }
    } catch (err) { showMsg('Koneksi gagal: ' + err.message, 'error'); }
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
    loadHome();
}

function loadHome() {
    loadAbsen();
    loadMonitoring();
    loadCuti();
}

// ── Navigation ──
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => {
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        item.classList.add('active');
        const page = item.dataset.page;
        document.getElementById('page-' + page).classList.add('active');
        if (page === 'home') loadHome();
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
let calStartDate = new Date().toISOString().split('T')[0];

function calNav(days) {
    const d = new Date(calStartDate);
    d.setDate(d.getDate() + days);
    calStartDate = d.toISOString().split('T')[0];
    loadOccupancy();
}

function closeCalPopup() {
    document.getElementById('calPopup').style.display = 'none';
    document.getElementById('calPopupOverlay').style.display = 'none';
}

function showBookingPopup(b) {
    const statusMap = {'pending':'⏳ Pending','confirmed':'✅ Confirmed','checked_in':'🏨 Checked In','checked_out':'🚪 Checked Out'};
    const sourceMap = {'walk_in':'Walk In','agoda':'Agoda','booking':'Booking.com','traveloka':'Traveloka','airbnb':'Airbnb','tiket':'Tiket.com','phone':'Phone'};
    const payMap = {'unpaid':'❌ Belum Bayar','partial':'⚠️ Sebagian','paid':'✅ Lunas'};
    document.getElementById('calPopup').innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div style="font-weight:800;font-size:14px;color:var(--navy);">📋 Detail Booking</div>
            <button onclick="closeCalPopup()" style="background:none;border:none;font-size:18px;cursor:pointer;">✕</button>
        </div>
        <div style="font-size:12px;line-height:2;">
            <div><strong>Kode:</strong> ${b.booking_code||'-'}</div>
            <div><strong>Tamu:</strong> ${b.guest_name||'-'}</div>
            <div><strong>Check-in:</strong> ${b.check_in_date}</div>
            <div><strong>Check-out:</strong> ${b.check_out_date}</div>
            <div><strong>Status:</strong> ${statusMap[b.status]||b.status}</div>
            <div><strong>Sumber:</strong> ${sourceMap[b.booking_source]||b.booking_source||'-'}</div>
            <div><strong>Bayar:</strong> ${payMap[b.payment_status]||b.payment_status||'-'}</div>
        </div>`;
    document.getElementById('calPopup').style.display = 'block';
    document.getElementById('calPopupOverlay').style.display = 'block';
}

async function loadOccupancy() {
    try {
        const res = await fetch(API + '&action=occupancy&start=' + calStartDate);
        const data = await res.json();
        const d = data.data || {};

        // Stats with Pie Chart
        const occ = parseInt(d.occupied)||0;
        const avail = parseInt(d.available)||0;
        const total = parseInt(d.total_rooms)||0;
        const rate = parseFloat(d.occupancy_rate)||0;
        const arrivals = parseInt(d.arrivals_today)||0;
        const departures = parseInt(d.departures_today)||0;

        // SVG donut chart
        const radius = 54, cx = 65, cy = 65, stroke = 14;
        const circ = 2 * Math.PI * radius;
        const occPct = total > 0 ? occ / total : 0;
        const occLen = circ * occPct;
        const availLen = circ - occLen;

        document.getElementById('occStats').innerHTML = `
            <div class="card" style="margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;justify-content:center;">
                    <!-- Donut Chart -->
                    <div style="position:relative;width:130px;height:130px;flex-shrink:0;">
                        <svg width="130" height="130" viewBox="0 0 130 130">
                            <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#e5e7eb" stroke-width="${stroke}"/>
                            <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#0ea5e9" stroke-width="${stroke}"
                                stroke-dasharray="${occLen} ${availLen}"
                                stroke-dashoffset="${circ * 0.25}"
                                stroke-linecap="round"
                                style="transition:stroke-dasharray .8s ease;"/>
                            <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#22c55e" stroke-width="${stroke}"
                                stroke-dasharray="${availLen} ${occLen}"
                                stroke-dashoffset="${circ * 0.25 - occLen}"
                                stroke-linecap="round"
                                style="transition:stroke-dasharray .8s ease;"/>
                        </svg>
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                            <div style="font-size:24px;font-weight:900;color:var(--navy);line-height:1;">${rate}%</div>
                            <div style="font-size:8px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;">Occupancy</div>
                        </div>
                    </div>
                    <!-- Right Stats -->
                    <div style="flex:1;min-width:160px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div style="background:#f0fdf4;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;letter-spacing:.3px;">Available</div>
                                <div style="font-size:22px;font-weight:900;color:#16a34a;">${avail}</div>
                            </div>
                            <div style="background:#fef2f2;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.3px;">Occupied</div>
                                <div style="font-size:22px;font-weight:900;color:#dc2626;">${occ}</div>
                            </div>
                            <div style="background:#eff6ff;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.3px;">Total Rooms</div>
                                <div style="font-size:22px;font-weight:900;color:#2563eb;">${total}</div>
                            </div>
                            <div style="background:#fefce8;border-radius:10px;padding:10px;text-align:center;">
                                <div style="font-size:8px;font-weight:700;color:#ca8a04;text-transform:uppercase;letter-spacing:.3px;">Occ. Rate</div>
                                <div style="font-size:22px;font-weight:900;color:#ca8a04;">${rate}%</div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Arrivals / Departures -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px;">
                    <div style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:#16a34a;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">✈️</div>
                        <div><div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;">Arrivals</div><div style="font-size:20px;font-weight:900;color:#16a34a;">${arrivals}</div></div>
                    </div>
                    <div style="background:linear-gradient(135deg,#fff7ed,#fed7aa);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:#ea580c;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">🚪</div>
                        <div><div style="font-size:8px;font-weight:700;color:#ea580c;text-transform:uppercase;">Departures</div><div style="font-size:20px;font-weight:900;color:#ea580c;">${departures}</div></div>
                    </div>
                </div>
            </div>`;

        // Room grid
        const rooms = d.rooms || [];
        if (rooms.length === 0) {
            document.getElementById('roomGrid').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Tidak ada data kamar.</div>';
        } else {
            let rh = '<div class="room-grid">';
            rooms.forEach(r => {
                const isOcc = r.status === 'occupied';
                rh += `<div class="room-box ${isOcc?'occ':'avail'}">
                    ${r.room_number}
                    <div class="room-type">${r.room_type||''}</div>
                    ${isOcc ? `<div class="room-guest">${r.guest_name||''}</div>` : ''}
                </div>`;
            });
            rh += '</div>';
            document.getElementById('roomGrid').innerHTML = rh;
        }

        // Calendar
        const bookings = d.bookings || [];
        const start = new Date(d.calendar_start || calStartDate);
        const days = 14;
        const dates = [];
        const today = new Date().toISOString().split('T')[0];
        for (let i = 0; i < days; i++) {
            const dt = new Date(start);
            dt.setDate(dt.getDate() + i);
            dates.push(dt.toISOString().split('T')[0]);
        }
        
        // Period label
        const startM = new Date(dates[0]);
        const endM = new Date(dates[dates.length-1]);
        const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        document.getElementById('calPeriod').textContent = 
            startM.getDate() + ' ' + months[startM.getMonth()] + ' - ' + endM.getDate() + ' ' + months[endM.getMonth()] + ' ' + endM.getFullYear();

        // Group rooms by type
        const roomsByType = {};
        rooms.forEach(r => {
            const t = r.room_type || 'Standard';
            if (!roomsByType[t]) roomsByType[t] = [];
            roomsByType[t].push(r);
        });

        // Build booking map: room_id -> [{booking, startCol, span}]
        const bookingMap = {};
        bookings.forEach(b => {
            if (!bookingMap[b.room_id]) bookingMap[b.room_id] = [];
            const bStart = b.check_in_date;
            const bEnd = b.check_out_date;
            let startCol = -1, endCol = -1;
            for (let i = 0; i < dates.length; i++) {
                if (dates[i] >= bStart && startCol < 0) startCol = i;
                if (dates[i] < bEnd) endCol = i;
            }
            if (bStart < dates[0]) startCol = 0;
            if (endCol < 0 && bEnd > dates[0]) endCol = dates.length - 1;
            if (startCol >= 0 && endCol >= startCol) {
                bookingMap[b.room_id].push({ ...b, startCol, span: endCol - startCol + 1 });
            }
        });

        const dayNames = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
        let g = `<table class="cal-table"><thead><tr><th class="room-h">🏠</th>`;
        dates.forEach(dt => {
            const dd = new Date(dt + 'T00:00:00');
            const isToday = dt === today;
            g += `<th class="${isToday?'today-h':''}">${dayNames[dd.getDay()]}<br><span style="font-size:12px;">${dd.getDate()}</span></th>`;
        });
        g += `</tr></thead><tbody>`;

        // Room rows grouped by type
        const typeNames = Object.keys(roomsByType);
        typeNames.forEach(typeName => {
            g += `<tr class="cal-type-row"><td colspan="${days+1}">📂 ${typeName} (${roomsByType[typeName].length})</td></tr>`;
            roomsByType[typeName].forEach(room => {
                const roomBookings = bookingMap[room.id] || [];
                // Build a "used" array to track which cells are covered by a colspan
                const used = new Array(days).fill(false);
                roomBookings.forEach(rb => {
                    for (let c = rb.startCol; c < rb.startCol + rb.span; c++) {
                        if (c > rb.startCol) used[c] = true; // mark non-start cells as used
                    }
                });

                g += `<tr><td class="cal-room-cell">${room.room_number}</td>`;
                for (let i = 0; i < days; i++) {
                    if (used[i]) continue; // skip cells consumed by colspan
                    const isToday = dates[i] === today;
                    // Check if a booking starts here
                    const bk = roomBookings.find(rb => rb.startCol === i);
                    if (bk) {
                        const cls = 's-' + (bk.status||'').replace('_','-');
                        const name = (bk.guest_name||'Guest').substring(0, 8);
                        const bData = JSON.stringify({booking_code:bk.booking_code,guest_name:bk.guest_name,check_in_date:bk.check_in_date,check_out_date:bk.check_out_date,status:bk.status,booking_source:bk.booking_source,payment_status:bk.payment_status}).replace(/'/g,'&#39;');
                        g += `<td colspan="${bk.span}" class="cal-day-cell${isToday?' today-c':''}">`;
                        g += `<div class="cal-bar ${cls}" onclick='showBookingPopup(${bData})'><span>${name}</span></div>`;
                        g += `</td>`;
                    } else {
                        g += `<td class="cal-day-cell${isToday?' today-c':''}"></td>`;
                    }
                }
                g += `</tr>`;
            });
        });
        g += '</tbody></table>';
        document.getElementById('calGrid').innerHTML = g;

        // Scroll to today column
        const todayIdx = dates.indexOf(today);
        if (todayIdx > 1) {
            const scrollEl = document.getElementById('calScroll');
            setTimeout(() => { scrollEl.scrollLeft = Math.max(0, (todayIdx - 1) * 70); }, 100);
        }
    } catch(e) { 
        console.error(e);
        document.getElementById('roomGrid').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>'; 
    }
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
loadCredentials();
(async function checkSession() {
    try {
        const res = await fetch(API + '&action=profile');
        const data = await res.json();
        if (data.success && data.data) {
            showApp(data.data.full_name);
        }
    } catch(e) { /* stay on auth screen */ }
})();

// ═══ CUTI PAGE ═══
let selectedCutiType = 'cuti';

function selectCutiType(el) {
    document.querySelectorAll('.cuti-type').forEach(i => i.classList.remove('selected'));
    el.classList.add('selected');
    selectedCutiType = el.dataset.type;
}

async function submitCuti(e) {
    e.preventDefault();
    const btn = document.getElementById('cutiBtn');
    btn.disabled = true; btn.textContent = '⏳ Mengirim...';
    const fd = new FormData(e.target);
    fd.append('action', 'leave_submit');
    fd.append('leave_type', selectedCutiType);
    try {
        const res = await fetch(API, { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); } catch(pe) {
            btn.textContent = '❌ Server error';
            setTimeout(() => { btn.textContent = '📨 Kirim Pengajuan'; btn.disabled = false; }, 2000);
            return false;
        }
        if (data.success) {
            btn.textContent = '✅ ' + data.message;
            e.target.reset();
            document.querySelectorAll('.cuti-type').forEach(i => i.classList.remove('selected'));
            document.querySelector('.cuti-type[data-type="cuti"]').classList.add('selected');
            selectedCutiType = 'cuti';
            setTimeout(() => { btn.textContent = '📨 Kirim Pengajuan'; btn.disabled = false; loadCuti(); }, 2000);
        } else {
            btn.textContent = '❌ ' + data.message;
            setTimeout(() => { btn.textContent = '📨 Kirim Pengajuan'; btn.disabled = false; }, 2500);
        }
    } catch(err) {
        btn.textContent = '❌ Koneksi gagal';
        setTimeout(() => { btn.textContent = '📨 Kirim Pengajuan'; btn.disabled = false; }, 2000);
    }
    return false;
}

async function loadCuti() {
    try {
        const res = await fetch(API + '&action=leave_history');
        const data = await res.json();
        const stats = data.stats || {};
        const rows = data.data || [];

        document.getElementById('cutiStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">⏳ Pending</div><div class="sv" style="color:var(--orange);">${stats.pending||0}</div></div>
                <div class="stat-card"><div class="sl">✅ Disetujui</div><div class="sv" style="color:var(--green);">${stats.approved||0}</div></div>
                <div class="stat-card"><div class="sl">❌ Ditolak</div><div class="sv" style="color:var(--red);">${stats.rejected||0}</div></div>
                <div class="stat-card"><div class="sl">🏖️ Cuti Tahun Ini</div><div class="sv" style="color:var(--blue);">${stats.cuti_used||0}</div></div>
            </div>`;

        if (rows.length === 0) {
            document.getElementById('cutiHistory').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">Belum ada riwayat pengajuan cuti.</div>';
            return;
        }

        const typeLabel = { cuti:'🏖️ Cuti', sakit:'🩺 Sakit', izin:'📋 Izin', cuti_khusus:'⭐ Khusus' };
        const statusCls = { pending:'ls-pending', approved:'ls-approved', rejected:'ls-rejected' };
        const statusLabel = { pending:'⏳ Pending', approved:'✅ Disetujui', rejected:'❌ Ditolak' };
        let html = '';
        rows.forEach(r => {
            const s = new Date(r.start_date).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'});
            const e = new Date(r.end_date).toLocaleDateString('id-ID',{day:'numeric',month:'short',year:'numeric'});
            const days = Math.ceil((new Date(r.end_date) - new Date(r.start_date)) / 86400000) + 1;
            html += `<div style="padding:12px 0;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                    <span style="font-weight:700;font-size:12px;">${typeLabel[r.leave_type]||r.leave_type}</span>
                    <span class="leave-status ${statusCls[r.status]||''}">${statusLabel[r.status]||r.status}</span>
                </div>
                <div style="font-size:11px;color:var(--muted);">📅 ${s} — ${e} (${days} hari)</div>
                <div style="font-size:11px;color:var(--text);margin-top:3px;">${r.reason||''}</div>
                ${r.admin_notes ? `<div style="font-size:10px;color:var(--blue);margin-top:3px;background:#eff6ff;padding:4px 8px;border-radius:4px;">💬 ${r.admin_notes}</div>` : ''}
            </div>`;
        });
        document.getElementById('cutiHistory').innerHTML = html;
    } catch(e) { document.getElementById('cutiHistory').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>'; }
}

// ═══ NOTIFICATIONS ═══
let notifOpen = false;

function toggleNotifs() {
    notifOpen = !notifOpen;
    const popup = document.getElementById('notifPopup');
    if (notifOpen) {
        popup.classList.add('open');
        loadNotifs();
    } else {
        popup.classList.remove('open');
    }
}

// Close notif popup when clicking outside
document.addEventListener('click', function(e) {
    if (notifOpen && !e.target.closest('.notif-bell') && !e.target.closest('.notif-popup')) {
        notifOpen = false;
        document.getElementById('notifPopup').classList.remove('open');
    }
});

async function loadNotifs() {
    try {
        const res = await fetch(API + '&action=notifications');
        const data = await res.json();
        const notifs = data.data || [];
        if (notifs.length === 0) {
            document.getElementById('notifList').innerHTML = '<div class="np-empty">🔔 Belum ada notifikasi</div>';
            return;
        }
        const typeLabel = { cuti:'🏖️ Cuti', sakit:'🩺 Sakit', izin:'📋 Izin', cuti_khusus:'⭐ Khusus' };
        let html = '';
        notifs.forEach(n => {
            const icon = n.status === 'approved' ? '✅' : '❌';
            const label = n.status === 'approved' ? 'DISETUJUI' : 'DITOLAK';
            const color = n.status === 'approved' ? 'var(--green)' : 'var(--red)';
            const s = new Date(n.start_date).toLocaleDateString('id-ID',{day:'numeric',month:'short'});
            const e = new Date(n.end_date).toLocaleDateString('id-ID',{day:'numeric',month:'short'});
            const time = n.approved_at ? new Date(n.approved_at).toLocaleDateString('id-ID',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}) : '';
            html += `<div class="np-item">
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                    <span style="font-size:14px;">${icon}</span>
                    <span style="font-weight:700;font-size:12px;color:${color};">${label}</span>
                    <span style="font-size:10px;color:var(--muted);margin-left:auto;">${time}</span>
                </div>
                <div style="font-size:11px;">${typeLabel[n.leave_type]||n.leave_type}: ${s} — ${e}</div>
                ${n.admin_notes ? `<div style="font-size:10px;color:var(--blue);margin-top:2px;">💬 ${n.admin_notes}</div>` : ''}
            </div>`;
        });
        document.getElementById('notifList').innerHTML = html;
    } catch(e) { document.getElementById('notifList').innerHTML = '<div class="np-empty">Gagal memuat</div>'; }
}

async function checkNotifs() {
    try {
        const res = await fetch(API + '&action=notifications');
        const data = await res.json();
        const notifs = data.data || [];
        const lastSeen = localStorage.getItem('notif_last_seen') || '';
        const hasNew = notifs.length > 0 && (!lastSeen || notifs[0].approved_at > lastSeen);
        document.getElementById('notifDot').classList.toggle('show', hasNew);
        if (notifOpen && notifs.length > 0) {
            localStorage.setItem('notif_last_seen', notifs[0].approved_at);
        }
    } catch(e) {}
}

// ═══ PWA INSTALL ═══
let deferredPrompt = null;
const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    document.getElementById('installBanner').classList.add('show');
});

document.getElementById('installBanner').addEventListener('click', async (e) => {
    if (e.target.closest('.ib-close')) return;
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const result = await deferredPrompt.userChoice;
    if (result.outcome === 'accepted') {
        document.getElementById('installBanner').classList.remove('show');
    }
    deferredPrompt = null;
});

window.addEventListener('appinstalled', () => {
    document.getElementById('installBanner').classList.remove('show');
    deferredPrompt = null;
});

// Show iOS guide if on Safari and not yet installed
if (isIOS && !isStandalone && !localStorage.getItem('ios_guide_dismissed')) {
    document.getElementById('iosGuide').style.display = 'block';
}

// Register service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?php echo $baseUrl; ?>/sw.js').catch(() => {});
}

// Check notifications every 60s
setInterval(checkNotifs, 60000);
setTimeout(checkNotifs, 3000);
</script>

</body>
</html>
