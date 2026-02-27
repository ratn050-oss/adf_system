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

$pageTitle = "Dashboard Proyek - CQC Enjiniring";
$currentUser = $_SESSION['user_name'] ?? 'User';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen',
                'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 8px 16px rgba(0, 102, 204, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .header-left p {
            opacity: 0.9;
            font-size: 14px;
        }

        .header-actions button {
            background: #FFD700;
            color: #0066CC;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .header-actions button:hover {
            background: #FFC700;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 215, 0, 0.3);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border-top: 4px solid #0066CC;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .stat-card.yellow {
            border-top-color: #FFD700;
        }

        .stat-card.green {
            border-top-color: #10b981;
        }

        .stat-card.red {
            border-top-color: #ef4444;
        }

        .stat-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        .stat-label {
            font-size: 13px;
            color: #999;
            margin-bottom: 8px;
            text-transform: uppercase;
            font-weight: 600;
        }

        .stat-value {
            font-size: 28px;
            font-weight: bold;
            color: #0066CC;
        }

        .stat-card.yellow .stat-value {
            color: #FFD700;
        }

        .stat-card.green .stat-value {
            color: #10b981;
        }

        .stat-card.red .stat-value {
            color: #ef4444;
        }

        .stat-subtitle {
            font-size: 12px;
            color: #ccc;
            margin-top: 8px;
        }

        /* Charts Section */
        .charts-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: #0066CC;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-canvas {
            max-height: 250px;
        }

        /* Projects Table */
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #0066CC;
            margin: 40px 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #FFD700;
        }

        .projects-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: linear-gradient(135deg, #0066CC 0%, #004499 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-planning {
            background: #e3f2fd;
            color: #0066CC;
        }

        .status-procurement {
            background: #fff3cd;
            color: #994500;
        }

        .status-installation {
            background: #d1ecff;
            color: #0066CC;
        }

        .status-testing {
            background: #c8e6c9;
            color: #2e7d32;
        }

        .status-completed {
            background: #a5d6a7;
            color: #1b5e20;
        }

        .status-on_hold {
            background: #ffccbc;
            color: #d84315;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #eee;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 5px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #0066CC, #FFD700);
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 12px;
            color: #666;
        }

        .budget-text {
            font-size: 12px;
            color: #666;
        }

        .action-links {
            display: flex;
            gap: 10px;
        }

        .action-links a {
            padding: 6px 12px;
            background: #0066CC;
            color: white;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .action-links a:hover {
            background: #004499;
            transform: scale(1.05);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            color: #0066CC;
            margin-bottom: 10px;
        }

        /* Footer */
        .footer {
            text-align: center;
            color: #999;
            font-size: 12px;
            margin-top: 40px;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 20px;
            }

            .charts-section {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 15px;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1>📊 Dashboard Proyek CQC</h1>
                <p>Solar Panel Installation Project Management System</p>
            </div>
            <div class="header-actions">
                <button onclick="location.href='cqc-projects-add.php'">➕ Proyek Baru</button>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📋</div>
                <div class="stat-label">Total Proyek</div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
            </div>

            <div class="stat-card yellow">
                <div class="stat-icon">⚡</div>
                <div class="stat-label">Proyek Berjalan</div>
                <div class="stat-value"><?php echo $stats['active']; ?></div>
                <div class="stat-subtitle">Procurement, Installation, Testing</div>
            </div>

            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-label">Rata-rata Progress</div>
                <div class="stat-value"><?php echo $stats['avg_progress']; ?>%</div>
            </div>

            <div class="stat-card red">
                <div class="stat-icon">💰</div>
                <div class="stat-label">Total Pengeluaran</div>
                <div class="stat-value">Rp <?php echo number_format($stats['total_spent'], 0); ?></div>
                <div class="stat-subtitle">dari Rp <?php echo number_format($stats['total_budget'], 0); ?></div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-section">
            <!-- Status Distribution -->
            <div class="chart-card">
                <div class="chart-title">📈 Distribusi Status</div>
                <canvas id="statusChart" class="chart-canvas"></canvas>
            </div>

            <!-- Budget Distribution -->
            <div class="chart-card">
                <div class="chart-title">💵 Budget vs Pengeluaran</div>
                <canvas id="budgetChart" class="chart-canvas"></canvas>
            </div>

            <!-- Progress Overview -->
            <div class="chart-card">
                <div class="chart-title">⏳ Progress Rata-rata</div>
                <canvas id="progressChart" class="chart-canvas"></canvas>
            </div>
        </div>

        <!-- Running Projects -->
        <div class="section-title">⚡ Proyek Sedang Berjalan</div>
        
        <?php if (!empty($running_projects)): ?>
            <div class="projects-table">
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
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $proj['progress_percentage']; ?>%"></div>
                                    </div>
                                    <div class="progress-text"><?php echo $proj['progress_percentage']; ?>%</div>
                                </td>
                                <td>
                                    <div class="budget-text">
                                        Rp <?php echo number_format($proj['spent_idr'] ?? 0, 0); ?> / 
                                        Rp <?php echo number_format($proj['budget_idr'] ?? 0, 0); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-links">
                                        <a href="cqc-projects-detail.php?id=<?php echo $proj['id']; ?>">Lihat</a>
                                        <a href="cqc-projects-edit.php?id=<?php echo $proj['id']; ?>">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <h3>Tidak Ada Proyek Sedang Berjalan</h3>
                <p>Mulai dengan membuat proyek baru untuk instalasi panel surya.</p>
                <button style="background: #0066CC; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; margin-top: 20px;" onclick="location.href='cqc-projects-add.php'">
                    ➕ Buat Proyek Baru
                </button>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>© 2026 CQC Enjiniring | Solar Panel Projects Dashboard</p>
        </div>
    </div>

    <script>
        // Status Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: [
                        'Planning', 
                        'Procurement', 
                        'Installation', 
                        'Testing', 
                        'Completed', 
                        'On Hold'
                    ],
                    datasets: [{
                        data: [
                            <?php echo $stats['by_status']['planning'] ?? 0; ?>,
                            <?php echo $stats['by_status']['procurement'] ?? 0; ?>,
                            <?php echo $stats['by_status']['installation'] ?? 0; ?>,
                            <?php echo $stats['by_status']['testing'] ?? 0; ?>,
                            <?php echo $stats['by_status']['completed'] ?? 0; ?>,
                            <?php echo $stats['by_status']['on_hold'] ?? 0; ?>
                        ],
                        backgroundColor: [
                            '#e3f2fd',
                            '#fff3cd',
                            '#d1ecff',
                            '#c8e6c9',
                            '#a5d6a7',
                            '#ffccbc'
                        ],
                        borderColor: [
                            '#0066CC',
                            '#994500',
                            '#0066CC',
                            '#2e7d32',
                            '#1b5e20',
                            '#d84315'
                        ],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { font: { size: 11 } }
                        }
                    }
                }
            });
        }

        // Budget Chart
        const budgetCtx = document.getElementById('budgetChart');
        if (budgetCtx) {
            new Chart(budgetCtx, {
                type: 'bar',
                data: {
                    labels: ['Budget Total', 'Pengeluaran', 'Sisa'],
                    datasets: [{
                        label: 'Rp',
                        data: [
                            <?php echo $stats['total_budget']; ?>,
                            <?php echo $stats['total_spent']; ?>,
                            <?php echo $stats['remaining']; ?>
                        ],
                        backgroundColor: [
                            '#0066CC',
                            '#FFD700',
                            '#10b981'
                        ]
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return 'Rp ' + (value / 1000000).toFixed(0) + 'M';
                                }
                            }
                        }
                    }
                }
            });
        }

        // Progress Chart
        const progressCtx = document.getElementById('progressChart');
        if (progressCtx) {
            new Chart(progressCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Selesai', 'Proses'],
                    datasets: [{
                        data: [<?php echo $stats['avg_progress']; ?>, <?php echo 100 - $stats['avg_progress']; ?>],
                        backgroundColor: ['#0066CC', '#e0e0e0'],
                        borderColor: ['#004499', '#ffffff'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.label + ': ' + context.parsed + '%';
                                }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
