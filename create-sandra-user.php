<?php
/**
 * Create Sandra User
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    // Connect to master database
    $pdo = new PDO(
        'mysql:host=localhost;dbname=adf_system;charset=utf8mb4',
        'root',
        ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $result = [
        'status' => 'success',
        'steps' => []
    ];

    // ============================================
    // STEP 1: Check existing users
    // ============================================
    $stmt = $pdo->query('SELECT id, username, email, full_name FROM users ORDER BY id');
    $existingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result['steps'][] = [
        'step' => 'Check Existing Users',
        'status' => '✅',
        'count' => count($existingUsers),
        'users' => $existingUsers
    ];

    // ============================================
    // STEP 2: Check if sandra already exists
    // ============================================
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute(['sandra']);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $result['steps'][] = [
            'step' => 'Sandra User',
            'status' => '⚠️ Already exists',
            'user_id' => $existing['id']
        ];
    } else {
        // ============================================
        // STEP 3: Create Sandra user
        // ============================================
        $username = 'sandra';
        $email = 'sandra@adfsystem.local';
        $fullName = 'Sandra';
        $password = 'sandra123'; // Default password
        $roleId = 3; // Staff role
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare('
            INSERT INTO users (username, email, password, full_name, role_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$username, $email, $hashedPassword, $fullName, $roleId]);
        $sandraId = $pdo->lastInsertId();

        $result['steps'][] = [
            'step' => 'Create Sandra User',
            'status' => '✅ Created',
            'user_id' => $sandraId,
            'username' => $username,
            'password' => $password,
            'role_id' => $roleId,
            'note' => 'Password: sandra123 (unhashed above for reference only)'
        ];
    }

    // ============================================
    // STEP 4: Get Sandra's current id
    // ============================================
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute(['sandra']);
    $sandra = $stmt->fetch(PDO::FETCH_ASSOC);
    $sandraId = $sandra['id'];

    // ============================================
    // STEP 5: Assign Sandra to all businesses
    // ============================================
    $stmt = $pdo->query('SELECT id, business_name FROM businesses WHERE is_active = 1');
    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $assignCount = 0;
    foreach ($businesses as $biz) {
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO user_business_assignment (user_id, business_id, assigned_at)
            VALUES (?, ?, NOW())
        ');
        $stmt->execute([$sandraId, $biz['id']]);
        if ($stmt->rowCount() > 0) {
            $assignCount++;
        }
    }

    $result['steps'][] = [
        'step' => 'Assign Sandra to Businesses',
        'status' => '✅',
        'newly_assigned' => $assignCount,
        'total_businesses' => count($businesses),
        'businesses' => array_map(fn($b) => $b['business_name'], $businesses)
    ];

    // ============================================
    // STEP 6: Grant Sandra permissions
    // ============================================
    $stmt = $pdo->query('SELECT DISTINCT menu_code FROM user_menu_permissions');
    $menus = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $permCount = 0;
    foreach ($businesses as $biz) {
        foreach ($menus as $menuCode) {
            $stmt = $pdo->prepare('
                INSERT INTO user_menu_permissions 
                (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete, created_at)
                VALUES (?, ?, ?, 1, 1, 0, 0, NOW())
                ON DUPLICATE KEY UPDATE 
                can_view = 1,
                can_create = 1
            ');
            $stmt->execute([$sandraId, $biz['id'], $menuCode]);
            if ($stmt->rowCount() > 0) {
                $permCount++;
            }
        }
    }

    $result['steps'][] = [
        'step' => 'Grant Sandra Permissions',
        'status' => '✅',
        'permissions_set' => $permCount,
        'permission_level' => 'CAN_VIEW + CAN_CREATE',
        'menus_count' => count($menus)
    ];

    // ============================================
    // FINAL RESULT
    // ============================================
    $result['summary'] = [
        'message' => '✅ SANDRA SIAP LOGIN!',
        'username' => 'sandra',
        'password' => 'sandra123',
        'businesses' => count($businesses),
        'menus' => count($menus),
        'permissions' => $permCount
    ];

} catch (Exception $e) {
    http_response_code(500);
    $result = [
        'status' => 'error',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>

