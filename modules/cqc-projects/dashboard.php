<?php
/**
 * CQC Projects Dashboard
 * Dashboard untuk solar panel projects dengan grafik progress
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

// Get database connection untuk CQC
try {
    $pdo = getCQCDatabaseConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Get project statistics
$stats = [];
try {
    // Total projects
    $result = $pdo->query("SELECT COUNT(*) as count FROM cqc_projects");
    $stats['total'] = (int)$result->fetch()['count'];
    
    // By status
    $result = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM cqc_projects 
        GROUP BY status
    ");
    $stats['by_status'] = [];
    while ($row = $result->fetch()) {
        $stats['by_status'][$row['status']] = (int)$row['count'];
    }
    
    // Total budget vs spent
    $result = $pdo->query("
        SELECT 
            SUM(budget_idr) as total_budget,
            SUM(spent_idr) as total_spent
        FROM cqc_projects
    ");
    $budget = $result->fetch();
    $stats['total_budget'] = (float)($budget['total_budget'] ?? 0);
    $stats['total_spent'] = (float)($budget['total_spent'] ?? 0);
    $stats['remaining'] = $stats['total_budget'] - $stats['total_spent'];
    
    // Active projects (ongoing + installation)
    $result = $pdo->query("
        SELECT COUNT(*) as count 
        FROM cqc_projects 
        WHERE status IN ('procurement', 'installation', 'testing')
    ");
    $stats['active'] = (int)$result->fetch()['count'];
    
    // Average progress
    $result = $pdo->query("
        SELECT AVG(progress_percentage) as avg_progress 
        FROM cqc_projects 
        WHERE status != 'planning'
    ");
    $progress = $result->fetch();
    $stats['avg_progress'] = (int)($progress['avg_progress'] ?? 0);
    
} catch (Exception $e) {
    // Table might not exist yet
    $stats = [
        'total' => 0,
        'by_status' => [],
        'total_budget' => 0,
        'total_spent' => 0,
        'remaining' => 0,
        'active' => 0,
        'avg_progress' => 0
    ];
}

// Get running projects (untuk quick view)
$running_projects = [];
try {
    $stmt = $pdo->query("
        SELECT id, project_name, client_name, status, progress_percentage, 
               budget_idr, spent_idr, start_date, estimated_completion
        FROM cqc_projects
        WHERE status IN ('procurement', 'installation', 'testing')
        ORDER BY progress_percentage DESC
        LIMIT 5
    ");
    $running_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist
}

$pageTitle = "CQC Projects Dashboard";
$pageSubtitle = "Solar Panel Installation Project Management";

$additionalCSS = [];
$inlineStyles = '<style>
/* Chart.js */
</style>';

include '../../includes/header.php';
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
<style>
        .cqc-container { max-width: 1100px; margin: 0 auto; }

        /* Header - slim elegant */
        .cqc-header {
            background: linear-gradient(135deg, #2d3436 0%, #1e272e 100%);
            color: #fff;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .cqc-header h1 { font-size: 14px; margin-bottom: 1px; font-weight: 700; color: #ffd32a; }
        .cqc-header p { opacity: 0.5; font-size: 10px; margin: 0; color: #dfe6e9; }
        .cqc-header button {
            background: #ffd32a; color: #2d3436; border: none;
            padding: 6px 14px; border-radius: 5px; font-weight: 700;
            cursor: pointer; font-size: 11px; transition: all 0.2s;
            letter-spacing: 0.3px;
        }
        .cqc-header button:hover { background: #fdcb6e; }

        /* Stats - tight compact */
        .cqc-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        .cqc-stat-card {
            background: #2d3436;
            padding: 10px 12px;
            border-radius: 6px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
            border-left: 3px solid #636e72;
        }
        .cqc-stat-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,0.15); }
        .cqc-stat-card.yellow { border-left-color: #ffd32a; }
        .cqc-stat-card.green { border-left-color: #00b894; }
        .cqc-stat-card.red { border-left-color: #d63031; }

        .cqc-stat-icon { font-size: 16px; margin-bottom: 4px; }
        .cqc-stat-label { font-size: 9px; color: #b2bec3; margin-bottom: 3px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.6px; }
        .cqc-stat-value { font-size: 18px; font-weight: 800; color: #dfe6e9; }
        .cqc-stat-card.yellow .cqc-stat-value { color: #ffd32a; }
        .cqc-stat-card.green .cqc-stat-value { color: #00b894; }
        .cqc-stat-card.red .cqc-stat-value { color: #ff7675; }
        .cqc-stat-subtitle { font-size: 9px; color: #636e72; margin-top: 2px; }

        /* Charts - tight */
        .cqc-charts-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 12px;
        }
        .cqc-chart-card {
            background: #2d3436;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.1);
            border: 1px solid #3d3d3d;
            position: relative;
            overflow: hidden;
        }
        .cqc-chart-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, #ffd32a, #fdcb6e, #ffeaa7);
            background-size: 300% 100%;
            animation: cqcShimmer 4s ease infinite;
        }
        @keyframes cqcShimmer {
            0%,100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .cqc-chart-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.18); }
        .cqc-chart-title {
            font-size: 10px; font-weight: 700; color: #ffd32a;
            margin-bottom: 8px; display: flex; align-items: center;
            gap: 5px; letter-spacing: 0.5px; text-transform: uppercase;
        }
        .cqc-chart-canvas { max-height: 170px; }

        /* Section title */
        .cqc-section-title {
            font-size: 12px; font-weight: 700; color: #2d3436;
            margin: 12px 0 8px; padding-bottom: 6px;
            border-bottom: 2px solid #ffd32a;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        /* Table - tight */
        .cqc-projects-table {
            background: #2d3436;
            border-radius: 6px; overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
        }
        .cqc-projects-table table { width: 100%; border-collapse: collapse; }
        .cqc-projects-table th {
            background: #1e272e; color: #ffd32a;
            padding: 8px 10px; text-align: left;
            font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .cqc-projects-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #3d3d3d;
            font-size: 12px; color: #dfe6e9;
        }
        .cqc-projects-table tr:hover { background: #353b48; }

        .status-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; }
        .status-planning { background: rgba(116,185,255,0.15); color: #74b9ff; }
        .status-procurement { background: rgba(255,211,42,0.15); color: #ffd32a; }
        .status-installation { background: rgba(0,184,148,0.15); color: #00b894; }
        .status-testing { background: rgba(85,239,196,0.15); color: #55efc4; }
        .status-completed { background: rgba(0,184,148,0.25); color: #00b894; }
        .status-on_hold { background: rgba(214,48,49,0.15); color: #ff7675; }

        .cqc-progress-bar { width: 100%; height: 4px; background: #3d3d3d; border-radius: 2px; overflow: hidden; margin-bottom: 2px; }
        .cqc-progress-fill { height: 100%; background: linear-gradient(90deg, #ffd32a, #fdcb6e); border-radius: 2px; }
        .cqc-progress-text { font-size: 10px; color: #b2bec3; }

        .cqc-action-links { display: flex; gap: 4px; }
        .cqc-action-links a {
            padding: 3px 8px; background: #ffd32a; color: #2d3436;
            border-radius: 3px; text-decoration: none; font-size: 10px; font-weight: 700;
        }
        .cqc-action-links a:hover { background: #fdcb6e; }

        .cqc-empty-state { text-align: center; padding: 30px 16px; color: #b2bec3; }
        .cqc-empty-state-icon { font-size: 36px; margin-bottom: 8px; }
        .cqc-empty-state h3 { color: #dfe6e9; margin-bottom: 4px; font-size: 14px; }
        .cqc-empty-state p { font-size: 12px; color: #636e72; }

        @media (max-width: 900px) {
            .cqc-stats-grid { grid-template-columns: repeat(2, 1fr); }
            .cqc-charts-section { grid-template-columns: 1fr; }
        }
</style>

    <div class="cqc-container">
        <div class="cqc-header">
            <div>
                <h1>☀️ Dashboard Proyek CQC</h1>
                <p>Solar Panel Installation Project Management</p>
            </div>
            <button onclick="location.href='add.php'">+ Proyek Baru</button>
        </div>

        <div class="cqc-stats-grid">
            <div class="cqc-stat-card">
                <div class="cqc-stat-icon">📋</div>
                <div class="cqc-stat-label">Total Proyek</div>
                <div class="cqc-stat-value"><?php echo $stats['total']; ?></div>
            </div>
            <div class="cqc-stat-card yellow">
                <div class="cqc-stat-icon">⚡</div>
                <div class="cqc-stat-label">Proyek Berjalan</div>
                <div class="cqc-stat-value"><?php echo $stats['active']; ?></div>
                <div class="cqc-stat-subtitle">Procurement, Installation, Testing</div>
            </div>
            <div class="cqc-stat-card green">
                <div class="cqc-stat-icon">✅</div>
                <div class="cqc-stat-label">Rata-rata Progress</div>
                <div class="cqc-stat-value"><?php echo $stats['avg_progress']; ?>%</div>
            </div>
            <div class="cqc-stat-card red">
                <div class="cqc-stat-icon">💰</div>
                <div class="cqc-stat-label">Total Pengeluaran</div>
                <div class="cqc-stat-value">Rp <?php echo number_format($stats['total_spent'], 0); ?></div>
                <div class="cqc-stat-subtitle">dari Rp <?php echo number_format($stats['total_budget'], 0); ?></div>
            </div>
        </div>

        <div class="cqc-charts-section">
            <div class="cqc-chart-card">
                <div class="cqc-chart-title">📈 Distribusi Status</div>
                <canvas id="statusChart" class="cqc-chart-canvas"></canvas>
            </div>
            <div class="cqc-chart-card">
                <div class="cqc-chart-title">💵 Budget vs Pengeluaran</div>
                <canvas id="budgetChart" class="cqc-chart-canvas"></canvas>
            </div>
            <div class="cqc-chart-card">
                <div class="cqc-chart-title">⏳ Progress Rata-rata</div>
                <canvas id="progressChart" class="cqc-chart-canvas"></canvas>
            </div>
        </div>

        <div class="cqc-section-title">⚡ Proyek Sedang Berjalan</div>
        
        <?php if (!empty($running_projects)): ?>
            <div class="cqc-projects-table">
                <table>
                    <thead>
                        <tr>
                            <th>Nama Proyek</th>
                            <th>Klien</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Budget</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($running_projects as $proj): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($proj['project_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($proj['client_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $proj['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $proj['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="cqc-progress-bar">
                                        <div class="cqc-progress-fill" style="width: <?php echo $proj['progress_percentage']; ?>%"></div>
                                    </div>
                                    <div class="cqc-progress-text"><?php echo $proj['progress_percentage']; ?>%</div>
                                </td>
                                <td>
                                    <div style="font-size: 11px; color: #b2bec3;">
                                        Rp <?php echo number_format($proj['spent_idr'] ?? 0, 0); ?> / 
                                        Rp <?php echo number_format($proj['budget_idr'] ?? 0, 0); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="cqc-action-links">
                                        <a href="detail.php?id=<?php echo $proj['id']; ?>">Lihat</a>
                                        <a href="add.php?id=<?php echo $proj['id']; ?>">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="cqc-empty-state">
                <div class="cqc-empty-state-icon">📭</div>
                <h3>Tidak Ada Proyek Sedang Berjalan</h3>
                <p>Mulai dengan membuat proyek baru untuk instalasi panel surya.</p>
                <button style="background: #ffd32a; color: #2d3436; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer; margin-top: 14px; font-weight: 700; font-size: 11px;" onclick="location.href='add.php'">
                    ➕ Buat Proyek Baru
                </button>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // === Center Text Plugin (futuristic doughnut center) ===
        const centerTextPlugin = {
            id: 'centerText',
            afterDraw(chart) {
                if (!chart.config.options.plugins.centerText) return;
                const { text, subtext, color } = chart.config.options.plugins.centerText;
                const { ctx, chartArea: { left, right, top, bottom } } = chart;
                const cx = (left + right) / 2;
                const cy = (top + bottom) / 2;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                if (text) {
                    ctx.font = 'bold 18px -apple-system, BlinkMacSystemFont, sans-serif';
                    ctx.fillStyle = color || '#dfe6e9';
                    ctx.fillText(text, cx, subtext ? cy - 7 : cy);
                }
                if (subtext) {
                    ctx.font = '700 8px -apple-system, BlinkMacSystemFont, sans-serif';
                    ctx.fillStyle = '#636e72';
                    ctx.fillText(subtext, cx, cy + 12);
                }
                ctx.restore();
            }
        };
        Chart.register(centerTextPlugin);

        // === Shared tooltip style ===
        const cqcTooltip = {
            backgroundColor: 'rgba(30, 39, 46, 0.95)',
            titleColor: '#ffd32a',
            bodyColor: '#dfe6e9',
            borderColor: 'rgba(255,211,42,0.3)',
            borderWidth: 1,
            cornerRadius: 6,
            padding: 8,
            titleFont: { size: 10, weight: '700' },
            bodyFont: { size: 11, weight: '600' },
            displayColors: true,
            boxPadding: 3
        };

        // === Gradient helper ===
        function cqcGrad(ctx, c1, c2) {
            const g = ctx.createLinearGradient(0, 0, 0, 250);
            g.addColorStop(0, c1);
            g.addColorStop(1, c2);
            return g;
        }

        // ── Status Chart ──
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            const sData = [
                <?php echo $stats['by_status']['planning'] ?? 0; ?>,
                <?php echo $stats['by_status']['procurement'] ?? 0; ?>,
                <?php echo $stats['by_status']['installation'] ?? 0; ?>,
                <?php echo $stats['by_status']['testing'] ?? 0; ?>,
                <?php echo $stats['by_status']['completed'] ?? 0; ?>,
                <?php echo $stats['by_status']['on_hold'] ?? 0; ?>
            ];
            const sTotal = sData.reduce((a,b) => a+b, 0);

            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Planning', 'Procurement', 'Installation', 'Testing', 'Completed', 'On Hold'],
                    datasets: [{
                        data: sData,
                        backgroundColor: ['#636e72','#ffd32a','#b2bec3','#00b894','#2d3436','#d63031'],
                        hoverBackgroundColor: ['#b2bec3','#ffeaa7','#dfe6e9','#55efc4','#636e72','#ff7675'],
                        borderWidth: 0,
                        spacing: 2,
                        borderRadius: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '70%',
                    plugins: {
                        centerText: { text: sTotal.toString(), subtext: 'PROYEK', color: '#dfe6e9' },
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, pointStyle: 'circle', padding: 8, font: { size: 9, weight: '600' }, color: '#b2bec3' }
                        },
                        tooltip: cqcTooltip
                    },
                    animation: { animateRotate: true, duration: 800, easing: 'easeOutQuart' }
                }
            });
        }

        // ── Budget Chart ──
        const budgetCtx = document.getElementById('budgetChart');
        if (budgetCtx) {
            const ctx2d = budgetCtx.getContext('2d');
            new Chart(budgetCtx, {
                type: 'bar',
                data: {
                    labels: ['Budget', 'Terpakai', 'Sisa'],
                    datasets: [{
                        label: 'Rp',
                        data: [
                            <?php echo $stats['total_budget']; ?>,
                            <?php echo $stats['total_spent']; ?>,
                            <?php echo $stats['remaining']; ?>
                        ],
                        backgroundColor: [
                            cqcGrad(ctx2d, '#636e72', '#2d3436'),
                            cqcGrad(ctx2d, '#ffd32a', '#fdcb6e'),
                            cqcGrad(ctx2d, '#00b894', '#00cec9')
                        ],
                        borderRadius: 4,
                        borderSkipped: false,
                        barPercentage: 0.55,
                        categoryPercentage: 0.7
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            ...cqcTooltip,
                            callbacks: { label: function(ctx) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(ctx.parsed.x); } }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)', drawBorder: false },
                            ticks: {
                                color: '#636e72', font: { size: 9, weight: '600' },
                                callback: function(v) { return v >= 1e9 ? 'Rp '+(v/1e9).toFixed(1)+'B' : 'Rp '+(v/1e6).toFixed(0)+'M'; }
                            }
                        },
                        y: { grid: { display: false }, ticks: { color: '#dfe6e9', font: { size: 10, weight: '600' } } }
                    },
                    animation: { duration: 800, easing: 'easeOutQuart' }
                }
            });
        }

        // ── Progress Chart ──
        const progressCtx = document.getElementById('progressChart');
        if (progressCtx) {
            const pVal = <?php echo $stats['avg_progress']; ?>;
            new Chart(progressCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Tersisa'],
                    datasets: [{
                        data: [pVal, 100 - pVal],
                        backgroundColor: [
                            (function(){
                                const g = progressCtx.getContext('2d').createLinearGradient(0,0,200,200);
                                g.addColorStop(0, '#ffd32a');
                                g.addColorStop(1, '#fdcb6e');
                                return g;
                            })(),
                            'rgba(99, 110, 114, 0.2)'
                        ],
                        borderWidth: 0,
                        spacing: 2,
                        borderRadius: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: '75%',
                    plugins: {
                        centerText: { text: pVal + '%', subtext: 'PROGRESS', color: '#ffd32a' },
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, pointStyle: 'circle', padding: 8, font: { size: 9, weight: '600' }, color: '#b2bec3' }
                        },
                        tooltip: {
                            ...cqcTooltip,
                            callbacks: { label: function(ctx) { return ctx.label + ': ' + ctx.parsed + '%'; } }
                        }
                    },
                    animation: { animateRotate: true, duration: 1000, easing: 'easeOutQuart' }
                }
            });
        }
    </script>

<?php include '../../includes/footer.php'; ?>
