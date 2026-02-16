<?php
/**
 * API INTEGRATIONS MANAGER
 * Manage OpenAI API, Cloudbed API, and other third-party integrations
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

// Check authorization - Only Admin/Owner can manage API integrations
$userRole = $_SESSION['role'] ?? '';
if (!in_array($userRole, ['admin', 'owner', 'developer'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$db = Database::getInstance();
$message = '';
$error = '';

// Handle API settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    try {
        if ($action === 'save_openai') {
            $apiKey = trim($_POST['openai_api_key']);
            $model = $_POST['openai_model'] ?? 'gpt-3.5-turbo';
            $isActive = isset($_POST['openai_active']) ? 1 : 0;
            
            // Validate API key format
            if (!empty($apiKey) && !preg_match('/^sk-[a-zA-Z0-9]{48,}$/', $apiKey)) {
                throw new Exception('Invalid OpenAI API key format');
            }
            
            // Save to database
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('openai_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$apiKey, $apiKey]);
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('openai_model', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$model, $model]);
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('openai_active', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$isActive, $isActive]);
            
            $message = 'OpenAI API settings berhasil disimpan!';
            
        } elseif ($action === 'save_cloudbed') {
            $clientId = trim($_POST['cloudbed_client_id']);
            $clientSecret = trim($_POST['cloudbed_client_secret']);
            $propertyId = trim($_POST['cloudbed_property_id']);
            $isActive = isset($_POST['cloudbed_active']) ? 1 : 0;
            
            // Save to database
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbed_client_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$clientId, $clientId]);
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbed_client_secret', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$clientSecret, $clientSecret]);
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbed_property_id', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$propertyId, $propertyId]);
            $db->query("INSERT INTO settings (setting_key, setting_value) VALUES ('cloudbed_active', ?) ON DUPLICATE KEY UPDATE setting_value = ?", [$isActive, $isActive]);
            
            $message = 'Cloudbed API settings berhasil disimpan!';
            
        } elseif ($action === 'test_openai') {
            // Test OpenAI API connection
            $apiKeySetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'openai_api_key'");
            $apiKey = $apiKeySetting['setting_value'] ?? '';
            
            if (empty($apiKey)) {
                throw new Exception('OpenAI API key not configured');
            }
            
            $testResult = testOpenAIConnection($apiKey);
            if ($testResult['success']) {
                $message = '✅ OpenAI API connection successful! Model: ' . $testResult['model'];
            } else {
                $error = '❌ OpenAI API test failed: ' . $testResult['error'];
            }
            
        } elseif ($action === 'test_cloudbed') {
            // Test Cloudbed API connection
            $clientIdSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_client_id'");
            $clientSecretSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'cloudbed_client_secret'");
            
            $clientId = $clientIdSetting['setting_value'] ?? '';
            $clientSecret = $clientSecretSetting['setting_value'] ?? '';
            
            if (empty($clientId) || empty($clientSecret)) {
                throw new Exception('Cloudbed credentials not configured');
            }
            
            $testResult = testCloudbedConnection($clientId, $clientSecret);
            if ($testResult['success']) {
                $message = '✅ Cloudbed API connection successful! Property: ' . $testResult['property_name'];
            } else {
                $error = '❌ Cloudbed API test failed: ' . $testResult['error'];
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get current settings
function getSetting($key, $default = '') {
    global $db;
    $result = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return $result['setting_value'] ?? $default;
}

// Test OpenAI API connection
function testOpenAIConnection($apiKey) {
    $curl = curl_init();
    
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.openai.com/v1/models',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return [
            'success' => true,
            'model' => count($data['data'] ?? []) . ' models available'
        ];
    } else {
        $errorData = json_decode($response, true);
        return [
            'success' => false,
            'error' => $errorData['error']['message'] ?? 'Connection failed'
        ];
    }
}

// Test Cloudbed API connection
function testCloudbedConnection($clientId, $clientSecret) {
    // First get access token
    $curl = curl_init();
    
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://hotels.cloudbeds.com/api/v1.1/access_token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'read:hotel'
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode === 200) {
        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? '';
        
        if (empty($accessToken)) {
            return ['success' => false, 'error' => 'Failed to get access token'];
        }
        
        // Test API with hotel info
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://hotels.cloudbeds.com/api/v1.1/getHotel',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);
        
        $hotelResponse = curl_exec($curl);
        $hotelHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($hotelHttpCode === 200) {
            $hotelData = json_decode($hotelResponse, true);
            return [
                'success' => true,
                'property_name' => $hotelData['data']['propertyName'] ?? 'Connected'
            ];
        }
    }
    
    $errorData = json_decode($response, true);
    return [
        'success' => false,
        'error' => $errorData['error_description'] ?? 'Connection failed'
    ];
}

$currentSettings = [
    'openai_api_key' => getSetting('openai_api_key'),
    'openai_model' => getSetting('openai_model', 'gpt-3.5-turbo'),
    'openai_active' => getSetting('openai_active', '0'),
    'cloudbed_client_id' => getSetting('cloudbed_client_id'),
    'cloudbed_client_secret' => getSetting('cloudbed_client_secret'),
    'cloudbed_property_id' => getSetting('cloudbed_property_id'),
    'cloudbed_active' => getSetting('cloudbed_active', '0'),
];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Integrations - ADF System</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .integrations-container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .integration-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin: 1.5rem 0;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #e5e7eb;
        }
        .integration-card.openai { border-left-color: #10b981; }
        .integration-card.cloudbed { border-left-color: #3b82f6; }
        .integration-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .integration-logo {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            color: white;
        }
        .integration-logo.openai { background: #10b981; }
        .integration-logo.cloudbed { background: #3b82f6; }
        .form-group {
            margin: 1rem 0;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            margin-right: 0.5rem;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn:hover { opacity: 0.9; }
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #ef4444; }
        .feature-list {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .feature-list h4 {
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .feature-list ul {
            margin: 0;
            padding-left: 1.5rem;
            color: #6b7280;
        }
    </style>
</head>
<body>

<div class="integrations-container">
    <h1>🔌 API Integrations Management</h1>
    <p>Kelola integrasi dengan layanan pihak ketiga untuk fitur AI dan PMS.</p>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- OpenAI Integration -->
    <div class="integration-card openai">
        <div class="integration-header">
            <div class="integration-logo openai">AI</div>
            <div>
                <h2>OpenAI API Integration</h2>
                <div class="status-indicator <?= $currentSettings['openai_active'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $currentSettings['openai_active'] ? '🟢 Active' : '🔴 Inactive' ?>
                </div>
            </div>
        </div>
        
        <div class="feature-list">
            <h4>🚀 Fitur yang Tersedia:</h4>
            <ul>
                <li><strong>Smart Guest Assistant:</strong> AI chat untuk guest inquiries</li>
                <li><strong>Review Analysis:</strong> Analisis sentimen review otomatis</li>
                <li><strong>Revenue Optimization:</strong> AI pricing recommendations</li>
                <li><strong>Automated Reports:</strong> Generate laporan dengan insights AI</li>
                <li><strong>Guest Preferences:</strong> Prediksi preferensi tamu dari data historis</li>
            </ul>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_openai">
            
            <div class="form-group">
                <label for="openai_api_key">OpenAI API Key:</label>
                <input type="password" name="openai_api_key" id="openai_api_key" 
                       class="form-control" 
                       value="<?= htmlspecialchars($currentSettings['openai_api_key']) ?>"
                       placeholder="sk-...">
                <small style="color: #6b7280;">Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a></small>
            </div>
            
            <div class="form-group">
                <label for="openai_model">Model:</label>
                <select name="openai_model" id="openai_model" class="form-control">
                    <option value="gpt-3.5-turbo" <?= $currentSettings['openai_model'] === 'gpt-3.5-turbo' ? 'selected' : '' ?>>GPT-3.5 Turbo (Recommended)</option>
                    <option value="gpt-4" <?= $currentSettings['openai_model'] === 'gpt-4' ? 'selected' : '' ?>>GPT-4 (Premium)</option>
                    <option value="gpt-4-turbo-preview" <?= $currentSettings['openai_model'] === 'gpt-4-turbo-preview' ? 'selected' : '' ?>>GPT-4 Turbo Preview</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="openai_active" value="1" <?= $currentSettings['openai_active'] ? 'checked' : '' ?>>
                    Aktifkan OpenAI Integration
                </label>
            </div>
            
            <button type="submit" class="btn btn-success">💾 Save OpenAI Settings</button>
        </form>
        
        <form method="POST" style="margin-top: 1rem;">
            <input type="hidden" name="action" value="test_openai">
            <button type="submit" class="btn btn-secondary">🧪 Test Connection</button>
        </form>
    </div>
    
    <!-- Cloudbed Integration -->
    <div class="integration-card cloudbed">
        <div class="integration-header">
            <div class="integration-logo cloudbed">CB</div>
            <div>
                <h2>Cloudbed API Integration</h2>
                <div class="status-indicator <?= $currentSettings['cloudbed_active'] ? 'status-active' : 'status-inactive' ?>">
                    <?= $currentSettings['cloudbed_active'] ? '🟢 Active' : '🔴 Inactive' ?>
                </div>
            </div>
        </div>
        
        <div class="feature-list">
            <h4>🏨 Fitur yang Tersedia:</h4>
            <ul>
                <li><strong>Real-time Synchronization:</strong> Sync reservations & room status</li>
                <li><strong>Guest Data Import:</strong> Import guest profiles dari Cloudbed</li>
                <li><strong>Rate Management:</strong> Sync pricing dengan Cloudbed rates</li>
                <li><strong>Inventory Sync:</strong> Real-time room availability updates</li>
                <li><strong>Unified Reporting:</strong> Combine data dari ADF + Cloudbed</li>
            </ul>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="save_cloudbed">
            
            <div class="form-group">
                <label for="cloudbed_client_id">Client ID:</label>
                <input type="text" name="cloudbed_client_id" id="cloudbed_client_id" 
                       class="form-control" 
                       value="<?= htmlspecialchars($currentSettings['cloudbed_client_id']) ?>"
                       placeholder="Your Cloudbed Client ID">
            </div>
            
            <div class="form-group">
                <label for="cloudbed_client_secret">Client Secret:</label>
                <input type="password" name="cloudbed_client_secret" id="cloudbed_client_secret" 
                       class="form-control" 
                       value="<?= htmlspecialchars($currentSettings['cloudbed_client_secret']) ?>"
                       placeholder="Your Cloudbed Client Secret">
                <small style="color: #6b7280;">Get credentials from Cloudbed Developer Portal</small>
            </div>
            
            <div class="form-group">
                <label for="cloudbed_property_id">Property ID:</label>
                <input type="text" name="cloudbed_property_id" id="cloudbed_property_id" 
                       class="form-control" 
                       value="<?= htmlspecialchars($currentSettings['cloudbed_property_id']) ?>"
                       placeholder="Your Property ID (optional)">
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="cloudbed_active" value="1" <?= $currentSettings['cloudbed_active'] ? 'checked' : '' ?>>
                    Aktifkan Cloudbed Integration
                </label>
            </div>
            
            <button type="submit" class="btn btn-success">💾 Save Cloudbed Settings</button>
        </form>
        
        <form method="POST" style="margin-top: 1rem;">
            <input type="hidden" name="action" value="test_cloudbed">
            <button type="submit" class="btn btn-secondary">🧪 Test Connection</button>
        </form>
    </div>
</div>

<div style="text-align: center; margin: 2rem 0;">
    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">← Back to Dashboard</a>
</div>

</body>
</html>