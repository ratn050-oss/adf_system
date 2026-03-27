!DOCTYPE html>
html>
head>
   <title>Test Auth Class dengan Logging</title>
   <style>
       body { font-family: monospace; margin: 40px; background: #f5f5f5; }
       .box { padding: 20px; margin: 20px 0; border: 2px solid #ddd; border-radius: 8px; background: white; }
       .success { border-color: #28a745; background: #d4edda; }
       .error { border-color: #dc3545; background: #f8d7da; }
       pre { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
   </style>
/head>
body>
   <h1>🔍 Test Auth::login() dengan Detail Logging</h1>
   
   <?php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   
   // Simulate Auth::login() with detailed logging
   function testLogin($username, $password) {
       $logs = [];
       
       try {
           $logs[] = "Step 1: Creating PDO connection...";
           $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
           $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
           $logs[] = "✅ PDO connection created";
           
           $logs[] = "Step 2: Preparing query...";
           $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
           $logs[] = "✅ Query prepared";
           
           $logs[] = "Step 3: Executing query with username = '$username'...";
           $stmt->execute([$username]);
           $logs[] = "✅ Query executed";
           
           $logs[] = "Step 4: Fetching user...";
           $user = $stmt->fetch(PDO::FETCH_ASSOC);
           
           if ($user) {
               $logs[] = "✅ User found: ID={$user['id']}, Username={$user['username']}, Role={$user['role']}";
           } else {
               $logs[] = "❌ User NOT found";
               return ['success' => false, 'logs' => $logs, 'reason' => 'User not found'];
           }
           
           $logs[] = "Step 5: Checking password...";
           $passwordMatch = false;
           
           // Try bcrypt first
           $logs[] = "  Trying password_verify()...";
           if (password_verify($password, $user['password'])) {
               $passwordMatch = true;
               $logs[] = "  ✅ Bcrypt match!";
           } else {
               $logs[] = "  ❌ Bcrypt no match";
           }
           
           // Try MD5 if bcrypt fails
           if (!$passwordMatch) {
               $logs[] = "  Trying MD5 comparison...";
               $md5Hash = md5($password);
               $logs[] = "  Input password MD5: $md5Hash";
               $logs[] = "  Stored password: {$user['password']}";
               
               if ($user['password'] === $md5Hash) {
                   $passwordMatch = true;
                   $logs[] = "  ✅ MD5 match!";
               } else {
                   $logs[] = "  ❌ MD5 no match";
               }
           }
           
           if (!$passwordMatch) {
               $logs[] = "❌ Final result: Password NOT match";
               return ['success' => false, 'logs' => $logs, 'reason' => 'Password mismatch'];
           }
           
           $logs[] = "✅ Password matched!";
           $logs[] = "Step 6: Starting session...";
           
           // Don't actually start session in test
           $logs[] = "✅ Session would be started here";
           $logs[] = "✅ Session variables would be set";
           
           $logs[] = "Step 7: Loading preferences...";
           $stmt = $pdo->prepare("SELECT theme, language FROM user_preferences WHERE user_id = ?");
           $stmt->execute([$user['id']]);
           $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
           
           if ($preferences) {
               $logs[] = "✅ Preferences found";
           } else {
               $logs[] = "ℹ️ No preferences, using defaults";
           }
           
           $logs[] = "Step 8: Updating last login...";
           $stmt = $pdo->prepare("UPDATE users SET updated_at = NOW() WHERE id = ?");
           $stmt->execute([$user['id']]);
           $logs[] = "✅ Last login updated";
           
           $logs[] = "✅✅✅ LOGIN SUCCESS!";
           
           return ['success' => true, 'logs' => $logs, 'user' => $user];
           
       } catch (PDOException $e) {
           $logs[] = "❌ PDO EXCEPTION: " . $e->getMessage();
           $logs[] = "❌ Trace: " . $e->getTraceAsString();
           return ['success' => false, 'logs' => $logs, 'reason' => 'Exception: ' . $e->getMessage()];
       } catch (Exception $e) {
           $logs[] = "❌ GENERAL EXCEPTION: " . $e->getMessage();
           return ['success' => false, 'logs' => $logs, 'reason' => 'Exception: ' . $e->getMessage()];
       }
   }
   
   // Test staff1
   echo "<div class='box'>";
   echo "<h3>Test 1: Login staff1/staff123</h3>";
   $result = testLogin('staff1', 'staff123');
   
   echo "<pre>";
   foreach ($result['logs'] as $log) {
       echo $log . "\n";
   }
   echo "</pre>";
   
   if ($result['success']) {
       echo "<div class='success'><strong>✅ LOGIN WOULD SUCCEED!</strong></div>";
   } else {
       echo "<div class='error'><strong>❌ LOGIN FAILED: {$result['reason']}</strong></div>";
   }
   echo "</div>";
   
   // Test admin
   echo "<div class='box'>";
   echo "<h3>Test 2: Login admin/admin</h3>";
   $result = testLogin('admin', 'admin');
   
   echo "<pre>";
   foreach ($result['logs'] as $log) {
       echo $log . "\n";
   }
   echo "</pre>";
   
   if ($result['success']) {
       echo "<div class='success'><strong>✅ LOGIN WOULD SUCCEED!</strong></div>";
   } else {
       echo "<div class='error'><strong>❌ LOGIN FAILED: {$result['reason']}</strong></div>";
   }
   echo "</div>";
   ?>
   
   <hr>
   <a href="debug-pdo-login.php">← Back</a>
/body>
/html>
