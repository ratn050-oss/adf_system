<?php
/**
 * KASBOOK DAILY SIMPLE - Owner Cash Management (FIXED VERSION)
 * Clean & Simple Interface for Daily Cash Tracking
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'manager', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$errorMsg = '';
try {
    $masterDb = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . MASTER_DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    
    $businessMapping = ['narayana-hotel' => 1, 'bens-cafe' => 2];
    $businessIdString = ACTIVE_BUSINESS_ID;
    $businessId = $businessMapping[$businessIdString] ?? 1;
    
    $selectedDate = $_GET['date'] ?? date('Y-m-d');
    
    // Validate date format
    DateTime::createFromFormat('Y-m-d', $selectedDate) or die('Invalid date format');
    
    $displayDate = new DateTime($selectedDate);
    
    // Get Petty Cash account (single source of truth)
    $stmt = $masterDb->prepare(
        "SELECT id, account_name, current_balance FROM cash_accounts 
         WHERE business_id = ? AND account_type = 'petty_cash' LIMIT 1"
    );
    $stmt->execute([$businessId]);
    $pettyCashAccount = $stmt->fetch();
    
    if (!$pettyCashAccount) {
        $errorMsg = '❌ Petty Cash account tidak ditemukan. Hubungi admin.';
    } else {
        $pettyCashAccountId = $pettyCashAccount['id'];
        
        // KAS MASUK - Owner capital injections untuk hari ini
        $kasMasukOwner = 0;
        $stmt = $masterDb->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? 
             AND transaction_type = 'debit' AND description LIKE '%OWNER%'"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $kasMasukOwner = $stmt->fetchColumn();
        
        // KAS MASUK - Revenue cash untuk hari ini
        $kasMasukRevenue = 0;
        $stmt = $masterDb->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? 
             AND transaction_type = 'debit' AND description LIKE '%REVENUE%'"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $kasMasukRevenue = $stmt->fetchColumn();
        
        $kasMasukTotal = $kasMasukOwner + $kasMasukRevenue;
        
        // KAS KELUAR - operasional untuk hari ini
        $kasKeluar = 0;
        $stmt = $masterDb->prepare(
            "SELECT COALESCE(SUM(amount), 0) as total FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ? AND transaction_type = 'credit'"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $kasKeluar = $stmt->fetchColumn();
        
        // SALDO AKHIR - hitung dari semua transaksi sampai hari ini
        $saldoAkhir = $pettyCashAccount['current_balance'] ?? 0;
        
        // Get transaksi detail untuk hari ini
        $transaksiDetail = [];
        $stmt = $masterDb->prepare(
            "SELECT id, transaction_type, amount, description, reference_number, created_at
             FROM cash_account_transactions
             WHERE cash_account_id = ? AND transaction_date = ?
             ORDER BY created_at ASC"
        );
        $stmt->execute([$pettyCashAccountId, $selectedDate]);
        $transaksiDetail = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    $errorMsg = '❌ Database Error: ' . $e->getMessage();
}

function formatCurrency($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasbook Daily - Simple Tracking</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css">
    <style>
        :root {
            --primary: #0071e3;
            --success: #34c759;
            --danger: #ff3b30;
            --gray: #6c757d;
            --border: #e5e7eb;
            --light: #f5f5f5;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--light);
        }

        .navbar-custom {
            background: white;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .container-main {
            max-width: 1000px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .header-subtitle {
            font-size: 14px;
            color: var(--gray);
            margin: 0;
        }

        .date-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .date-controls input,
        .date-controls button {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
        }

        .date-controls button {
            background: var(--primary);
            color: white;
            border: none;
            font-weight: 500;
        }

        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .card-simple {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            font-size: 24px;
        }

        .card-icon.incoming {
            background: rgba(52, 199, 89, 0.1);
        }

        .card-icon.outgoing {
            background: rgba(255, 59, 48, 0.1);
        }

        .card-icon.balance {
            background: rgba(0, 113, 227, 0.1);
        }

        .card-label {
            font-size: 11px;
            color: var(--gray);
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .card-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            font-family: monospace;
        }

        .card-description {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 12px;
        }

        .breakdown {
            font-size: 11px;
            padding-top: 12px;
            border-top: 1px solid var(--border);
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            color: var(--gray);
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .table-wrapper {
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .table-wrapper table {
            width: 100%;
            margin: 0;
        }

        .table-wrapper th {
            background: var(--light);
            padding: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
        }

        .table-wrapper td {
            padding: 12px;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .table-wrapper tr:last-child td {
            border: none;
        }

        .badge-debit {
            background: rgba(52, 199, 89, 0.1);
            color: var(--success);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-credit {
            background: rgba(255, 59, 48, 0.1);
            color: var(--danger);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
        }

        .error-box {
            background: rgba(255, 59, 48, 0.1);
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray);
        }

        @media (max-width: 768px) {
            .header-section {
                flex-direction: column;
                align-items: flex-start;
            }
            .cards-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="navbar-custom">
        <div class="container-main" style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;">
            <h6 style="margin: 0; font-size: 14px; font-weight: 700;">📊 Kasbook Daily</h6>
            <a href="dashboard.php" style="text-decoration: none; color: var(--gray); font-size: 12px;">← Dashboard</a>
        </div>
    </div>

    <div class="container-main">
        <div class="header-section">
            <div>
                <h1 class="header-title">Kasbook Daily</h1>
                <p class="header-subtitle"><?= $displayDate->format('l, d F Y') ?></p>
            </div>
            <div class="date-controls">
                <input type="date" id="dateInput" value="<?= $selectedDate ?>" class="form-control" style="width: auto; max-width: 150px;">
                <button onclick="updateDate()" style="min-width: 100px;">Load</button>
                <button onclick="window.print()" style="min-width: 100px; background: var(--gray);">Print</button>
            </div>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div class="error-box"><?= $errorMsg ?></div>
        <?php else: ?>

            <!-- Main Cards -->
            <div class="cards-grid">
                <div class="card-simple">
                    <div class="card-icon incoming">💵</div>
                    <div class="card-label">Kas Masuk Hari Ini</div>
                    <div class="card-value"><?= formatCurrency($kasMasukTotal) ?></div>
                    <div class="card-description">Total uang masuk ke kasir</div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>📦 Dari Owner:</span>
                            <strong><?= formatCurrency($kasMasukOwner) ?></strong>
                        </div>
                        <div class="breakdown-item">
                            <span>🏨 Dari Revenue:</span>
                            <strong><?= formatCurrency($kasMasukRevenue) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-simple">
                    <div class="card-icon outgoing">💸</div>
                    <div class="card-label">Kas Keluar Hari Ini</div>
                    <div class="card-value"><?= formatCurrency($kasKeluar) ?></div>
                    <div class="card-description">Pengeluaran operasional</div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Operasional:</span>
                            <strong><?= formatCurrency($kasKeluar) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="card-simple">
                    <div class="card-icon balance">💰</div>
                    <div class="card-label">Saldo Akhir Hari</div>
                    <div class="card-value"><?= formatCurrency($saldoAkhir) ?></div>
                    <div class="card-description">Kas di tangan (final)</div>
                    <div class="breakdown">
                        <div class="breakdown-item">
                            <span>Status:</span>
                            <strong style="color: <?= $saldoAkhir >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                                <?= $saldoAkhir >= 0 ? '✅ OK' : '⚠️ MINUS' ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detail Table -->
            <div>
                <h5 class="section-title">📋 Detail Transaksi Hari Ini</h5>
                
                <?php if (empty($transaksiDetail)): ?>
                    <div class="table-wrapper">
                        <div class="empty-state">
                            <p>Belum ada transaksi hari ini</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Jenis</th>
                                    <th>Keterangan</th>
                                    <th style="text-align: right;">Nominal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transaksiDetail as $txn): ?>
                                <tr>
                                    <td>
                                        <?= isset($txn['created_at']) ? substr($txn['created_at'], 11, 5) : '-' ?>
                                    </td>
                                    <td>
                                        <?php if ($txn['transaction_type'] == 'debit'): ?>
                                            <span class="badge-debit">MASUK</span>
                                        <?php else: ?>
                                            <span class="badge-credit">KELUAR</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($txn['description'] ?? '-') ?></td>
                                    <td style="text-align: right; font-weight: 600;">
                                        <?= formatCurrency($txn['amount']) ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div style="text-align: center; margin-top: 32px; padding: 16px; color: var(--gray); font-size: 11px;">
                <a href="kasbook-entry.php" style="color: var(--primary); text-decoration: none; margin-right: 12px; font-weight: 500;">+ Tambah Transaksi</a>
                <span>|</span>
                <a href="dashboard.php" style="color: var(--primary); text-decoration: none; margin-left: 12px; font-weight: 500;">Kembali ke Dashboard</a>
            </div>

        <?php endif; ?>
    </div>

    <script>
        function updateDate() {
            const date = document.getElementById('dateInput').value;
            if (date) {
                window.location.href = '?date=' + date;
            }
        }
        document.getElementById('dateInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') updateDate();
        });
    </script>
</body>
</html>
