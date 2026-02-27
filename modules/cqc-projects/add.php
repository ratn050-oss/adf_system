<?php
/**
 * CQC Projects - Add/Edit Project
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

if (!isModuleEnabled('cqc-projects')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once 'db-helper.php';

// Database connection
try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

$pageTitle = "Tambah Proyek Baru - CQC";
$project = null;
$isEdit = false;

// Check if editing
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $isEdit = true;
    $pageTitle = "Edit Proyek - CQC";
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM cqc_projects WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$project) {
            header('Location: dashboard.php');
            exit;
        }
    } catch (Exception $e) {
        // Project not found
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $data = [
            'project_name' => $_POST['project_name'] ?? '',
            'project_code' => $_POST['project_code'] ?? '',
            'description' => $_POST['description'] ?? '',
            'location' => $_POST['location'] ?? '',
            'client_name' => $_POST['client_name'] ?? '',
            'client_phone' => $_POST['client_phone'] ?? '',
            'client_email' => $_POST['client_email'] ?? '',
            'solar_capacity_kwp' => $_POST['solar_capacity_kwp'] ?? 0,
            'panel_count' => $_POST['panel_count'] ?? 0,
            'panel_type' => $_POST['panel_type'] ?? '',
            'inverter_type' => $_POST['inverter_type'] ?? '',
            'budget_idr' => str_replace('.', '', $_POST['budget_idr'] ?? 0),
            'status' => $_POST['status'] ?? 'planning',
            'progress_percentage' => $_POST['progress_percentage'] ?? 0,
            'start_date' => $_POST['start_date'] ?? null,
            'end_date' => $_POST['end_date'] ?? null,
            'estimated_completion' => $_POST['estimated_completion'] ?? null,
        ];
        
        if ($isEdit) {
            // Update
            $stmt = $pdo->prepare("
                UPDATE cqc_projects 
                SET project_name = ?, project_code = ?, description = ?, location = ?,
                    client_name = ?, client_phone = ?, client_email = ?,
                    solar_capacity_kwp = ?, panel_count = ?, panel_type = ?, inverter_type = ?,
                    budget_idr = ?, status = ?, progress_percentage = ?,
                    start_date = ?, end_date = ?, estimated_completion = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $data['project_name'], $data['project_code'], $data['description'], $data['location'],
                $data['client_name'], $data['client_phone'], $data['client_email'],
                $data['solar_capacity_kwp'], $data['panel_count'], $data['panel_type'], $data['inverter_type'],
                $data['budget_idr'], $data['status'], $data['progress_percentage'],
                $data['start_date'], $data['end_date'], $data['estimated_completion'],
                $_GET['id']
            ]);
            
            header('Location: detail.php?id=' . $_GET['id'] . '&success=updated');
        } else {
            // Create
            $stmt = $pdo->prepare("
                INSERT INTO cqc_projects 
                (project_name, project_code, description, location,
                 client_name, client_phone, client_email,
                 solar_capacity_kwp, panel_count, panel_type, inverter_type,
                 budget_idr, status, progress_percentage,
                 start_date, end_date, estimated_completion, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['project_name'], $data['project_code'], $data['description'], $data['location'],
                $data['client_name'], $data['client_phone'], $data['client_email'],
                $data['solar_capacity_kwp'], $data['panel_count'], $data['panel_type'], $data['inverter_type'],
                $data['budget_idr'], $data['status'], $data['progress_percentage'],
                $data['start_date'], $data['end_date'], $data['estimated_completion'],
                $_SESSION['user_id']
            ]);
            
            $newId = $pdo->lastInsertId();
            
            // Create project balance record
            $pdo->prepare("INSERT INTO cqc_project_balances (project_id, total_expenses_idr, remaining_budget_idr) VALUES (?, 0, ?)")
                ->execute([$newId, $data['budget_idr']]);
            
            header('Location: detail.php?id=' . $newId . '&success=created');
        }
        exit;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 28px;
        }

        .header-back {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
        }

        .header-back:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Form */
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: #0066CC;
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFD700;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input, textarea, select {
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }

        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #0066CC;
            box-shadow: 0 0 0 3px rgba(0, 102, 204, 0.1);
        }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        /* FormButton */
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }

        button {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 102, 204, 0.3);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        /* Alert */
        .alert {
            background: #fee;
            border: 1px solid #fcc;
            color: #c33;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert.success {
            background: #efe;
            border-color: #cfc;
            color: #3c3;
        }

        .required {
            color: #d32f2f;
        }

        .hint {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><?php echo $isEdit ? '✏️ Edit Proyek' : '➕ Proyek Baru'; ?></h1>
            <a href="dashboard.php" class="header-back">← Kembali</a>
        </div>

        <!-- Form -->
        <div class="form-card">
            <?php if (isset($error)): ?>
                <div class="alert"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <!-- Project Basic Info -->
                <div class="form-section">
                    <h3>📋 Informasi Dasar Proyek</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nama Proyek <span class="required">*</span></label>
                            <input type="text" name="project_name" required value="<?php echo htmlspecialchars($project['project_name'] ?? ''); ?>" placeholder="Contoh: Solar Panel PT XYZ">
                        </div>

                        <div class="form-group">
                            <label>Kode Proyek <span class="required">*</span></label>
                            <input type="text" name="project_code" required value="<?php echo htmlspecialchars($project['project_code'] ?? ''); ?>" placeholder="Contoh: PRJ-2024-001">
                            <p class="hint">Harus unik, digunakan untuk tracking</p>
                        </div>

                        <div class="form-group">
                            <label>Lokasi <span class="required">*</span></label>
                            <input type="text" name="location" required value="<?php echo htmlspecialchars($project['location'] ?? ''); ?>" placeholder="Contoh: Jl. Merdeka No. 123, Jakarta">
                        </div>

                        <div class="form-group">
                            <label>Status Proyek</label>
                            <select name="status">
                                <option value="planning" <?php echo ($project['status'] ?? 'planning') === 'planning' ? 'selected' : ''; ?>>Planning</option>
                                <option value="procurement" <?php echo ($project['status'] ?? '') === 'procurement' ? 'selected' : ''; ?>>Procurement</option>
                                <option value="installation" <?php echo ($project['status'] ?? '') === 'installation' ? 'selected' : ''; ?>>Installation</option>
                                <option value="testing" <?php echo ($project['status'] ?? '') === 'testing' ? 'selected' : ''; ?>>Testing</option>
                                <option value="completed" <?php echo ($project['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="on_hold" <?php echo ($project['status'] ?? '') === 'on_hold' ? 'selected' : ''; ?>>On Hold</option>
                            </select>
                        </div>

                        <div class="form-group full">
                            <label>Deskripsi Proyek</label>
                            <textarea name="description" placeholder="Detail lengkap tentang proyek..."><?php echo htmlspecialchars($project['description'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Client Info -->
                <div class="form-section">
                    <h3>👤 Informasi Klien</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Nama Klien</label>
                            <input type="text" name="client_name" value="<?php echo htmlspecialchars($project['client_name'] ?? ''); ?>" placeholder="Nama klien atau perusahaan">
                        </div>

                        <div class="form-group">
                            <label>Telepon Klien</label>
                            <input type="tel" name="client_phone" value="<?php echo htmlspecialchars($project['client_phone'] ?? ''); ?>" placeholder="0812-3456-7890">
                        </div>

                        <div class="form-group">
                            <label>Email Klien</label>
                            <input type="email" name="client_email" value="<?php echo htmlspecialchars($project['client_email'] ?? ''); ?>" placeholder="client@example.com">
                        </div>
                    </div>
                </div>

                <!-- Solar Panel Specifications -->
                <div class="form-section">
                    <h3>☀️ Spesifikasi Panel Surya</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Kapasitas (KWp)</label>
                            <input type="number" name="solar_capacity_kwp" step="0.1" value="<?php echo htmlspecialchars($project['solar_capacity_kwp'] ?? ''); ?>" placeholder="3.5">
                            <p class="hint">Kilowatt Peak</p>
                        </div>

                        <div class="form-group">
                            <label>Jumlah Panel</label>
                            <input type="number" name="panel_count" step="1" value="<?php echo htmlspecialchars($project['panel_count'] ?? ''); ?>" placeholder="10">
                        </div>

                        <div class="form-group">
                            <label>Tipe Panel</label>
                            <input type="text" name="panel_type" value="<?php echo htmlspecialchars($project['panel_type'] ?? ''); ?>" placeholder="Contoh: Polycrystalline 350W">
                        </div>

                        <div class="form-group">
                            <label>Tipe Inverter</label>
                            <input type="text" name="inverter_type" value="<?php echo htmlspecialchars($project['inverter_type'] ?? ''); ?>" placeholder="Contoh: Hybrid 10KW">
                        </div>
                    </div>
                </div>

                <!-- Budget & Schedule -->
                <div class="form-section">
                    <h3>💰 Budget & Jadwal</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Budget Total (Rp) <span class="required">*</span></label>
                            <input type="text" name="budget_idr" required value="<?php echo isset($project['budget_idr']) ? number_format($project['budget_idr'], 0) : ''; ?>" placeholder="100000000">
                            <p class="hint">Tanpa koma/titik</p>
                        </div>

                        <div class="form-group">
                            <label>Progress (%)</label>
                            <input type="range" name="progress_percentage" min="0" max="100" value="<?php echo htmlspecialchars($project['progress_percentage'] ?? 0); ?>" id="progressSlider">
                            <p class="hint" id="progressValue"><?php echo htmlspecialchars($project['progress_percentage'] ?? 0); ?>%</p>
                        </div>

                        <div class="form-group">
                            <label>Tanggal Mulai</label>
                            <input type="date" name="start_date" value="<?php echo htmlspecialchars($project['start_date'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label>Tanggal Selesai Rencana</label>
                            <input type="date" name="end_date" value="<?php echo htmlspecialchars($project['end_date'] ?? ''); ?>">
                        </div>

                        <div class="form-group">
                            <label>Estimasi Selesai</label>
                            <input type="date" name="estimated_completion" value="<?php echo htmlspecialchars($project['estimated_completion'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <?php echo $isEdit ? '✅ Simpan Perubahan' : '➕ Buat Proyek'; ?>
                    </button>
                    <button type="button" class="btn-secondary" onclick="history.back()">Batal</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Update progress value display
        document.getElementById('progressSlider').addEventListener('input', function() {
            document.getElementById('progressValue').textContent = this.value + '%';
        });

        // Format budget input
        document.querySelector('input[name="budget_idr"]').addEventListener('change', function() {
            const value = this.value.replace(/\D/g, '');
            this.value = value ? new Intl.NumberFormat('id-ID').format(value) : '';
        });
    </script>
</body>
</html>
