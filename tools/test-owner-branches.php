!DOCTYPE html>
html>
head>
   <title>Test Owner Branches API</title>
   <style>
       body {
           font-family: monospace;
           background: #1e1e1e;
           color: #d4d4d4;
           padding: 20px;
       }
       .result {
           background: #252526;
           border: 1px solid #3e3e42;
           padding: 20px;
           margin: 20px 0;
           border-radius: 8px;
       }
       pre {
           background: #1e1e1e;
           padding: 15px;
           border-radius: 5px;
           overflow-x: auto;
       }
       .success { color: #4ec9b0; }
       .error { color: #f48771; }
       button {
           background: #0e639c;
           color: white;
           padding: 10px 20px;
           border: none;
           border-radius: 5px;
           cursor: pointer;
           margin: 5px;
       }
       button:hover {
           background: #1177bb;
       }
   </style>
/head>
body>
   <h1>🔍 Test Owner Branches API</h1>
   <button onclick="testAPI()">Test API</button>
   <button onclick="location.href='../modules/owner/dashboard.php'">Go to Dashboard</button>
   
   <div id="result"></div>
   
   <script>
       async function testAPI() {
           const resultDiv = document.getElementById('result');
           resultDiv.innerHTML = '<p>Loading...</p>';
           
           try {
               const response = await fetch('../api/owner-branches.php');
               const data = await response.json();
               
               let html = '<div class="result">';
               html += '<h2>API Response:</h2>';
               
               if (data.success) {
                   html += '<p class="success">✅ Success!</p>';
                   html += '<p>Count: ' + data.count + ' branches</p>';
                   
                   if (data.branches && data.branches.length > 0) {
                       html += '<h3>Branches:</h3>';
                       data.branches.forEach(branch => {
                           html += '<div style="background: #2d2d30; padding: 10px; margin: 10px 0; border-radius: 5px;">';
                           html += '<strong>ID:</strong> ' + branch.id + '<br>';
                           html += '<strong>Name:</strong> ' + branch.branch_name + '<br>';
                           html += '<strong>Address:</strong> ' + branch.city + '<br>';
                           html += '<strong>Phone:</strong> ' + branch.phone;
                           html += '</div>';
                       });
                   } else {
                       html += '<p class="error">No branches found</p>';
                   }
                   
                   if (data.user_info) {
                       html += '<h3>User Info:</h3>';
                       html += '<p>Username: ' + data.user_info.username + '</p>';
                       html += '<p>Role: ' + data.user_info.role + '</p>';
                       html += '<p>Total Businesses in DB: ' + data.user_info.total_businesses + '</p>';
                       html += '<p>Accessible: ' + data.user_info.accessible_businesses + '</p>';
                   }
               } else {
                   html += '<p class="error">❌ Failed: ' + data.message + '</p>';
               }
               
               html += '<h3>Full Response:</h3>';
               html += '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
               html += '</div>';
               
               resultDiv.innerHTML = html;
               
           } catch (error) {
               resultDiv.innerHTML = '<div class="result"><p class="error">❌ Error: ' + error.message + '</p></div>';
           }
       }
       
       // Auto test on load
       window.onload = testAPI;
   </script>
/body>
/html>
