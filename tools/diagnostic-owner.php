<?php
/**
 * OWNER SYSTEM DIAGNOSTIC - Cek Semua Fungsi
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

echo "<!DOCTYPE html>";
echo "<html><head>";
echo "<meta charset='UTF-8'>";
echo "<title>Owner System Diagnostic</title>";
echo "<style>
    body { font-family: Arial; padding: 20px; background: #f5f5f5; }
    .section { background: white; padding: 20px; margin: 20px 0; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .warning { color: orange; font-weight: bold; }
    h1 { color: #333; }
    h2 { color: #666; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
    pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
    .status { display: inline-block; padding: 5px 10px; border-radius: 5px; color: white; font-size: 12px; }
    .status.ok { background: #4CAF50; }
    .status.fail { background: #f44336; }
    .status.warn { background: #ff9800; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
    th { background: #f0f0f0; }
</style>";
echo "</head><body>";

echo "<h1>🔍 OWNER SYSTEM DIAGNOSTIC</h1>";
echo "<p>Diagnostic lengkap untuk semua fungsi owner monitoring</p>";

// ============================================
// 1. CEK SESSION
// ============================================
echo "<div class='section'>";
echo "<h2>1️⃣ Session Status</h2>";

if (isset($_SESSION['user_id'])) {
    echo "<p class='success'>✅ User is logged in</p>";
    echo "<table>";
    echo "<tr><th>Key</th><th>Value</th></tr>";
    echo "<tr><td>User ID</td><td>" . ($_SESSION['user_id'] ?? '-') . "</td></tr>";
    echo "<tr><td>Username</td><td>" . ($_SESSION['username'] ?? '-') . "</td></tr>";
    echo "<tr><td>Full Name</td><td>" . ($_SESSION['full_name'] ?? '-') . "</td></tr>";
    echo "<tr><td>Role</td><td><strong>" . ($_SESSION['role'] ?? '-') . "</strong></td></tr>";
    echo "<tr><td>Business Access</td><td>" . ($_SESSION['business_access'] ?? 'NOT SET') . "</td></tr>";
    echo "<tr><td>Logged In Flag</td><td>" . (isset($_SESSION['logged_in']) ? 'TRUE' : 'FALSE') . "</td></tr>";
    echo "</table>";
    
    // Decode business access
    if (isset($_SESSION['business_access'])) {
        $accessIds = json_decode($_SESSION['business_access'], true);
        if (is_array($accessIds) && !empty($accessIds)) {
            echo "<p class='success'>✅ Business Access: " . implode(', ', $accessIds) . "</p>";
        } else {
            echo "<p class='error'>❌ Business Access kosong atau invalid!</p>";
        }
    } else {
        echo "<p class='error'>❌ Business Access tidak ada di session!</p>";
    }
} else {
    echo "<p class='error'>❌ User is NOT logged in</p>";
    echo "<p>Please login first: <a href='../owner-login.php'>Owner Login</a></p>";
}
echo "</div>";

// ============================================
// 2. CEK DATABASE USER
// ============================================
echo "<div class='section'>";
echo "<h2>2️⃣ Database User Check</h2>";

if (isset($_SESSION['user_id'])) {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo "<p class='success'>✅ User found in database</p>";
            echo "<table>";
            echo "<tr><th>Field</th><th>Value</th></tr>";
            foreach ($user as $key => $value) {
                if ($key === 'password') {
                    $value = '***hidden***';
                }
                echo "<tr><td>{$key}</td><td>" . htmlspecialchars($value) . "</td></tr>";
            }
            echo "</table>";
            
            // Check business_access
            if (!empty($user['business_access'])) {
                $dbAccessIds = json_decode($user['business_access'], true);
                echo "<p class='success'>✅ Business Access dari DB: " . implode(', ', $dbAccessIds) . "</p>";
                
                // Update session if different
                if (!isset($_SESSION['business_access']) || $_SESSION['business_access'] !== $user['business_access']) {
                    $_SESSION['business_access'] = $user['business_access'];
                    echo "<p class='warning'>⚠️ Session updated dengan business_access dari database!</p>";
                }
            } else {
                echo "<p class='error'>❌ Business Access di database kosong!</p>";
            }
        } else {
            echo "<p class='error'>❌ User tidak ditemukan di database!</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Database error: " . $e->getMessage() . "</p>";
    }
} else {
    echo "<p class='warning'>⚠️ Tidak bisa cek database karena belum login</p>";
}
echo "</div>";

// ============================================
// 3. CEK CONFIG BUSINESSES
// ============================================
echo "<div class='section'>";
echo "<h2>3️⃣ Business Configuration</h2>";

require_once '../config/businesses.php';

if (isset($BUSINESSES) && !empty($BUSINESSES)) {
    echo "<p class='success'>✅ Config businesses.php loaded</p>";
    echo "<p><strong>Total businesses:</strong> " . count($BUSINESSES) . "</p>";
    
    echo "<table>";
    echo "<tr><th>ID</th><th>Name</th><th>Database</th><th>Type</th><th>Active</th></tr>";
    foreach ($BUSINESSES as $biz) {
        $activeIcon = $biz['active'] ? '✅' : '❌';
        echo "<tr>";
        echo "<td>{$biz['id']}</td>";
        echo "<td>{$biz['name']}</td>";
        echo "<td>{$biz['database']}</td>";
        echo "<td>{$biz['type']}</td>";
        echo "<td>{$activeIcon}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>❌ Config businesses.php tidak ditemukan atau kosong!</p>";
}
echo "</div>";

// ============================================
// 4. TEST API OWNER-BRANCHES
// ============================================
echo "<div class='section'>";
echo "<h2>4️⃣ API owner-branches.php Test</h2>";

if (isset($_SESSION['user_id'])) {
    echo "<p>Testing API endpoint...</p>";
    
    // Simulate API call
    ob_start();
    include '../api/owner-branches.php';
    $apiOutput = ob_get_clean();
    
    echo "<h3>API Response:</h3>";
    echo "<pre>" . htmlspecialchars($apiOutput) . "</pre>";
    
    $apiData = json_decode($apiOutput, true);
    if ($apiData && isset($apiData['success'])) {
        if ($apiData['success']) {
            echo "<p class='success'>✅ API berhasil!</p>";
            echo "<p><strong>Count:</strong> " . ($apiData['count'] ?? 0) . "</p>";
            
            if (isset($apiData['branches']) && !empty($apiData['branches'])) {
                echo "<h4>Branches List:</h4>";
                echo "<table>";
                echo "<tr><th>ID</th><th>Name</th><th>Database</th></tr>";
                foreach ($apiData['branches'] as $branch) {
                    echo "<tr>";
                    echo "<td>{$branch['id']}</td>";
                    echo "<td>{$branch['branch_name']}</td>";
                    echo "<td>{$branch['database']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='error'>❌ Branches array kosong!</p>";
            }
        } else {
            echo "<p class='error'>❌ API error: " . ($apiData['message'] ?? 'Unknown') . "</p>";
        }
    } else {
        echo "<p class='error'>❌ API response tidak valid JSON!</p>";
    }
} else {
    echo "<p class='warning'>⚠️ Tidak bisa test API karena belum login</p>";
}
echo "</div>";

// ============================================
// 5. CEK SEMUA API ENDPOINTS
// ============================================
echo "<div class='section'>";
echo "<h2>5️⃣ All Owner API Endpoints</h2>";

$apis = [
    'owner-branches.php' => 'Get list of accessible businesses',
    'owner-stats.php' => 'Get statistics (income/expense)',
    'owner-occupancy.php' => 'Get room occupancy',
    'owner-chart-data.php' => 'Get chart data',
    'owner-recent-transactions.php' => 'Get recent transactions'
];

echo "<table>";
echo "<tr><th>API</th><th>Description</th><th>Status</th></tr>";
foreach ($apis as $file => $desc) {
    $path = "../api/{$file}";
    $exists = file_exists($path);
    $status = $exists ? "<span class='status ok'>EXISTS</span>" : "<span class='status fail'>NOT FOUND</span>";
    
    echo "<tr>";
    echo "<td><strong>{$file}</strong></td>";
    echo "<td>{$desc}</td>";
    echo "<td>{$status}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";

// ============================================
// 6. SOLUSI DAN REKOMENDASI
// ============================================
echo "<div class='section'>";
echo "<h2>6️⃣ Diagnosis & Solutions</h2>";

$issues = [];
$solutions = [];

// Check session
if (!isset($_SESSION['user_id'])) {
    $issues[] = "User belum login";
    $solutions[] = "Login dulu di: <a href='../owner-login.php'>owner-login.php</a>";
}

// Check business_access in session
if (!isset($_SESSION['business_access']) || empty($_SESSION['business_access'])) {
    $issues[] = "business_access tidak ada di session";
    $solutions[] = "Logout dan login ulang untuk refresh session";
}

// Check role
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'owner' && $_SESSION['role'] !== 'admin') {
    $issues[] = "Role bukan owner atau admin: " . $_SESSION['role'];
    $solutions[] = "Login dengan user yang role-nya owner atau admin";
}

if (empty($issues)) {
    echo "<p class='success'>✅ Tidak ada issue yang terdeteksi!</p>";
    echo "<p>Jika masih ada masalah, silakan:</p>";
    echo "<ol>";
    echo "<li>Clear browser cache</li>";
    echo "<li>Logout dan login ulang</li>";
    echo "<li>Cek browser console (F12) untuk JavaScript errors</li>";
    echo "</ol>";
} else {
    echo "<h3 class='error'>❌ Issues Found:</h3>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li class='error'>{$issue}</li>";
    }
    echo "</ul>";
    
    echo "<h3 class='success'>✅ Solutions:</h3>";
    echo "<ol>";
    foreach ($solutions as $solution) {
        echo "<li>{$solution}</li>";
    }
    echo "</ol>";
}

echo "</div>";

// ============================================
// QUICK ACTIONS
// ============================================
echo "<div class='section'>";
echo "<h2>⚡ Quick Actions</h2>";
echo "<p>";
echo "<a href='../logout.php' style='padding: 10px 20px; background: #f44336; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Logout</a> ";
echo "<a href='../owner-login.php' style='padding: 10px 20px; background: #4CAF50; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Login</a> ";
echo "<a href='../owner-portal.php' style='padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Owner Portal</a> ";
echo "<a href='../modules/owner/dashboard.php' style='padding: 10px 20px; background: #ff9800; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Dashboard</a> ";
echo "<a href='?' style='padding: 10px 20px; background: #9c27b0; color: white; text-decoration: none; border-radius: 5px; margin: 5px;'>Refresh Test</a>";
echo "</p>";
echo "</div>";

echo "</body></html>";
