           </div>
           <!-- End Page Content -->
           
           <!-- Footer -->
           <?php
           // Get custom footer text from settings
           $footerCopyrightSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_copyright'");
           $footerCopyright = $footerCopyrightSetting['setting_value'] ?? ('© ' . APP_YEAR . ' ' . APP_NAME . '. All rights reserved.');
           
           $footerVersionSetting = $db->fetchOne("SELECT setting_value FROM settings WHERE setting_key = 'footer_version'");
           $footerVersion = $footerVersionSetting['setting_value'] ?? ('Version ' . APP_VERSION);
           ?>
           <footer style="margin-top: 3rem; padding: 2rem 0; border-top: 1px solid var(--bg-tertiary); text-align: center; color: var(--text-muted);">
               <p><?php echo htmlspecialchars($footerCopyright); ?></p>
               <p style="font-size: 0.875rem; margin-top: 0.5rem;"><?php echo htmlspecialchars($footerVersion); ?></p>
           </footer>
       </main>
   </div>
   
   <!-- Main JavaScript -->
   <script src="<?php echo BASE_URL; ?>/assets/js/main.js?v=<?php echo time(); ?>"></script>
   
   <!-- Push Notifications -->
   <script src="<?php echo BASE_URL; ?>/assets/js/notifications.js?v=<?php echo time(); ?>"></script>
   <script>
       // Initialize notification polling for owner/admin
       <?php 
       $userRole = $_SESSION['role'] ?? '';
       $isOwnerAdmin = in_array($userRole, ['owner', 'admin', 'developer']);
       ?>
       <?php if ($isOwnerAdmin): ?>
       (function() {
           let lastNotificationCount = 0;
           
           // Check for new notifications every 30 seconds
           async function checkNotifications() {
               try {
                   const response = await fetch('<?php echo BASE_URL; ?>/api/get-notifications.php');
                   const data = await response.json();
                   
                   if (data.success && data.unread_count > lastNotificationCount) {
                       // New notification arrived
                       const newNotifs = data.notifications.slice(0, data.unread_count - lastNotificationCount);
                       
                       for (const notif of newNotifs) {
                           if (window.NotificationManager && window.NotificationManager.isEnabled()) {
                               await window.NotificationManager.showNotification(notif.title, {
                                   body: notif.message,
                                   tag: 'notif-' + notif.id,
                                   data: notif.data
                               });
                           }
                       }
                       
                       // Update badge
                       updateNotificationBadge(data.unread_count);
                   }
                   
                   lastNotificationCount = data.unread_count;
               } catch (e) {
                   console.log('Notification check failed:', e);
               }
           }
           
           function updateNotificationBadge(count) {
               const badge = document.getElementById('notification-badge');
               if (badge) {
                   badge.textContent = count;
                   badge.style.display = count > 0 ? 'inline-block' : 'none';
               }
           }
           
           // Check every 30 seconds
           setInterval(checkNotifications, 30000);
           
           // Initial check
           setTimeout(checkNotifications, 2000);
       })();
       <?php endif; ?>
   </script>
   
   <!-- End Shift Feature -->
   <script>
       // Inject BASE_URL for end-shift.js (define once)
       if (typeof BASE_URL === 'undefined') {
           window.BASE_URL = '<?php echo BASE_URL; ?>';
       }
       console.log('BASE_URL set to:', window.BASE_URL);
       // Expose current user name for UI messages
       window.APP_USER_NAME = '<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User', ENT_QUOTES); ?>';
   </script>
   <script src="<?php echo BASE_URL; ?>/assets/js/end-shift.js?v=<?php echo time(); ?>"></script>

   
   <!-- Attach End Shift Button Event -->
   <script>
       // Check if DOM already loaded
       if (document.readyState === 'loading') {
           document.addEventListener('DOMContentLoaded', attachEndShiftHandler);
       } else {
           // DOM already loaded, attach immediately
           attachEndShiftHandler();
       }
       
       function attachEndShiftHandler() {
           console.log('🔧 Attaching End Shift handler...');
           console.log('🔍 window.initiateEndShift:', typeof window.initiateEndShift);
           console.log('🔍 initiateEndShift:', typeof initiateEndShift);
           
           const endShiftBtn = document.getElementById('endShiftButton');
           console.log('🔍 End Shift Button found:', endShiftBtn);
           
           if (endShiftBtn) {
               // Use window.initiateEndShift explicitly
               if (typeof window.initiateEndShift === 'function') {
                   endShiftBtn.addEventListener('click', function(e) {
                       console.log('🎯 End Shift button clicked!');
                       console.log('🎯 Event:', e);
                       try {
                           window.initiateEndShift();
                       } catch (error) {
                           console.error('❌ Error calling initiateEndShift:', error);
                       }
                   });
                   console.log('✅ End Shift handler attached successfully');
               } else {
                   console.error('❌ window.initiateEndShift function not found!');
                   console.log('Available window functions:', Object.keys(window).filter(k => typeof window[k] === 'function').slice(0, 20));
               }
           } else {
               console.error('❌ End Shift button not found!');
           }
       }
   </script>
   
   <!-- Initialize Feather Icons & Setup -->
   <script>
       // Initialize Feather Icons
       feather.replace();
       
       // Real-time clock update
       function updateClock() {
           const now = new Date();
           
           // Update time (HH:MM:SS)
           const hours = String(now.getHours()).padStart(2, '0');
           const minutes = String(now.getMinutes()).padStart(2, '0');
           const seconds = String(now.getSeconds()).padStart(2, '0');
           const timeString = `${hours}:${minutes}:${seconds}`;
           
           const timeElement = document.getElementById('currentTime');
           if (timeElement) {
               timeElement.textContent = timeString;
           }
           
           // Update date at midnight
           const dateElement = document.getElementById('currentDate');
           if (dateElement && now.getHours() === 0 && now.getMinutes() === 0 && now.getSeconds() === 0) {
               location.reload(); // Reload to update date
           }
       }
       
       // Update clock every second
       setInterval(updateClock, 1000);
       updateClock(); // Initial call
   </script>
   
   <!-- html2pdf.js Library for PDF Export -->
   <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
   <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
   
   <!-- Additional JavaScript -->
   <?php if (isset($additionalJS)): ?>
       <?php foreach ($additionalJS as $js): ?>
           <script src="<?php echo BASE_URL . '/' . $js; ?>"></script>
       <?php endforeach; ?>
   <?php endif; ?>
   
   <!-- Inline Scripts -->
   <?php if (isset($inlineScript)): ?>
       <script>
           <?php echo $inlineScript; ?>
       </script>
   <?php endif; ?>
</body>
</html>
