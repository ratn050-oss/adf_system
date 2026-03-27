<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$isLoggedIn = $auth->isLoggedIn();
$isOwner = false;
$currentUser = null;

if ($isLoggedIn) {
    $currentUser = $auth->getCurrentUser();
    $isOwner = $auth->hasRole('owner') || $auth->hasRole('admin');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard Debug</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 10px;
            background: #f5f5f5;
            font-size: 14px;
        }
        .section {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        h2 { margin-top: 0; color: #1e1b4b; }
        button {
            background: #4338ca;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            margin: 5px 5px 5px 0;
            font-size: 14px;
        }
        pre {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        .logo-test {
            background: linear-gradient(135deg, #1e1b4b, #4338ca);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .logo-test img {
            background: white;
            padding: 10px;
            border-radius: 8px;
            max-height: 60px;
        }
    </style>
</head>
<body>
    <h1>🔍 Owner Dashboard Debug</h1>
    
    <div class="section">
        <h2>1. Authentication Status</h2>
        <p><strong>Logged In:</strong> <?php echo $isLoggedIn ? '<span class="success">✓ Yes</span>' : '<span class="error">✗ No</span>'; ?></p>
        <?php if ($isLoggedIn): ?>
            <p><strong>User:</strong> <?php echo htmlspecialchars($currentUser['username']); ?></p>
            <p><strong>Role:</strong> <?php echo htmlspecialchars($currentUser['role']); ?></p>
            <p><strong>Is Owner/Admin:</strong> <?php echo $isOwner ? '<span class="success">✓ Yes</span>' : '<span class="error">✗ No</span>'; ?></p>
        <?php else: ?>
            <p class="warning">⚠ You need to login first!</p>
            <a href="../../login.php" style="display: inline-block; background: #4338ca; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px;">Go to Login</a>
        <?php endif; ?>
    </div>
    
    <div class="section">
        <h2>2. Logo Test</h2>
        <div class="logo-test">
            <img src="../../uploads/logos/logo.png" 
                 alt="Logo" 
                 onerror="this.style.display='none'; document.getElementById('logoError').style.display='block';">
            <div id="logoError" style="display:none; color: white;">❌ Logo failed to load</div>
            <p style="color: white; margin: 10px 0 0 0; font-size: 12px;">../../uploads/logos/logo.png</p>
        </div>
        
        <h3>Alternative Paths Test:</h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <div style="background: #eee; padding: 10px; border-radius: 5px;">
                <p><strong>Relative Path:</strong></p>
                <img src="../../uploads/logos/logo.png" style="height: 40px; border: 2px solid blue;">
            </div>
            <div style="background: #eee; padding: 10px; border-radius: 5px;">
                <p><strong>Absolute Path:</strong></p>
                <img src="/narayana/uploads/logos/logo.png" style="height: 40px; border: 2px solid green;">
            </div>
        </div>
    </div>
    
    <?php if ($isOwner): ?>
    <div class="section">
        <h2>3. API Tests</h2>
        
        <button onclick="testAPI('branches')">Test Branches API</button>
        <button onclick="testAPI('stats')">Test Stats API</button>
        <button onclick="testAPI('chart')">Test Chart API</button>
        <button onclick="testAPI('occupancy')">Test Occupancy API</button>
        
        <div id="apiResults"></div>
    </div>
    
    <div class="section">
        <h2>4. Chart Test</h2>
        <canvas id="testChart" style="max-height: 200px;"></canvas>
        <button onclick="loadChart()">Load Chart</button>
        <div id="chartStatus"></div>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>5. Server Info</h2>
        <p><strong>Server Time:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        <p><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></p>
        <p><strong>User Agent:</strong> <small><?php echo $_SERVER['HTTP_USER_AGENT']; ?></small></p>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        let testChart = null;
        
        async function testAPI(type) {
            const resultDiv = document.getElementById('apiResults');
            resultDiv.innerHTML = '<p>Testing ' + type + ' API...</p>';
            
            try {
                let url;
                switch(type) {
                    case 'branches':
                        url = '../../api/owner-branches.php';
                        break;
                    case 'stats':
                        url = '../../api/owner-stats.php';
                        break;
                    case 'chart':
                        url = '../../api/owner-chart-data.php?period=7days';
                        break;
                    case 'occupancy':
                        url = '../../api/owner-occupancy.php';
                        break;
                }
                
                console.log('Testing URL:', url);
                const response = await fetch(url);
                const data = await response.json();
                
                console.log('Response:', data);
                
                resultDiv.innerHTML = '<h3>' + type.toUpperCase() + ' API Result:</h3><pre>' + 
                    JSON.stringify(data, null, 2) + '</pre>';
                    
                if (data.success) {
                    resultDiv.innerHTML = '<p class="success">✓ ' + type + ' API works!</p>' + resultDiv.innerHTML;
                } else {
                    resultDiv.innerHTML = '<p class="error">✗ ' + type + ' API failed: ' + data.message + '</p>' + resultDiv.innerHTML;
                }
            } catch (error) {
                console.error('Error:', error);
                resultDiv.innerHTML = '<p class="error">✗ Error: ' + error.message + '</p>';
            }
        }
        
        async function loadChart() {
            const statusDiv = document.getElementById('chartStatus');
            statusDiv.innerHTML = '<p>Loading chart data...</p>';
            
            try {
                const response = await fetch('../../api/owner-chart-data.php?period=7days');
                const result = await response.json();
                
                console.log('Chart data:', result);
                
                if (result.success && result.data) {
                    statusDiv.innerHTML = '<p class="success">✓ Chart data loaded!</p>';
                    
                    const ctx = document.getElementById('testChart').getContext('2d');
                    
                    if (testChart) {
                        testChart.destroy();
                    }
                    
                    testChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: result.data.labels,
                            datasets: [
                                {
                                    label: 'Income',
                                    data: result.data.income,
                                    borderColor: '#10b981',
                                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                    tension: 0.4
                                },
                                {
                                    label: 'Expense',
                                    data: result.data.expense,
                                    borderColor: '#ef4444',
                                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                    tension: 0.4
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                } else {
                    statusDiv.innerHTML = '<p class="error">✗ Failed: ' + (result.message || 'Unknown error') + '</p>';
                }
            } catch (error) {
                console.error('Chart error:', error);
                statusDiv.innerHTML = '<p class="error">✗ Error: ' + error.message + '</p>';
            }
        }
        
        // Test logo loading on page load
        window.addEventListener('load', function() {
            const img = new Image();
            img.onload = () => console.log('✓ Logo loaded successfully:', img.width + 'x' + img.height);
            img.onerror = () => console.error('✗ Logo failed to load');
            img.src = '../../uploads/logos/logo.png';
        });
    </script>
</body>
</html>
