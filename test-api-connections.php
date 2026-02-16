<?php
/**
 * API Connection Test Utility
 * Test koneksi ke OpenAI dan Cloudbed API
 */

require_once 'config/config.php';
require_once 'includes/OpenAIHelper.php';
require_once 'includes/CloudbedHelper.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'test_openai':
            $openai = new OpenAIHelper();
            
            if (!$openai->isAvailable()) {
                echo json_encode([
                    'success' => false,
                    'error' => 'OpenAI not configured. Please set your API key first.'
                ]);
                break;
            }
            
            try {
                // Simple test completion
                $result = $openai->generateCompletion(
                    'Say "Hello from OpenAI!" in exactly 5 words.',
                    'You are a helpful assistant.',
                    50
                );
                
                if ($result['success']) {
                    $response = $result['data']['choices'][0]['message']['content'];
                    echo json_encode([
                        'success' => true,
                        'message' => 'OpenAI connection successful!',
                        'response' => $response,
                        'model' => $result['data']['model'] ?? 'unknown'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => $result['error']
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Exception: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'test_cloudbed':
            $cloudbed = new CloudbedHelper();
            
            if (!$cloudbed->isAvailable()) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Cloudbed not configured. Please set your credentials first.'
                ]);
                break;
            }
            
            try {
                $result = $cloudbed->testConnection();
                
                if ($result['success']) {
                    $propertyData = $result['data']['data'] ?? [];
                    echo json_encode([
                        'success' => true,
                        'message' => 'Cloudbed connection successful!',
                        'property_name' => $propertyData['propertyName'] ?? 'Unknown',
                        'property_id' => $propertyData['propertyID'] ?? 'Unknown'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => $result['error']
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Exception: ' . $e->getMessage()
                ]);
            }
            break;
            
        case 'test_database':
            try {
                $db = Database::getInstance();
                
                // Test basic database connection
                $result = $db->fetchOne("SELECT 1 as test");
                
                if ($result && $result['test'] == 1) {
                    // Check AI tables exist
                    $aiTables = [
                        'review_analysis',
                        'guest_sync', 
                        'daily_reports',
                        'api_usage_log'
                    ];
                    
                    $existingTables = [];
                    $missingTables = [];
                    
                    foreach ($aiTables as $table) {
                        $tableCheck = $db->fetchOne("SHOW TABLES LIKE '$table'");
                        if ($tableCheck) {
                            $existingTables[] = $table;
                        } else {
                            $missingTables[] = $table;
                        }
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Database connection successful!',
                        'existing_tables' => $existingTables,
                        'missing_tables' => $missingTables
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'Database test query failed'
                    ]);
                }
            } catch (Exception $e) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Database error: ' . $e->getMessage()
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Unknown action'
            ]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Connection Test - ADF System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .test-card {
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        .test-card:hover {
            transform: translateY(-2px);
        }
        .test-result {
            min-height: 80px;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        .loading {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #007bff;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <h1><i class="fas fa-plug"></i> API Connection Test</h1>
                <p class="text-muted">Test your API integrations and database setup</p>
                <hr>
            </div>
        </div>

        <div class="row">
            <!-- Database Test -->
            <div class="col-md-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-database"></i> Database Connection</h5>
                    </div>
                    <div class="card-body">
                        <p>Test database connection and AI tables setup</p>
                        <button class="btn btn-primary" onclick="testDatabase()">
                            <i class="fas fa-test"></i> Test Database
                        </button>
                        <div id="database-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- OpenAI Test -->
            <div class="col-md-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-brain"></i> OpenAI API</h5>
                    </div>
                    <div class="card-body">
                        <p>Test OpenAI API connection and authentication</p>
                        <button class="btn btn-primary" onclick="testOpenAI()">
                            <i class="fas fa-test"></i> Test OpenAI
                        </button>
                        <div id="openai-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Cloudbed Test -->
            <div class="col-md-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-cloud"></i> Cloudbed API</h5>
                    </div>
                    <div class="card-body">
                        <p>Test Cloudbed API connection and property access</p>
                        <button class="btn btn-primary" onclick="testCloudbed()">
                            <i class="fas fa-test"></i> Test Cloudbed
                        </button>
                        <div id="cloudbed-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test All Button -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <button class="btn btn-success btn-lg" onclick="testAll()">
                    <i class="fas fa-play"></i> Test All Connections
                </button>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <h5><i class="fas fa-link"></i> Quick Links:</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <a href="modules/settings/api-integrations.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-cog"></i> API Settings
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="setup-ai-features.php" class="btn btn-outline-warning btn-sm">
                                <i class="fas fa-database"></i> Setup Database
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="ai-features-demo.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-play"></i> Try AI Features
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showLoading(resultId) {
            const result = document.getElementById(resultId);
            result.style.display = 'block';
            result.className = 'test-result alert alert-info';
            result.innerHTML = '<div class="loading"></div>Testing connection...';
        }

        function showResult(resultId, data) {
            const result = document.getElementById(resultId);
            result.style.display = 'block';
            
            if (data.success) {
                result.className = 'test-result alert alert-success';
                result.innerHTML = `
                    <i class="fas fa-check-circle"></i> <strong>Success!</strong><br>
                    ${data.message}
                    ${data.response ? '<br><small><strong>Response:</strong> ' + data.response + '</small>' : ''}
                    ${data.model ? '<br><small><strong>Model:</strong> ' + data.model + '</small>' : ''}
                    ${data.property_name ? '<br><small><strong>Property:</strong> ' + data.property_name + '</small>' : ''}
                    ${data.existing_tables ? '<br><small><strong>AI Tables:</strong> ' + data.existing_tables.length + ' found</small>' : ''}
                    ${data.missing_tables && data.missing_tables.length > 0 ? '<br><small style="color: orange;"><strong>Missing Tables:</strong> ' + data.missing_tables.join(', ') + '</small>' : ''}
                `;
            } else {
                result.className = 'test-result alert alert-danger';
                result.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i> <strong>Failed!</strong><br>
                    ${data.error}
                `;
            }
        }

        function testDatabase() {
            showLoading('database-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=test_database'
            })
            .then(response => response.json())
            .then(data => showResult('database-result', data))
            .catch(error => showResult('database-result', {success: false, error: error.message}));
        }

        function testOpenAI() {
            showLoading('openai-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=test_openai'
            })
            .then(response => response.json())
            .then(data => showResult('openai-result', data))
            .catch(error => showResult('openai-result', {success: false, error: error.message}));
        }

        function testCloudbed() {
            showLoading('cloudbed-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=test_cloudbed'
            })
            .then(response => response.json())
            .then(data => showResult('cloudbed-result', data))
            .catch(error => showResult('cloudbed-result', {success: false, error: error.message}));
        }

        function testAll() {
            testDatabase();
            setTimeout(() => testOpenAI(), 500);
            setTimeout(() => testCloudbed(), 1000);
        }
    </script>
</body>
</html>