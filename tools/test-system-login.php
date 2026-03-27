!DOCTYPE html>
html>
head>
   <title>Test System Login</title>
   <style>
       body { font-family: Arial; margin: 40px; }
       .box { padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 8px; }
       .success { background: #d4edda; border-color: #28a745; }
       .error { background: #f8d7da; border-color: #dc3545; }
       .info { background: #d1ecf1; border-color: #17a2b8; }
   </style>
/head>
body>
   <h1>🧪 Test System Login</h1>
   
   <?php
   session_start();
   require_once '../config/config.php';
   require_once '../includes/auth.php';
   require_once '../includes/functions.php';
   
   $auth = new Auth();
   
   echo "<div class='box info'>";
   echo "<h3>Test 1: Login dengan staff1/staff123</h3>";
   
   try {
       $result = $auth->login('staff1', 'staff123');
       echo "<pre>Login result: " . ($result ? 'TRUE' : 'FALSE') . "</pre>";
       
       if ($result) {
           $user = $auth->getCurrentUser();
           echo "<div class='success'>";
           echo "✅ Login BERHASIL!<br>";
           echo "User: " . $user['username'] . "<br>";
           echo "Role: " . $user['role'] . "<br>";
           echo "Full Name: " . $user['full_name'] . "<br>";
           
           if ($user['role'] === 'admin' || $user['role'] === 'owner') {
               echo "<br>❌ MASALAH: Role adalah {$user['role']}, seharusnya ditolak!";
           } else {
               echo "<br>✅ Role OK: {$user['role']} - boleh akses System Login";
           }
           echo "</div>";
           
           $auth->logout();
       } else {
           echo "<div class='error'>❌ Login GAGAL!<br>";
           echo "Cek error_log PHP untuk detail error</div>";
       }
   } catch (Exception $e) {
       echo "<div class='error'>❌ EXCEPTION: " . $e->getMessage() . "</div>";
   }
   echo "</div>";
   
   echo "<div class='box info'>";
   echo "<h3>Test 2: Login dengan admin/admin</h3>";
   
   if ($auth->login('admin', 'admin')) {
       $user = $auth->getCurrentUser();
       echo "<div class='success'>";
       echo "✅ Login BERHASIL!<br>";
       echo "User: " . $user['username'] . "<br>";
       echo "Role: " . $user['role'] . "<br>";
       echo "Full Name: " . $user['full_name'] . "<br>";
       
       if ($user['role'] === 'admin' || $user['role'] === 'owner') {
           echo "<br>✅ DETEKSI OK: Role adalah {$user['role']}, harus ditolak di login.php";
       } else {
           echo "<br>❌ MASALAH: Role adalah {$user['role']}, tidak terdeteksi sebagai admin!";
       }
       echo "</div>";
       
       $auth->logout();
   } else {
       echo "<div class='error'>❌ Login GAGAL!</div>";
   }
   echo "</div>";
   
   ?>
   
   <div class="box">
       <h3>Kesimpulan:</h3>
       <p>Jika Test 1 berhasil dan Test 2 terdeteksi sebagai admin, maka Auth class bekerja dengan benar.</p>
       <p>Masalah mungkin di login.php redirect logic atau session.</p>
   </div>
   
   <hr>
   <a href="../login.php">← Kembali ke Login</a>
/body>
/html>
