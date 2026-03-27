!DOCTYPE html>
html>
head>
   <title>Debug Login PDO</title>
   <style>
       body { font-family: monospace; margin: 40px; background: #f5f5f5; }
       .box { padding: 20px; margin: 20px 0; border: 2px solid #ddd; border-radius: 8px; background: white; }
       .success { border-color: #28a745; background: #d4edda; }
       .error { border-color: #dc3545; background: #f8d7da; }
       pre { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
   </style>
/head>
body>
   <h1>🔍 Debug Login dengan PDO Langsung</h1>
   
   <?php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   
   echo "<div class='box'>";
   echo "<h3>Step 1: Test PDO Connection</h3>";
   
   try {
       $pdo = new PDO('mysql:host=localhost;dbname=narayana', 'root', '');
       $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
       echo "<div class='success'>✅ PDO Connection OK</div>";
   } catch (PDOException $e) {
       echo "<div class='error'>❌ PDO Connection FAILED: " . $e->getMessage() . "</div>";
       exit;
   }
   echo "</div>";
   
   echo "<div class='box'>";
   echo "<h3>Step 2: Test Query staff1</h3>";
   $username = 'staff1';
   $password = 'staff123';
   
   try {
       $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
       $stmt->execute([$username]);
       $user = $stmt->fetch(PDO::FETCH_ASSOC);
       
       if ($user) {
           echo "<div class='success'>✅ User FOUND</div>";
           echo "<pre>";
           echo "Username: " . $user['username'] . "\n";
           echo "Role: " . $user['role'] . "\n";
           echo "Password (stored): " . $user['password'] . "\n";
           echo "MD5('staff123'): " . md5($password) . "\n";
           echo "Match: " . ($user['password'] === md5($password) ? 'YES ✅' : 'NO ❌') . "\n";
           echo "</pre>";
           
           if ($user['password'] === md5($password)) {
               echo "<div class='success'><strong>✅ PASSWORD MATCH! Login seharusnya berhasil</strong></div>";
           } else {
               echo "<div class='error'><strong>❌ PASSWORD NOT MATCH!</strong></div>";
           }
       } else {
           echo "<div class='error'>❌ User NOT FOUND</div>";
       }
   } catch (PDOException $e) {
       echo "<div class='error'>❌ Query Error: " . $e->getMessage() . "</div>";
   }
   echo "</div>";
   
   echo "<div class='box'>";
   echo "<h3>Step 3: Test Query admin</h3>";
   $username = 'admin';
   $password = 'admin';
   
   try {
       $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
       $stmt->execute([$username]);
       $user = $stmt->fetch(PDO::FETCH_ASSOC);
       
       if ($user) {
           echo "<div class='success'>✅ User FOUND</div>";
           echo "<pre>";
           echo "Username: " . $user['username'] . "\n";
           echo "Role: " . $user['role'] . "\n";
           echo "Password (stored): " . $user['password'] . "\n";
           echo "MD5('admin'): " . md5($password) . "\n";
           echo "Match: " . ($user['password'] === md5($password) ? 'YES ✅' : 'NO ❌') . "\n";
           echo "</pre>";
           
           if ($user['password'] === md5($password)) {
               echo "<div class='success'><strong>✅ PASSWORD MATCH! Login seharusnya berhasil</strong></div>";
           } else {
               echo "<div class='error'><strong>❌ PASSWORD NOT MATCH!</strong></div>";
           }
       } else {
           echo "<div class='error'>❌ User NOT FOUND</div>";
       }
   } catch (PDOException $e) {
       echo "<div class='error'>❌ Query Error: " . $e->getMessage() . "</div>";
   }
   echo "</div>";
   
   echo "<div class='box'>";
   echo "<h3>Kesimpulan:</h3>";
   echo "<p>Jika kedua test di atas menunjukkan PASSWORD MATCH, berarti:</p>";
   echo "<ul>";
   echo "<li>✅ Database connection OK</li>";
   echo "<li>✅ Query OK</li>";
   echo "<li>✅ Password hash OK</li>";
   echo "<li>❌ Masalah ada di Auth class logic</li>";
   echo "</ul>";
   echo "</div>";
   ?>
   
   <hr>
   <a href="test-system-login.php">← Back to Test Page</a>
/body>
/html>
