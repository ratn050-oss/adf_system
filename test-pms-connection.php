<?php
/**
 * Cloudbed PMS Connection Test
 * Test koneksi dan fungsi-fungsi integrasi Cloudbed PMS
 */

require_once 'config/config.php';
require_once 'includes/CloudbedPMS.php';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $cloudbed = new CloudbedPMS();
    
    switch ($action) {
        case 'test_connection':
            echo json_encode($cloudbed->testConnection());
            break;
            
        case 'get_property_info':
            $result = $cloudbed->getPropertyInfo();
            if ($result['success']) {
                $propertyData = $result['data']['data'] ?? [];
                echo json_encode([
                    'success' => true,
                    'property' => $propertyData
                ]);
            } else {
                echo json_encode($result);
            }
            break;
            
        case 'test_sync_reservations':
            $startDate = $_POST['start_date'] ?? date('Y-m-d');
            $endDate = $_POST['end_date'] ?? date('Y-m-d', strtotime('+7 days'));
            $result = $cloudbed->syncReservationsFromCloudbed($startDate, $endDate);
            echo json_encode($result);
            break;
            
        case 'get_room_rates':
            $result = $cloudbed->getRoomRates();
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'rates' => $result['data']['data'] ?? []
                ]);
            } else {
                echo json_encode($result);
            }
            break;
            
        case 'get_availability':
            $result = $cloudbed->getRoomAvailability();
            if ($result['success']) {
                echo json_encode([
                    'success' => true,
                    'availability' => $result['data']['data'] ?? []
                ]);
            } else {
                echo json_encode($result);
            }
            break;
            
        case 'test_database':
            try {
                $db = Database::getInstance();
                
                // Test basic database connection
                $result = $db->fetchOne("SELECT 1 as test");
                
                if ($result && $result['test'] == 1) {
                    // Check PMS tables exist
                    $pmsTables = [
                        'cloudbed_api_log',
                        'cloudbed_sync_log',
                        'cloudbed_room_mapping',
                        'cloudbed_webhook_events'
                    ];
                    
                    $existingTables = [];
                    $missingTables = [];
                    
                    foreach ($pmsTables as $table) {
                        $tableCheck = $db->fetchOne("SHOW TABLES LIKE '$table'");
                        if ($tableCheck) {
                            $existingTables[] = $table;
                        } else {
                            $missingTables[] = $table;
                        }
                    }
                    
                    // Check enhanced columns
                    $enhancedColumns = [];
                    $reservasiCheck = $db->fetchOne("SHOW COLUMNS FROM reservasi LIKE 'cloudbed_reservation_id'");
                    if ($reservasiCheck) {
                        $enhancedColumns[] = 'reservasi.cloudbed_reservation_id';
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Database connection successful!',
                        'existing_tables' => $existingTables,
                        'missing_tables' => $missingTables,
                        'enhanced_columns' => $enhancedColumns
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

$cloudbed = new CloudbedPMS();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloudbed PMS Connection Test - ADF System</title>
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
        .status-badge {
            position: absolute;
            top: 15px;
            right: 15px;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1><i class="fas fa-plug"></i> Cloudbed PMS Connection Test</h1>
                        <p class="text-muted">Test your Cloudbed Property Management System integration</p>
                    </div>
                    <div>
                        <span class="badge bg-<?php echo $cloudbed->isAvailable() ? 'success' : 'warning'; ?>">
                            PMS Status: <?php echo $cloudbed->isAvailable() ? 'Ready' : 'Not Configured'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Database Test -->
            <div class="col-lg-6 mb-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-database"></i> Database Setup</h5>
                    </div>
                    <div class="card-body">
                        <p>Test database schema and PMS tables</p>
                        <button class="btn btn-primary" onclick="testDatabase()">
                            <i class="fas fa-test"></i> Test Database
                        </button>
                        <div id="database-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Connection Test -->
            <div class="col-lg-6 mb-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-link"></i> API Connection</h5>
                    </div>
                    <div class="card-body">
                        <p>Test connection to Cloudbed API</p>
                        <button class="btn btn-primary" onclick="testConnection()" 
                                <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                            <i class="fas fa-plug"></i> Test Connection
                        </button>
                        <div id="connection-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Property Info -->
            <div class="col-lg-6 mb-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-building"></i> Property Information</h5>
                    </div>
                    <div class="card-body">
                        <p>Retrieve property details from Cloudbed</p>
                        <button class="btn btn-info" onclick="getPropertyInfo()"
                                <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                            <i class="fas fa-info-circle"></i> Get Property Info
                        </button>
                        <div id="property-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Reservation Sync Test -->
            <div class="col-lg-6 mb-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-calendar-check"></i> Reservation Sync</h5>
                    </div>
                    <div class="card-body">
                        <p>Test reservation synchronization</p>
                        <div class="row mb-3">
                            <div class="col-6">
                                <input type="date" id="sync-start-date" class="form-control form-control-sm" 
                                       value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-6">
                                <input type="date" id="sync-end-date" class="form-control form-control-sm" 
                                       value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                            </div>
                        </div>
                        <button class="btn btn-warning" onclick="testReservationSync()"
                                <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                            <i class="fas fa-sync"></i> Test Sync
                        </button>
                        <div id="sync-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Room Rates -->
            <div class="col-lg-6 mb-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-dollar-sign"></i> Room Rates</h5>
                    </div>
                    <div class="card-body">
                        <p>Fetch current room rates from Cloudbed</p>
                        <button class="btn btn-success" onclick="getRoomRates()"
                                <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                            <i class="fas fa-money-bill"></i> Get Rates
                        </button>
                        <div id="rates-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Availability -->
            <div class="col-lg-6 mb-4">
                <div class="card test-card">
                    <div class="card-header">
                        <h5><i class="fas fa-bed"></i> Room Availability</h5>
                    </div>
                    <div class="card-body">
                        <p>Check room availability from Cloudbed</p>
                        <button class="btn btn-info" onclick="getAvailability()"
                                <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                            <i class="fas fa-check-circle"></i> Check Availability
                        </button>
                        <div id="availability-result" class="test-result alert" style="display: none;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test All Button -->
        <div class="row mt-4">
            <div class="col-12 text-center">
                <button class="btn btn-success btn-lg" onclick="testAll()"
                        <?php echo !$cloudbed->isAvailable() ? 'disabled' : ''; ?>>
                    <i class="fas fa-play"></i> Run All Tests
                </button>
            </div>
        </div>

        <!-- Configuration Help -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-<?php echo $cloudbed->isAvailable() ? 'success' : 'warning'; ?>">
                    <?php if ($cloudbed->isAvailable()): ?>
                        <h5><i class="fas fa-check-circle"></i> PMS Integration Ready!</h5>
                        <p>Your Cloudbed PMS integration is configured and ready to use.</p>
                    <?php else: ?>
                        <h5><i class="fas fa-exclamation-triangle"></i> PMS Not Configured</h5>
                        <p>Please configure your Cloudbed credentials first:</p>
                    <?php endif; ?>
                    
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <a href="modules/settings/cloudbed-pms.php" class="btn btn-<?php echo $cloudbed->isAvailable() ? 'outline-primary' : 'primary'; ?> btn-sm">
                                <i class="fas fa-cog"></i> PMS Settings
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="setup-cloudbed-pms.php" class="btn btn-outline-warning btn-sm">
                                <i class="fas fa-database"></i> Setup Database
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="https://developers.cloudbeds.com/" target="_blank" class="btn btn-outline-info btn-sm">
                                <i class="fas fa-book"></i> API Documentation
                            </a>
                        </div>
                        <div class="col-md-3">
                            <a href="modules/frontdesk/reservasi.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-calendar"></i> Reservations
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
            result.innerHTML = '<div class="loading"></div>Testing...';
        }

        function showResult(resultId, data) {
            const result = document.getElementById(resultId);
            result.style.display = 'block';
            
            if (data.success) {
                result.className = 'test-result alert alert-success';
                let content = `<i class="fas fa-check-circle"></i> <strong>Success!</strong><br>${data.message}`;
                
                // Add specific data based on result type
                if (data.property) {
                    content += `<br><small><strong>Property:</strong> ${data.property.propertyName || 'N/A'}</small>`;
                    content += `<br><small><strong>ID:</strong> ${data.property.propertyID || 'N/A'}</small>`;
                    content += `<br><small><strong>Rooms:</strong> ${data.property.roomCount || 'N/A'}</small>`;
                }
                
                if (data.existing_tables) {
                    content += `<br><small><strong>PMS Tables:</strong> ${data.existing_tables.length} found</small>`;
                }
                
                if (data.missing_tables && data.missing_tables.length > 0) {
                    content += `<br><small style="color: orange;"><strong>Missing:</strong> ${data.missing_tables.join(', ')}</small>`;
                }
                
                if (data.synced_count !== undefined) {
                    content += `<br><small><strong>Synced:</strong> ${data.synced_count} reservations</small>`;
                }
                
                if (data.rates && data.rates.length > 0) {
                    content += `<br><small><strong>Rate Data:</strong> ${data.rates.length} room types</small>`;
                }
                
                if (data.availability && data.availability.length > 0) {
                    content += `<br><small><strong>Availability:</strong> ${data.availability.length} room types</small>`;
                }
                
                result.innerHTML = content;
            } else {
                result.className = 'test-result alert alert-danger';
                result.innerHTML = `
                    <i class="fas fa-exclamation-circle"></i> <strong>Failed!</strong><br>
                    ${data.error}
                    ${data.http_code ? '<br><small>HTTP Code: ' + data.http_code + '</small>' : ''}
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

        function testConnection() {
            showLoading('connection-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=test_connection'
            })
            .then(response => response.json())
            .then(data => showResult('connection-result', data))
            .catch(error => showResult('connection-result', {success: false, error: error.message}));
        }

        function getPropertyInfo() {
            showLoading('property-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_property_info'
            })
            .then(response => response.json())
            .then(data => showResult('property-result', data))
            .catch(error => showResult('property-result', {success: false, error: error.message}));
        }

        function testReservationSync() {
            const startDate = document.getElementById('sync-start-date').value;
            const endDate = document.getElementById('sync-end-date').value;
            
            showLoading('sync-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=test_sync_reservations&start_date=${startDate}&end_date=${endDate}`
            })
            .then(response => response.json())
            .then(data => showResult('sync-result', data))
            .catch(error => showResult('sync-result', {success: false, error: error.message}));
        }

        function getRoomRates() {
            showLoading('rates-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_room_rates'
            })
            .then(response => response.json())
            .then(data => showResult('rates-result', data))
            .catch(error => showResult('rates-result', {success: false, error: error.message}));
        }

        function getAvailability() {
            showLoading('availability-result');
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_availability'
            })
            .then(response => response.json())
            .then(data => showResult('availability-result', data))
            .catch(error => showResult('availability-result', {success: false, error: error.message}));
        }

        function testAll() {
            testDatabase();
            setTimeout(() => testConnection(), 1000);
            setTimeout(() => getPropertyInfo(), 2000);
            setTimeout(() => testReservationSync(), 3000);
            setTimeout(() => getRoomRates(), 4000);
            setTimeout(() => getAvailability(), 5000);
        }
    </script>
</body>
</html>