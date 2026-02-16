<?php
/**
 * INVESTOR LEDGER - Buku Kas Pengeluaran Investor
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
$base_path = dirname(dirname(dirname(__FILE__)));

require_once $base_path . '/config/config.php';
require_once $base_path . '/config/database.php';
require_once $base_path . '/includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
} else {
    $base_url = BASE_URL;
}

$db = Database::getInstance()->getConnection();

// Get selected project
$project_id = intval($_GET['project_id'] ?? 0);
$project = null;
$expenses = [];
$categories = [];

if ($project_id) {
    try {
        // Check what columns actually exist
        $stmt = $db->query("DESCRIBE projects");
        $columnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($columnsInfo, 'Field');
        
        // Build flexible SELECT based on available columns
        $name_col = 'COALESCE(';
        if (in_array('project_name', $columns)) $name_col .= 'project_name, ';
        if (in_array('name', $columns)) $name_col .= 'name, ';
        $name_col .= "'Project') as project_name";
        
        $code_col = 'COALESCE(';
        if (in_array('project_code', $columns)) $code_col .= 'project_code, ';
        if (in_array('code', $columns)) $code_col .= 'code, ';
        $code_col .= "CONCAT('PROJ-', LPAD(id, 4, '0'))) as project_code";
        
        $budget_col = 'COALESCE(';
        if (in_array('budget_idr', $columns)) $budget_col .= 'budget_idr, ';
        if (in_array('budget', $columns)) $budget_col .= 'budget, ';
        $budget_col .= '0) as budget_idr';
        
        $desc_col = 'COALESCE(';
        if (in_array('description', $columns)) $desc_col .= 'description, ';
        if (in_array('desc', $columns)) $desc_col .= 'desc, ';
        $desc_col .= "'') as description";
        
        $status_col = 'COALESCE(';
        if (in_array('status', $columns)) $status_col .= 'status, ';
        $status_col .= "'ongoing') as status";
        
        $stmt = $db->prepare("
            SELECT id,
                   $name_col,
                   $code_col,
                   $budget_col,
                   $desc_col,
                   $status_col,
                   created_at,
                   0 as total_expenses
            FROM projects
            WHERE id = ?
        ");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($project) {
            // Get expenses for this project
            try {
                $stmt = $db->prepare("
                    SELECT id, project_id, category_id, amount, description, 
                           COALESCE(expense_date, created_at) as expense_date, 
                           created_at
                    FROM project_expenses
                    WHERE project_id = ?
                    ORDER BY expense_date DESC, created_at DESC
                ");
                $stmt->execute([$project_id]);
                $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Calculate total expenses
                $total_exp = 0;
                foreach ($expenses as $exp) {
                    $total_exp += $exp['amount'] ?? 0;
                }
                $project['total_expenses'] = $total_exp;
            } catch (Exception $e) {
                $expenses = [];
                $project['total_expenses'] = 0;
            }
        }
    } catch (Exception $e) {
        error_log('Project fetch error: ' . $e->getMessage());
    }
}

// Get all projects
try {
    // Check what columns actually exist in projects table
    $stmt = $db->query("DESCRIBE projects");
    $columnsInfo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_column($columnsInfo, 'Field');
    
    // Build flexible SELECT based on available columns
    $name_col = 'COALESCE(';
    if (in_array('project_name', $columns)) $name_col .= 'project_name, ';
    if (in_array('name', $columns)) $name_col .= 'name, ';
    $name_col .= "'Project') as project_name";
    
    $code_col = 'COALESCE(';
    if (in_array('project_code', $columns)) $code_col .= 'project_code, ';
    if (in_array('code', $columns)) $code_col .= 'code, ';
    $code_col .= "CONCAT('PROJ-', LPAD(id, 4, '0'))) as project_code";
    
    $budget_col = 'COALESCE(';
    if (in_array('budget_idr', $columns)) $budget_col .= 'budget_idr, ';
    if (in_array('budget', $columns)) $budget_col .= 'budget, ';
    $budget_col .= '0) as budget_idr';
    
    $desc_col = 'COALESCE(';
    if (in_array('description', $columns)) $desc_col .= 'description, ';
    if (in_array('desc', $columns)) $desc_col .= 'desc, ';
    $desc_col .= "'') as description";
    
    $status_col = 'COALESCE(';
    if (in_array('status', $columns)) $status_col .= 'status, ';
    $status_col .= "'ongoing') as status";
    
    $stmt = $db->query("
        SELECT id,
               $name_col,
               $code_col,
               $budget_col,
               $desc_col,
               $status_col,
               created_at
        FROM projects
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projects = [];
}

// Get categories
try {
    $stmt = $db->query("SELECT * FROM project_expense_categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categories = [];
}

$pageTitle = 'Buku Kas - Investor';
include $base_path . '/includes/header.php';
?>

<style>
.ledger-page {
    padding: 2rem;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 2px solid rgba(99, 102, 241, 0.1);
}

.page-header h1 {
    font-size: 2rem;
    font-weight: 700;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin: 0;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

.btn {
    padding: 0.75rem 1.5rem;
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.no-project {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--text-muted);
}

.project-selector {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 3rem;
}

.project-list {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1.5rem;
    max-height: 500px;
    overflow-y: auto;
}

.project-list h3 {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 1rem;
    color: var(--text-primary);
}

.project-item {
    padding: 1rem;
    border-radius: 8px;
    cursor: pointer;
    margin-bottom: 0.75rem;
    transition: all 0.2s ease;
    border-left: 3px solid transparent;
}

.project-item:hover {
    background: rgba(99, 102, 241, 0.05);
    border-left-color: #6366f1;
}

.project-item.active {
    background: rgba(99, 102, 241, 0.1);
    border-left-color: #6366f1;
}

.project-item .name {
    font-weight: 600;
    color: var(--text-primary);
}

.project-item .code {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.project-details {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 2rem;
}

.project-details h2 {
    font-size: 1.3rem;
    font-weight: 700;
    margin-bottom: 1.5rem;
    color: var(--text-primary);
}

.project-summary {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 2rem;
}

.summary-item {
    background: rgba(99, 102, 241, 0.05);
    padding: 1rem;
    border-radius: 10px;
    border-left: 3px solid #6366f1;
}

.summary-item .label {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
}

.summary-item .value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
}

.expense-form {
    background: rgba(99, 102, 241, 0.05);
    padding: 1.5rem;
    border-radius: 10px;
    margin-bottom: 2rem;
}

.form-row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 1rem;
    margin-bottom: 1rem;
    align-items: flex-end;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 0.8rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--text-secondary);
}

.form-group input,
.form-group select {
    padding: 0.75rem;
    border: 1.5px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-size: 0.9rem;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.expense-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 2rem;
}

.expense-table th,
.expense-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.expense-table th {
    background: rgba(99, 102, 241, 0.05);
    font-weight: 700;
    color: var(--text-muted);
    font-size: 0.8rem;
    text-transform: uppercase;
}

.expense-table td {
    color: var(--text-primary);
}

.expense-table .amount {
    font-weight: 700;
    color: #f59e0b;
}

.expense-table .date {
    color: var(--text-muted);
    font-size: 0.9rem;
}

.btn-delete {
    background: #ef4444;
    color: white;
    padding: 0.4rem 0.8rem;
    border-radius: 6px;
    font-size: 0.75rem;
    cursor: pointer;
    border: none;
    transition: all 0.2s ease;
}

.btn-delete:hover {
    background: #dc2626;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted);
}

@media (max-width: 768px) {
    .project-selector {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        grid-template-columns: 1fr;
    }
    
    .project-summary {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="ledger-page">
    <div class="page-header">
        <h1>
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <line x1="12" y1="2" x2="12" y2="22"/>
                <path d="M17 5H9.5a1.5 1.5 0 0 0 0 3h5a1.5 1.5 0 0 1 0 3h-5.5"/>
            </svg>
            Buku Kas Pengeluaran
        </h1>
        <div class="header-actions">
            <a href="<?= BASE_URL ?>/modules/investor/" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Kembali
            </a>
        </div>
    </div>

    <?php if (empty($projects)): ?>
        <div class="no-project">
            <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="opacity: 0.3; margin-bottom: 1rem;">
                <path d="M9 11l3 3L22 4"/>
                <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p>Belum ada projek. <a href="<?= BASE_URL ?>/modules/investor/" style="color: #6366f1; text-decoration: none;">Buat projek terlebih dahulu.</a></p>
        </div>
    <?php else: ?>
        <div class="project-selector">
            <div class="project-list">
                <h3>Pilih Projek</h3>
                <?php foreach ($projects as $proj): ?>
                <div class="project-item <?= $project_id == $proj['id'] ? 'active' : '' ?>" onclick="selectProject(<?= $proj['id'] ?>)">
                    <div class="name"><?= htmlspecialchars($proj['project_name']) ?></div>
                    <div class="code"><?= htmlspecialchars($proj['project_code'] ?? 'PROJ-' . str_pad($proj['id'], 4, '0', STR_PAD_LEFT)) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($project): ?>
            <div class="project-details">
                <h2><?= htmlspecialchars($project['project_name']) ?></h2>
                
                <div class="project-summary">
                    <div class="summary-item">
                        <div class="label">Budget</div>
                        <div class="value">Rp <?= number_format($project['budget_idr'] ?? 0, 0, ',', '.') ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Total Pengeluaran</div>
                        <div class="value">Rp <?= number_format($project['total_expenses'] ?? 0, 0, ',', '.') ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="label">Sisa Budget</div>
                        <div class="value">Rp <?= number_format(($project['budget_idr'] ?? 0) - ($project['total_expenses'] ?? 0), 0, ',', '.') ?></div>
                    </div>
                </div>

                <div class="expense-form">
                    <h3 style="margin-bottom: 1rem; color: var(--text-primary);">Catat Pengeluaran</h3>
                    <form id="expenseForm" onsubmit="saveExpense(event)">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Deskripsi Pengeluaran</label>
                                <input type="text" name="description" required placeholder="Nama item/pengeluaran">
                            </div>
                            <div class="form-group">
                                <label>Jumlah (Rp)</label>
                                <input type="number" name="amount" required placeholder="0" min="1">
                            </div>
                            <div class="form-group">
                                <label>Tanggal</label>
                                <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <button type="submit" class="btn btn-success">+ Catat</button>
                        </div>
                    </form>
                </div>

                <table class="expense-table">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Deskripsi</th>
                            <th>Jumlah</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                        <tr>
                            <td colspan="4" class="empty-state">Belum ada pengeluaran</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($expenses as $exp): ?>
                            <tr>
                                <td class="date"><?= date('d M Y', strtotime($exp['expense_date'] ?? $exp['created_at'])) ?></td>
                                <td><?= htmlspecialchars($exp['description'] ?? '-') ?></td>
                                <td class="amount">Rp <?= number_format($exp['amount'] ?? 0, 0, ',', '.') ?></td>
                                <td>
                                    <button class="btn-delete" onclick="deleteExpense(<?= $exp['id'] ?>, <?= $project['id'] ?>)">Hapus</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="no-project">
                <p>Pilih projek dari daftar di samping</p>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function selectProject(projectId) {
    window.location.href = '<?= BASE_URL ?>/modules/investor/ledger.php?project_id=' + projectId;
}

async function saveExpense(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    formData.append('project_id', <?= $project_id ?>);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-expense-save.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Gagal menyimpan'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function deleteExpense(expenseId, projectId) {
    if (!confirm('Hapus pengeluaran ini?')) return;
    
    const formData = new FormData();
    formData.append('expense_id', expenseId);
    formData.append('project_id', projectId);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-expense-delete.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Gagal menghapus'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}
</script>

<?php include $base_path . '/includes/footer.php'; ?>
