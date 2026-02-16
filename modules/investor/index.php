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

// Get all projects for project management section
try {
    $projects = $db->query("
        SELECT p.*,
               COALESCE(SUM(pe.amount), 0) as total_expenses,
               COUNT(pe.id) as expense_count
        FROM projects p
        LEFT JOIN project_expenses pe ON p.id = pe.project_id
        GROUP BY p.id
        ORDER BY p.created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $projects = [];
}

$totalProjects = count($projects);

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
* {
    box-sizing: border-box;
}

.investor-page {
    padding: 2rem;
    max-width: 1600px;
    margin: 0 auto;
    background: var(--bg-primary);
    min-height: 100vh;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3rem;
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
}

.header-actions {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
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
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

.btn-primary:hover {
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-success:hover {
    box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
}

.btn:active {
    transform: translateY(0);
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.summary-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1.75rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.summary-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.05), transparent);
    border-radius: 50%;
}

.summary-card:hover {
    border-color: rgba(99, 102, 241, 0.3);
    box-shadow: 0 8px 24px rgba(99, 102, 241, 0.1);
    transform: translateY(-4px);
}

.summary-card .label {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}

.summary-card .value {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-primary);
    line-height: 1.2;
}

.summary-card.highlight {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
    border-color: rgba(99, 102, 241, 0.3);
}

.summary-card.highlight .value {
    color: #6366f1;
}

/* Section Header */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 2.5rem 0 1.5rem 0;
    padding-bottom: 1rem;
    border-bottom: 2px solid rgba(99, 102, 241, 0.1);
}

.section-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.section-title svg {
    stroke: #6366f1;
    stroke-width: 2.5;
}

/* Investor List */
.investor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 1.5rem;
    margin-bottom: 3rem;
}

.investor-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1.5rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    gap: 1rem;
    position: relative;
    overflow: hidden;
}

.investor-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}

.investor-card:hover {
    border-color: rgba(99, 102, 241, 0.4);
    box-shadow: 0 12px 32px rgba(99, 102, 241, 0.15);
    transform: translateY(-6px);
}

.investor-card .header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
}

.investor-card .name {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.investor-card .contact {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
    word-break: break-word;
}

.investor-card .amount {
    text-align: right;
    font-size: 1.25rem;
    font-weight: 700;
    color: #10b981;
    background: rgba(16, 185, 129, 0.1);
    padding: 0.75rem 1rem;
    border-radius: 8px;
}

.investor-card .actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.investor-card .btn-sm {
    padding: 0.5rem 0.9rem;
    font-size: 0.75rem;
    border-radius: 8px;
    flex: 1;
    min-width: 80px;
    text-align: center;
    justify-content: center;
}

.btn-outline {
    background: transparent;
    border: 1.5px solid var(--border-color);
    color: var(--text-secondary);
    transition: all 0.2s ease;
}

.btn-outline:hover {
    background: rgba(99, 102, 241, 0.1);
    border-color: #6366f1;
    color: #6366f1;
}

/* Projects Section */
.projects-section {
    margin-bottom: 3rem;
}

.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}

.project-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1.5rem;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    gap: 1rem;
    position: relative;
    cursor: pointer;
}

.project-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, #f59e0b, #ec4899);
}

.project-card:hover {
    border-color: rgba(245, 158, 11, 0.4);
    box-shadow: 0 12px 32px rgba(245, 158, 11, 0.15);
    transform: translateY(-6px);
}

.project-card .project-name {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.project-card .project-code {
    font-size: 0.75rem;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.project-card .project-amount {
    font-size: 1.2rem;
    font-weight: 700;
    color: #f59e0b;
}

.project-card .project-meta {
    display: flex;
    justify-content: space-between;
    padding: 1rem 0;
    border-top: 1px solid var(--border-color);
    font-size: 0.85rem;
    color: var(--text-muted);
}

.project-card .meta-item {
    display: flex;
    flex-direction: column;
    align-items: center;
}

.project-card .meta-value {
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text-primary);
}

.project-card .project-actions {
    display: flex;
    gap: 0.5rem;
}

.project-card .btn-sm {
    padding: 0.5rem 0.9rem;
    font-size: 0.75rem;
    border-radius: 8px;
    flex: 1;
    text-align: center;
    justify-content: center;
}

.add-project-card {
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.05), rgba(236, 72, 153, 0.05));
    border: 2px dashed rgba(245, 158, 11, 0.3);
    border-radius: 14px;
    padding: 2rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    text-align: center;
    transition: all 0.3s ease;
    cursor: pointer;
}

.add-project-card:hover {
    border-color: rgba(245, 158, 11, 0.6);
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(236, 72, 153, 0.1));
    box-shadow: 0 8px 20px rgba(245, 158, 11, 0.15);
}

.add-project-card svg {
    width: 48px;
    height: 48px;
    stroke: #f59e0b;
    stroke-width: 2;
}

.add-project-card .text {
    font-weight: 600;
    color: var(--text-secondary);
}

/* History Table */
.history-section {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 2rem;
    margin-top: 3rem;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th,
.history-table td {
    padding: 1rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.history-table th {
    font-size: 0.8rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    background: rgba(99, 102, 241, 0.05);
}

.history-table td {
    font-size: 0.9rem;
    color: var(--text-primary);
}

.history-table tr:hover {
    background: rgba(99, 102, 241, 0.02);
}

.history-table .amount-cell {
    font-weight: 700;
    color: #10b981;
}

.history-table .date-cell {
    color: var(--text-muted);
    font-size: 0.85rem;
}

.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    color: var(--text-muted);
}

.empty-state svg {
    width: 64px;
    height: 64px;
    margin-bottom: 1rem;
    opacity: 0.3;
}

.empty-state p {
    font-size: 1rem;
    margin: 0;
}

/* Modal Styling */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.6);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-overlay.active {
    display: flex;
}

.modal-content {
    background: var(--bg-secondary);
    border-radius: 16px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    overflow-y: auto;
    animation: slideUp 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    padding: 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.75rem;
    color: var(--text-muted);
    cursor: pointer;
    transition: color 0.2s ease;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 6px;
}

.modal-close:hover {
    color: var(--text-primary);
    background: rgba(99, 102, 241, 0.1);
}

.modal-body {
    padding: 2rem;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 0.6rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.85rem 1rem;
    border: 1.5px solid var(--border-color);
    border-radius: 10px;
    font-size: 0.95rem;
    background: var(--bg-primary);
    color: var(--text-primary);
    transition: all 0.2s ease;
    font-family: inherit;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

.modal-footer {
    padding: 1.5rem;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
}

.btn-secondary {
    background: var(--bg-tertiary);
    color: var(--text-secondary);
    border: 1.5px solid var(--border-color);
}

.btn-secondary:hover {
    background: rgba(99, 102, 241, 0.05);
    border-color: #6366f1;
    color: #6366f1;
}

@media (max-width: 768px) {
    .investor-page {
        padding: 1rem;
    }
    
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
    }
    
    .header-actions .btn {
        flex: 1;
    }
    
    .investor-grid {
        grid-template-columns: 1fr;
    }
    
    .projects-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="investor-page">
    <!-- Header -->
    <div class="page-header">
        <h1>
            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Investor & Projek
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
            <div class="label">📊 Jumlah Investor</div>
            <div class="value"><?= $totalInvestors ?></div>
        </div>
        <div class="summary-card highlight">
            <div class="label">💰 Total Modal</div>
            <div class="value">Rp <?= number_format($totalCapital, 0, ',', '.') ?></div>
        </div>
        <div class="summary-card">
            <div class="label">🏗️ Projek Aktif</div>
            <div class="value"><?= $totalProjects ?></div>
        </div>
    </div>

    <!-- Projects Section -->
    <div class="projects-section">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M9 11l3 3L22 4"/>
                    <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Manajemen Projek
            </h2>
            <button class="btn btn-primary" onclick="openAddProjectModal()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Tambah Projek
            </button>
        </div>

        <div class="projects-grid">
            <?php if (empty($projects)): ?>
                <div class="add-project-card" onclick="openAddProjectModal()">
                    <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="9" y1="9" x2="15" y2="9"/>
                        <line x1="9" y1="15" x2="15" y2="15"/>
                    </svg>
                    <div class="text">Buat Projek Pertama</div>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">Kelola budget dan pengeluaran projek dengan buku kas</p>
                </div>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                <div class="project-card" onclick="goToProjectLedger(<?= $project['id'] ?>)">
                    <div>
                        <div class="project-name"><?= htmlspecialchars($project['project_name'] ?? 'N/A') ?></div>
                        <div class="project-code">
                            <?php 
                            $code = $project['project_code'] ?? 'PROJ-' . str_pad($project['id'], 4, '0', STR_PAD_LEFT);
                            echo htmlspecialchars($code);
                            ?>
                        </div>
                    </div>
                    <div class="project-amount">
                        Rp <?= number_format($project['budget_idr'] ?? 0, 0, ',', '.') ?>
                    </div>
                    <div class="project-meta">
                        <div class="meta-item">
                            <span>Pengeluaran</span>
                            <div class="meta-value">Rp <?= number_format($project['total_expenses'] ?? 0, 0, ',', '.') ?></div>
                        </div>
                        <div class="meta-item">
                            <span>Transaksi</span>
                            <div class="meta-value"><?= $project['expense_count'] ?? 0 ?></div>
                        </div>
                    </div>
                    <div class="project-actions">
                        <button class="btn btn-sm btn-outline" onclick="event.stopPropagation(); goToProjectLedger(<?= $project['id'] ?>)">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M9 12h6m-6 4h6M9 8h6m-13 9a10 10 0 1120 0 10 10 0 01-20 0z"/>
                            </svg>
                            Buku Kas
                        </button>
                        <button class="btn btn-sm btn-outline" onclick="event.stopPropagation(); editProject(<?= $project['id'] ?>)">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                                <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Edit
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="add-project-card" onclick="openAddProjectModal()">
                    <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <div class="text">Tambah Projek Baru</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Investor List -->
    <div class="section-header">
        <h2 class="section-title">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Daftar Investor
        </h2>
    </div>
    
    <div class="investor-grid">
        <?php if (empty($investors)): ?>
            <div class="empty-state">
                <svg width="64" height="64" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                </svg>
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
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                        Setoran
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="viewHistory(<?= $investor['id'] ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10"/>
                            <polyline points="12,6 12,12 16,14"/>
                        </svg>
                        History
                    </button>
                    <button class="btn btn-sm btn-outline" onclick="editInvestor(<?= $investor['id'] ?>)">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
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
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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

<!-- Modal: Add Project -->
<div class="modal-overlay" id="addProjectModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Tambah Projek Baru</h3>
            <button class="modal-close" onclick="closeModal('addProjectModal')">&times;</button>
        </div>
        <form id="addProjectForm" onsubmit="saveProject(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Projek *</label>
                    <input type="text" name="project_name" required placeholder="Nama projek">
                </div>
                <div class="form-group">
                    <label>Kode Projek</label>
                    <input type="text" name="project_code" placeholder="PROJ-001">
                </div>
                <div class="form-group">
                    <label>Budget (Rp) *</label>
                    <input type="number" name="budget_idr" required placeholder="0" min="1">
                </div>
                <div class="form-group">
                    <label>Deskripsi</label>
                    <textarea name="description" rows="2" placeholder="Deskripsi projek..."></textarea>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" required>
                        <option value="planning">Perencanaan</option>
                        <option value="ongoing" selected>Berjalan</option>
                        <option value="on_hold">Tunda</option>
                        <option value="completed">Selesai</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addProjectModal')">Batal</button>
                <button type="submit" class="btn btn-primary">Simpan Projek</button>
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

function openAddProjectModal() {
    document.getElementById('addProjectForm').reset();
    document.getElementById('addProjectModal').classList.add('active');
}

async function saveProject(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-project-save.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('Projek berhasil ditambahkan');
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Gagal menyimpan'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}

function goToProjectLedger(projectId) {
    // Go to investor ledger page with project selected
    window.location.href = '<?= BASE_URL ?>/modules/investor/ledger.php?project_id=' + projectId;
}

function editProject(projectId) {
    // TODO: Implement edit project modal
    alert('Fitur edit proyek akan segera tersedia');
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
