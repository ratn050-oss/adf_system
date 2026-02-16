<?php
/**
 * MODUL INVESTOR - Pencatatan Dana Investor
 * Terpisah dari projek, hanya mencatat setoran dari investor
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

// Define base_url for API calls
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
} else {
    $base_url = BASE_URL;
}

$db = Database::getInstance()->getConnection();

// Get all investors with their total deposits
try {
    $investors = $db->query("
        SELECT i.*, 
               COALESCE(SUM(CASE WHEN it.type = 'deposit' OR it.transaction_type = 'deposit' THEN it.amount ELSE 0 END), 0) as total_deposits,
               COALESCE(i.name, i.investor_name) as name,
               COALESCE(i.contact, i.contact_phone) as contact,
               COALESCE(i.total_capital, i.balance) as total_capital
        FROM investors i
        LEFT JOIN investor_transactions it ON i.id = it.investor_id
        GROUP BY i.id
        ORDER BY COALESCE(i.name, i.investor_name)
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if investor_transactions doesn't have the right structure
    try {
        $investors = $db->query("
            SELECT i.*, 
                   COALESCE(i.name, i.investor_name) as name,
                   COALESCE(i.contact, i.contact_phone) as contact,
                   COALESCE(i.total_capital, i.balance) as total_capital
            FROM investors i 
            ORDER BY COALESCE(i.name, i.investor_name)
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Absolute fallback
        $investors = $db->query("SELECT * FROM investors")->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get recent deposits
try {
    $recentDeposits = $db->query("
        SELECT it.*, 
               COALESCE(i.name, i.investor_name) as investor_name
        FROM investor_transactions it
        JOIN investors i ON it.investor_id = i.id
        WHERE it.type = 'deposit' OR it.transaction_type = 'deposit'
        ORDER BY it.created_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recentDeposits = [];
}

// Calculate totals
$totalInvestors = count($investors);
$totalCapital = 0;
foreach ($investors as $inv) {
    // Use the flexible field name we calculated in the query
    $totalCapital += $inv['total_capital'] ?? 0;
}

$pageTitle = 'Data Investor';
include $base_path . '/includes/header.php';
?>

<style>
.investor-page {
    padding: 1.5rem;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.page-header h1 {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.header-actions {
    display: flex;
    gap: 0.75rem;
}

.btn {
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    transition: all 0.2s;
}

.btn-primary {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-success:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.summary-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
}

.summary-card .label {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-bottom: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.summary-card .value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-primary);
}

.summary-card.highlight {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(139, 92, 246, 0.1));
    border-color: rgba(99, 102, 241, 0.3);
}

.summary-card.highlight .value {
    color: #6366f1;
}

/* Investor List */
.section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.investor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.investor-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    transition: all 0.2s;
}

.investor-card:hover {
    border-color: #6366f1;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.investor-card .header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}

.investor-card .name {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.investor-card .contact {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

.investor-card .amount {
    font-size: 1.25rem;
    font-weight: 700;
    color: #10b981;
}

.investor-card .actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

.investor-card .btn-sm {
    padding: 0.4rem 0.8rem;
    font-size: 0.75rem;
    border-radius: 6px;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-secondary);
}

.btn-outline:hover {
    background: var(--bg-tertiary);
    border-color: #6366f1;
    color: #6366f1;
}

/* History Table */
.history-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th,
.history-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.history-table th {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.history-table td {
    font-size: 0.9rem;
    color: var(--text-primary);
}

.history-table .amount-cell {
    font-weight: 600;
    color: #10b981;
}

.history-table .date-cell {
    color: var(--text-muted);
    font-size: 0.85rem;
}

.empty-state {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted);
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: var(--bg-secondary);
    border-radius: 16px;
    width: 100%;
    max-width: 500px;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-primary);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-muted);
    cursor: pointer;
}

.modal-body {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1.25rem;
}

.form-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 0.9rem;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.modal-footer {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.btn-secondary {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}
</style>

<div class="investor-page">
    <!-- Header -->
    <div class="page-header">
        <h1>
            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Data Investor
        </h1>
        <div class="header-actions">
            <button class="btn btn-success" onclick="openDepositModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Catat Setoran
            </button>
            <button class="btn btn-primary" onclick="openAddInvestorModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="8.5" cy="7" r="4"/>
                    <line x1="20" y1="8" x2="20" y2="14"/>
                    <line x1="23" y1="11" x2="17" y2="11"/>
                </svg>
                Tambah Investor
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="label">Jumlah Investor</div>
            <div class="value"><?= $totalInvestors ?></div>
        </div>
        <div class="summary-card highlight">
            <div class="label">Total Modal Terkumpul</div>
            <div class="value">Rp <?= number_format($totalCapital, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Investor List -->
    <h2 class="section-title">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
        </svg>
        Daftar Investor
    </h2>
    
    <div class="investor-grid">
        <?php if (empty($investors)): ?>
            <div class="empty-state">
                <p>Belum ada data investor</p>
            </div>
        <?php else: ?>
            <?php foreach ($investors as $investor): ?>
            <div class="investor-card">
                <div class="header">
                    <div>
                        <div class="name"><?= htmlspecialchars($investor['name'] ?? $investor['investor_name'] ?? '-') ?></div>
                        <div class="contact">
                            <?= htmlspecialchars($investor['contact'] ?? $investor['contact_phone'] ?? '-') ?>
                            <?php if (!empty($investor['email'])): ?>
                                • <?= htmlspecialchars($investor['email']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="amount">Rp <?= number_format($investor['total_capital'] ?? $investor['balance'] ?? 0, 0, ',', '.') ?></div>
                </div>
                <div class="actions">
                    <button class="btn btn-sm btn-outline" onclick="openDepositModal(<?= $investor['id'] ?>, '<?= htmlspecialchars($investor['name'] ?? $investor['investor_name'] ?? '') ?>')">
                        + Setoran
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="viewHistory(<?= $investor['id'] ?>)">
                        History
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="editInvestor(<?= $investor['id'] ?>)">
                        Edit
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Recent Deposits History -->
    <div class="history-section">
        <h2 class="section-title">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12,6 12,12 16,14"/>
            </svg>
            Riwayat Setoran Terbaru
        </h2>
        
        <?php if (empty($recentDeposits)): ?>
            <div class="empty-state">
                <p>Belum ada riwayat setoran</p>
            </div>
        <?php else: ?>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Investor</th>
                        <th>Keterangan</th>
                        <th>Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentDeposits as $deposit): ?>
                    <tr>
                        <td class="date-cell"><?= date('d M Y', strtotime($deposit['created_at'])) ?></td>
                        <td><?= htmlspecialchars($deposit['investor_name']) ?></td>
                        <td><?= htmlspecialchars($deposit['description'] ?? '-') ?></td>
                        <td class="amount-cell">Rp <?= number_format($deposit['amount'] ?? 0, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Add Investor -->
<div class="modal-overlay" id="addInvestorModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Tambah Investor Baru</h3>
            <button class="modal-close" onclick="closeModal('addInvestorModal')">&times;</button>
        </div>
        <form id="addInvestorForm" onsubmit="saveInvestor(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Investor *</label>
                    <input type="text" name="name" required placeholder="Nama lengkap investor">
                </div>
                <div class="form-group">
                    <label>No. Telepon</label>
                    <input type="text" name="phone" placeholder="08xxxx">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label>Catatan</label>
                    <textarea name="notes" rows="2" placeholder="Catatan tambahan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addInvestorModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Add Deposit -->
<div class="modal-overlay" id="depositModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Catat Setoran Investor</h3>
            <button class="modal-close" onclick="closeModal('depositModal')">&times;</button>
        </div>
        <form id="depositForm" onsubmit="saveDeposit(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Pilih Investor *</label>
                    <select name="investor_id" id="depositInvestorSelect" required>
                        <option value="">-- Pilih Investor --</option>
                        <?php foreach ($investors as $inv): ?>
                        <option value="<?= $inv['id'] ?>"><?= htmlspecialchars($inv['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Jumlah Setoran (Rp) *</label>
                    <input type="number" name="amount" required placeholder="0" min="1">
                </div>
                <div class="form-group">
                    <label>Tanggal Setoran</label>
                    <input type="date" name="deposit_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Keterangan</label>
                    <textarea name="description" rows="2" placeholder="Keterangan setoran..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('depositModal')">Batal</button>
                <button type="submit" class="btn btn-success">Simpan Setoran</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddInvestorModal() {
    document.getElementById('addInvestorForm').reset();
    document.getElementById('addInvestorModal').classList.add('active');
}

function openDepositModal(investorId = null, investorName = null) {
    document.getElementById('depositForm').reset();
    if (investorId) {
        document.getElementById('depositInvestorSelect').value = investorId;
    }
    document.getElementById('depositModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

async function saveInvestor(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-save.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('Investor berhasil ditambahkan');
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Gagal menyimpan'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

async function saveDeposit(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-deposit.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('Setoran berhasil dicatat');
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Gagal menyimpan'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

function viewHistory(investorId) {
    // TODO: Implement history view
    alert('Fitur history akan segera tersedia');
}

function editInvestor(investorId) {
    // TODO: Implement edit
    alert('Fitur edit akan segera tersedia');
}

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});
</script>

<?php include $base_path . '/includes/footer.php'; ?>
