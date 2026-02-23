    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-container">
            <div class="footer-section footer-about">
                <?php
                // Load footer settings from database (safe - won't crash if table missing)
                $footerLogo = '';
                $footerText = '';
                $footerShowLogo = '1';
                $footerSiteName = BUSINESS_NAME;
                $footerInstagram = '';
                $footerWhatsapp = '';
                $footerCopyright = '';
                try {
                    $footerDb = PublicDatabase::getInstance();
                    $tableExists = $footerDb->fetchOne("SHOW TABLES LIKE 'settings'");
                    if ($tableExists) {
                        $footerSettings = $footerDb->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_footer_logo', 'web_footer_text', 'web_footer_show_logo', 'web_footer_copyright', 'web_logo', 'web_site_name', 'web_instagram', 'web_whatsapp')");
                        foreach ($footerSettings as $fs) {
                            if ($fs['setting_key'] === 'web_footer_logo' && !empty($fs['setting_value'])) $footerLogo = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_footer_text') $footerText = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_footer_show_logo') $footerShowLogo = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_footer_copyright') $footerCopyright = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_logo' && empty($footerLogo)) $footerLogo = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_site_name' && !empty($fs['setting_value'])) $footerSiteName = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_instagram') $footerInstagram = $fs['setting_value'];
                            if ($fs['setting_key'] === 'web_whatsapp') $footerWhatsapp = $fs['setting_value'];
                        }
                    }
                } catch (Exception $e) {
                    // Silently continue with defaults
                } catch (Error $e) {
                    // Silently continue with defaults
                }
                ?>
                <?php if ($footerShowLogo === '1' && !empty($footerLogo)): ?>
                <div class="footer-logo">
                    <img src="<?php echo baseUrl($footerLogo); ?>" alt="<?php echo htmlize($footerSiteName); ?>" class="footer-logo-img">
                </div>
                <?php else: ?>
                <h4><?php echo htmlize($footerSiteName); ?></h4>
                <?php endif; ?>
                <?php if (!empty($footerText)): ?>
                <p><?php echo htmlize($footerText); ?></p>
                <?php else: ?>
                <p><?php echo htmlize(getConfig('hotel_description')); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="footer-section">
                <h4>Contact Us</h4>
                <p>
                    <i data-feather="phone" class="icon-small"></i>
                    <a href="tel:<?php echo htmlize(getConfig('phone')); ?>">
                        <?php echo htmlize(getConfig('phone')); ?>
                    </a>
                </p>
                <p>
                    <i data-feather="mail" class="icon-small"></i>
                    <a href="mailto:<?php echo htmlize(getConfig('email')); ?>">
                        <?php echo htmlize(getConfig('email')); ?>
                    </a>
                </p>
                <p>
                    <i data-feather="map-pin" class="icon-small"></i>
                    <?php echo htmlize(getConfig('address')); ?>
                </p>
            </div>
            
            <div class="footer-section">
                <h4>Services</h4>
                <ul>
                    <li><a href="<?php echo baseUrl('rooms.php'); ?>">View Rooms</a></li>
                    <li><a href="<?php echo baseUrl('booking.php'); ?>">Book Now</a></li>
                    <li><a href="<?php echo baseUrl('faqs.php'); ?>">FAQ</a></li>
                </ul>
            </div>
            
            <div class="footer-section">
                <h4>Follow Us</h4>
                <div class="social-links">
                    <?php if (!empty($footerInstagram)): ?>
                    <a href="https://instagram.com/<?php echo htmlize($footerInstagram); ?>" title="Instagram" target="_blank"><i data-feather="instagram"></i></a>
                    <?php endif; ?>
                    <a href="#" title="Facebook"><i data-feather="facebook"></i></a>
                    <?php if (!empty($footerWhatsapp)): ?>
                    <a href="https://wa.me/<?php echo htmlize($footerWhatsapp); ?>" title="WhatsApp" target="_blank"><i data-feather="message-circle"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="footer-bottom">
            <?php if (!empty($footerCopyright)): ?>
            <p>&copy; <?php echo htmlize($footerCopyright); ?></p>
            <?php else: ?>
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlize(BUSINESS_NAME); ?>. All Rights Reserved.</p>
            <?php endif; ?>
        </div>
    </footer>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="<?php echo assetUrl('js/main.js'); ?>"></script>
    
    <?php if (isset($additionalJS)): foreach ((array)$additionalJS as $js): ?>
    <script src="<?php echo assetUrl($js); ?>"></script>
    <?php endforeach; endif; ?>
    
    <script>
        // Initialize Feather icons
        feather.replace();
    </script>
</body>
</html>
