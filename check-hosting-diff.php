<?php
/**
 * Check Hosting Differences - Diagnostic Tool
 * Run this on both local and hosting to compare
 */

require_once 'config/config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hosting Difference Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 2rem;
            min-height: 100vh;
            color: #333;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 {
            color: #667eea;
            margin-bottom: 0.5rem;
            font-size: 1.8rem;
        }
        .subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }
        .section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .section h2 {
            color: #667eea;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem;
            background: white;
            margin-bottom: 0.5rem;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .info-row:last-child {
            margin-bottom: 0;
        }
        .label {
            font-weight: 600;
            color: #555;
        }
        .value {
            color: #333;
            word-break: break-all;
            text-align: right;
            max-width: 60%;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        .file-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .file-item {
            background: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .file-icon {
            color: #667eea;
        }
        .environment-banner {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            text-align: center;
            font-weight: 600;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Hosting Difference Checker</h1>
        <p class="subtitle">Diagnostic tool to identify differences between local and hosting environment</p>

        <?php
        $isProduction = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') === false && 
                        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') === false);
        ?>

        <div class="environment-banner">
            <?php if ($isProduction): ?>
                🌐 PRODUCTION/HOSTING Environment Detected
            <?php else: ?>
                💻 LOCAL Development Environment Detected
            <?php endif; ?>
        </div>

        <!-- Server Info -->
        <div class="section">
            <h2>🖥️ Server Information</h2>
            <div class="info-row">
                <span class="label">Environment:</span>
                <span class="value">
                    <?php if ($isProduction): ?>
                        <span class="badge badge-danger">PRODUCTION</span>
                    <?php else: ?>
                        <span class="badge badge-success">LOCAL</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">HTTP Host:</span>
                <span class="value"><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Server Software:</span>
                <span class="value"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?></span>
            </div>
            <div class="info-row">
                <span class="label">PHP Version:</span>
                <span class="value">
                    <?= PHP_VERSION ?>
                    <?php if (version_compare(PHP_VERSION, '7.4.0', '>=')): ?>
                        <span class="badge badge-success">✓ OK</span>
                    <?php else: ?>
                        <span class="badge badge-danger">⚠ OLD</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span class="label">Document Root:</span>
                <span class="value"><?= htmlspecialchars($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Script Filename:</span>
                <span class="value"><?= htmlspecialchars($_SERVER['SCRIPT_FILENAME'] ?? 'N/A') ?></span>
            </div>
        </div>

        <!-- Configuration -->
        <div class="section">
            <h2>⚙️ Application Configuration</h2>
            <div class="info-row">
                <span class="label">BASE_URL:</span>
                <span class="value"><strong><?= BASE_URL ?></strong></span>
            </div>
            <div class="info-row">
                <span class="label">BASE_PATH:</span>
                <span class="value"><?= BASE_PATH ?></span>
            </div>
            <div class="info-row">
                <span class="label">DB_HOST:</span>
                <span class="value"><?= DB_HOST ?></span>
            </div>
            <div class="info-row">
                <span class="label">DB_NAME:</span>
                <span class="value"><strong><?= DB_NAME ?></strong></span>
            </div>
            <div class="info-row">
                <span class="label">DB_USER:</span>
                <span class="value"><?= DB_USER ?></span>
            </div>
        </div>

        <!-- Database Check -->
        <div class="section">
            <h2>💾 Database Connection</h2>
            <?php
            try {
                require_once 'config/database.php';
                $db = Database::getInstance()->getConnection();
                
                echo '<div class="info-row">';
                echo '<span class="label">Connection Status:</span>';
                echo '<span class="value"><span class="badge badge-success">✓ Connected</span></span>';
                echo '</div>';
                
                // Get tables
                $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                $tableCount = count($tables);
                
                echo '<div class="info-row">';
                echo '<span class="label">Tables Found:</span>';
                echo '<span class="value"><strong>' . $tableCount . ' tables</strong></span>';
                echo '</div>';
                
                // Check critical tables
                $criticalTables = ['investors', 'investor_transactions', 'projects', 'businesses', 'users'];
                $missingTables = [];
                
                foreach ($criticalTables as $table) {
                    if (!in_array($table, $tables)) {
                        $missingTables[] = $table;
                    }
                }
                
                echo '<div class="info-row">';
                echo '<span class="label">Critical Tables:</span>';
                if (empty($missingTables)) {
                    echo '<span class="value"><span class="badge badge-success">✓ All Present</span></span>';
                } else {
                    echo '<span class="value"><span class="badge badge-danger">⚠ Missing: ' . implode(', ', $missingTables) . '</span></span>';
                }
                echo '</div>';
                
            } catch (Exception $e) {
                echo '<div class="info-row">';
                echo '<span class="label">Connection Status:</span>';
                echo '<span class="value"><span class="badge badge-danger">✗ Failed: ' . htmlspecialchars($e->getMessage()) . '</span></span>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- File System Check -->
        <div class="section">
            <h2>📁 Critical Files Check</h2>
            <?php
            $criticalFiles = [
                'config/config.php',
                'config/database.php',
                'includes/auth.php',
                'modules/investor/index.php',
                'modules/investor/deposits-history.php',
                'api/investor-transactions.php',
                'assets/css/style.css'
            ];
            
            $missingFiles = [];
            $presentFiles = [];
            
            foreach ($criticalFiles as $file) {
                if (file_exists($file)) {
                    $presentFiles[] = $file;
                } else {
                    $missingFiles[] = $file;
                }
            }
            
            echo '<div class="info-row">';
            echo '<span class="label">Files Present:</span>';
            echo '<span class="value"><strong>' . count($presentFiles) . ' / ' . count($criticalFiles) . '</strong></span>';
            echo '</div>';
            
            if (!empty($missingFiles)) {
                echo '<div class="info-row">';
                echo '<span class="label">Missing Files:</span>';
                echo '<span class="value"><span class="badge badge-danger">';
                echo implode(', ', $missingFiles);
                echo '</span></span>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- URL Mapping Check -->
        <div class="section">
            <h2>🔗 URL Mapping & Routes</h2>
            <div class="info-row">
                <span class="label">Investor Module URL:</span>
                <span class="value">
                    <a href="<?= BASE_URL ?>/modules/investor/" target="_blank" style="color: #667eea; text-decoration: none;">
                        <?= BASE_URL ?>/modules/investor/ →
                    </a>
                </span>
            </div>
            <div class="info-row">
                <span class="label">API Base URL:</span>
                <span class="value">
                    <a href="<?= BASE_URL ?>/api/investor-get.php" target="_blank" style="color: #667eea; text-decoration: none;">
                        <?= BASE_URL ?>/api/investor-get.php →
                    </a>
                </span>
            </div>
        </div>

        <!-- Module Comparison -->
        <div class="section">
            <h2>📊 Module Status</h2>
            <?php
            $modulePath = 'modules/investor/index.php';
            if (file_exists($modulePath)) {
                $fileSize = filesize($modulePath);
                $lastModified = date('Y-m-d H:i:s', filemtime($modulePath));
                
                echo '<div class="info-row">';
                echo '<span class="label">Investor Module:</span>';
                echo '<span class="value"><span class="badge badge-success">✓ Present</span></span>';
                echo '</div>';
                
                echo '<div class="info-row">';
                echo '<span class="label">File Size:</span>';
                echo '<span class="value">' . number_format($fileSize / 1024, 2) . ' KB</span>';
                echo '</div>';
                
                echo '<div class="info-row">';
                echo '<span class="label">Last Modified:</span>';
                echo '<span class="value">' . $lastModified . '</span>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- Recommendations -->
        <div class="section">
            <h2>💡 Troubleshooting Tips</h2>
            <div style="background: white; padding: 1rem; border-radius: 6px;">
                <ol style="padding-left: 1.5rem; line-height: 1.8;">
                    <li><strong>BASE_URL mismatch:</strong> Pastikan BASE_URL di hosting sesuai dengan domain/path Anda</li>
                    <li><strong>Database issues:</strong> Cek kredensial database di config.php (DB_NAME, DB_USER, DB_PASS)</li>
                    <li><strong>Missing files:</strong> Upload semua file via FTP, pastikan struktur folder sama</li>
                    <li><strong>File permissions:</strong> Set chmod 755 untuk folder, 644 untuk file PHP</li>
                    <li><strong>Cache issues:</strong> Clear browser cache, atau tekan Ctrl+F5</li>
                    <li><strong>PHP version:</strong> Pastikan hosting menggunakan PHP 7.4+ atau PHP 8.x</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>
