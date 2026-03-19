<?php
/**
 * Developer Panel - Design Tools
 * Design Menu Resto & Room Number Generator
 */

define('APP_ACCESS', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once __DIR__ . '/includes/dev_auth.php';

$auth = new DevAuth();
$auth->requireLogin();

$user = $auth->getCurrentUser();
$pdo = $auth->getConnection();
$pageTitle = '🎨 Design Tools';

$section = $_GET['section'] ?? 'overview';

// Handle asset upload BEFORE any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_assets') {
    $assetsDir = __DIR__ . '/assets/room-design/';
    if (!is_dir($assetsDir)) {
        mkdir($assetsDir, 0755, true);
    }
    
    $uploaded = false;
    
    if (!empty($_FILES['font_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['font_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['ttf', 'otf'])) {
            move_uploaded_file($_FILES['font_file']['tmp_name'], $assetsDir . 'font.ttf');
            $uploaded = true;
        }
    }
    
    if (!empty($_FILES['wood_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['wood_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
            move_uploaded_file($_FILES['wood_file']['tmp_name'], $assetsDir . 'wood_texture.jpg');
            $uploaded = true;
        }
    }
    
    if ($uploaded) {
        $_SESSION['success_message'] = 'Asset berhasil diupload!';
    }
    header('Location: design.php?section=room-number');
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    
    <?php if ($section === 'overview'): ?>
    <!-- ═══════════════════════════════════ -->
    <!-- DESIGN TOOLS OVERVIEW              -->
    <!-- ═══════════════════════════════════ -->
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-1"><i class="bi bi-palette me-2"></i>Design Tools</h4>
            <p class="text-muted mb-0">Tools untuk desain aset visual hotel & restoran</p>
        </div>
    </div>
    
    <div class="row g-4">
        <!-- Menu Resto Design -->
        <div class="col-md-6 col-lg-4">
            <div class="content-card h-100" style="border-top: 3px solid var(--dev-warning);">
                <div class="p-4 text-center">
                    <div style="font-size: 48px; margin-bottom: 12px;">🍽️</div>
                    <h5 class="fw-bold">Design Menu Resto</h5>
                    <p class="text-muted small">Editor visual untuk membuat menu restoran dengan tema elegan. Support cetak A4 dan berbagai tema warna.</p>
                    <div class="d-flex gap-2 justify-content-center mt-3">
                        <a href="design.php?section=menu-resto" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil-square me-1"></i>Editor
                        </a>
                        <a href="<?= BASE_URL ?>public/menu-narayana-resto.html" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Full Page
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Room Number Design -->
        <div class="col-md-6 col-lg-4">
            <div class="content-card h-100" style="border-top: 3px solid var(--dev-success);">
                <div class="p-4 text-center">
                    <div style="font-size: 48px; margin-bottom: 12px;">🚪</div>
                    <h5 class="fw-bold">Design Nomor Room</h5>
                    <p class="text-muted small">Generator nomor kamar dengan tekstur kayu dan teks emas. Output gambar PNG resolusi tinggi.</p>
                    <a href="design.php?section=room-number" class="btn btn-sm btn-outline-success mt-3">
                        <i class="bi bi-image me-1"></i>Generate
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Future: More design tools -->
        <div class="col-md-6 col-lg-4">
            <div class="content-card h-100" style="border-top: 3px solid var(--dev-info); opacity: 0.5;">
                <div class="p-4 text-center">
                    <div style="font-size: 48px; margin-bottom: 12px;">🏷️</div>
                    <h5 class="fw-bold">Design Lainnya</h5>
                    <p class="text-muted small">Coming soon — ID Card Staff, Welcome Card, Invoice Template, dll.</p>
                    <button class="btn btn-sm btn-outline-secondary mt-3" disabled>
                        <i class="bi bi-lock me-1"></i>Coming Soon
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <?php elseif ($section === 'menu-resto'): ?>
    <!-- ═══════════════════════════════════ -->
    <!-- MENU RESTO EDITOR (IFRAME)         -->
    <!-- ═══════════════════════════════════ -->
    <div class="row mb-3">
        <div class="col-12 d-flex align-items-center justify-content-between">
            <div>
                <a href="design.php" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="bi bi-arrow-left"></i> Kembali
                </a>
                <span class="fw-bold">🍽️ Design Menu Resto</span>
            </div>
            <a href="<?= BASE_URL ?>public/menu-narayana-resto.html" target="_blank" class="btn btn-sm btn-primary">
                <i class="bi bi-box-arrow-up-right me-1"></i>Buka Fullscreen
            </a>
        </div>
    </div>
    <div class="content-card p-0" style="overflow: hidden; border-radius: 12px;">
        <iframe src="<?= BASE_URL ?>public/menu-narayana-resto.html" 
                style="width:100%; height:calc(100vh - 160px); border:none; display:block;"
                allowfullscreen></iframe>
    </div>
    
    <?php elseif ($section === 'room-number'): ?>
    <!-- ═══════════════════════════════════ -->
    <!-- ROOM NUMBER GENERATOR              -->
    <!-- ═══════════════════════════════════ -->
    <div class="row mb-3">
        <div class="col-12">
            <a href="design.php" class="btn btn-sm btn-outline-secondary me-2">
                <i class="bi bi-arrow-left"></i> Kembali
            </a>
            <span class="fw-bold">🚪 Design Nomor Room</span>
        </div>
    </div>
    
    <?php
    // Check if required assets exist
    $assetsDir = __DIR__ . '/assets/room-design/';
    $fontFile = $assetsDir . 'font.ttf';
    $woodFile = $assetsDir . 'wood_texture.jpg';
    $hasFont = file_exists($fontFile);
    $hasWood = file_exists($woodFile);
    $gdLoaded = extension_loaded('gd');
    
    // Get all rooms from business databases for quick select
    $allRooms = [];
    try {
        $bizStmt = $pdo->query("SELECT id, business_name, db_name FROM businesses WHERE is_active = 1");
        $businesses = $bizStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($businesses as $biz) {
            try {
                $bizPdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $biz['db_name'], DB_USER, DB_PASS);
                $bizPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $roomStmt = $bizPdo->query("SELECT room_number FROM rooms ORDER BY room_number");
                $rooms = $roomStmt->fetchAll(PDO::FETCH_COLUMN);
                if ($rooms) {
                    $allRooms[$biz['business_name']] = $rooms;
                }
            } catch (Exception $e) {}
        }
    } catch (Exception $e) {}
    ?>
    
    <!-- Status Check -->
    <?php if (!$gdLoaded || !$hasFont || !$hasWood): ?>
    <div class="alert alert-warning">
        <h6 class="alert-heading fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>Requirement Check</h6>
        <ul class="mb-0 small">
            <li>GD Extension: <?= $gdLoaded ? '✅ Loaded' : '❌ Tidak aktif — aktifkan di php.ini' ?></li>
            <li>Font file (<code>developer/assets/room-design/font.ttf</code>): <?= $hasFont ? '✅ Ada' : '❌ Belum ada — upload font TTF' ?></li>
            <li>Wood texture (<code>developer/assets/room-design/wood_texture.jpg</code>): <?= $hasWood ? '✅ Ada' : '❌ Belum ada — upload gambar tekstur kayu' ?></li>
        </ul>
        <?php if (!$hasFont || !$hasWood): ?>
        <hr>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end" action="design.php?section=room-number&action=upload-assets">
            <?php if (!$hasFont): ?>
            <div class="col-md-5">
                <label class="form-label small fw-bold">Upload Font TTF</label>
                <input type="file" name="font_file" accept=".ttf,.otf" class="form-control form-control-sm">
            </div>
            <?php endif; ?>
            <?php if (!$hasWood): ?>
            <div class="col-md-5">
                <label class="form-label small fw-bold">Upload Wood Texture JPG</label>
                <input type="file" name="wood_file" accept=".jpg,.jpeg,.png" class="form-control form-control-sm">
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button type="submit" name="action" value="upload_assets" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-upload me-1"></i>Upload
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="row g-4">
        <!-- Settings Panel -->
        <div class="col-lg-4">
            <div class="content-card">
                <div class="card-header-custom">
                    <h6 class="mb-0"><i class="bi bi-sliders me-2"></i>Pengaturan</h6>
                </div>
                <div class="p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nomor Kamar</label>
                        <input type="text" id="roomNo" class="form-control" value="301" maxlength="5" 
                               oninput="updatePreview()">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Nama Hotel</label>
                        <input type="text" id="hotelName" class="form-control" value="NARAYANA" maxlength="30"
                               oninput="updatePreview()">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Sub Text</label>
                        <input type="text" id="subText" class="form-control" value="HOTEL" maxlength="20"
                               oninput="updatePreview()">
                    </div>
                    
                    <?php if (!empty($allRooms)): ?>
                    <hr>
                    <label class="form-label small fw-bold">Quick Select Room</label>
                    <?php foreach ($allRooms as $bizName => $rooms): ?>
                    <div class="mb-2">
                        <div class="text-muted small mb-1"><?= htmlspecialchars($bizName) ?></div>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($rooms as $rn): ?>
                            <button type="button" class="btn btn-outline-dark btn-sm px-2 py-0" 
                                    style="font-size: 11px; min-width: 40px;"
                                    onclick="document.getElementById('roomNo').value='<?= htmlspecialchars($rn) ?>';updatePreview();">
                                <?= htmlspecialchars($rn) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <hr>
                    <button class="btn btn-success w-100" onclick="downloadImage()" <?= (!$gdLoaded || !$hasFont || !$hasWood) ? 'disabled' : '' ?>>
                        <i class="bi bi-download me-1"></i>Download PNG
                    </button>
                    
                    <button class="btn btn-outline-primary w-100 mt-2" onclick="generateBatch()" <?= (!$gdLoaded || !$hasFont || !$hasWood) ? 'disabled' : '' ?>>
                        <i class="bi bi-collection me-1"></i>Generate Semua Room
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Preview Panel -->
        <div class="col-lg-8">
            <div class="content-card">
                <div class="card-header-custom">
                    <h6 class="mb-0"><i class="bi bi-eye me-2"></i>Preview</h6>
                </div>
                <div class="p-4 text-center" style="background: #e8e8e8; min-height: 400px; display: flex; align-items: center; justify-content: center;">
                    <?php if ($gdLoaded && $hasFont && $hasWood): ?>
                    <img id="roomPreview" 
                         src="design-room-image.php?no=301&hotel=NARAYANA&sub=HOTEL&t=<?= time() ?>" 
                         alt="Room Number Preview"
                         style="max-width: 100%; max-height: 500px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                    <?php else: ?>
                    <div class="text-muted">
                        <i class="bi bi-image" style="font-size: 64px;"></i>
                        <p class="mt-2">Upload font & wood texture terlebih dahulu untuk melihat preview</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Batch results -->
            <div class="content-card mt-4" id="batchResults" style="display: none;">
                <div class="card-header-custom">
                    <h6 class="mb-0"><i class="bi bi-collection me-2"></i>Batch Generate Results</h6>
                </div>
                <div class="p-4" id="batchGrid"></div>
            </div>
        </div>
    </div>
    
    <script>
    let debounceTimer;
    function updatePreview() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            const no = document.getElementById('roomNo').value || '301';
            const hotel = document.getElementById('hotelName').value || 'NARAYANA';
            const sub = document.getElementById('subText').value || 'HOTEL';
            const img = document.getElementById('roomPreview');
            if (img) {
                img.src = 'design-room-image.php?no=' + encodeURIComponent(no) + 
                          '&hotel=' + encodeURIComponent(hotel) + 
                          '&sub=' + encodeURIComponent(sub) + 
                          '&t=' + Date.now();
            }
        }, 300);
    }
    
    function downloadImage() {
        const no = document.getElementById('roomNo').value || '301';
        const hotel = document.getElementById('hotelName').value || 'NARAYANA';
        const sub = document.getElementById('subText').value || 'HOTEL';
        const url = 'design-room-image.php?no=' + encodeURIComponent(no) + 
                    '&hotel=' + encodeURIComponent(hotel) + 
                    '&sub=' + encodeURIComponent(sub) + 
                    '&download=1';
        
        const a = document.createElement('a');
        a.href = url;
        a.download = 'room-' + no + '.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
    
    function generateBatch() {
        const hotel = document.getElementById('hotelName').value || 'NARAYANA';
        const sub = document.getElementById('subText').value || 'HOTEL';
        const rooms = <?= json_encode(array_merge(...array_values($allRooms ?: [['301']]))) ?>;
        
        const grid = document.getElementById('batchGrid');
        const wrap = document.getElementById('batchResults');
        wrap.style.display = 'block';
        
        let html = '<div class="row g-3">';
        rooms.forEach(no => {
            const src = 'design-room-image.php?no=' + encodeURIComponent(no) + 
                        '&hotel=' + encodeURIComponent(hotel) + 
                        '&sub=' + encodeURIComponent(sub) + '&t=' + Date.now();
            const dl = 'design-room-image.php?no=' + encodeURIComponent(no) + 
                       '&hotel=' + encodeURIComponent(hotel) + 
                       '&sub=' + encodeURIComponent(sub) + '&download=1';
            html += '<div class="col-6 col-md-4 col-lg-3 text-center">';
            html += '<img src="' + src + '" class="img-fluid rounded shadow-sm mb-2" style="max-height:150px;">';
            html += '<div><a href="' + dl + '" download="room-' + no + '.png" class="btn btn-sm btn-outline-success"><i class="bi bi-download"></i> ' + no + '</a></div>';
            html += '</div>';
        });
        html += '</div>';
        grid.innerHTML = html;
        
        wrap.scrollIntoView({ behavior: 'smooth' });
    }
    </script>
    
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
