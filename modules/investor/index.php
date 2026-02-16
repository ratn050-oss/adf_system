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
    
    $projects = $db->query("
        SELECT id,
               $name_col,
               $code_col,
               $budget_col,
               $desc_col,
               $status_col,
               created_at,
               0 as total_expenses,
               0 as expense_count
        FROM projects
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback: minimal query
    try {
        $projects = $db->query("
            SELECT * FROM projects
            ORDER BY created_at DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        $projects = [];
    }
}

$totalProjects = count($projects);

// Calculate totals
$totalInvestors = count($investors);
$totalCapital = 0;
foreach ($investors as $inv) {
    // Use the flexible field name we calculated in the query
    $totalCapital += $inv['total_capital'] ?? 0;
}

// ====== CHART DATA ======
$chart_budget_vs_expense = [];
$chart_contractor_pie = [];
$total_all_expenses = 0;

foreach ($projects as &$proj) {
    $pid = $proj['id'];
    // Get real expense total per project
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM project_expenses WHERE project_id = ?");
        $stmt->execute([$pid]);
        $proj['total_expenses'] = floatval($stmt->fetchColumn());
    } catch (Exception $e) { $proj['total_expenses'] = 0; }

    // Get salary + division totals
    $proj['total_gaji'] = 0;
    $proj['total_divisi'] = 0;
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_salary),0) FROM project_salaries WHERE project_id = ?");
        $stmt->execute([$pid]);
        $proj['total_gaji'] = floatval($stmt->fetchColumn());
    } catch (Exception $e) {}
    try {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM project_division_expenses WHERE project_id = ?");
        $stmt->execute([$pid]);
        $proj['total_divisi'] = floatval($stmt->fetchColumn());
    } catch (Exception $e) {}

    $proj['grand_expenses'] = $proj['total_expenses'] + $proj['total_gaji'] + $proj['total_divisi'];
    $total_all_expenses += $proj['grand_expenses'];

    $chart_budget_vs_expense[] = [
        'name' => $proj['project_name'] ?? 'Project',
        'budget' => floatval($proj['budget_idr'] ?? 0),
        'expense' => $proj['grand_expenses'],
    ];

    // Expenses per contractor for this project
    try {
        $expCols = [];
        try {
            $stmt = $db->query("DESCRIBE project_expenses");
            $expCols = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Exception $e) {}

        if (in_array('division_name', $expCols)) {
            $stmt = $db->prepare("SELECT division_name, SUM(amount) as total FROM project_expenses WHERE project_id = ? AND division_name IS NOT NULL AND division_name != '' GROUP BY division_name");
            $stmt->execute([$pid]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $dn = $row['division_name'];
                if (!isset($chart_contractor_pie[$dn])) $chart_contractor_pie[$dn] = 0;
                $chart_contractor_pie[$dn] += floatval($row['total']);
            }
        }
    } catch (Exception $e) {}

    try {
        $stmt = $db->prepare("SELECT division_name, SUM(amount) as total FROM project_division_expenses WHERE project_id = ? GROUP BY division_name");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dn = $row['division_name'];
            if (!isset($chart_contractor_pie[$dn])) $chart_contractor_pie[$dn] = 0;
            $chart_contractor_pie[$dn] += floatval($row['total']);
        }
    } catch (Exception $e) {}
}
unset($proj);

// Sort contractor pie by value descending
arsort($chart_contractor_pie);

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

.btn-kas {
    background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-kas:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37,99,235,0.4);
}

.btn-edit {
    background: linear-gradient(135deg, #f59e0b, #d97706) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-edit:hover {
    background: linear-gradient(135deg, #d97706, #b45309) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(217,119,6,0.4);
}

.btn-hapus {
    background: linear-gradient(135deg, #ef4444, #dc2626) !important;
    color: white !important;
    border: none !important;
    font-weight: 600;
}
.btn-hapus:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(239,68,68,0.4);
}

/* Charts Section */
.charts-section {
    margin-bottom: 3rem;
}

.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
}

.chart-card {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
}

.chart-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
}

.chart-card.chart-pie::after {
    background: linear-gradient(90deg, #6366f1, #ec4899);
}

.chart-card.chart-bar::after {
    background: linear-gradient(90deg, #10b981, #3b82f6);
}

.chart-card h3 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--text-primary);
    margin: 0 0 .2rem 0;
    display: flex;
    align-items: center;
    gap: .5rem;
}

.chart-card .chart-sub {
    font-size: .8rem;
    color: var(--text-muted);
    margin-bottom: 1rem;
}

.chart-card canvas {
    max-height: 300px;
}

.chart-empty {
    text-align: center;
    padding: 2rem;
    color: var(--text-muted);
    font-size: .9rem;
}

@media (max-width: 900px) {
    .charts-grid { grid-template-columns: 1fr; }
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
    min-height: 250px;
    overflow: visible;
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
    gap: 0.3rem;
    margin-top: 0.75rem;
    flex-wrap: wrap;
    width: 100%;
    z-index: 10;
    position: relative;
}

.project-card .btn-sm {
    padding: 0.45rem 0.7rem;
    font-size: 0.72rem;
    border-radius: 6px;
    flex: 1;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.2rem;
    white-space: nowrap;
    line-height: 1;
    height: 30px;
    cursor: pointer;
    transition: all 0.2s ease;
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
        <div class="summary-card">
            <div class="label">💸 Total Pengeluaran</div>
            <div class="value" style="color:#d97706">Rp <?= number_format($total_all_expenses, 0, ',', '.') ?></div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="section-header">
            <h2 class="section-title">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M18 20V10M12 20V4M6 20v-6"/>
                </svg>
                Grafik Pengeluaran
            </h2>
        </div>
        <div class="charts-grid">
            <div class="chart-card chart-pie">
                <h3>🍩 Pengeluaran per Kontraktor</h3>
                <div class="chart-sub">Distribusi biaya berdasarkan kontraktor / divisi</div>
                <?php if (empty($chart_contractor_pie)): ?>
                    <div class="chart-empty">Belum ada data pengeluaran per kontraktor.<br>Pilih kontraktor saat catat pengeluaran di Buku Kas.</div>
                <?php else: ?>
                    <canvas id="pieChart"></canvas>
                <?php endif; ?>
            </div>
            <div class="chart-card chart-bar">
                <h3>📊 Budget vs Pengeluaran</h3>
                <div class="chart-sub">Perbandingan budget dan realisasi per projek</div>
                <?php if (empty($chart_budget_vs_expense)): ?>
                    <div class="chart-empty">Belum ada data projek.</div>
                <?php else: ?>
                    <canvas id="barChart"></canvas>
                <?php endif; ?>
            </div>
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
                            <div class="meta-value" style="color:#d97706">Rp <?= number_format($project['grand_expenses'] ?? $project['total_expenses'] ?? 0, 0, ',', '.') ?></div>
                        </div>
                        <div class="meta-item">
                            <span>Sisa</span>
                            <?php $sisa = ($project['budget_idr'] ?? 0) - ($project['grand_expenses'] ?? 0); ?>
                            <div class="meta-value" style="color:<?= $sisa >= 0 ? '#059669' : '#dc2626' ?>">Rp <?= number_format($sisa, 0, ',', '.') ?></div>
                        </div>
                    </div>
                    <div class="project-actions">
                        <button class="btn btn-sm btn-kas" onclick="event.stopPropagation(); goToProjectLedger(<?= $project['id'] ?>)">
                            📒 Buku Kas
                        </button>
                        <button class="btn btn-sm btn-edit" onclick="event.stopPropagation(); editProject(<?= $project['id'] ?>)">
                            ✏️ Edit
                        </button>
                        <button class="btn btn-sm btn-hapus" onclick="event.stopPropagation(); deleteProject(<?= $project['id'] ?>, '<?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?>')">
                            🗑️ Hapus
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
// ====== CHART RENDERING ======
document.addEventListener('DOMContentLoaded', function() {
    const darkMode = document.documentElement.classList.contains('dark') || document.body.classList.contains('dark-mode');
    const textColor = darkMode ? '#ccc' : '#666';
    const gridColor = darkMode ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';

    // Pie Chart - Pengeluaran per Kontraktor
    <?php if (!empty($chart_contractor_pie)): ?>
    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
        const pieColors = ['#6366f1','#ec4899','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ef4444','#14b8a6','#f97316','#06b6d4','#84cc16','#e879f9'];
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_keys($chart_contractor_pie)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($chart_contractor_pie)) ?>,
                    backgroundColor: pieColors.slice(0, <?= count($chart_contractor_pie) ?>),
                    borderWidth: 2,
                    borderColor: darkMode ? '#1e1e2e' : '#fff',
                    hoverOffset: 8,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: textColor, padding: 12, font: { size: 12, weight: 600 }, usePointStyle: true, pointStyle: 'circle' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                const val = ctx.parsed;
                                const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                                const pct = ((val/total)*100).toFixed(1);
                                return ctx.label + ': Rp ' + val.toLocaleString('id-ID') + ' (' + pct + '%)';
                            }
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    // Bar Chart - Budget vs Pengeluaran
    <?php if (!empty($chart_budget_vs_expense)): ?>
    const barCtx = document.getElementById('barChart');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($chart_budget_vs_expense, 'name')) ?>,
                datasets: [
                    {
                        label: 'Budget',
                        data: <?= json_encode(array_column($chart_budget_vs_expense, 'budget')) ?>,
                        backgroundColor: 'rgba(59,130,246,0.7)',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.6,
                    },
                    {
                        label: 'Pengeluaran',
                        data: <?= json_encode(array_column($chart_budget_vs_expense, 'expense')) ?>,
                        backgroundColor: 'rgba(245,158,11,0.7)',
                        borderColor: '#f59e0b',
                        borderWidth: 1,
                        borderRadius: 6,
                        barPercentage: 0.6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: textColor, font: { size: 12, weight: 600 }, usePointStyle: true, pointStyle: 'circle', padding: 16 }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ctx.dataset.label + ': Rp ' + ctx.parsed.y.toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { color: textColor, font: { size: 11 } },
                        grid: { display: false }
                    },
                    y: {
                        ticks: {
                            color: textColor,
                            font: { size: 11 },
                            callback: function(val) {
                                if (val >= 1e9) return 'Rp ' + (val/1e9).toFixed(1) + 'M';
                                if (val >= 1e6) return 'Rp ' + (val/1e6).toFixed(0) + 'Jt';
                                if (val >= 1e3) return 'Rp ' + (val/1e3).toFixed(0) + 'Rb';
                                return 'Rp ' + val;
                            }
                        },
                        grid: { color: gridColor }
                    }
                }
            }
        });
    }
    <?php endif; ?>
});

// ====== EXISTING FUNCTIONS ======
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

async function deleteProject(projectId, projectName) {
    if (!confirm(`Apakah Anda yakin ingin menghapus projek "${projectName}"?\n\nSemua data pengeluaran projek ini akan ikut terhapus!`)) {
        return;
    }
    
    try {
        const response = await fetch('<?= BASE_URL ?>/api/investor-project-delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'project_id=' + encodeURIComponent(projectId)
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(result.message || 'Projek berhasil dihapus');
            location.reload(); // Refresh page to update project list
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
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
