<?php
// modules/payroll/process.php
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

// Payroll requires module check
if (!isModuleEnabled('payroll')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$pageTitle = 'Proses Gaji';
$pageSubtitle = 'Hitung gaji karyawan per periode';

// 1. Check or Create Period
$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');
$months = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

// Get or Create Draft Period
$period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);

if (!$period && isset($_POST['create_period'])) {
    try {
        $label = $months[$month] . ' ' . $year;
        $db->query("INSERT INTO payroll_periods (period_month, period_year, period_label, status, created_by) VALUES (?, ?, ?, 'draft', ?)", 
                  [$month, $year, $label, $_SESSION['user_id']]);
        $period = $db->fetchOne("SELECT * FROM payroll_periods WHERE period_month = ? AND period_year = ?", [$month, $year]);
        
        // Auto-populate slips from active employees
        $employees = $db->fetchAll("SELECT * FROM payroll_employees WHERE is_active = 1");
        foreach ($employees as $emp) {
            $db->query("INSERT INTO payroll_slips (period_id, employee_id, employee_name, position, base_salary) VALUES (?, ?, ?, ?, ?)",
                      [$period['id'], $emp['id'], $emp['full_name'], $emp['position'], $emp['base_salary']]);
        }
        
        setFlash('success', 'Periode gaji berhasil dibuat');
        header("Location: process.php?month=$month&year=$year");
        exit;
    } catch (PDOException $e) {
        setFlash('error', 'Gagal membuat periode: ' . $e->getMessage());
    }
}

// 2. Load Slips for this Period
$slips = [];
if ($period) {
    $slips = $db->fetchAll("
        SELECT s.*, e.employee_code, e.department 
        FROM payroll_slips s 
        JOIN payroll_employees e ON s.employee_id = e.id 
        WHERE s.period_id = ?
        ORDER BY s.employee_name ASC", 
        [$period['id']]
    );
}

// 3. Handle Update Slip (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    $slip_id = $_POST['slip_id'];
    
    // Components
    $base_salary = (float)$_POST['base_salary'];
    $overtime_hours = (float)$_POST['overtime_hours'];
    $incentive = (float)$_POST['incentive'];
    $allowance = (float)$_POST['allowance'];
    $bonus = (float)$_POST['bonus'];
    $other = (float)$_POST['other_income'];
    
    // Deductions
    $loan = (float)$_POST['deduction_loan'];
    $absence = (float)$_POST['deduction_absence'];
    $tax = (float)$_POST['deduction_tax'];
    $bpjs = (float)$_POST['deduction_bpjs'];
    $ded_other = (float)$_POST['deduction_other'];
    
    // Calculate Overtime: (Base / 200) * Hours
    $overtime_rate = $base_salary / 200;
    $overtime_amount = $overtime_hours * $overtime_rate;
    
    // Calculate Totals
    $total_earnings = $base_salary + $overtime_amount + $incentive + $allowance + $bonus + $other;
    $total_deductions = $loan + $absence + $tax + $bpjs + $ded_other;
    $net_salary = $total_earnings - $total_deductions;
    
    try {
        $sql = "UPDATE payroll_slips SET 
                base_salary = ?, overtime_hours = ?, overtime_rate = ?, overtime_amount = ?,
                incentive = ?, allowance = ?, bonus = ?, other_income = ?,
                deduction_loan = ?, deduction_absence = ?, deduction_tax = ?, deduction_bpjs = ?, deduction_other = ?,
                total_earnings = ?, total_deductions = ?, net_salary = ?
                WHERE id = ?";
        
        $db->query($sql, [
            $base_salary, $overtime_hours, $overtime_rate, $overtime_amount,
            $incentive, $allowance, $bonus, $other,
            $loan, $absence, $tax, $bpjs, $ded_other,
            $total_earnings, $total_deductions, $net_salary,
            $slip_id
        ]);
        
        // Recalculate Period Totals
        $period_id = $period['id'];
        $db->query("UPDATE payroll_periods p
                    LEFT JOIN (
                        SELECT period_id, SUM(total_earnings) as gross, SUM(total_deductions) as ded, SUM(net_salary) as net, COUNT(id) as cnt 
                        FROM payroll_slips 
                        WHERE period_id = ?
                    ) s ON p.id = s.period_id
                    SET p.total_gross = s.gross, p.total_deductions = s.ded, p.total_net = s.net, p.total_employees = s.cnt
                    WHERE p.id = ?", [$period_id, $period_id]);
                    
        echo json_encode(['status' => 'success', 'net_salary' => $net_salary]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// 4. Handle Finalize Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_period'])) {
    $db->query("UPDATE payroll_periods SET status = 'submitted', submitted_at = NOW(), submitted_by = ? WHERE id = ?", 
              [$_SESSION['user_id'], $period['id']]);
    setFlash('success', 'Gaji diajukan ke Owner');
    header("Location: process.php?month=$month&year=$year");
    exit;
}

include '../../includes/header.php';
?>

<style>
/* Modern compact table style for payroll */
.payroll-table th { font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; background: var(--bg-secondary); }
.payroll-table td { vertical-align: middle; padding: 0.75rem 0.5rem; }
.input-compact { border: 1px solid transparent; background: transparent; width: 100%; padding: 4px; font-size: 0.9rem; border-radius: 4px; transition: all 0.2s; text-align: right; }
.input-compact:focus { border-color: var(--primary-color); background: var(--bg-secondary); outline: none; }
.input-compact:hover { background: var(--bg-tertiary); }
.sticky-col { position: sticky; left: 0; background: var(--bg-secondary); z-index: 10; border-right: 1px solid var(--border-color); }
.sticky-header { position: sticky; top: 0; z-index: 20; }
.row-active { background-color: rgba(var(--primary-rgb), 0.05); }
.total-cell { font-weight: 700; color: var(--primary-color); }
</style>

<div class="main-content">
    
    <!-- Header & Filter -->
    <div class="header-container fade-in-up">
        <div class="header-content">
            <h1 class="page-title">Proses Gaji</h1>
            <p class="page-subtitle">Hitung gaji bulanan karyawan</p>
        </div>
        <div class="header-actions">
            <form method="GET" class="d-flex gap-2">
                <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach($months as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $k == $month ? 'selected' : ''; ?>><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php for($y = 2024; $y <= date('Y')+1; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Period Status Control -->
    <?php if (!$period): ?>
        <div class="empty-state fade-in-up">
            <i data-feather="calendar" class="empty-icon"></i>
            <h3>Periode Belum Dibuat</h3>
            <p>Belum ada data gaji untuk periode <?php echo $months[$month] . ' ' . $year; ?></p>
            <form method="POST">
                <input type="hidden" name="create_period" value="1">
                <button type="submit" class="btn btn-primary mt-3">Buat Periode Baru</button>
            </form>
        </div>
    <?php else: ?>
        
        <!-- Status Bar -->
        <div class="card mb-3 fade-in-up">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-<?php echo $period['status'] == 'draft' ? 'secondary' : ($period['status'] == 'submitted' ? 'warning' : 'success'); ?> px-3 py-2 text-uppercase">
                        <?php echo $period['status']; ?>
                    </span>
                    <div>
                        <small class="text-muted d-block">Total Gaji Bersih</small>
                        <h4 class="mb-0 text-success">Rp <?php echo number_format($period['total_net'], 0, ',', '.'); ?></h4>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <?php if($period['status'] == 'draft'): ?>
                        <form method="POST" onsubmit="return confirm('Ajukan gaji ini ke Owner? Data tidak bisa diubah setelah diajukan.')">
                            <input type="hidden" name="submit_period" value="1">
                            <button type="submit" class="btn btn-success">
                                <i data-feather="send"></i> Ajukan ke Owner
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="print-submission.php?period_id=<?php echo $period['id']; ?>" target="_blank" class="btn btn-outline-secondary">
                        <i data-feather="printer"></i> Cetak Laporan
                    </a>
                </div>
            </div>
        </div>

        <!-- Working Table -->
        <div class="card fade-in-up" style="animation-delay: 0.1s">
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                    <table class="table payroll-table mb-0">
                        <thead class="sticky-header">
                            <tr>
                                <th class="sticky-col" style="min-width: 200px;">Karyawan</th>
                                <th class="text-end" style="width: 120px;">Gaji Pokok</th>
                                <th class="text-center" style="width: 80px;">Jam Lembur</th>
                                <th class="text-end" style="width: 120px;">Nominal Lembur</th>
                                <th class="text-end" style="width: 120px;">Insentif</th>
                                <th class="text-end" style="width: 120px;">Tunjangan</th>
                                <th class="text-end" style="width: 120px;">Bonus/Lain</th>
                                <th class="text-end text-danger" style="width: 120px;">Potongan</th>
                                <th class="text-end fw-bold" style="width: 140px;">Gaji Bersih</th>
                                <th class="text-center" style="width: 50px;">#</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($slips as $slip): ?>
                            <tr id="row-<?php echo $slip['id']; ?>">
                                <td class="sticky-col">
                                    <div class="fw-bold"><?php echo htmlspecialchars($slip['employee_name']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($slip['position']); ?></small>
                                </td>
                                
                                <!-- Editable Inputs -->
                                <td>
                                    <input type="text" class="input-compact currency-input" 
                                           value="<?php echo number_format($slip['base_salary'], 0, ',', '.'); ?>"
                                           data-field="base_salary" data-id="<?php echo $slip['id']; ?>" readonly>
                                </td>
                                
                                <!-- Overtime Logic -->
                                <td>
                                    <input type="number" class="input-compact text-center bg-warning-subtle" 
                                           value="<?php echo $slip['overtime_hours']; ?>" step="0.5"
                                           data-field="overtime_hours" data-id="<?php echo $slip['id']; ?>" 
                                           onchange="calculateRow(<?php echo $slip['id']; ?>)">
                                </td>
                                <td class="text-end text-muted">
                                    <span id="ot-amount-<?php echo $slip['id']; ?>">
                                        <?php echo number_format($slip['overtime_amount'], 0, ',', '.'); ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <input type="text" class="input-compact currency-input" 
                                           value="<?php echo number_format($slip['incentive'], 0, ',', '.'); ?>"
                                           data-field="incentive" data-id="<?php echo $slip['id']; ?>"
                                           onchange="calculateRow(<?php echo $slip['id']; ?>)">
                                </td>
                                <td>
                                    <input type="text" class="input-compact currency-input" 
                                           value="<?php echo number_format($slip['allowance'], 0, ',', '.'); ?>"
                                           data-field="allowance" data-id="<?php echo $slip['id']; ?>"
                                           onchange="calculateRow(<?php echo $slip['id']; ?>)">
                                </td>
                                <td>
                                    <!-- Combined Bonus + Others for display compact, detail in modal if needed -->
                                    <input type="text" class="input-compact currency-input" 
                                           value="<?php echo number_format($slip['bonus'] + $slip['other_income'], 0, ',', '.'); ?>"
                                           data-field="other_income" data-id="<?php echo $slip['id']; ?>"
                                           onchange="calculateRow(<?php echo $slip['id']; ?>)">
                                </td>
                                <td>
                                    <!-- Combined Deductions -->
                                    <input type="text" class="input-compact currency-input text-danger" 
                                           value="<?php echo number_format($slip['total_deductions'], 0, ',', '.'); ?>"
                                           data-field="total_deductions" data-id="<?php echo $slip['id']; ?>"
                                           readonly onclick="openDeductionModal(<?php echo htmlspecialchars(json_encode($slip)); ?>)">
                                </td>
                                
                                <td class="text-end fw-bold text-success">
                                    <span id="net-<?php echo $slip['id']; ?>">
                                        <?php echo number_format($slip['net_salary'], 0, ',', '.'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-icon btn-ghost-secondary" onclick='openDeductionModal(<?php echo json_encode($slip); ?>)'>
                                        <i data-feather="edit-2" style="width: 14px;"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Detailed Deduction Modal -->
<div class="modal" id="deductionModal" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rincian Potongan: <span id="modalEmpName"></span></h5>
                <button type="button" class="btn-close" onclick="closeDeductionModal()"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalSlipId">
                <div class="form-group mb-2">
                    <label>Kasbon / Pinjaman</label>
                    <input type="text" class="form-control currency-input modal-input" id="modalLoan">
                </div>
                <div class="form-group mb-2">
                    <label>Absensi / Alpha</label>
                    <input type="text" class="form-control currency-input modal-input" id="modalAbsence">
                </div>
                <div class="form-group mb-2">
                    <label>BPJS</label>
                    <input type="text" class="form-control currency-input modal-input" id="modalBpjs">
                </div>
                <div class="form-group mb-2">
                    <label>Lain-lain</label>
                    <input type="text" class="form-control currency-input modal-input" id="modalOther">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="saveDeduction()">Simpan</button>
            </div>
        </div>
    </div>
</div>
<div class="modal-backdrop" id="deductionBackdrop" style="display: none;"></div>

<script>
feather.replace();

// Format Currency Input
document.querySelectorAll('.currency-input').forEach(input => {
    input.addEventListener('keyup', function(e) {
        let val = this.value.replace(/\D/g, '');
        this.value = new Intl.NumberFormat('id-ID').format(val);
    });
});

function getVal(selector) {
    let el = document.querySelector(selector);
    if (!el) return 0;
    return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
}

function getValByRow(id, field) {
    let el = document.querySelector(`input[data-id="${id}"][data-field="${field}"]`);
    if (!el) return 0;
    
    // Special for overtime hours (decimal)
    if (field === 'overtime_hours') return parseFloat(el.value) || 0;
    
    return parseFloat(el.value.replace(/\./g, '').replace(/,/g, '')) || 0;
}

function calculateRow(id) {
    // Logic: Base Salary / 200 * Overtime Hours
    let base = getValByRow(id, 'base_salary');
    let hours = getValByRow(id, 'overtime_hours');
    let rate = base / 200;
    let otAmount = Math.round(hours * rate);
    
    // Update OT Amount Display
    document.getElementById(`ot-amount-${id}`).innerText = new Intl.NumberFormat('id-ID').format(otAmount);
    
    // Gather other incomes
    let incentive = getValByRow(id, 'incentive');
    let allowance = getValByRow(id, 'allowance');
    let other = getValByRow(id, 'other_income');
    
    // Deductions (read from hidden data or current input)
    let dedInput = document.querySelector(`input[data-id="${id}"][data-field="total_deductions"]`);
    let totalDed = parseFloat(dedInput.value.replace(/\./g, '').replace(/,/g, '')) || 0;
    
    // Calculate Net
    let totalEarn = base + otAmount + incentive + allowance + other; // bonus merged into other for compact view
    let net = totalEarn - totalDed;
    
    document.getElementById(`net-${id}`).innerText = new Intl.NumberFormat('id-ID').format(net);
    
    // Auto-save via Ajax
    saveRow(id);
}

function saveRow(id) {
    const data = new FormData();
    data.append('ajax_update', 1);
    data.append('slip_id', id);
    
    // Inputs
    data.append('base_salary', getValByRow(id, 'base_salary'));
    data.append('overtime_hours', getValByRow(id, 'overtime_hours'));
    data.append('incentive', getValByRow(id, 'incentive'));
    data.append('allowance', getValByRow(id, 'allowance'));
    data.append('bonus', 0); // hidden in this compact view
    data.append('other_income', getValByRow(id, 'other_income'));
    
    // Deductions (need to store these in data attributes for proper retrieval?)
    // For now, assuming they are managed via modal only, and total is displayed
    // To make this robust, we should store detailed deductions in data attributes of the row tr
    const row = document.getElementById(`row-${id}`);
    data.append('deduction_loan', row.getAttribute('data-loan') || 0);
    data.append('deduction_absence', row.getAttribute('data-absence') || 0);
    data.append('deduction_tax', 0);
    data.append('deduction_bpjs', row.getAttribute('data-bpjs') || 0);
    data.append('deduction_other', row.getAttribute('data-other') || 0);
    
    fetch('process.php', {
        method: 'POST',
        body: data
    }).then(res => res.json())
      .then(res => {
          if(res.status === 'success') {
              // visual feedback?
          }
      });
}

// Modal Logic for Deductions
function openDeductionModal(slip) {
    document.getElementById('modalSlipId').value = slip.id;
    document.getElementById('modalEmpName').innerText = slip.employee_name;
    
    // Set values
    setInputVal('modalLoan', slip.deduction_loan);
    setInputVal('modalAbsence', slip.deduction_absence);
    setInputVal('modalBpjs', slip.deduction_bpjs);
    setInputVal('modalOther', slip.deduction_other); // using deduction_other field
    
    document.getElementById('deductionBackdrop').style.display = 'block';
    document.getElementById('deductionModal').style.display = 'block';
}

function setInputVal(id, val) {
    document.getElementById(id).value = new Intl.NumberFormat('id-ID').format(val);
}

function closeDeductionModal() {
    document.getElementById('deductionBackdrop').style.display = 'none';
    document.getElementById('deductionModal').style.display = 'none';
}

function saveDeduction() {
    let id = document.getElementById('modalSlipId').value;
    let loan = getVal('#modalLoan');
    let abs = getVal('#modalAbsence');
    let bpjs = getVal('#modalBpjs');
    let other = getVal('#modalOther');
    
    let total = loan + abs + bpjs + other;
    
    // Update Row Attributes
    let row = document.getElementById(`row-${id}`);
    row.setAttribute('data-loan', loan);
    row.setAttribute('data-absence', abs);
    row.setAttribute('data-bpjs', bpjs);
    row.setAttribute('data-other', other);
    
    // Update Total Input Display
    let input = document.querySelector(`input[data-id="${id}"][data-field="total_deductions"]`);
    input.value = new Intl.NumberFormat('id-ID').format(total);
    
    closeDeductionModal();
    calculateRow(id); // Recalculate Net and Save
}

// Init row data attributes on load
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach($slips as $s): ?>
    var r = document.getElementById('row-<?php echo $s['id']; ?>');
    if(r) {
        r.setAttribute('data-loan', <?php echo $s['deduction_loan']; ?>);
        r.setAttribute('data-absence', <?php echo $s['deduction_absence']; ?>);
        r.setAttribute('data-bpjs', <?php echo $s['deduction_bpjs']; ?>);
        r.setAttribute('data-other', <?php echo $s['deduction_other']; ?>);
        
        // Initial calc to ensure consistency
        // calculateRow(<?php echo $s['id']; ?>); 
    }
    <?php endforeach; ?>
});

</script>

<?php include '../../includes/footer.php'; ?>
