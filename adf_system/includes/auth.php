<?php
/**
 * Authentication Class
 */

defined('APP_ACCESS') or define('APP_ACCESS', true);
require_once __DIR__ . '/../config/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function startSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
    }
    
    public function login($username, $password) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $passwordMatch = false;
            if ($user) {
                if (password_verify($password, $user['password'])) {
                    $passwordMatch = true;
                } else if ($user['password'] === md5($password)) {
                    $passwordMatch = true;
                }
            }
            
            if ($user && $passwordMatch) {
                $this->startSession();
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                
                // Handle different database structures
                // Business DB has 'role' column, Master DB has 'role_id'
                if (isset($user['role'])) {
                    $_SESSION['role'] = $user['role'];
                } else {
                    // Master database - need to get role from role_id
                    try {
                        $roleStmt = $pdo->prepare("SELECT role_code FROM roles WHERE id = ?");
                        $roleStmt->execute([$user['role_id'] ?? 1]);
                        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                        $_SESSION['role'] = $roleData['role_code'] ?? 'staff';
                    } catch (Exception $e) {
                        $_SESSION['role'] = 'staff';
                    }
                }
                
                $_SESSION['business_access'] = $user['business_access'] ?? 'all';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                
                try {
                    $stmt = $pdo->prepare("SELECT theme, language FROM user_preferences WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($preferences) {
                        $_SESSION['user_theme'] = $preferences['theme'];
                        $_SESSION['user_language'] = $preferences['language'];
                    } else {
                        $_SESSION['user_theme'] = 'dark';
                        $_SESSION['user_language'] = 'id';
                    }
                } catch (PDOException $e) {
                    $_SESSION['user_theme'] = 'dark';
                    $_SESSION['user_language'] = 'id';
                }
                
                $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);
                
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Auth login error: " . $e->getMessage());
            return false;
        }
    }
    
    public function logout() {
        $this->startSession();
        session_unset();
        session_destroy();
        return true;
    }
    
    public function isLoggedIn() {
        $this->startSession();
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
    }
    
    public function getCurrentUser() {
        $this->startSession();
        if ($this->isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'full_name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ];
        }
        return null;
    }
    
    public function hasRole($role) {
        $this->startSession();
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        
        if (!isset($_SESSION['user_theme']) || !isset($_SESSION['user_language'])) {
            try {
                $preferences = $this->db->fetchOne(
                    "SELECT theme, language FROM user_preferences WHERE user_id = ?",
                    [$_SESSION['user_id']]
                );
                
                if ($preferences) {
                    $_SESSION['user_theme'] = $preferences['theme'];
                    $_SESSION['user_language'] = $preferences['language'];
                } else {
                    $_SESSION['user_theme'] = 'dark';
                    $_SESSION['user_language'] = 'id';
                }
            } catch (Exception $e) {
                $_SESSION['user_theme'] = 'dark';
                $_SESSION['user_language'] = 'id';
            }
        }
    }
    
    public function requireRole($role) {
        $this->requireLogin();
        if (!$this->hasRole($role)) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }
    
    public function hasPermission($module) {
        // Check if user is logged in
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        // Developer role has full access to everything
        $userRole = $_SESSION['role'] ?? 'staff';
        if ($userRole === 'developer') {
            return true;
        }
        
        // Get username from session
        $username = $_SESSION['username'] ?? null;
        if (!$username) {
            return false;
        }
        
        try {
            // Connect to master database
            $masterPdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=adf_system;charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Get user ID from master
            $userStmt = $masterPdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $userStmt->execute([$username]);
            $masterUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$masterUser) {
                // User not in master database, fallback to role-based
                return $this->hasPermissionFallback($module);
            }
            
            $masterId = $masterUser['id'];
            
            // Get current business ID from session
            $activeBusinessId = $_SESSION['active_business_id'] ?? null;
            
            // If no active business set, fallback (shouldn't happen after login)
            if (!$activeBusinessId) {
                error_log("⚠️ FALLBACK: No active_business_id in session for user {$username} (ID {$masterId})");
                error_log("Session active_business_id = " . var_export($_SESSION['active_business_id'] ?? 'MISSING', true));
                return $this->hasPermissionFallback($module);
            }
            
            // Map business_id to business_code
            $idToCodeMap = [
                'bens-cafe' => 'BENSCAFE',
                'narayana-hotel' => 'NARAYANAHOTEL'
            ];
            $businessCode = $idToCodeMap[$activeBusinessId] ?? strtoupper(str_replace('-', '', $activeBusinessId));
            
            // Get business ID from master
            $bizStmt = $masterPdo->prepare("SELECT id FROM businesses WHERE business_code = ? LIMIT 1");
            $bizStmt->execute([$businessCode]);
            $business = $bizStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$business) {
                // Business not found, fallback
                error_log("Warning: Business not found for code {$businessCode}");
                return $this->hasPermissionFallback($module);
            }
            
            $businessId = $business['id'];
            
            // Check permission in master database
            // Query directly using menu_code (no JOIN needed)
            $permStmt = $masterPdo->prepare("
                SELECT can_view
                FROM user_menu_permissions
                WHERE user_id = ? 
                  AND business_id = ? 
                  AND menu_code = ?
                  AND can_view = 1
                LIMIT 1
            ");
            $permStmt->execute([$masterId, $businessId, $module]);
            $permission = $permStmt->fetch(PDO::FETCH_ASSOC);
            
            // If found and can_view = 1, return true
            if ($permission) {
                return true;
            }
            
            // If not found, return false (no fallback)
            return false;
            
        } catch (Exception $e) {
            // Log error for debugging - IMPORTANT FOR TROUBLESHOOTING
            error_log("⚠️ Permission check FAILED for user_id=" . ($_SESSION['user_id'] ?? 'none') . ", module=" . $module . ": " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Fallback to role-based on error
            return $this->hasPermissionFallback($module);
        }
    }
    
    /**
     * Fallback permission check based on role (for backward compatibility)
     */
    private function hasPermissionFallback($module) {
        $userRole = $_SESSION['role'] ?? 'staff';
        
        // Try old user_permissions table in business database
        try {
            $user_id = $_SESSION['user_id'] ?? null;
            if ($user_id) {
                $conn = $this->db->getConnection();
                $query = "SELECT * FROM user_permissions WHERE user_id = ? AND permission = ? LIMIT 1";
                $stmt = $conn->prepare($query);
                $stmt->execute([$user_id, $module]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    return true;
                }
            }
        } catch (Exception $e) {
            // Table might not exist, continue to role-based
        }
        
        // Final fallback: role-based permissions
        $rolePermissions = [
            'admin' => ['dashboard', 'cashbook', 'divisions', 'frontdesk', 'sales_invoice', 'procurement', 'users', 'reports', 'settings', 'inventor', 'project'],
            'manager' => ['dashboard', 'cashbook', 'divisions', 'frontdesk', 'sales_invoice', 'procurement', 'users', 'reports', 'settings', 'investor', 'project'],
            'accountant' => ['dashboard', 'cashbook', 'reports', 'procurement', 'investor', 'project'],
            'staff' => ['dashboard', 'cashbook', 'investor', 'project']
        ];
        
        $permissions = $rolePermissions[$userRole] ?? ['dashboard'];
        
        // Log when using fallback
        error_log("🔴 USING FALLBACK: user_id=" . ($_SESSION['user_id'] ?? 'none') . ", role=" . $userRole . ", module=" . $module . ", has_perm=" . (in_array($module, $permissions) ? "YES" : "NO"));
        
        return in_array($module, $permissions);
    }
}
