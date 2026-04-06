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
$bizType = $bizConfig['business_type'] ?? 'general';
$isHotel = ($bizType === 'hotel');
$isCafe = in_array($bizType, ['cafe', 'restaurant']);
$themeColor = $bizConfig['theme']['color_primary'] ?? '#0d1f3c';
$themeSecondary = $bizConfig['theme']['color_secondary'] ?? ($isCafe ? '#2563eb' : '#1a3a5c');
$bizIcon = $bizConfig['theme']['icon'] ?? '🏢';

// Logo
$absenConfig = $db->fetchOne("SELECT app_logo FROM payroll_attendance_config WHERE id=1") ?: [];
$appLogo = null;
if (!empty($absenConfig['app_logo'])) {
    $appLogo = (str_starts_with($absenConfig['app_logo'], 'http')) ? $absenConfig['app_logo'] : $baseUrl . '/' . ltrim($absenConfig['app_logo'], '/');
}

// Invoice/PDF logo (same as report-settings) for slip gaji — from MASTER DB
$slipLogo = null;
try {
    $masterDb = Database::getInstance();
    $invoiceLogoRow = $masterDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", ['key' => 'invoice_logo_' . ACTIVE_BUSINESS_ID]);
    if ($invoiceLogoRow && !empty($invoiceLogoRow['setting_value'])) {
        $val = $invoiceLogoRow['setting_value'];
        $slipLogo = (strpos($val, 'http') === 0) ? $val : $baseUrl . '/uploads/logos/' . $val;
    }
} catch (Exception $e) {}

// PWA Icon — use login_logo from settings (same DB as login.php & developer-settings.php)
$pwaIconUrl = 'absen-icon.php?size=192'; // fallback
try {
    // developer-settings.php stores into getInstance() DB, login.php reads from same
    $settingsDb = Database::getInstance();
    $iconKeys = [
        'pwa_app_icon' => 'uploads/icons/',
        'login_logo'   => 'uploads/logos/',
    ];
    foreach ($iconKeys as $iconKey => $localPrefix) {
        $iconRow = $settingsDb->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$iconKey]);
        $iconVal = $iconRow['setting_value'] ?? null;
        if (!$iconVal) continue;
        if (strpos($iconVal, 'http') === 0) {
            $pwaIconUrl = $iconVal;
            break;
        } else {
            $localPath = __DIR__ . '/../../' . $localPrefix . $iconVal;
            if (file_exists($localPath)) {
                $pwaIconUrl = $baseUrl . '/' . $localPrefix . $iconVal;
                break;
            }
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor); ?>">
    <title>Staff Portal - <?php echo $bizName; ?></title>
    <link rel="manifest" href="staff-manifest.php?b=<?php echo urlencode($bizSlug); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($pwaIconUrl); ?>">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        :root { --navy:<?php echo htmlspecialchars($themeColor); ?>; --navy2:<?php echo htmlspecialchars($themeSecondary); ?>; --gold:#f0b429; --green:#059669; --red:#dc2626; --orange:#ea580c; --blue:#2563eb; --purple:#7c3aed; --bg:#f1f5f9; --card:#fff; --border:#e2e8f0; --muted:#64748b; --text:#1e293b; }
        body { font-family:'Inter','Segoe UI',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; -webkit-font-smoothing:antialiased; }

        /* ── Auth Screen ── */
        .auth-wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 100%); }
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
        .app-header { background:linear-gradient(135deg,var(--navy),var(--navy2)); padding:14px 16px; display:flex; align-items:center; gap:10px; position:sticky; top:0; z-index:100; }
        .app-header .logo { height:36px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,.2); }
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

        /* Install banner — fixed bottom, visible everywhere */
        .install-banner { position:fixed; bottom:0; left:0; right:0; z-index:900; background:linear-gradient(135deg,var(--navy) 0%,var(--navy2) 100%); color:#fff; padding:16px 16px calc(16px + env(safe-area-inset-bottom,0px)); display:none; align-items:center; gap:12px; cursor:pointer; border-top:1px solid rgba(240,180,41,.3); box-shadow:0 -4px 30px rgba(0,0,0,.3); }
        .install-banner::before { content:''; position:absolute; top:-50%; right:-20%; width:120px; height:120px; background:radial-gradient(circle,rgba(240,180,41,.15),transparent 70%); border-radius:50%; pointer-events:none; }
        .install-banner.show { display:flex; animation:ibSlideUp .5s cubic-bezier(.16,1,.3,1); }
        @keyframes ibSlideUp { from { opacity:0; transform:translateY(100%); } to { opacity:1; transform:translateY(0); } }
        .install-banner .ib-icon { width:44px; height:44px; background:linear-gradient(135deg,var(--gold),#e09800); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; }
        .install-banner .ib-text { flex:1; }
        .install-banner .ib-title { font-weight:700; font-size:14px; color:#fff; }
        .install-banner .ib-sub { font-size:11px; color:rgba(255,255,255,.6); margin-top:2px; }
        .install-banner .ib-action { background:var(--gold); color:var(--navy); border:none; padding:10px 20px; border-radius:10px; font-size:12px; font-weight:700; cursor:pointer; white-space:nowrap; }
        .install-banner .ib-close { background:none; border:none; font-size:16px; cursor:pointer; padding:4px; color:rgba(255,255,255,.4); position:absolute; top:8px; right:8px; }
        /* Install progress overlay */
        .install-progress { display:none; position:fixed; inset:0; background:rgba(5,10,24,.95); z-index:2000; flex-direction:column; align-items:center; justify-content:center; }
        .install-progress.show { display:flex; animation:faceIn .3s ease; }
        .ip-icon { width:80px; height:80px; border-radius:20px; margin-bottom:20px; object-fit:cover; box-shadow:0 8px 30px rgba(0,0,0,.4); }
        .ip-title { color:#fff; font-size:18px; font-weight:700; margin-bottom:6px; }
        .ip-sub { color:rgba(255,255,255,.5); font-size:12px; margin-bottom:24px; text-align:center; padding:0 40px; }
        .ip-bar { width:200px; height:4px; background:rgba(255,255,255,.1); border-radius:2px; overflow:hidden; margin-bottom:8px; }
        .ip-bar-fill { height:100%; background:linear-gradient(90deg,var(--gold),#34d399); border-radius:2px; width:0%; transition:width .5s cubic-bezier(.4,0,.2,1); }
        .ip-step { color:rgba(255,255,255,.4); font-size:11px; min-height:16px; }
        .ip-done { display:none; flex-direction:column; align-items:center; }
        .ip-check { width:56px; height:56px; background:linear-gradient(135deg,#34d399,#059669); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:28px; color:#fff; margin-bottom:16px; animation:popIn .4s cubic-bezier(.16,1,.3,1); }
        @keyframes popIn { from { transform:scale(0); } to { transform:scale(1); } }
        .ip-done-text { color:#fff; font-size:16px; font-weight:700; }
        .ip-done-sub { color:rgba(255,255,255,.5); font-size:11px; margin-top:4px; }

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

        /* Slip Gaji rows */
        .slip-row { display:flex; justify-content:space-between; padding:6px 0; font-size:11px; border-bottom:1px dashed #e2e8f0; }
        .slip-row:last-child { border-bottom:none; }
        .slip-val { font-weight:600; font-family:'SF Mono',Monaco,monospace; color:var(--text); font-size:11px; }
        .slip-deduct { color:#dc2626; }

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
        .room-box.b2b { background:#fef2f2; color:var(--red); border-color:#fca5a5; position:relative; }
        .room-box.b2b::after { content:'B2B'; position:absolute; top:-6px; right:-6px; background:#16a34a; color:#fff; font-size:7px; font-weight:700; padding:1px 4px; border-radius:6px; line-height:1.2; }
        .room-box .room-type { font-size:8px; color:var(--muted); font-weight:400; margin-top:1px; }
        .room-box .room-guest { font-size:8px; color:var(--red); font-weight:500; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .room-box .room-next { font-size:7px; color:#16a34a; font-weight:600; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

        /* Booking Calendar - Frontdesk Style */
        .cal-nav { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:6px; }
        .cal-nav button { background:var(--navy); color:#fff; border:none; border-radius:8px; padding:6px 14px; font-size:11px; font-weight:600; cursor:pointer; transition:.15s; }
        .cal-nav button:active { opacity:.7; transform:scale(.96); }
        .cal-nav .cal-period { font-size:12px; font-weight:700; color:var(--navy); }
        .cal-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; border-radius:10px; background:#fff; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .cal-grid { display:grid; gap:0; width:fit-content; min-width:fit-content; }
        .cal-grid-header { display:contents; }
        .cal-grid-footer { display:contents; }
        /* Header cells */
        .cg-hdr-room { background:linear-gradient(135deg,#f1f5f9,#fff); border-right:2px solid #e2e8f0; border-bottom:2px solid #cbd5e1; padding:4px; font-weight:800; text-align:center; position:sticky; left:0; z-index:40; font-size:9px; color:#475569; letter-spacing:.8px; text-transform:uppercase; display:flex; align-items:center; justify-content:center; min-width:95px; max-width:95px; box-shadow:2px 0 6px rgba(0,0,0,.04); min-height:28px; }
        .cg-hdr-date { background:linear-gradient(180deg,#f8fafc,#f1f5f9); border-right:1px solid #e2e8f0; border-bottom:2px solid #cbd5e1; padding:3px 2px; text-align:center; font-weight:700; font-size:9px; color:#334155; min-width:130px; min-height:28px; }
        .cg-hdr-date.today { background:rgba(99,102,241,.12)!important; }
        .cg-hdr-day { font-size:9px; text-transform:uppercase; font-weight:600; color:#64748b; letter-spacing:.3px; }
        .cg-hdr-num { font-size:13px; font-weight:900; color:#1e293b; margin-left:2px; }
        .cg-hdr-date.today .cg-hdr-num { color:#6366f1; }
        /* Footer cells */
        .cg-ftr-room { background:linear-gradient(135deg,#f1f5f9,#fff); border-right:2px solid #e2e8f0; border-top:2px solid #cbd5e1; padding:4px; font-weight:800; text-align:center; position:sticky; left:0; z-index:40; font-size:9px; color:#475569; letter-spacing:.8px; text-transform:uppercase; display:flex; align-items:center; justify-content:center; min-width:95px; max-width:95px; box-shadow:2px 0 6px rgba(0,0,0,.04); min-height:28px; }
        .cg-ftr-date { background:linear-gradient(180deg,#f8fafc,#f1f5f9); border-right:1px solid #e2e8f0; border-top:2px solid #cbd5e1; padding:3px 2px; text-align:center; font-weight:700; font-size:9px; color:#334155; min-height:28px; }
        .cg-ftr-date.today { background:rgba(99,102,241,.12)!important; }
        /* Type header row */
        .cg-type-hdr { background:linear-gradient(135deg,#eef2ff,#e0e7ff); border-right:2px solid #a5b4fc; border-bottom:1px solid #c7d2fe; padding:3px 6px; font-weight:800; color:#4338ca; position:sticky; left:0; z-index:30; display:flex; align-items:center; font-size:10px; gap:4px; min-width:95px; max-width:95px; box-shadow:2px 0 6px rgba(0,0,0,.04); min-height:24px; }
        .cg-type-price { background:linear-gradient(135deg,#eef2ff,#e0e7ff); border-right:1px solid #c7d2fe; border-bottom:1px solid #a5b4fc; display:flex; align-items:center; justify-content:center; font-size:9px; font-weight:800; color:#4338ca; letter-spacing:.3px; min-height:24px; }
        /* Room labels */
        .cg-room { background:linear-gradient(135deg,#f8fafc,#fff); border-right:2px solid #e2e8f0; border-bottom:1px solid #f1f5f9; padding:2px 4px; font-weight:700; color:#334155; position:sticky; left:0; z-index:30; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; min-width:95px; max-width:95px; box-shadow:2px 0 6px rgba(0,0,0,.04); transition:.15s; min-height:28px; }
        .cg-room:hover { background:linear-gradient(135deg,#eef2ff,#e0e7ff); border-right-color:#a5b4fc; }
        .cg-room-type { font-size:8px; font-weight:600; color:#6366f1; text-transform:uppercase; letter-spacing:.5px; line-height:1; }
        .cg-room-num { font-size:14px; color:#1e293b; font-weight:900; line-height:1; letter-spacing:.3px; }
        /* Date cells */
        .cg-cell { border-right:1px solid rgba(51,65,85,.12); border-bottom:1px solid rgba(51,65,85,.12); min-width:130px; min-height:28px; position:relative; background:transparent; cursor:pointer; }
        .cg-cell.today { background:rgba(99,102,241,.05)!important; }
        .cg-cell:hover { background:rgba(99,102,241,.04); }
        /* Booking bars - Skewed CloudBed style */
        .bbar-wrap { position:absolute; top:2px; left:50%; height:24px; display:flex; align-items:center; overflow:visible; z-index:10; margin-left:4px; cursor:pointer; }
        .bbar { width:100%; height:22px; padding:0 6px; display:flex; align-items:center; justify-content:center; text-align:center; box-shadow:0 2px 6px rgba(0,0,0,.15),0 1px 2px rgba(0,0,0,.1); font-weight:700; font-size:10px; line-height:1.1; position:relative; border-radius:3px; white-space:nowrap; transform:skewX(-20deg); color:#fff!important; transition:all .2s; overflow:hidden; }
        .bbar > span { transform:skewX(20deg); color:#fff!important; text-shadow:0 1px 3px rgba(0,0,0,.6); font-weight:800; font-size:10px; display:block; }
        .bbar::before { content:''; position:absolute; left:-8px; top:50%; transform:translateY(-50%); width:0; height:0; border-top:10px solid transparent; border-bottom:10px solid transparent; border-right:5px solid; border-right-color:inherit; }
        .bbar::after { content:''; position:absolute; right:-8px; top:50%; transform:translateY(-50%); width:0; height:0; border-top:10px solid transparent; border-bottom:10px solid transparent; border-left:5px solid; border-left-color:inherit; }
        .bbar:hover { transform:skewX(-20deg) scaleY(1.15); box-shadow:0 8px 24px rgba(0,0,0,.3); z-index:20; }
        .bbar.s-confirmed { background:linear-gradient(135deg,#06b6d4,#22d3ee)!important; border-color:#06b6d4; }
        .bbar.s-pending { background:linear-gradient(135deg,#0ea5e9,#38bdf8)!important; border-color:#0ea5e9; }
        .bbar.s-checked-in { background:linear-gradient(135deg,#16a34a,#22c55e)!important; border-color:#16a34a; }
        .bbar.s-checked-out { background:linear-gradient(135deg,#9ca3af,#d1d5db)!important; border-color:#9ca3af; opacity:.4; }
        .bbar.s-checked-out > span { color:#6b7280!important; text-shadow:0 1px 2px rgba(0,0,0,.1)!important; }
        .bbar.s-checked-out:hover { opacity:.6; }
        .bbar::after { content:''; position:absolute; right:-8px; top:50%; transform:translateY(-50%); width:0; height:0; border-top:10px solid transparent; border-bottom:10px solid transparent; border-left:5px solid; border-left-color:inherit; }
        .bbar:hover { transform:skewX(-20deg) scaleY(1.15); box-shadow:0 8px 24px rgba(0,0,0,.3); z-index:20; }
        .bbar.s-confirmed { background:linear-gradient(135deg,#06b6d4,#22d3ee)!important; border-color:#06b6d4; }
        .bbar.s-pending { background:linear-gradient(135deg,#0ea5e9,#38bdf8)!important; border-color:#0ea5e9; }
        .bbar.s-checked-in { background:linear-gradient(135deg,#16a34a,#22c55e)!important; border-color:#16a34a; }
        .bbar.s-checked-out { background:linear-gradient(135deg,#9ca3af,#d1d5db)!important; border-color:#9ca3af; opacity:.4; }
        .bbar.s-checked-out > span { color:#6b7280!important; text-shadow:0 1px 2px rgba(0,0,0,.1)!important; }
        .bbar.s-checked-out:hover { opacity:.6; }
        /* Legend */
        .cal-legend { display:flex; flex-wrap:wrap; gap:10px; margin-top:10px; justify-content:center; }
        .cal-legend-item { display:flex; align-items:center; gap:4px; font-size:9px; color:var(--muted); font-weight:500; }
        .cal-legend-dot { width:14px; height:8px; border-radius:3px; transform:skewX(-20deg); }
        /* Popup */
        .cal-popup { position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:16px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.3); z-index:1000; width:290px; max-width:90vw; }
        .cal-popup-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:999; }

        /* Breakfast */
        .bf-order { padding:12px; border-bottom:1px solid var(--border); transition:background .15s; }
        .bf-order:last-child { border-bottom:none; }
        .bf-order:hover { background:#f8fafc; }
        .bf-order-hdr { display:flex; align-items:center; justify-content:space-between; margin-bottom:4px; }
        .bf-time { font-size:10px; font-weight:700; color:var(--blue); background:#eff6ff; padding:2px 6px; border-radius:4px; }
        .bf-pax { font-size:9px; font-weight:600; color:var(--muted); background:var(--bg); padding:2px 6px; border-radius:4px; }
        .bf-status { font-size:9px; font-weight:700; padding:2px 8px; border-radius:10px; text-transform:uppercase; letter-spacing:.3px; }
        .bf-st-pending { background:rgba(245,158,11,.12); color:#d97706; }
        .bf-st-prep { background:rgba(99,102,241,.12); color:#6366f1; }
        .bf-st-served { background:rgba(16,185,129,.12); color:#059669; }
        .bf-st-done { background:rgba(107,114,128,.12); color:#6b7280; }
        .bf-guest { font-size:13px; font-weight:700; color:var(--navy); margin-bottom:2px; }
        .bf-room { font-size:10px; color:var(--muted); margin-bottom:5px; }
        .bf-menus { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:5px; }
        .bf-tag { font-size:9px; padding:2px 6px; background:rgba(139,92,246,.1); color:#7c3aed; border-radius:4px; font-weight:600; }
        .bf-foot { display:flex; align-items:center; gap:8px; }
        .bf-price { font-size:11px; font-weight:800; color:#059669; }

        /* Absen button */
        .absen-link { display:block; background:linear-gradient(135deg,var(--navy),var(--navy2)); color:#fff; text-decoration:none; border-radius:16px; padding:20px 16px; text-align:center; margin-bottom:14px; cursor:pointer; position:relative; overflow:hidden; transition:transform .15s; }
        .absen-link:active { transform:scale(.98); }
        .absen-link::before { content:''; position:absolute; top:-50%; right:-30%; width:150px; height:150px; background:radial-gradient(circle,rgba(255,255,255,.06),transparent 70%); border-radius:50%; pointer-events:none; }
        .absen-link .al-icon { margin-bottom:8px; }
        .absen-link .al-icon svg { width:44px; height:44px; }
        .absen-link .al-title { font-size:15px; font-weight:700; letter-spacing:.3px; }
        .absen-link .al-sub { font-size:11px; color:rgba(255,255,255,.6); margin-top:4px; }

        /* Face Scan Modal — Professional Biometric */
        .face-overlay { display:none; position:fixed; inset:0; background:linear-gradient(160deg,#050a18 0%,#0a1628 40%,#0f1d35 100%); z-index:1000; flex-direction:column; align-items:center; justify-content:center; }
        .face-overlay.show { display:flex; animation:faceIn .3s cubic-bezier(.16,1,.3,1); }
        @keyframes faceIn { from { opacity:0; transform:scale(1.03); } to { opacity:1; transform:scale(1); } }
        .face-close { position:absolute; top:env(safe-area-inset-top,16px); right:16px; margin-top:16px; background:rgba(255,255,255,.06); backdrop-filter:blur(12px); border:1px solid rgba(255,255,255,.08); color:rgba(255,255,255,.6); font-size:18px; width:40px; height:40px; border-radius:50%; cursor:pointer; z-index:10; transition:all .2s; }
        .face-close:hover { background:rgba(255,255,255,.12); color:#fff; }
        .face-header { text-align:center; margin-bottom:20px; }
        .face-header h3 { color:#fff; font-size:15px; font-weight:700; margin:0 0 4px; letter-spacing:.8px; text-transform:uppercase; }
        .face-header p { color:rgba(255,255,255,.35); font-size:10px; margin:0; letter-spacing:.5px; }
        .face-ring-wrap { position:relative; width:260px; height:260px; }
        .face-ring-outer { position:absolute; inset:-14px; border-radius:50%; border:1.5px solid rgba(240,180,41,.12); }
        .face-ring-scan { position:absolute; inset:-14px; border-radius:50%; border:2.5px solid transparent; border-top-color:rgba(240,180,41,.7); border-right-color:rgba(240,180,41,.2); animation:faceSpin 1.5s linear infinite; }
        @keyframes faceSpin { to { transform:rotate(360deg); } }
        .face-ring-scan.matched { border-top-color:#34d399; border-right-color:rgba(52,211,153,.3); animation-duration:.8s; }
        .face-ring-pulse { position:absolute; inset:-22px; border-radius:50%; border:1px solid rgba(240,180,41,.08); animation:ringPulse 2s ease-out infinite; }
        @keyframes ringPulse { 0% { transform:scale(.96); opacity:1; } 100% { transform:scale(1.06); opacity:0; } }
        .face-container { position:relative; width:260px; height:260px; border-radius:50%; overflow:hidden; border:3px solid rgba(255,255,255,.08); transition:border-color .3s, box-shadow .3s; }
        .face-container.matched { border-color:#34d399; box-shadow:0 0 50px rgba(52,211,153,.2), 0 0 100px rgba(52,211,153,.08); }
        .face-container video { width:100%; height:100%; object-fit:cover; transform:scaleX(-1); }
        .face-container canvas { position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; }
        .face-corners { position:absolute; inset:0; pointer-events:none; }
        .face-corners::before, .face-corners::after { content:''; position:absolute; width:28px; height:28px; border-color:rgba(240,180,41,.5); border-style:solid; }
        .face-corners::before { top:12px; left:12px; border-width:2px 0 0 2px; border-radius:4px 0 0 0; }
        .face-corners::after { top:12px; right:12px; border-width:2px 2px 0 0; border-radius:0 4px 0 0; }
        .face-corners-b { position:absolute; inset:0; pointer-events:none; }
        .face-corners-b::before, .face-corners-b::after { content:''; position:absolute; width:28px; height:28px; border-color:rgba(240,180,41,.5); border-style:solid; }
        .face-corners-b::before { bottom:12px; left:12px; border-width:0 0 2px 2px; border-radius:0 0 0 4px; }
        .face-corners-b::after { bottom:12px; right:12px; border-width:0 2px 2px 0; border-radius:0 0 4px 0; }
        .face-container.matched .face-corners::before, .face-container.matched .face-corners::after,
        .face-container.matched .face-corners-b::before, .face-container.matched .face-corners-b::after { border-color:#34d399; transition:border-color .3s; }
        .face-scan-line { position:absolute; left:10%; right:10%; height:1.5px; background:linear-gradient(90deg,transparent,rgba(240,180,41,.5),transparent); top:20%; animation:scanLine 1.8s ease-in-out infinite; pointer-events:none; }
        @keyframes scanLine { 0%,100% { top:20%; opacity:.4; } 50% { top:75%; opacity:1; } }
        .face-container.matched .face-scan-line { background:linear-gradient(90deg,transparent,rgba(52,211,153,.5),transparent); animation-duration:1s; }
        .face-status { color:#fff; font-size:15px; text-align:center; margin-top:18px; font-weight:700; min-height:20px; letter-spacing:.3px; transition:color .3s; }
        .face-status-sub { color:rgba(255,255,255,.4); font-size:10px; text-align:center; margin-top:4px; min-height:14px; letter-spacing:.3px; }
        .face-meter { width:220px; height:5px; background:rgba(255,255,255,.06); border-radius:3px; margin-top:14px; overflow:hidden; position:relative; }
        .face-meter::before { content:''; position:absolute; inset:0; background:rgba(255,255,255,.02); border-radius:3px; }
        .face-meter-fill { height:100%; border-radius:3px; width:0%; transition:width .25s cubic-bezier(.4,0,.2,1), background .3s; }
        .face-meter-label { color:rgba(255,255,255,.55); font-size:12px; text-align:center; margin-top:6px; min-height:14px; font-weight:600; font-variant-numeric:tabular-nums; }
        .face-btn-register { margin-top:20px; padding:14px 40px; background:linear-gradient(135deg,#f0b429,#e09800); color:var(--navy); border:none; border-radius:14px; font-size:14px; font-weight:700; cursor:pointer; display:none; transition:all .2s; box-shadow:0 4px 24px rgba(240,180,41,.25); letter-spacing:.5px; text-transform:uppercase; }
        .face-btn-register:active { transform:scale(.96); }
        .face-btn-register:disabled { opacity:.5; transform:none; }
        .face-btn-reregister { margin-top:12px; padding:10px 28px; background:transparent; color:rgba(255,255,255,.5); border:1.5px solid rgba(255,255,255,.12); border-radius:12px; font-size:11px; font-weight:600; cursor:pointer; display:none; transition:all .2s; letter-spacing:.3px; backdrop-filter:blur(8px); }
        .face-btn-reregister:hover { border-color:rgba(255,255,255,.25); color:rgba(255,255,255,.8); background:rgba(255,255,255,.04); }
        .face-btn-reregister:active { transform:scale(.96); }
        .face-gps-info { color:rgba(255,255,255,.3); font-size:9px; text-align:center; margin-top:14px; min-height:14px; padding:0 20px; font-variant-numeric:tabular-nums; }
        .face-particles { position:absolute; inset:0; pointer-events:none; overflow:hidden; }
        .face-particle { position:absolute; width:2px; height:2px; background:rgba(240,180,41,.2); border-radius:50%; animation:particleFloat linear infinite; }
        @keyframes particleFloat { 0% { transform:translateY(100vh) scale(0); opacity:0; } 10% { opacity:.8; } 90% { opacity:.8; } 100% { transform:translateY(-20px) scale(1); opacity:0; } }

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
            <?php
            $displayLogo = $appLogo ?: (strpos($pwaIconUrl, 'absen-icon.php') === false ? $pwaIconUrl : null);
            if ($displayLogo): ?><img src="<?php echo htmlspecialchars($displayLogo); ?>" alt="Logo"><?php endif; ?>
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
        <?php 
        $headerLogo = $appLogo ?: (strpos($pwaIconUrl, 'absen-icon.php') === false ? $pwaIconUrl : null);
        if ($headerLogo): ?><img src="<?php echo htmlspecialchars($headerLogo); ?>" class="logo"><?php endif; ?>
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
        <div class="absen-link" onclick="openFaceScan()">
            <div class="al-icon"><svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="4" y="8" width="56" height="48" rx="10" stroke="rgba(255,255,255,.25)" stroke-width="2"/><rect x="4" y="8" width="18" height="14" rx="4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none" style="clip-path:inset(0 50% 50% 0)"/><rect x="42" y="8" width="18" height="14" rx="4" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none" style="clip-path:inset(0 0 50% 50%)"/><rect x="4" y="42" width="18" height="14" rx="4" stroke="white" stroke-width="2.5" fill="none" style="clip-path:inset(50% 50% 0 0)"/><rect x="42" y="42" width="18" height="14" rx="4" stroke="white" stroke-width="2.5" fill="none" style="clip-path:inset(50% 0 0 50%)"/><circle cx="32" cy="28" r="8" stroke="white" stroke-width="2"/><path d="M22 46c0-5.523 4.477-10 10-10s10 4.477 10 10" stroke="white" stroke-width="2" stroke-linecap="round"/><line x1="4" y1="32" x2="12" y2="32" stroke="rgba(255,255,255,.4)" stroke-width="1.5" stroke-dasharray="2 2"/><line x1="52" y1="32" x2="60" y2="32" stroke="rgba(255,255,255,.4)" stroke-width="1.5" stroke-dasharray="2 2"/></svg></div>
            <div class="al-title">Face Scan — Absen Sekarang</div>
            <div class="al-sub">Tap untuk verifikasi wajah otomatis</div>
        </div>

        <!-- Status Hari Ini -->
        <div class="card">
            <div class="card-title">📋 Status Absen Hari Ini</div>
            <div id="todayStatus"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>

        <!-- Target Jam - Donut Chart -->
        <div class="card">
            <div class="card-title">📊 Target Jam Bulan Ini</div>
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

    <!-- ═══ PAGE: ROOM (Hotel only) ═══ -->
    <?php if ($isHotel): ?>
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
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#0ea5e9;"></div>Pending</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#16a34a;"></div>Checked In</div>
                <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#9ca3af;opacity:.4;"></div>Checked Out</div>
            </div>
        </div>
    </div>
    <div id="calPopupOverlay" class="cal-popup-overlay" style="display:none;" onclick="closeCalPopup()"></div>
    <div id="calPopup" class="cal-popup" style="display:none;"></div>

    <!-- ═══ PAGE: BREAKFAST (Hotel only) ═══ -->
    <div class="page" id="page-breakfast">
        <div id="bfStats"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        <div class="card">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div class="card-title" style="margin:0;">☕ Today's Breakfast Orders</div>
                <button onclick="loadBreakfast()" style="background:none;border:none;font-size:14px;cursor:pointer;" title="Refresh">🔄</button>
            </div>
            <div id="bfOrderList" style="margin-top:10px;"><div class="loading"><span class="spin"></span></div></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isCafe): ?>
    <!-- ═══ PAGE: JADWAL (Cafe only) ═══ -->
    <div class="page" id="page-schedule">
        <div class="card">
            <div class="card-title">⏰ Jadwal Kerja Saya</div>
            <div id="scheduleInfo"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
        <div class="card">
            <div class="card-title">📊 Ketepatan Waktu Bulan Ini</div>
            <div id="punctualityStats"><div class="loading"><span class="spin"></span> Memuat...</div></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ═══ SLIP GAJI PAGE ═══ -->
    <div class="page" id="page-slipgaji">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;padding:0 2px;">
            <select id="slipPeriod" class="fi" style="width:auto;padding:6px 10px;font-size:11px;border-radius:8px;" onchange="loadSlipGaji()">
                <option value="">Memuat...</option>
            </select>
            <button id="btnDownloadSlip" onclick="downloadSlipGaji()" style="display:none;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;border:none;padding:7px 14px;border-radius:8px;font-size:11px;font-weight:600;cursor:pointer;display:none;align-items:center;gap:5px;">📥 Download</button>
        </div>
        <div id="slipGajiContent"><div class="loading"><span class="spin"></span> Memuat...</div></div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <div class="nav-item active" data-page="home"><span class="nav-icon">🏠</span><span class="nav-label">Home</span></div>
        <?php if ($isHotel): ?>
        <div class="nav-item" data-page="occupancy"><span class="nav-icon">🏨</span><span class="nav-label">Room Monitor</span></div>
        <div class="nav-item" data-page="breakfast"><span class="nav-icon">☕</span><span class="nav-label">Breakfast</span></div>
        <?php elseif ($isCafe): ?>
        <div class="nav-item" data-page="schedule"><span class="nav-icon">⏰</span><span class="nav-label">Jadwal</span></div>
        <?php endif; ?>
        <div class="nav-item" data-page="slipgaji"><span class="nav-icon">💰</span><span class="nav-label">Slip Gaji</span></div>
    </div>
</div>

<!-- Face Scan Overlay — Professional Biometric -->
<div class="face-overlay" id="faceOverlay">
    <div class="face-particles" id="faceParticles"></div>
    <button class="face-close" onclick="closeFaceScan()">✕</button>
    <div class="face-header">
        <div style="margin-bottom:10px;"><svg width="32" height="32" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 20V14a6 6 0 016-6h6" stroke="rgba(255,255,255,.5)" stroke-width="2.5" stroke-linecap="round"/><path d="M44 8h6a6 6 0 016 6v6" stroke="rgba(255,255,255,.5)" stroke-width="2.5" stroke-linecap="round"/><path d="M56 44v6a6 6 0 01-6 6h-6" stroke="rgba(255,255,255,.5)" stroke-width="2.5" stroke-linecap="round"/><path d="M20 56h-6a6 6 0 01-6-6v-6" stroke="rgba(255,255,255,.5)" stroke-width="2.5" stroke-linecap="round"/><circle cx="32" cy="26" r="10" stroke="white" stroke-width="2"/><path d="M20 48c0-6.627 5.373-12 12-12s12 5.373 12 12" stroke="white" stroke-width="2" stroke-linecap="round"/></svg></div>
        <h3 id="faceTitle">Face Recognition</h3>
        <p id="faceSubtitle">Verifikasi identitas karyawan</p>
    </div>
    <div class="face-ring-wrap">
        <div class="face-ring-pulse"></div>
        <div class="face-ring-outer"></div>
        <div class="face-ring-scan" id="faceRingScan"></div>
        <div class="face-container" id="faceRing">
            <video id="faceVideo" autoplay playsinline muted></video>
            <canvas id="faceCanvas"></canvas>
            <div class="face-scan-line" id="faceScanLine"></div>
            <div class="face-corners"></div>
            <div class="face-corners-b"></div>
        </div>
    </div>
    <div class="face-status" id="faceStatus">Memulai...</div>
    <div class="face-status-sub" id="faceStatusSub"></div>
    <div class="face-meter" id="faceMeter" style="display:none;">
        <div class="face-meter-fill" id="faceMeterFill"></div>
    </div>
    <div class="face-meter-label" id="faceMeterLabel"></div>
    <button class="face-btn-register" id="btnFaceRegister" onclick="registerFace()">Daftarkan Wajah</button>
    <button class="face-btn-reregister" id="btnFaceReregister" onclick="reregisterFace()">🔄 Daftar Ulang Wajah</button>
    <div class="face-gps-info" id="faceGpsInfo"></div>
</div>

<!-- Install Progress Overlay -->
<div class="install-progress" id="installProgress">
    <img class="ip-icon" id="ipIcon" src="<?php echo htmlspecialchars($pwaIconUrl); ?>" alt="App">
    <div class="ip-title"><?php echo $bizName; ?></div>
    <div class="ip-sub" id="ipSub">Installing Staff Portal...</div>
    <div class="ip-bar"><div class="ip-bar-fill" id="ipBarFill"></div></div>
    <div class="ip-step" id="ipStep">Preparing...</div>
    <div class="ip-done" id="ipDone">
        <div class="ip-check">✓</div>
        <div class="ip-done-text">Terinstall!</div>
        <div class="ip-done-sub">Cek home screen atau daftar aplikasi (app drawer)</div>
        <div class="ip-done-sub" style="margin-top:6px;font-size:10px;opacity:.5;">Jika tidak muncul di home screen, swipe up → cari "Staff Portal"</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/dist/face-api.min.js"></script>
<script>
const API = '<?php echo $apiUrl; ?>';
const CRED_KEY = 'staff_saved_cred_<?php echo md5($bizSlug); ?>';
const FACE_MODEL_URL = '<?php echo $baseUrl; ?>/assets/face-weights';
const FACE_MODEL_CDN = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
const BIZ_TYPE = '<?php echo $bizType; ?>';
const IS_HOTEL = <?php echo $isHotel ? 'true' : 'false'; ?>;
const IS_CAFE = <?php echo $isCafe ? 'true' : 'false'; ?>;
const LOGO_URL = '<?php echo $appLogo ?: ''; ?>';
const SLIP_LOGO_URL = '<?php echo $slipLogo ?: ($appLogo ?: ''); ?>';
const BIZ_NAME = '<?php echo $bizName; ?>';

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
    if (IS_CAFE) loadSchedule();
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
        if (page === 'slipgaji') loadSlipGaji();
        if (page === 'occupancy' && IS_HOTEL) loadOccupancy();
        if (page === 'breakfast' && IS_HOTEL) loadBreakfast();
        if (page === 'schedule' && IS_CAFE) loadSchedule();
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
            const wh = parseFloat(a.work_hours) || 0;
            const statusMap = { present: '✅ Hadir', late: '⏰ Terlambat', absent: '❌ Absen', leave: '📝 Izin' };

            let scanGrid;
            if (IS_CAFE) {
                // Cafe: 2 scan (Masuk / Pulang) with schedule info
                const scheduleInfo = a.schedule_start && a.schedule_end
                    ? `<div style="text-align:center;font-size:10px;color:var(--muted);margin-bottom:8px;">Jadwal: ${a.schedule_start?.substring(0,5) || '—'} — ${a.schedule_end?.substring(0,5) || '—'}</div>` : '';
                const lateInfo = a.late_minutes && a.late_minutes > 0
                    ? `<div style="text-align:center;font-size:10px;color:var(--red);margin-top:4px;">Terlambat ${a.late_minutes} menit</div>` : '';
                const earlyInfo = a.early_leave_minutes && a.early_leave_minutes > 0
                    ? `<div style="text-align:center;font-size:10px;color:var(--orange);margin-top:4px;">Pulang awal ${a.early_leave_minutes} menit</div>` : '';
                scanGrid = `
                    ${scheduleInfo}
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:center;">
                        <div style="background:var(--bg);border-radius:10px;padding:14px;">
                            <div style="font-size:20px;margin-bottom:4px;">🟢</div>
                            <div style="font-size:10px;color:var(--muted);font-weight:600;">MASUK</div>
                            <div style="font-size:22px;font-weight:800;color:var(--green);margin-top:2px;">${s1}</div>
                        </div>
                        <div style="background:var(--bg);border-radius:10px;padding:14px;">
                            <div style="font-size:20px;margin-bottom:4px;">🔴</div>
                            <div style="font-size:10px;color:var(--muted);font-weight:600;">PULANG</div>
                            <div style="font-size:22px;font-weight:800;color:var(--navy);margin-top:2px;">${s2}</div>
                        </div>
                    </div>
                    ${lateInfo}${earlyInfo}`;
            } else {
                // Hotel: 4 scan split-shift
                const s3 = a.scan_3 ? a.scan_3.substring(0,5) : '—';
                const s4 = a.scan_4 ? a.scan_4.substring(0,5) : '—';
                scanGrid = `
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
            }

            document.getElementById('todayStatus').innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <span class="badge ${a.status==='present'?'b-hadir':a.status==='late'?'b-late':'b-absent'}">${statusMap[a.status]||a.status}</span>
                    <span style="font-size:11px;color:var(--muted);">${wh > 0 ? wh.toFixed(1) + ' jam' : ''}</span>
                </div>
                ${scanGrid}`;
        } else {
            document.getElementById('todayStatus').innerHTML = '<div style="text-align:center;padding:16px;color:var(--muted);font-size:12px;">⏳ Belum absen hari ini. Tap "Scan Wajah" di atas untuk absen.</div>';
        }
    } catch(e) { document.getElementById('todayStatus').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat data</div>'; }

    // Monthly donut chart
    try {
        const m = new Date().toISOString().substring(0,7);
        const res = await fetch(API + '&action=attendance_history&month=' + m);
        const data = await res.json();
        const s = data.summary || {};
        const totalHours = s.total_hours || 0;
        const target = s.target || 200;
        const pct = target > 0 ? Math.min(Math.round(totalHours / target * 100), 100) : 0;
        const remaining = Math.max(0, target - totalHours);
        const daysPresent = s.days_present || 0;
        const daysLate = s.days_late || 0;

        // Donut chart using SVG conic gradient simulation
        const radius = 70, cx = 85, cy = 85, stroke = 14;
        const circumference = 2 * Math.PI * radius;
        const dashOffset = circumference - (pct / 100) * circumference;
        const gradColor1 = pct >= 90 ? '#10b981' : pct >= 60 ? '#f59e0b' : '#ef4444';
        const gradColor2 = pct >= 90 ? '#059669' : pct >= 60 ? '#d97706' : '#dc2626';
        const gradId = 'donutGrad';

        document.getElementById('monthlySummary').innerHTML = `
            <div style="display:flex;align-items:center;gap:20px;justify-content:center;">
                <div style="position:relative;width:170px;height:170px;flex-shrink:0;">
                    <svg width="170" height="170" viewBox="0 0 170 170" style="transform:rotate(-90deg);">
                        <defs>
                            <linearGradient id="${gradId}" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="${gradColor1}"/>
                                <stop offset="100%" stop-color="${gradColor2}"/>
                            </linearGradient>
                            <filter id="donutShadow"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-color="${gradColor1}" flood-opacity="0.3"/></filter>
                        </defs>
                        <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="#e2e8f0" stroke-width="${stroke}" />
                        <circle cx="${cx}" cy="${cy}" r="${radius}" fill="none" stroke="url(#${gradId})" stroke-width="${stroke}" 
                            stroke-linecap="round" stroke-dasharray="${circumference}" stroke-dashoffset="${circumference}" filter="url(#donutShadow)">
                            <animate attributeName="stroke-dashoffset" from="${circumference}" to="${dashOffset}" dur="1.2s" fill="freeze" calcMode="spline" keySplines="0.4 0 0.2 1" keyTimes="0;1"/>
                        </circle>
                    </svg>
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                        <div style="font-size:28px;font-weight:800;color:${gradColor1};line-height:1;" id="donutPctNum">0</div>
                        <div style="font-size:10px;font-weight:700;color:${gradColor1};margin-top:1px;">%</div>
                        <div style="font-size:9px;color:var(--muted);margin-top:3px;">dari ${target}j</div>
                    </div>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="display:grid;gap:8px;">
                        <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                            <div style="width:32px;height:32px;background:#10b981;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;">📅</div>
                            <div><div style="font-size:9px;color:#059669;font-weight:600;text-transform:uppercase;">Hadir</div><div style="font-size:18px;font-weight:800;color:#065f46;">${daysPresent} <span style="font-size:10px;font-weight:400;">hari</span></div></div>
                        </div>
                        <div style="background:linear-gradient(135deg,#fefce8,#fef9c3);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                            <div style="width:32px;height:32px;background:#eab308;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;">🎯</div>
                            <div><div style="font-size:9px;color:#a16207;font-weight:600;text-transform:uppercase;">Sisa Target</div><div style="font-size:18px;font-weight:800;color:#854d0e;">${remaining.toFixed(1)} <span style="font-size:10px;font-weight:400;">jam</span></div></div>
                        </div>
                    </div>
                </div>
            </div>`;
        // Animate percentage number
        let cur = 0; const tgt = pct;
        const animPct = () => { if (cur < tgt) { cur += Math.max(1, Math.round((tgt - cur) / 10)); if (cur > tgt) cur = tgt; document.getElementById('donutPctNum').textContent = cur; requestAnimationFrame(animPct); } };
        requestAnimationFrame(animPct);
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

        let html;
        if (IS_CAFE) {
            html = '<div style="overflow-x:auto;"><table class="tbl"><thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Jam Kerja</th><th>Status</th></tr></thead><tbody>';
        } else {
            html = '<div style="overflow-x:auto;"><table class="tbl"><thead><tr><th>Tanggal</th><th>Scan 1</th><th>Scan 2</th><th>Scan 3</th><th>Scan 4</th><th>Total</th><th>Status</th></tr></thead><tbody>';
        }
        const statusMap = { present:'Hadir', late:'Terlambat', absent:'Absen', leave:'Izin', holiday:'Libur', half_day:'½ Hari' };
        rows.forEach(r => {
            const dt = new Date(r.attendance_date);
            const day = dt.toLocaleDateString('id-ID',{weekday:'short',day:'numeric',month:'short'});
            const s1 = r.check_in_time ? r.check_in_time.substring(0,5) : '—';
            const s2 = r.check_out_time ? r.check_out_time.substring(0,5) : '—';
            const wh = parseFloat(r.work_hours)||0;
            const bc = r.status==='present'?'b-hadir':r.status==='late'?'b-late':'b-absent';

            if (IS_CAFE) {
                html += `<tr><td style="white-space:nowrap;">${day}</td><td style="font-weight:600;color:var(--green);">${s1}</td><td style="font-weight:600;color:var(--navy);">${s2}</td><td style="font-weight:700;">${wh>0?wh.toFixed(1)+'j':'—'}</td><td><span class="badge ${bc}">${statusMap[r.status]||r.status}</span></td></tr>`;
            } else {
                const s3 = r.scan_3 ? r.scan_3.substring(0,5) : '—';
                const s4 = r.scan_4 ? r.scan_4.substring(0,5) : '—';
                html += `<tr><td style="white-space:nowrap;">${day}</td><td style="font-weight:600;color:var(--green);">${s1}</td><td>${s2}</td><td style="color:var(--green);">${s3}</td><td>${s4}</td><td style="font-weight:700;">${wh>0?wh.toFixed(1)+'j':'—'}</td><td><span class="badge ${bc}">${statusMap[r.status]||r.status}</span></td></tr>`;
            }
        });
        html += '</tbody></table></div>';
        document.getElementById('monitorTable').innerHTML = html;
    } catch(e) { document.getElementById('monitorTable').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>'; }
}

// ═══ SCHEDULE PAGE (Cafe) ═══
async function loadSchedule() {
    if (!IS_CAFE) return;
    try {
        const res = await fetch(API + '&action=work_schedule');
        const data = await res.json();
        if (!data.success) {
            document.getElementById('scheduleInfo').innerHTML = '<div style="color:var(--muted);text-align:center;padding:16px;font-size:12px;">Jadwal belum dikonfigurasi admin.</div>';
            return;
        }
        const s = data.data;
        const dayNames = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
        const todayIdx = new Date().getDay();

        let html = '<div style="margin-bottom:12px;">';
        // Today highlight
        html += `<div style="background:linear-gradient(135deg,var(--navy),var(--navy2));color:#fff;border-radius:12px;padding:16px;margin-bottom:12px;text-align:center;">
            <div style="font-size:11px;opacity:.7;">Jadwal Hari Ini — ${dayNames[todayIdx]}</div>
            <div style="font-size:28px;font-weight:800;margin:6px 0;">${s.start_time?.substring(0,5) || '—'} — ${s.end_time?.substring(0,5) || '—'}</div>
            <div style="font-size:12px;opacity:.8;">${s.total_hours || 8} jam kerja${s.break_minutes ? ' · istirahat ' + s.break_minutes + ' menit' : ''}</div>
        </div>`;

        // Weekly schedule
        if (s.weekly && s.weekly.length > 0) {
            html += '<div style="font-size:12px;font-weight:700;color:var(--navy);margin-bottom:8px;">📅 Jadwal Mingguan</div>';
            s.weekly.forEach(d => {
                const isToday = d.day_index == todayIdx;
                const isOff = d.is_off;
                html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:8px;margin-bottom:4px;${isToday?'background:var(--bg);border:1.5px solid var(--gold);':'border:1px solid var(--border);'}">
                    <div style="font-weight:${isToday?'700':'500'};font-size:13px;">${isToday?'▶ ':''}${dayNames[d.day_index]}</div>
                    <div style="font-size:12px;color:${isOff?'var(--red)':'var(--green)'};font-weight:600;">${isOff ? 'LIBUR' : (d.start_time?.substring(0,5)+' — '+d.end_time?.substring(0,5))}</div>
                </div>`;
            });
        }
        html += '</div>';
        document.getElementById('scheduleInfo').innerHTML = html;

        // Punctuality stats
        const m = new Date().toISOString().substring(0,7);
        const hRes = await fetch(API + '&action=attendance_history&month=' + m);
        const hData = await hRes.json();
        const rows = hData.data || [];
        let onTime = 0, lateCount = 0, totalLateMin = 0;
        rows.forEach(r => {
            if (r.status === 'present') onTime++;
            if (r.status === 'late') { lateCount++; totalLateMin += (parseInt(r.late_minutes) || 0); }
        });
        const totalDays = onTime + lateCount;
        const pctOnTime = totalDays > 0 ? Math.round(onTime / totalDays * 100) : 100;
        const barColor = pctOnTime >= 90 ? 'var(--green)' : pctOnTime >= 70 ? 'var(--orange)' : 'var(--red)';

        document.getElementById('punctualityStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">Tepat Waktu</div><div class="sv" style="color:var(--green);">${onTime}</div><div class="ss">hari</div></div>
                <div class="stat-card"><div class="sl">Terlambat</div><div class="sv" style="color:var(--orange);">${lateCount}</div><div class="ss">${totalLateMin > 0 ? 'total '+totalLateMin+' menit' : 'hari'}</div></div>
            </div>
            <div style="margin-top:4px;">
                <div style="display:flex;justify-content:space-between;font-size:11px;margin-bottom:4px;">
                    <span style="color:var(--muted);">Ketepatan Waktu</span>
                    <span style="font-weight:700;color:${barColor};">${pctOnTime}%</span>
                </div>
                <div class="progress"><div class="progress-bar" style="width:${pctOnTime}%;background:${barColor};"></div></div>
            </div>`;
    } catch(e) {
        document.getElementById('scheduleInfo').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat jadwal</div>';
    }
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
    const statusMap = {'pending':'⏳ Pending','confirmed':'✅ Confirmed','checked_in':'🏨 Checked In','checked_out':'� Checked Out'};
    const sourceMap = {'walk_in':'🚶 Walk In','agoda':'🟠 Agoda','booking':'🔵 Booking.com','traveloka':'🔷 Traveloka','airbnb':'🏠 Airbnb','tiket':'🎫 Tiket.com','phone':'📞 Phone','whatsapp':'💬 WhatsApp'};
    const payMap = {'unpaid':'❌ Belum Bayar','partial':'⚠️ Sebagian','paid':'✅ Lunas'};
    const statusColor = {'pending':'#0ea5e9','confirmed':'#06b6d4','checked_in':'#16a34a','checked_out':'#9ca3af'};
    const cin = b.check_in_date ? new Date(b.check_in_date+'T00:00:00') : null;
    const cout = b.check_out_date ? new Date(b.check_out_date+'T00:00:00') : null;
    const nights = cin && cout ? Math.round((cout - cin) / 86400000) : '-';
    const fmtDate = d => d ? d.getDate() + ' ' + ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][d.getMonth()] + ' ' + d.getFullYear() : '-';
    document.getElementById('calPopup').innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="font-weight:800;font-size:14px;color:var(--navy);">📋 Detail Booking</div>
            <button onclick="closeCalPopup()" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--muted);">✕</button>
        </div>
        <div style="background:linear-gradient(135deg,${statusColor[b.status]||'#64748b'}20,${statusColor[b.status]||'#64748b'}10);border-left:3px solid ${statusColor[b.status]||'#64748b'};border-radius:0 8px 8px 0;padding:8px 10px;margin-bottom:10px;">
            <div style="font-size:15px;font-weight:800;color:var(--navy);">${b.guest_name||'-'}</div>
            <div style="font-size:10px;color:var(--muted);margin-top:2px;">Kode: <strong>${b.booking_code||'-'}</strong></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px;">
            <div style="background:#f0fdf4;border-radius:8px;padding:8px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;">Check-in</div>
                <div style="font-size:11px;font-weight:800;color:#166534;">${fmtDate(cin)}</div>
            </div>
            <div style="background:#fef2f2;border-radius:8px;padding:8px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:#dc2626;text-transform:uppercase;">Check-out</div>
                <div style="font-size:11px;font-weight:800;color:#991b1b;">${fmtDate(cout)}</div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:10px;">
            <div style="background:#f8fafc;border-radius:8px;padding:6px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;">Malam</div>
                <div style="font-size:16px;font-weight:900;color:var(--navy);">${nights}</div>
            </div>
            <div style="background:#f8fafc;border-radius:8px;padding:6px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;">Status</div>
                <div style="font-size:10px;font-weight:700;color:${statusColor[b.status]||'#64748b'};">${statusMap[b.status]||b.status}</div>
            </div>
            <div style="background:#f8fafc;border-radius:8px;padding:6px;text-align:center;">
                <div style="font-size:8px;font-weight:700;color:var(--muted);text-transform:uppercase;">Bayar</div>
                <div style="font-size:10px;font-weight:700;">${payMap[b.payment_status]||b.payment_status||'-'}</div>
            </div>
        </div>
        <div style="font-size:11px;color:var(--muted);text-align:center;">Sumber: ${sourceMap[b.booking_source]||b.booking_source||'-'}</div>`;
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
        const arrivals = parseInt(d.arrivals_tomorrow)||0;
        const departures = parseInt(d.departures_tomorrow)||0;

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
                        <div><div style="font-size:8px;font-weight:700;color:#16a34a;text-transform:uppercase;">Cekin Besok</div><div style="font-size:20px;font-weight:900;color:#16a34a;">${arrivals}</div></div>
                    </div>
                    <div style="background:linear-gradient(135deg,#fff7ed,#fed7aa);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px;">
                        <div style="width:36px;height:36px;background:#ea580c;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;">🚪</div>
                        <div><div style="font-size:8px;font-weight:700;color:#ea580c;text-transform:uppercase;">Cekout Besok</div><div style="font-size:20px;font-weight:900;color:#ea580c;">${departures}</div></div>
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
                const hasB2B = isOcc && r.next_guest;
                const boxClass = hasB2B ? 'b2b' : (isOcc ? 'occ' : 'avail');
                rh += `<div class="room-box ${boxClass}">
                    ${r.room_number}
                    <div class="room-type">${r.room_type||''}</div>
                    ${isOcc ? `<div class="room-guest">${r.guest_name||''}</div>` : ''}
                    ${hasB2B ? `<div class="room-next">→ ${r.next_guest}</div>` : ''}
                </div>`;
            });
            rh += '</div>';
            document.getElementById('roomGrid').innerHTML = rh;
        }

        // ── Calendar (Frontdesk Style) ──
        const COL_W = 130; // pixels per day column (match frontdesk)
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
        
        const startM = new Date(dates[0] + 'T00:00:00');
        const endM = new Date(dates[dates.length-1] + 'T00:00:00');
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

        // Build booking map
        const bookingMap = {};
        bookings.forEach(b => {
            if (!bookingMap[b.room_id]) bookingMap[b.room_id] = [];
            const bStart = b.check_in_date, bEnd = b.check_out_date;
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
        let g = `<div class="cal-grid" style="grid-template-columns:95px repeat(${days},${COL_W}px);">`;

        // Header row
        g += `<div class="cal-grid-header">`;
        g += `<div class="cg-hdr-room">ROOMS</div>`;
        dates.forEach(dt => {
            const dd = new Date(dt + 'T00:00:00');
            const isTd = dt === today;
            g += `<div class="cg-hdr-date${isTd?' today':''}"><span class="cg-hdr-day">${dayNames[dd.getDay()]}</span> <span class="cg-hdr-num">${dd.getDate()}</span></div>`;
        });
        g += `</div>`;

        // Room rows
        const typeNames = Object.keys(roomsByType);
        typeNames.forEach(typeName => {
            // Type header
            g += `<div class="cg-type-hdr">📂 ${typeName}</div>`;
            for (let i = 0; i < days; i++) g += `<div class="cg-type-price"></div>`;

            roomsByType[typeName].forEach(room => {
                // Room label
                const tShort = (room.room_type || '').toUpperCase().substring(0, 6);
                g += `<div class="cg-room"><span class="cg-room-type">${tShort}</span><span class="cg-room-num">${room.room_number}</span></div>`;
                
                const roomBookings = bookingMap[room.id] || [];
                // Date cells
                for (let i = 0; i < days; i++) {
                    const isTd = dates[i] === today;
                    g += `<div class="cg-cell${isTd?' today':''}">`;
                    // Render bars starting on this cell
                    roomBookings.forEach(rb => {
                        if (rb.startCol === i) {
                            const barW = (rb.span * COL_W) - 10;
                            let cls = 's-' + (rb.status||'').replace('_','-');
                            const isCheckedIn = rb.status === 'checked_in';
                            const isCheckedOut = rb.status === 'checked_out';
                            const isPast = rb.check_out_date < today;
                            const icon = isCheckedIn ? '✓ ' : (isCheckedOut || isPast ? '📭 ' : '');
                            const name = (rb.guest_name||'Guest').substring(0, 12);
                            const code = (rb.booking_code||'').substring(0, 8);
                            const bData = JSON.stringify({booking_code:rb.booking_code,guest_name:rb.guest_name,check_in_date:rb.check_in_date,check_out_date:rb.check_out_date,status:rb.status,booking_source:rb.booking_source,payment_status:rb.payment_status}).replace(/'/g,'&#39;');
                            g += `<div class="bbar-wrap" style="width:${barW}px;" onclick='showBookingPopup(${bData})'>`;
                            g += `<div class="bbar ${cls}"><span>${icon}${name} • ${code}</span></div></div>`;
                        }
                    });
                    g += `</div>`;
                }
            });
        });

        // Footer row
        g += `<div class="cal-grid-footer">`;
        g += `<div class="cg-ftr-room">ROOMS</div>`;
        dates.forEach(dt => {
            const dd = new Date(dt + 'T00:00:00');
            const isTd = dt === today;
            g += `<div class="cg-ftr-date${isTd?' today':''}"><span class="cg-hdr-day">${dayNames[dd.getDay()]}</span> <span class="cg-hdr-num">${dd.getDate()}</span></div>`;
        });
        g += `</div>`;
        g += '</div>';
        document.getElementById('calGrid').innerHTML = g;

        // Scroll to today
        const todayIdx = dates.indexOf(today);
        if (todayIdx > 1) {
            const scrollEl = document.getElementById('calScroll');
            setTimeout(() => { scrollEl.scrollLeft = Math.max(0, (todayIdx - 1) * COL_W); }, 100);
        }
    } catch(e) { 
        console.error(e);
        document.getElementById('roomGrid').innerHTML = '<div style="color:var(--red);font-size:11px;">Gagal memuat</div>'; 
    }
}

// ═══ BREAKFAST PAGE ═══
async function loadBreakfast() {
    try {
        const res = await fetch(API + '&action=breakfast_orders');
        const data = await res.json();
        const d = data.data || {};
        const orders = d.orders || [];
        const stats = d.stats || {};
        const sc = stats.status || {};

        // Stats bar
        document.getElementById('bfStats').innerHTML = `
            <div class="stat-row">
                <div class="stat-card"><div class="sl">🍽️ ORDERS</div><div class="sv" style="color:var(--navy);">${stats.total_orders||0}</div></div>
                <div class="stat-card"><div class="sl">👥 TOTAL PAX</div><div class="sv" style="color:var(--blue);">${stats.total_pax||0}</div></div>
                <div class="stat-card"><div class="sl">⏳ PENDING</div><div class="sv" style="color:#f59e0b;">${sc.pending||0}</div></div>
                <div class="stat-card"><div class="sl">✅ SERVED</div><div class="sv" style="color:var(--green);">${(sc.served||0)+(sc.completed||0)}</div></div>
            </div>`;

        // Order list
        if (orders.length === 0) {
            document.getElementById('bfOrderList').innerHTML = `
                <div style="text-align:center;padding:30px 10px;">
                    <div style="font-size:40px;margin-bottom:8px;">🍳</div>
                    <div style="font-size:13px;font-weight:600;color:var(--muted);">Belum ada pesanan breakfast hari ini</div>
                </div>`;
            return;
        }

        let html = '';
        orders.forEach((o, idx) => {
            const time = o.breakfast_time ? o.breakfast_time.substring(0,5) : '--:--';
            const pax = o.total_pax || 1;
            const room = o.room_display || '-';
            const loc = {'restaurant':'🍽️ Restaurant','room_service':'🚪 Room Service','take_away':'🎁 Take Away'}[o.location] || o.location || '';
            const statusCls = {'pending':'bf-st-pending','preparing':'bf-st-prep','served':'bf-st-served','completed':'bf-st-done'}[o.order_status] || 'bf-st-pending';
            const statusTxt = {'pending':'Pending','preparing':'Preparing','served':'Served','completed':'Done'}[o.order_status] || o.order_status;

            // Menu tags
            let menuTags = '';
            const items = o.menu_items || [];
            if (items.length > 0) {
                items.forEach(m => {
                    const qty = (m.quantity||1) > 1 ? ' ×' + m.quantity : '';
                    menuTags += `<span class="bf-tag">${m.menu_name||'?'}${qty}</span>`;
                });
            } else {
                menuTags = '<span class="bf-tag">' + (o.menu_name || 'Menu?') + '</span>';
            }

            const price = parseFloat(o.total_price||0);
            const priceStr = price > 0 ? 'Rp ' + price.toLocaleString('id-ID') : 'Free';
            const req = o.special_requests ? `<div style="font-size:9px;color:#a855f7;margin-top:4px;">💬 ${o.special_requests}</div>` : '';

            html += `
            <div class="bf-order">
                <div class="bf-order-hdr">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span class="bf-time">🕐 ${time}</span>
                        <span class="bf-pax">${pax} pax</span>
                    </div>
                    <span class="bf-status ${statusCls}">${statusTxt}</span>
                </div>
                <div class="bf-guest">${o.guest_name||'Guest'}</div>
                <div class="bf-room">🛏️ Room ${room} ${loc ? '&nbsp;&nbsp;' + loc : ''}</div>
                <div class="bf-menus">${menuTags}</div>
                <div class="bf-foot">
                    <span class="bf-price">${priceStr}</span>
                </div>
                ${req}
            </div>`;
        });
        document.getElementById('bfOrderList').innerHTML = html;
    } catch(e) {
        console.error(e);
        document.getElementById('bfOrderList').innerHTML = '<div style="color:var(--red);font-size:11px;padding:10px;">Gagal memuat data breakfast</div>';
    }
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

// ═══ FACE SCAN — 2028 Vibe ═══
let faceModelsLoaded = false;
let faceStream = null;
let faceRAF = null;
let faceStoredDescriptor = null;
let faceVerifyMode = false;
let faceGps = null;
let faceGpsWatcher = null;
let faceConfig = null;
let faceDetected = false;
let faceProcessing = false;
let faceScanActive = false;
let faceMatchCount = 0;
let lastRecognitionTime = 0;
let nativeFaceDetector = null;

// Try hardware-accelerated FaceDetector API (Chrome Android, ~1-5ms vs 50-200ms)
try { if ('FaceDetector' in window) nativeFaceDetector = new FaceDetector({ fastMode: true, maxDetectedFaces: 1 }); } catch(e) {}

// Generate floating particles
function initFaceParticles() {
    const c = document.getElementById('faceParticles');
    if (c.children.length > 0) return;
    for (let i = 0; i < 20; i++) {
        const p = document.createElement('div');
        p.className = 'face-particle';
        p.style.left = Math.random() * 100 + '%';
        p.style.animationDuration = (4 + Math.random() * 6) + 's';
        p.style.animationDelay = Math.random() * 5 + 's';
        p.style.width = p.style.height = (1 + Math.random() * 2) + 'px';
        c.appendChild(p);
    }
}

async function loadFaceModels() {
    if (faceModelsLoaded) return true;
    setFaceStatus('Memuat AI model...', 'Neural network initialization');
    let url = FACE_MODEL_URL;
    try {
        const t = await fetch(url + '/tiny_face_detector_model-weights_manifest.json', { method: 'HEAD' });
        if (!t.ok) throw new Error();
    } catch(e) { url = FACE_MODEL_CDN; }
    try {
        setFaceStatus('Loading detector...', '1/3 modules');
        await faceapi.nets.tinyFaceDetector.loadFromUri(url);
        setFaceStatus('Loading landmarks...', '2/3 modules');
        await faceapi.nets.faceLandmark68TinyNet.loadFromUri(url);
        setFaceStatus('Loading recognizer...', '3/3 modules');
        await faceapi.nets.faceRecognitionNet.loadFromUri(url);
        faceModelsLoaded = true;
        // Warm up model (pre-compiles WebGL shaders for faster first detection)
        try {
            const wu = document.createElement('canvas'); wu.width = wu.height = 128;
            await faceapi.detectSingleFace(wu, new faceapi.TinyFaceDetectorOptions({ inputSize: 128 }));
        } catch(e) {}
        setFaceStatus('Model siap', nativeFaceDetector ? 'Hardware-accelerated mode' : 'AI detection mode');
        return true;
    } catch(e) {
        setFaceStatus('Gagal memuat model', e.message);
        return false;
    }
}

function setFaceStatus(main, sub) {
    document.getElementById('faceStatus').textContent = main;
    document.getElementById('faceStatusSub').textContent = sub || '';
}

async function openFaceScan() {
    const overlay = document.getElementById('faceOverlay');
    overlay.classList.add('show');
    faceScanActive = true;
    faceMatchCount = 0;
    initFaceParticles();

    // 1. Load face data
    setFaceStatus('Menghubungkan...', 'Mengambil data karyawan');
    try {
        const res = await fetch(API + '&action=face_data');
        const data = await res.json();
        if (!data.success) {
            if (data.auth === false) { doLogout(); return; }
            setFaceStatus('Error', data.message);
            return;
        }
        faceConfig = data.config;
        const emp = data.employee;
        if (emp.has_face && emp.face_descriptor) {
            faceStoredDescriptor = new Float32Array(emp.face_descriptor);
            faceVerifyMode = true;
        } else {
            faceStoredDescriptor = null;
            faceVerifyMode = false;
        }
        const att = data.today;
        if (att && att.check_in_time && att.check_out_time && att.scan_3 && att.scan_4) {
            setFaceStatus('Scan lengkap hari ini', '4/4 scan tercatat');
            document.getElementById('faceRing').classList.add('matched');
            document.getElementById('faceRingScan').classList.add('matched');
            return;
        }
    } catch(e) {
        setFaceStatus('Jaringan error', e.message);
        return;
    }

    // 2. Load models
    const ok = await loadFaceModels();
    if (!ok) return;

    // 3. GPS
    startFaceGps();

    // 4. Camera — lower res for faster processing, high framerate
    try {
        faceStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'user', width: { ideal: 480 }, height: { ideal: 480 }, frameRate: { ideal: 30 } }
        });
        const video = document.getElementById('faceVideo');
        video.srcObject = faceStream;
        video.setAttribute('playsinline', '');
        await new Promise(r => { video.onloadedmetadata = r; });
        await video.play().catch(() => {});
        const canvas = document.getElementById('faceCanvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        if (faceVerifyMode) {
            setFaceStatus('Arahkan wajah ke kamera', (nativeFaceDetector ? 'Hardware-accelerated' : 'AI') + ' face scanning');
            document.getElementById('faceTitle').textContent = 'Verifikasi Wajah';
            document.getElementById('faceMeter').style.display = 'block';
            document.getElementById('btnFaceRegister').style.display = 'none';
            document.getElementById('btnFaceReregister').style.display = 'block';
        } else {
            setFaceStatus('Wajah belum terdaftar', 'Posisikan wajah lalu tap tombol daftar');
            document.getElementById('faceTitle').textContent = 'Registrasi Wajah';
            document.getElementById('btnFaceRegister').style.display = 'block';
            document.getElementById('btnFaceReregister').style.display = 'none';
            document.getElementById('faceMeter').style.display = 'none';
        }
        // Use requestAnimationFrame loop with throttle for faster response
        faceProcessing = false;
        faceDetectRAF();
    } catch(e) {
        setFaceStatus('Kamera gagal', e.message);
    }
}

function faceDetectRAF() {
    if (!faceScanActive) return;
    faceRAF = requestAnimationFrame(async () => {
        if (!faceProcessing) {
            faceProcessing = true;
            await faceDetectLoop();
            faceProcessing = false;
        }
        faceDetectRAF();
    });
}

function closeFaceScan() {
    faceScanActive = false;
    if (faceRAF) { cancelAnimationFrame(faceRAF); faceRAF = null; }
    if (faceStream) { faceStream.getTracks().forEach(t => t.stop()); faceStream = null; }
    if (faceGpsWatcher) { navigator.geolocation.clearWatch(faceGpsWatcher); faceGpsWatcher = null; }
    const overlay = document.getElementById('faceOverlay');
    overlay.classList.remove('show');
    document.getElementById('faceMeter').style.display = 'none';
    document.getElementById('faceMeterFill').style.width = '0%';
    document.getElementById('faceMeterLabel').textContent = '';
    document.getElementById('btnFaceRegister').style.display = 'none';
    document.getElementById('btnFaceReregister').style.display = 'none';
    document.getElementById('faceGpsInfo').textContent = '';
    document.getElementById('faceRing').classList.remove('matched');
    document.getElementById('faceRingScan').classList.remove('matched');
    document.getElementById('faceStatusSub').textContent = '';
    document.getElementById('faceTitle').textContent = 'Face Recognition';
    document.getElementById('faceSubtitle').textContent = 'Verifikasi identitas karyawan';
    faceMatchCount = 0;
}

function startFaceGps() {
    if (!navigator.geolocation) return;
    faceGpsWatcher = navigator.geolocation.watchPosition(
        pos => {
            faceGps = pos;
            const acc = Math.round(pos.coords.accuracy);
            let info = '📍 ±' + acc + 'm';
            const locs = faceConfig?.locations || [];
            if (locs.length > 0) {
                let nearest = null, nDist = Infinity;
                locs.forEach(l => {
                    const d = haversineDist(pos.coords.latitude, pos.coords.longitude, l.lat, l.lng);
                    if (d < nDist) { nDist = d; nearest = l; }
                });
                info += ' · ' + nDist + 'm dari ' + nearest.name;
                if (nDist <= nearest.radius) info += ' ✅';
                else info += ' (maks ' + nearest.radius + 'm)';
            }
            document.getElementById('faceGpsInfo').textContent = info;
        },
        () => { document.getElementById('faceGpsInfo').textContent = '📍 GPS tidak tersedia'; },
        { enableHighAccuracy: true, maximumAge: 5000 }
    );
}

function haversineDist(lat1, lng1, lat2, lng2) {
    const R = 6371000, dLat = (lat2-lat1)*Math.PI/180, dLng = (lng2-lng1)*Math.PI/180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLng/2)**2;
    return Math.round(2*R*Math.asin(Math.sqrt(a)));
}

async function faceDetectLoop() {
    const video = document.getElementById('faceVideo');
    if (!video || video.readyState < 2) return;

    // ═══ Phase 1: Ultra-fast face PRESENCE detection ═══
    let facePresent = false;

    if (nativeFaceDetector) {
        // Hardware-accelerated browser FaceDetector API (~1-5ms)
        try {
            const faces = await nativeFaceDetector.detect(video);
            facePresent = faces.length > 0;
        } catch(e) {
            nativeFaceDetector = null; // Fallback if API fails
        }
    }

    if (!nativeFaceDetector) {
        // Fallback: face-api.js minimal detect (inputSize 128, no landmarks/descriptor)
        const quickOpts = new faceapi.TinyFaceDetectorOptions({ inputSize: 128, scoreThreshold: 0.25 });
        const quickDet = await faceapi.detectSingleFace(video, quickOpts);
        facePresent = !!quickDet;
    }

    faceDetected = facePresent;

    if (!facePresent) {
        setFaceStatus('Posisikan wajah', 'Pastikan pencahayaan cukup');
        document.getElementById('faceRing').classList.remove('matched');
        document.getElementById('faceRingScan').classList.remove('matched');
        if (faceVerifyMode) {
            document.getElementById('faceMeterFill').style.width = '0%';
            document.getElementById('faceMeterLabel').textContent = '';
        }
        faceMatchCount = 0;
        return;
    }

    // Face found! Immediate UI feedback
    if (!faceVerifyMode) {
        setFaceStatus('Wajah terdeteksi ✓', 'Tap tombol di bawah untuk mendaftar');
        return;
    }

    // ═══ Phase 2: Face RECOGNITION (verify mode, throttled every 150ms) ═══
    const now = Date.now();
    if (now - lastRecognitionTime < 150) {
        setFaceStatus('Menganalisis wajah...', 'AI recognition aktif');
        return;
    }
    lastRecognitionTime = now;

    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 160, scoreThreshold: 0.3 });
    const detection = await faceapi.detectSingleFace(video, options)
        .withFaceLandmarks(true)
        .withFaceDescriptor();

    if (!detection) {
        setFaceStatus('Menganalisis...', 'Sesuaikan posisi wajah');
        return;
    }

    const dist = faceapi.euclideanDistance(faceStoredDescriptor, detection.descriptor);
    const score = Math.max(0, Math.min(100, Math.round((1 - dist / 0.6) * 100)));
    const fill = document.getElementById('faceMeterFill');
    fill.style.width = score + '%';
    fill.style.background = score > 75 ? '#34d399' : score > 40 ? '#fbbf24' : '#f87171';
    document.getElementById('faceMeterLabel').textContent = score + '% match';

    if (dist < 0.5) {
        faceMatchCount++;
        if (faceMatchCount >= 1) {
            setFaceStatus('✓ Wajah terverifikasi', 'Identitas dikonfirmasi');
            document.getElementById('faceRing').classList.add('matched');
            document.getElementById('faceRingScan').classList.add('matched');
            faceScanActive = false;
            setTimeout(doFaceClock, 300);
        }
    } else if (dist < 0.6) {
        setFaceStatus('Mencocokkan...', 'Sesuaikan posisi sedikit');
        faceMatchCount = Math.max(0, faceMatchCount - 1);
    } else {
        setFaceStatus('Wajah tidak cocok', 'Coba daftar ulang wajah');
        faceMatchCount = 0;
    }
}

async function registerFace() {
    if (!faceDetected) { setFaceStatus('Deteksi gagal', 'Pastikan wajah terlihat di kamera'); return; }
    const btn = document.getElementById('btnFaceRegister');
    btn.disabled = true; btn.textContent = 'Mendaftarkan...';
    faceScanActive = false;
    if (faceRAF) { cancelAnimationFrame(faceRAF); faceRAF = null; }

    const video = document.getElementById('faceVideo');
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320 });
    const detection = await faceapi.detectSingleFace(video, options).withFaceLandmarks(true).withFaceDescriptor();
    if (!detection) {
        setFaceStatus('Gagal mendeteksi', 'Coba posisikan ulang wajah');
        btn.disabled = false; btn.textContent = 'Daftarkan Wajah';
        faceScanActive = true; faceDetectRAF();
        return;
    }

    const descriptorArr = Array.from(detection.descriptor);
    const fd = new FormData();
    fd.append('action', 'face_register');
    fd.append('face_descriptor', JSON.stringify(descriptorArr));
    try {
        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            faceStoredDescriptor = new Float32Array(descriptorArr);
            faceVerifyMode = true;
            setFaceStatus('Wajah terdaftar!', 'Memulai verifikasi...');
            document.getElementById('faceTitle').textContent = 'Verifikasi Wajah';
            btn.style.display = 'none';
            document.getElementById('faceMeter').style.display = 'block';
            document.getElementById('faceRing').classList.add('matched');
            setTimeout(() => {
                document.getElementById('faceRing').classList.remove('matched');
                faceScanActive = true; faceDetectRAF();
            }, 1200);
        } else {
            setFaceStatus('Gagal mendaftar', data.message);
            btn.disabled = false; btn.textContent = 'Daftarkan Wajah';
            faceScanActive = true; faceDetectRAF();
        }
    } catch(e) {
        setFaceStatus('Jaringan error', 'Periksa koneksi internet');
        btn.disabled = false; btn.textContent = 'Daftarkan Wajah';
        faceScanActive = true; faceDetectRAF();
    }
}

function reregisterFace() {
    faceVerifyMode = false;
    faceStoredDescriptor = null;
    faceMatchCount = 0;
    lastRecognitionTime = 0;
    document.getElementById('faceTitle').textContent = 'Registrasi Ulang Wajah';
    document.getElementById('faceSubtitle').textContent = 'Update data wajah karyawan';
    document.getElementById('btnFaceRegister').style.display = 'block';
    document.getElementById('btnFaceRegister').disabled = false;
    document.getElementById('btnFaceRegister').textContent = 'Daftarkan Wajah Baru';
    document.getElementById('btnFaceReregister').style.display = 'none';
    document.getElementById('faceMeter').style.display = 'none';
    document.getElementById('faceMeterFill').style.width = '0%';
    document.getElementById('faceMeterLabel').textContent = '';
    document.getElementById('faceRing').classList.remove('matched');
    document.getElementById('faceRingScan').classList.remove('matched');
    setFaceStatus('Posisikan wajah baru', 'Tap tombol daftar setelah wajah terdeteksi');
}

async function doFaceClock() {
    setFaceStatus('Menyimpan absensi...', 'Mengirim ke server');

    let address = '';
    if (faceGps) {
        try {
            const r = await fetch('https://nominatim.openstreetmap.org/reverse?lat=' + faceGps.coords.latitude + '&lon=' + faceGps.coords.longitude + '&format=json');
            const g = await r.json();
            address = g.display_name || '';
        } catch(e) {}
    }

    const fd = new FormData();
    fd.append('action', 'face_clock');
    fd.append('lat', faceGps ? faceGps.coords.latitude : 0);
    fd.append('lng', faceGps ? faceGps.coords.longitude : 0);
    fd.append('address', address);

    try {
        const res = await fetch(API, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            setFaceStatus('Absen berhasil!', data.message);
        } else {
            setFaceStatus('Gagal', data.message);
        }
        setTimeout(() => {
            closeFaceScan();
            loadAbsen();
        }, 2000);
    } catch(e) {
        setFaceStatus('Jaringan error', e.message);
        setTimeout(closeFaceScan, 2000);
    }
}

// Check notifications every 60s
setInterval(checkNotifs, 60000);
setTimeout(checkNotifs, 3000);
</script>

<!-- Install Banner — fixed bottom, works on auth + app -->
<div class="install-banner" id="installBanner">
    <div class="ib-icon">📲</div>
    <div class="ib-text">
        <div class="ib-title">Install Staff Portal</div>
        <div class="ib-sub">Akses lebih cepat dari home screen</div>
    </div>
    <button class="ib-action" id="ibAction">Install</button>
    <button class="ib-close" onclick="event.stopPropagation();this.parentElement.classList.remove('show');localStorage.setItem('ib_dismissed','1');">✕</button>
</div>

<!-- PWA Install Logic — MUST be after banner HTML -->
<script>
(function() {
    // Register SW immediately
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js', { scope: './' })
            .then(reg => console.log('[PWA] SW registered, scope:', reg.scope))
            .catch(err => console.error('[PWA] SW failed:', err));
    }

    let deferredPrompt = null;
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
    const wasDismissed = localStorage.getItem('ib_dismissed') === '1';
    const banner = document.getElementById('installBanner');
    const ibBtn = document.getElementById('ibAction');

    console.log('[PWA] standalone:', isStandalone, 'iOS:', isIOS, 'dismissed:', wasDismissed);

    // Already installed as PWA — hide everything
    if (isStandalone) return;

    function showBanner(mode) {
        if (wasDismissed || !banner) return;
        if (banner.classList.contains('show')) return;
        if (mode === 'manual') {
            banner.querySelector('.ib-title').textContent = 'Install Staff Portal';
            banner.querySelector('.ib-sub').textContent = 'Tap ⋮ menu Chrome → "Install app"';
            ibBtn.textContent = 'Cara Install';
            ibBtn.dataset.mode = 'manual';
        } else {
            banner.querySelector('.ib-title').textContent = 'Install Staff Portal';
            banner.querySelector('.ib-sub').textContent = 'Buka langsung dari home screen';
            ibBtn.textContent = 'Install';
            ibBtn.dataset.mode = 'native';
        }
        banner.classList.add('show');
        console.log('[PWA] Banner shown:', mode);
    }

    // Catch beforeinstallprompt
    window.addEventListener('beforeinstallprompt', (e) => {
        console.log('[PWA] beforeinstallprompt fired!');
        e.preventDefault();
        deferredPrompt = e;
        showBanner('native');
    });

    // Fallback timers for Android Chrome if prompt doesn't fire
    if (!isIOS && !wasDismissed) {
        [4000, 10000, 20000].forEach(ms => {
            setTimeout(() => {
                if (!deferredPrompt && !isStandalone) showBanner('manual');
            }, ms);
        });
    }

    // iOS guide
    if (isIOS && !localStorage.getItem('ios_guide_dismissed')) {
        const guide = document.getElementById('iosGuide');
        if (guide) guide.style.display = 'block';
    }

    // Install button click
    ibBtn.addEventListener('click', async (e) => {
        e.stopPropagation();

        // Manual mode — show guide
        if (!deferredPrompt || ibBtn.dataset.mode === 'manual') {
            showManualGuide();
            return;
        }

        // Native mode — show progress + trigger Chrome prompt
        const prog = document.getElementById('installProgress');
        const bar = document.getElementById('ipBarFill');
        const step = document.getElementById('ipStep');

        // Reset progress UI
        document.getElementById('ipSub').style.display = '';
        document.querySelector('.ip-bar').style.display = '';
        step.style.display = '';
        document.getElementById('ipDone').style.display = 'none';
        bar.style.width = '0%';

        prog.classList.add('show');

        bar.style.width = '20%'; step.textContent = 'Menyiapkan manifest...';
        await sleep(400);
        bar.style.width = '40%'; step.textContent = 'Mengunduh icon...';
        await sleep(400);
        bar.style.width = '60%'; step.textContent = 'Mempersiapkan app...';

        try {
            deferredPrompt.prompt();
            const result = await deferredPrompt.userChoice;

            if (result.outcome === 'accepted') {
                bar.style.width = '80%'; step.textContent = 'Installing...';
                await sleep(500);
                bar.style.width = '100%'; step.textContent = '';
                await sleep(400);

                // Show success
                document.getElementById('ipSub').style.display = 'none';
                document.querySelector('.ip-bar').style.display = 'none';
                step.style.display = 'none';
                document.getElementById('ipDone').style.display = 'flex';
                await sleep(4000);
            }
        } catch(err) {
            console.error('[PWA] Install error:', err);
            step.textContent = 'Gagal install, coba manual...';
            await sleep(1500);
        }

        prog.classList.remove('show');
        banner.classList.remove('show');
        deferredPrompt = null;
    });

    // Banner body click
    banner.addEventListener('click', (e) => {
        if (e.target.closest('.ib-close') || e.target.closest('.ib-action')) return;
        ibBtn.click();
    });

    // App installed event
    window.addEventListener('appinstalled', () => {
        console.log('[PWA] App installed!');
        banner.classList.remove('show');
        localStorage.removeItem('ib_dismissed');
        deferredPrompt = null;

        const prog = document.getElementById('installProgress');
        if (!prog.classList.contains('show')) {
            prog.classList.add('show');
            document.getElementById('ipSub').style.display = 'none';
            document.querySelector('.ip-bar').style.display = 'none';
            document.getElementById('ipStep').style.display = 'none';
            document.getElementById('ipDone').style.display = 'flex';
            setTimeout(() => prog.classList.remove('show'), 4000);
        }
    });

    function showManualGuide() {
        // Create fullscreen guide overlay
        const ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;z-index:2000;background:rgba(5,10,24,.96);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;animation:faceIn .3s ease;';
        ov.innerHTML = `
            <div style="text-align:center;max-width:320px;">
                <div style="font-size:56px;margin-bottom:16px;">📲</div>
                <h3 style="color:#fff;font-size:18px;font-weight:700;margin:0 0 8px;">Install Staff Portal</h3>
                <p style="color:rgba(255,255,255,.5);font-size:12px;margin:0 0 28px;">Ikuti langkah berikut di browser Chrome:</p>
                <div style="text-align:left;">
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#f0b429,#e09800);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0d1f3c;flex-shrink:0;">1</div>
                        <div>
                            <div style="color:#fff;font-size:14px;font-weight:600;">Tap menu ⋮</div>
                            <div style="color:rgba(255,255,255,.4);font-size:11px;margin-top:2px;">3 titik di kanan atas Chrome</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#f0b429,#e09800);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#0d1f3c;flex-shrink:0;">2</div>
                        <div>
                            <div style="color:#fff;font-size:14px;font-weight:600;">Pilih "Install app"</div>
                            <div style="color:rgba(255,255,255,.4);font-size:11px;margin-top:2px;">Atau "Add to Home screen"</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:28px;">
                        <div style="width:32px;height:32px;background:linear-gradient(135deg,#34d399,#059669);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:800;color:#fff;flex-shrink:0;">3</div>
                        <div>
                            <div style="color:#fff;font-size:14px;font-weight:600;">Tap "Install"</div>
                            <div style="color:rgba(255,255,255,.4);font-size:11px;margin-top:2px;">App muncul di home screen!</div>
                        </div>
                    </div>
                </div>
                <button onclick="this.closest('div[style]').parentElement.remove();" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);color:#fff;padding:12px 32px;border-radius:12px;font-size:13px;font-weight:600;cursor:pointer;width:100%;">Mengerti</button>
            </div>
        `;
        document.body.appendChild(ov);
        ov.addEventListener('click', (e) => { if (e.target === ov) ov.remove(); });
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

})(); // end PWA IIFE

// ═══ SLIP GAJI PAGE ═══
let slipPeriodsLoaded = false;
let currentSlipData = null;

async function loadSlipGaji() {
    const sel = document.getElementById('slipPeriod');
    const content = document.getElementById('slipGajiContent');
    const dlBtn = document.getElementById('btnDownloadSlip');
    if (dlBtn) dlBtn.style.display = 'none';
    
    // Load periods dropdown once
    if (!slipPeriodsLoaded) {
        try {
            const res = await fetch(API + '&action=salary_periods');
            const data = await res.json();
            if (!data.success && data.auth === false) { doLogout(); return; }
            const periods = data.data || [];
            if (periods.length === 0) {
                sel.innerHTML = '<option value="">Belum ada data</option>';
                content.innerHTML = '<div style="text-align:center;padding:40px 16px;"><div style="font-size:48px;margin-bottom:12px;">📋</div><div style="font-size:13px;color:var(--muted);">Belum ada slip gaji yang tersedia.</div><div style="font-size:11px;color:var(--muted);margin-top:4px;">Slip gaji akan muncul setelah payroll diproses admin.</div></div>';
                slipPeriodsLoaded = true;
                return;
            }
            sel.innerHTML = periods.map(p => `<option value="${p.id}" ${p.is_latest ? 'selected' : ''}>${p.period_label} — ${p.status_label}</option>`).join('');
            slipPeriodsLoaded = true;
        } catch(e) {
            sel.innerHTML = '<option value="">Gagal memuat</option>';
            content.innerHTML = '<div style="color:var(--red);font-size:11px;text-align:center;">Gagal memuat data periode</div>';
            return;
        }
    }
    
    const periodId = sel.value;
    if (!periodId) return;
    
    content.innerHTML = '<div class="loading"><span class="spin"></span> Memuat slip gaji...</div>';
    
    try {
        const res = await fetch(API + '&action=salary_slip&period_id=' + periodId);
        const data = await res.json();
        if (!data.success) {
            content.innerHTML = `<div style="text-align:center;padding:40px 16px;"><div style="font-size:48px;margin-bottom:12px;">${data.pending ? '⏳' : '📋'}</div><div style="font-size:13px;color:var(--muted);">${data.message || 'Slip gaji tidak ditemukan'}</div></div>`;
            return;
        }
        currentSlipData = data.data;
        renderSlipGaji(data.data);
        if (dlBtn) dlBtn.style.display = 'flex';
    } catch(e) {
        content.innerHTML = '<div style="color:var(--red);font-size:11px;text-align:center;padding:20px;">Gagal memuat slip gaji</div>';
    }
}

function renderSlipGaji(slip) {
    const content = document.getElementById('slipGajiContent');
    const fmt = (n) => new Intl.NumberFormat('id-ID').format(Math.round(n || 0));
    const workHours = parseFloat(slip.work_hours) || 0;
    const overtimeHours = parseFloat(slip.overtime_hours) || 0;
    const baseSalary = parseFloat(slip.base_salary) || 0;
    const actualBase = parseFloat(slip.actual_base) || 0;
    const overtimeAmount = parseFloat(slip.overtime_amount) || 0;
    const incentive = parseFloat(slip.incentive) || 0;
    const allowance = parseFloat(slip.allowance) || 0;
    const uangMakan = parseFloat(slip.uang_makan) || 0;
    const bonus = parseFloat(slip.bonus) || 0;
    const otherIncome = parseFloat(slip.other_income) || 0;
    const totalEarnings = parseFloat(slip.total_earnings) || 0;
    const dLoan = parseFloat(slip.deduction_loan) || 0;
    const dAbsence = parseFloat(slip.deduction_absence) || 0;
    const dTax = parseFloat(slip.deduction_tax) || 0;
    const dBpjs = parseFloat(slip.deduction_bpjs) || 0;
    const dOther = parseFloat(slip.deduction_other) || 0;
    const totalDeductions = parseFloat(slip.total_deductions) || 0;
    const netSalary = parseFloat(slip.net_salary) || 0;

    const logoHtml = SLIP_LOGO_URL ? `<img src="${SLIP_LOGO_URL}" style="height:52px;object-fit:contain;" crossorigin="anonymous">` : `<span style="font-size:28px;">🏨</span>`;

    const monthNames = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    const periodText = monthNames[parseInt(slip.period_month)] + ' ' + slip.period_year;

    const slipRow = (label, value, isDeduct, isBold) => {
        const color = isDeduct ? '#dc2626' : (isBold ? '#059669' : '#1e293b');
        const weight = isBold ? '700' : '400';
        const prefix = isDeduct ? '-' : '';
        return `<tr><td style="padding:5px 0;font-size:11px;color:#475569;border-bottom:1px solid #f1f5f9;">${label}</td><td style="padding:5px 0;font-size:11px;color:${color};font-weight:${weight};text-align:right;border-bottom:1px solid #f1f5f9;font-family:'SF Mono',Monaco,Consolas,monospace;">${prefix}Rp ${fmt(Math.abs(value))}</td></tr>`;
    };

    const totalRow = (label, value, bgColor, textColor) => {
        return `<tr><td style="padding:7px 0;font-size:11.5px;font-weight:700;color:${textColor};">${label}</td><td style="padding:7px 0;font-size:11.5px;font-weight:800;color:${textColor};text-align:right;font-family:'SF Mono',Monaco,Consolas,monospace;">Rp ${fmt(value)}</td></tr>`;
    };

    content.innerHTML = `
    <div id="slipGajiPrintArea" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,.08);border:1px solid #e2e8f0;">
        
        <!-- Header -->
        <div style="background:#fff;padding:20px 16px 16px;border-bottom:2px solid #0f172a;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                ${logoHtml}
                <div style="flex:1;">
                    <div style="font-size:15px;font-weight:800;color:#0f172a;letter-spacing:.3px;line-height:1.2;">${BIZ_NAME}</div>
                    <div style="font-size:9px;color:#64748b;letter-spacing:.3px;margin-top:2px;">Karimunjawa, Jepara • Indonesia</div>
                </div>
            </div>
            <div style="background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <div style="font-size:8px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1.5px;font-weight:600;">Slip Gaji Karyawan</div>
                    <div style="font-size:16px;font-weight:700;color:#fff;margin-top:3px;">Periode ${periodText}</div>
                </div>
                <div style="width:40px;height:40px;background:rgba(255,255,255,.1);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;">💰</div>
            </div>
        </div>

        <!-- Employee Info -->
        <div style="padding:14px 16px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-bottom:1px solid #e2e8f0;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Nama Karyawan</div>
                    <div style="font-size:12px;font-weight:700;color:#0f172a;margin-top:1px;">${slip.employee_name}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Jabatan</div>
                    <div style="font-size:12px;font-weight:600;color:#334155;margin-top:1px;">${slip.position || '-'}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">NIK / Kode</div>
                    <div style="font-size:12px;font-weight:600;color:#334155;margin-top:1px;font-family:monospace;">${slip.employee_code || '-'}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;">Departemen</div>
                    <div style="font-size:12px;font-weight:600;color:#334155;margin-top:1px;">${slip.department || '-'}</div>
                </div>
            </div>
        </div>

        <!-- Work Summary -->
        <div style="padding:12px 16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;border-bottom:1px solid #e2e8f0;">
            <div style="text-align:center;background:linear-gradient(135deg,#eff6ff,#dbeafe);border-radius:10px;padding:10px 6px;">
                <div style="font-size:18px;font-weight:800;color:#2563eb;">${workHours}</div>
                <div style="font-size:8px;color:#64748b;margin-top:2px;">Jam Kerja</div>
            </div>
            <div style="text-align:center;background:linear-gradient(135deg,#fef3c7,#fde68a);border-radius:10px;padding:10px 6px;">
                <div style="font-size:18px;font-weight:800;color:#b45309;">${overtimeHours}</div>
                <div style="font-size:8px;color:#64748b;margin-top:2px;">Jam Lembur</div>
            </div>
            <div style="text-align:center;background:linear-gradient(135deg,#f0fdf4,#bbf7d0);border-radius:10px;padding:10px 6px;">
                <div style="font-size:14px;font-weight:800;color:#059669;">Rp ${fmt(baseSalary)}</div>
                <div style="font-size:8px;color:#64748b;margin-top:2px;">Gaji Pokok</div>
            </div>
        </div>

        <!-- Earnings Table -->
        <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">
                <div style="width:6px;height:6px;background:#10b981;border-radius:50%;"></div>
                <div style="font-size:10px;font-weight:700;color:#059669;text-transform:uppercase;letter-spacing:.8px;">Pendapatan</div>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                ${slipRow('Gaji Pokok (Full)', baseSalary, false, false)}
                ${slipRow('Gaji Aktual (' + workHours + ' jam / 200 target)', actualBase, false, false)}
                ${slipRow('Uang Lembur (' + overtimeHours + ' jam)', overtimeAmount, false, false)}
                ${slipRow('Service', incentive, false, false)}
                ${slipRow('Tunjangan', allowance, false, false)}
                ${slipRow('Uang Makan', uangMakan, false, false)}
                ${slipRow('Bonus', bonus, false, false)}
                ${slipRow('Pendapatan Lainnya', otherIncome, false, false)}
                ${totalRow('Total Pendapatan', totalEarnings, '#f0fdf4', '#059669')}
            </table>
        </div>

        <!-- Deductions Table -->
        <div style="padding:14px 16px;border-bottom:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">
                <div style="width:6px;height:6px;background:#ef4444;border-radius:50%;"></div>
                <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.8px;">Potongan</div>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                ${slipRow('Pinjaman / Kasbon', dLoan, true, false)}
                ${slipRow('Potongan Absensi', dAbsence, true, false)}
                ${slipRow('Pajak (PPh 21)', dTax, true, false)}
                ${slipRow('BPJS', dBpjs, true, false)}
                ${slipRow('Potongan Lainnya', dOther, true, false)}
                ${totalRow('Total Potongan', totalDeductions, '#fef2f2', '#dc2626')}
            </table>
        </div>

        <!-- Net Salary -->
        <div style="padding:16px;background:linear-gradient(135deg,#059669,#10b981);">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:9px;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:1px;">Gaji Bersih (Take Home Pay)</div>
                    <div style="font-size:22px;font-weight:800;color:#fff;margin-top:3px;font-family:'SF Mono',Monaco,Consolas,monospace;">Rp ${fmt(netSalary)}</div>
                </div>
                <div style="font-size:28px;">💰</div>
            </div>
        </div>

        ${slip.bank_name ? `
        <!-- Bank Transfer -->
        <div style="padding:14px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                <div style="width:6px;height:6px;background:#2563eb;border-radius:50%;"></div>
                <div style="font-size:10px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.8px;">Transfer Bank</div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;">Bank</div>
                    <div style="font-size:12px;font-weight:700;color:#1e3a8a;">${slip.bank_name}</div>
                </div>
                <div>
                    <div style="font-size:8px;color:#94a3b8;text-transform:uppercase;">No. Rekening</div>
                    <div style="font-size:12px;font-weight:700;color:#1e3a8a;font-family:monospace;">${slip.bank_account || '-'}</div>
                </div>
            </div>
        </div>` : ''}

        <!-- Footer -->
        <div style="padding:10px 16px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
            <div style="font-size:8px;color:#94a3b8;">Dokumen resmi — digenerate otomatis oleh ${BIZ_NAME} Payroll System</div>
            <div style="font-size:8px;color:#cbd5e1;margin-top:2px;">Slip ID: #${slip.id} • ${new Date().toLocaleDateString('id-ID', {day:'numeric',month:'long',year:'numeric'})}</div>
        </div>
    </div>
    `;
}

// Download slip gaji as image
async function downloadSlipGaji() {
    if (!currentSlipData) return;
    const btn = document.getElementById('btnDownloadSlip');
    const origText = btn.innerHTML;
    btn.innerHTML = '⏳ Proses...';
    btn.disabled = true;

    try {
        // Load html2canvas dynamically
        if (typeof html2canvas === 'undefined') {
            await new Promise((resolve, reject) => {
                const sc = document.createElement('script');
                sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                sc.onload = resolve;
                sc.onerror = reject;
                document.head.appendChild(sc);
            });
        }

        const el = document.getElementById('slipGajiPrintArea');
        const canvas = await html2canvas(el, {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true,
            logging: false
        });

        const link = document.createElement('a');
        const monthNames = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'];
        const fname = 'SlipGaji_' + currentSlipData.employee_name.replace(/\s+/g,'_') + '_' + monthNames[parseInt(currentSlipData.period_month)] + currentSlipData.period_year + '.png';
        link.download = fname;
        link.href = canvas.toDataURL('image/png');
        link.click();
    } catch(e) {
        alert('Gagal download slip gaji. Coba lagi.');
        console.error(e);
    } finally {
        btn.innerHTML = origText;
        btn.disabled = false;
    }
}

</script>

</body>
</html>
