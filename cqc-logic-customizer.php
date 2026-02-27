<?php
/**
 * CQC Business Logic Customizer
 * Kustomisasi logika bisnis, fields, dan behavior khusus untuk CQC
 */

header('Content-Type: text/html; charset=utf-8');

$dbHost = 'localhost';
$dbUser = 'root';
$dbPass = '';
$masterDb = 'adf_system';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$masterDb", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get CQC config file
    $configFile = __DIR__ . '/config/businesses/cqc.php';
    $config = file_exists($configFile) ? require $configFile : [];
    
    $message = '';
    
    // Handle POST - save logic changes
    if ($_POST && isset($_POST['save_logic'])) {
        // Get form data
        $cashbookColumns = isset($_POST['cashbook_cols']) ? explode(',', $_POST['cashbook_cols']) : [];
        $dashboardWidgets = isset($_POST['dashboard_widgets']) ? $_POST['dashboard_widgets'] : [];
        $customFields = isset($_POST['custom_fields_json']) ? json_decode($_POST['custom_fields_json'], true) : [];
        $businessType = $_POST['business_type'] ?? 'contractor';
        
        // Build new config preserving existing settings
        $newConfig = "<?php\nreturn [\n";
        $newConfig .= "    'business_id' => 'cqc',\n";
        $newConfig .= "    'name' => '" . addslashes($config['name'] ?? 'CQC Enjiniring') . "',\n";
        $newConfig .= "    'business_type' => '" . $businessType . "',\n";
        $newConfig .= "    'database' => 'adf_cqc',\n";
        $newConfig .= "    'logo' => '',\n";
        
        // Enabled modules
        $newConfig .= "    'enabled_modules' => [\n";
        $newConfig .= "        'cashbook',\n";
        $newConfig .= "        'auth',\n";
        $newConfig .= "        'settings',\n";
        $newConfig .= "        'reports',\n";
        $newConfig .= "        'divisions',\n";
        $newConfig .= "        'procurement',\n";
        $newConfig .= "        'sales',\n";
        $newConfig .= "        'bills',\n";
        $newConfig .= "        'payroll'\n";
        $newConfig .= "    ],\n";
        
        // Theme
        $newConfig .= "    'theme' => [\n";
        $newConfig .= "        'color_primary' => '" . ($config['theme']['color_primary'] ?? '#059669') . "',\n";
        $newConfig .= "        'color_secondary' => '" . ($config['theme']['color_secondary'] ?? '#065f46') . "',\n";
        $newConfig .= "        'icon' => '" . ($config['theme']['icon'] ?? '🏢') . "'\n";
        $newConfig .= "    ],\n";
        
        // Cashbook columns - LOGIC
        $newConfig .= "    'cashbook_columns' => [\n";
        foreach ($cashbookColumns as $col) {
            $col = trim($col);
            if ($col) {
                $newConfig .= "        '" . str_replace("'", "\\'", $col) . "' => [\n";
                $newConfig .= "            'label' => '" . ucfirst(str_replace('_', ' ', $col)) . "',\n";
                $newConfig .= "            'type' => 'text',\n";
                $newConfig .= "            'required' => false\n";
                $newConfig .= "        ],\n";
            }
        }
        $newConfig .= "    ],\n";
        
        // Dashboard widgets - LOGIC
        $newConfig .= "    'dashboard_widgets' => [\n";
        foreach ($dashboardWidgets as $widget) {
            $newConfig .= "        'show_" . $widget . "' => true,\n";
        }
        $newConfig .= "    ],\n";
        
        // Custom fields - LOGIC
        if (!empty($customFields)) {
            $newConfig .= "    'custom_fields' => " . var_export($customFields, true) . ",\n";
        }
        
        $newConfig .= "];\n";
        
        if (file_put_contents($configFile, $newConfig)) {
            $message = "✅ Logika bisnis CQC berhasil diperbarui!";
            $config = require $configFile;
        } else {
            $message = "❌ Gagal menyimpan. Cek permission file.";
        }
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$businessType = $config['business_type'] ?? 'contractor';
$cashbookColumns = array_keys($config['cashbook_columns'] ?? []);
$dashboardWidgets = $config['dashboard_widgets'] ?? [];

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CQC Logic Customization | ADF System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .header {
            background: white;
            padding: 2rem;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 0;
        }
        .header h1 {
            font-size: 2rem;
            color: #333;
            margin-bottom: 0.5rem;
        }
        .header p {
            color: #666;
        }
        .content {
            background: white;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        .message.success {
            background: #e8f5e9;
            border: 1px solid #4caf50;
            color: #2e7d32;
        }
        .section {
            margin-bottom: 2.5rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid #e0e0e0;
        }
        .section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        h2 {
            font-size: 1.4rem;
            color: #333;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #333;
        }
        input[type="text"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
            font-family: inherit;
        }
        input[type="text"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: #667eea;
        }
        .checkbox-item label {
            margin: 0;
            font-weight: normal;
            cursor: pointer;
            user-select: none;
        }
        .multi-input {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .multi-input input {
            flex: 1;
        }
        button {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }
        .btn-secondary:hover {
            background: #e0e0e0;
        }
        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #1565c0;
        }
        .feature-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .feature-card {
            background: #f9f9f9;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .feature-card h3 {
            font-size: 1.1rem;
            margin-bottom: 0.75rem;
            color: #333;
        }
        .feature-card p {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.5;
        }
        .col-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        @media (max-width: 768px) {
            .col-2 {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚙️ CQC Business Logic Customizer</h1>
            <p>Kustomisasi logika bisnis, field, dan behavior khusus untuk CQC</p>
        </div>
        
        <div class="content">
            <?php if ($message): ?>
            <div class="message success">
                <?php echo $message; ?>
            </div>
            <?php endif; ?>
            
            <div class="info-box">
                💡 <strong>Dengan customizer ini, Anda bisa:</strong><br>
                ✓ Tentukan kolom cashbook yang relevan untuk CQC<br>
                ✓ Pilih widget dashboard apa yang ditampilkan<br>
                ✓ Definisikan field custom sesuai kebutuhan kontraktor<br>
                ✓ Sistem akan automatically load config ini saat user ke CQC
            </div>
            
            <form method="POST">
                <!-- Business Type -->
                <div class="section">
                    <h2>📋 Tipe Bisnis</h2>
                    <div class="form-group">
                        <label for="business_type">Pilih Tipe Bisnis CQC</label>
                        <select id="business_type" name="business_type" required>
                            <option value="contractor" <?php echo $businessType === 'contractor' ? 'selected' : ''; ?>>
                                🏗️ Kontraktor/Engineering
                            </option>
                            <option value="construction" <?php echo $businessType === 'construction' ? 'selected' : ''; ?>>
                                🏢 Konstruksi
                            </option>
                            <option value="service" <?php echo $businessType === 'service' ? 'selected' : ''; ?>>
                                🔧 Service & Maintenance
                            </option>
                            <option value="other" <?php echo $businessType === 'other' ? 'selected' : ''; ?>>
                                📦 Lainnya
                            </option>
                        </select>
                    </div>
                </div>
                
                <!-- Cashbook Columns Logic -->
                <div class="section">
                    <h2>📊 Kolom Cashbook Khusus CQC</h2>
                    <p style="margin-bottom: 1rem; color: #666;">
                        Tentukan kolom tambahan yang diperlukan untuk tracking keuangan CQC. 
                        Contoh: project_code, cost_center, allocation_id, work_order, etc.
                    </p>
                    
                    <div class="form-group">
                        <label>Kolom Cashbook Tambahan (pisahkan dengan koma)</label>
                        <textarea 
                            name="cashbook_cols"
                            placeholder="Contoh: project_code, cost_center, work_order, allocation_id, equipment_code"
                        ><?php echo implode(', ', $cashbookColumns); ?></textarea>
                        <small style="color: #999;">Setiap kolom akan ditambahkan ke form Cashbook CQC</small>
                    </div>
                </div>
                
                <!-- Dashboard Widgets Logic -->
                <div class="section">
                    <h2>📈 Dashboard Widgets untuk CQC</h2>
                    <p style="margin-bottom: 1rem; color: #666;">
                        Pilih widget mana yang ingin ditampilkan di dashboard CQC.
                    </p>
                    
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" id="widget_daily_sales" name="dashboard_widgets" value="daily_sales" 
                                <?php echo isset($dashboardWidgets['show_daily_sales']) && $dashboardWidgets['show_daily_sales'] ? 'checked' : ''; ?>>
                            <label for="widget_daily_sales">📅 Daily Sales/Income</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="widget_orders" name="dashboard_widgets" value="orders"
                                <?php echo isset($dashboardWidgets['show_orders']) && $dashboardWidgets['show_orders'] ? 'checked' : ''; ?>>
                            <label for="widget_orders">📦 Orders/Projects</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="widget_revenue" name="dashboard_widgets" value="revenue"
                                <?php echo isset($dashboardWidgets['show_revenue']) && $dashboardWidgets['show_revenue'] ? 'checked' : ''; ?>>
                            <label for="widget_revenue">💰 Total Revenue</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="widget_pending" name="dashboard_widgets" value="pending"
                                <?php echo isset($dashboardWidgets['show_pending']) && $dashboardWidgets['show_pending'] ? 'checked' : ''; ?>>
                            <label for="widget_pending">⏳ Pending Items</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="widget_expenses" name="dashboard_widgets" value="expenses"
                                <?php echo isset($dashboardWidgets['show_expenses']) && $dashboardWidgets['show_expenses'] ? 'checked' : ''; ?>>
                            <label for="widget_expenses">💸 Expenses</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="widget_cash_flow" name="dashboard_widgets" value="cash_flow"
                                <?php echo isset($dashboardWidgets['show_cash_flow']) && $dashboardWidgets['show_cash_flow'] ? 'checked' : ''; ?>>
                            <label for="widget_cash_flow">📊 Cash Flow</label>
                        </div>
                    </div>
                </div>
                
                <!-- Custom Fields Logic -->
                <div class="section">
                    <h2>🎯 Custom Fields untuk CQC</h2>
                    <p style="margin-bottom: 1rem; color: #666;">
                        Definisikan field custom yang hanya digunakan oleh CQC (akan automatis ditambahkan ke forms dan reports).
                    </p>
                    
                    <div class="form-group">
                        <label>Custom Fields (JSON format, opsional)</label>
                        <textarea 
                            name="custom_fields_json"
                            placeholder='{
  "project_code": {"label": "Kode Proyek", "type": "text", "required": true},
  "work_order": {"label": "Work Order", "type": "text", "required": false},
  "equipment_type": {"label": "Tipe Equipment", "type": "select", "options": ["Excavator", "Bulldozer", "Crane"], "required": false}
}'
                        ><?php echo json_encode($config['custom_fields'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?></textarea>
                        <small style="color: #999;">Format JSON - Gunakan field ini jika perlu custom validation/logic</small>
                    </div>
                </div>
                
                <!-- Features Overview -->
                <div class="section">
                    <h2>✨ Fitur Customization</h2>
                    <div class="feature-list">
                        <div class="feature-card">
                            <h3>📊 Business Type</h3>
                            <p>Sistem akan recognize CQC sebagai kontraktor dan auto-apply business rules yang sesuai.</p>
                        </div>
                        <div class="feature-card">
                            <h3>📋 Dynamic Fields</h3>
                            <p>Kolom cashbook dan custom fields hanya muncul di CQC, tidak di bisnis lain.</p>
                        </div>
                        <div class="feature-card">
                            <h3>📈 Conditional Logic</h3>
                            <p>Dashboard dan reports bisa punya logic berbeda based on business type.</p>
                        </div>
                        <div class="feature-card">
                            <h3>🔄 Any Time Edit</h3>
                            <p>Ubah kapan saja tanpa perlu coding, sistem will load perubahan secara automatic.</p>
                        </div>
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" name="save_logic" value="1" class="btn-primary">
                        💾 Simpan Logic Customization
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
