<?php
/**
 * Test Complete User Setup Workflow
 * Tests: Create user → Assign business → Set permissions
 */

header('Content-Type: application/json');
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

    $results = [
        'status' => 'success',
        'tests' => []
    ];

    // ========================================
    // TEST 1: Create Test User
    // ========================================
    $testUsername = 'testuser_' . time();
    $testPassword = 'Test@123';
    $hashedPassword = password_hash($testPassword, PASSWORD_BCRYPT);

    try {
        $stmt = $pdo->prepare('
            INSERT INTO users (username, email, password, full_name, role_id, created_at)
            VALUES (?, ?, ?, ?, 3, NOW())
        ');
        $stmt->execute([
            $testUsername,
            $testUsername . '@test.local',
            $hashedPassword,
            'Test User ' . date('H:i:s')
        ]);
        $userId = $pdo->lastInsertId();

        $results['tests'][] = [
            'name' => 'Create Test User',
            'status' => '✅ PASS',
            'details' => [
                'username' => $testUsername,
                'user_id' => $userId,
                'role' => 'Staff (ID=3)'
            ]
        ];
    } catch (Exception $e) {
        $results['tests'][] = [
            'name' => 'Create Test User',
            'status' => '❌ FAIL',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // ========================================
    // TEST 2: Get Available Businesses
    // ========================================
    try {
        $stmt = $pdo->query('SELECT id, business_name FROM businesses WHERE is_active = 1 LIMIT 2');
        $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($businesses) >= 2) {
            $results['tests'][] = [
                'name' => 'Get Available Businesses',
                'status' => '✅ PASS',
                'details' => [
                    'count' => count($businesses),
                    'businesses' => $businesses
                ]
            ];
        } else {
            throw new Exception('Need at least 2 businesses for assignment test');
        }
    } catch (Exception $e) {
        $results['tests'][] = [
            'name' => 'Get Available Businesses',
            'status' => '❌ FAIL',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // ========================================
    // TEST 3: Assign Business to User (Step 2)
    // ========================================
    try {
        foreach ($businesses as $index => $business) {
            $stmt = $pdo->prepare('
                INSERT IGNORE INTO user_business_assignment 
                (user_id, business_id, assigned_at) 
                VALUES (?, ?, NOW())
            ');
            $stmt->execute([$userId, $business['id']]);
        }

        // Verify assignments
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count FROM user_business_assignment 
            WHERE user_id = ?
        ');
        $stmt->execute([$userId]);
        $assignCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($assignCount >= 2) {
            $results['tests'][] = [
                'name' => 'Assign Businesses to User (Step 2)',
                'status' => '✅ PASS',
                'details' => [
                    'user_id' => $userId,
                    'businesses_assigned' => intval($assignCount),
                    'method' => 'INSERT via user_business_assignment table'
                ]
            ];
        } else {
            throw new Exception("Expected 2+ assignments, got {$assignCount}");
        }
    } catch (Exception $e) {
        $results['tests'][] = [
            'name' => 'Assign Businesses to User (Step 2)',
            'status' => '❌ FAIL',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // ========================================
    // TEST 4: Get Available Menus
    // ========================================
    try {
        $stmt = $pdo->query('
            SELECT DISTINCT menu_code 
            FROM user_menu_permissions 
            LIMIT 3
        ');
        $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results['tests'][] = [
            'name' => 'Get Available Menus',
            'status' => '✅ PASS',
            'details' => [
                'count' => count($menus),
                'menus' => $menus
            ]
        ];
    } catch (Exception $e) {
        $results['tests'][] = [
            'name' => 'Get Available Menus',
            'status' => '❌ FAIL',
            'error' => $e->getMessage()
        ];
    }

    // ========================================
    // TEST 5: Set Permissions for Business (Step 3)
    // ========================================
    try {
        $firstBusiness = $businesses[0];
        $permissionLevel = 'create';  // can_view=1, can_create=1

        // Get menu codes
        $stmt = $pdo->query('SELECT DISTINCT menu_code FROM user_menu_permissions');
        $menuCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($menuCodes as $menuCode) {
            $stmt = $pdo->prepare('
                INSERT INTO user_menu_permissions 
                (user_id, business_id, menu_code, can_view, can_create, can_edit, can_delete, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                can_view = VALUES(can_view),
                can_create = VALUES(can_create),
                can_edit = VALUES(can_edit),
                can_delete = VALUES(can_delete)
            ');
            
            $canView = ($permissionLevel === 'view' || $permissionLevel === 'create' || $permissionLevel === 'all') ? 1 : 0;
            $canCreate = ($permissionLevel === 'create' || $permissionLevel === 'all') ? 1 : 0;
            $canEdit = ($permissionLevel === 'all') ? 1 : 0;
            $canDelete = ($permissionLevel === 'all') ? 1 : 0;
            
            $stmt->execute([$userId, $firstBusiness['id'], $menuCode, $canView, $canCreate, $canEdit, $canDelete]);
        }

        // Verify permissions
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count FROM user_menu_permissions 
            WHERE user_id = ? AND business_id = ? AND can_view = 1
        ');
        $stmt->execute([$userId, $firstBusiness['id']]);
        $permCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $results['tests'][] = [
            'name' => 'Set Permissions for Business (Step 3)',
            'status' => '✅ PASS',
            'details' => [
                'user_id' => $userId,
                'business_id' => $firstBusiness['id'],
                'permission_level' => $permissionLevel,
                'menus_granted_permission' => intval($permCount),
                'method' => 'INSERT ON DUPLICATE KEY UPDATE'
            ]
        ];
    } catch (Exception $e) {
        $results['tests'][] = [
            'name' => 'Set Permissions for Business (Step 3)',
            'status' => '❌ FAIL',
            'error' => $e->getMessage()
        ];
        throw $e;
    }

    // ========================================
    // TEST 6: Verify Complete Workflow
    // ========================================
    try {
        // Check user exists
        $stmt = $pdo->prepare('SELECT id, username, role_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            throw new Exception('User not found');
        }

        // Check businesses assigned
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count FROM user_business_assignment WHERE user_id = ?
        ');
        $stmt->execute([$userId]);
        $businessCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Check permissions set
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count FROM user_menu_permissions 
            WHERE user_id = ? AND can_view = 1
        ');
        $stmt->execute([$userId]);
        $permissionCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        if ($businessCount >= 2 && $permissionCount >= 1) {
            $results['tests'][] = [
                'name' => 'Complete Workflow Verification',
                'status' => '✅ PASS',
                'summary' => [
                    'user_created' => true,
                    'businesses_assigned' => intval($businessCount),
                    'permissions_set' => intval($permissionCount),
                    'workflow_status' => 'COMPLETE'
                ]
            ];
        } else {
            throw new Exception("Incomplete workflow: businesses={$businessCount}, permissions={$permissionCount}");
        }
    } catch (Exception $e) {
        $results['tests'][] = [
            'name' => 'Complete Workflow Verification',
            'status' => '❌ FAIL',
            'error' => $e->getMessage()
        ];
    }

    // ========================================
    // CLEANUP: Delete test user
    // ========================================
    try {
        $pdo->prepare('DELETE FROM user_business_assignment WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM user_menu_permissions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        $results['cleanup'] = '✅ Test user deleted successfully';
    } catch (Exception $e) {
        $results['cleanup'] = '⚠️ ' . $e->getMessage();
    }

    // Final summary
    $passed = count(array_filter($results['tests'], fn($t) => strpos($t['status'], 'PASS') !== false));
    $failed = count($results['tests']) - $passed;
    
    $results['summary'] = [
        'total_tests' => count($results['tests']),
        'passed' => $passed,
        'failed' => $failed,
        'status' => $failed === 0 ? '✅ ALL TESTS PASSED' : "⚠️ {$failed} TEST(S) FAILED"
    ];

} catch (Exception $e) {
    http_response_code(500);
    $results = [
        'status' => 'error',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
