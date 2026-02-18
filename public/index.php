<?php
/**
 * PUBLIC WEBSITE - Homepage
 * Modern Luxury Resort Website (Style: Travel Destination Page)
 */

define('PUBLIC_ACCESS', true);
require_once './includes/config.php';
require_once './includes/database.php';

$pageTitle = 'Luxury Resort - ' . BUSINESS_NAME;
$additionalCSS = ['css/homepage.css'];

// Get hotel settings and featured packages
$db = PublicDatabase::getInstance();
try {
    // Get featured room types for packages
    $packages = $db->fetchAll("
        SELECT id, type_name as package_name, base_price, description
        FROM room_types
        ORDER BY base_price ASC
        LIMIT 3
    ");
} catch (Exception $e) {
    $packages = [];
}

?>
<?php include './includes/header.php'; ?>

<!-- Hero Section - Large Background -->
<section class="hero-section">
    <div class="hero-background" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.7), rgba(51, 65, 85, 0.7)), url('data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 1200 800%22><defs><linearGradient id=%22grad%22 x1=%220%25%22 y1=%220%25%22 x2=%22100%25%22 y2=%22100%25%22><stop offset=%220%25%22 style=%22stop-color:rgb(102,126,234);stop-opacity:1%22 /><stop offset=%22100%25%22 style=%22stop-color:rgb(118,75,162);stop-opacity:1%22 /></linearGradient></defs><rect width=%221200%22 height=%22800%22 fill=%22url(%23grad)%22/><circle cx=%22600%22 cy=%22400%22 r=%22300%22 fill=%22rgba(255,255,255,0.05)%22/></svg>'); background-size: cover; background-position: center;">
    </div>
    
    <div class="hero-container">
        <!-- Left Content -->
        <div class="hero-content">
            <div class="hero-text">
                <h1 class="hero-title">KARIMUNJAWA</h1>
                <p class="hero-description">
                    Temukan surga tropis di kepulauan eksotis Karimunjawa. 
                    Pantai berpasir putih, air kristal, dan mewah menginap 
                    yang tak terlupakan menanti Anda.
                </p>
                <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-explore">
                    <span>EXPLORE</span>
                    <i data-feather="arrow-right" style="margin-left: 0.5rem;"></i>
                </a>
            </div>
        </div>
        
        <!-- Right Featured Packages -->
        <div class="hero-packages">
            <?php foreach ($packages as $package): ?>
            <div class="package-card">
                <div class="package-image" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"></div>
                <div class="package-info">
                    <h3><?php echo htmlize($package['package_name']); ?></h3>
                    <p><?php echo htmlize(substr($package['description'] ?? '', 0, 60) . '...'); ?></p>
                    <div class="package-price"><?php echo formatCurrency($package['base_price']); ?></div>
                    <small>/malam</small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- About Section -->
<section class="section about-section">
    <div class="container">
        <div class="about-grid">
            <div class="about-content">
                <h2>Mengapa Narayana?</h2>
                <p>
                    Narayana Karimunjawa adalah destinasi pilihan untuk liburan impian Anda. 
                    Kami menawarkan kombinasi sempurna antara kemewahan, kenyamanan, dan 
                    keindahan alam yang tak tertandingi.
                </p>
                <ul class="features-list">
                    <li>🏖️ Pantai Private dengan Pasir Putih</li>
                    <li>🌊 Water Sports & Snorkeling</li>
                    <li>🍽️ Fine Dining Restaurant</li>
                    <li>🧘 Spa & Wellness Center</li>
                    <li>🎾 Fasilitas Olahraga Lengkap</li>
                    <li>👥 Concierge 24/7</li>
                </ul>
            </div>
            <div class="about-image">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 400px; border-radius: 1rem; display: flex; align-items: center; justify-content: center; color: white; font-size: 4rem;">
                    🏝️
                </div>
            </div>
        </div>
    </div>
</section>

<!-- All Packages/Rooms Section -->
<section class="section packages-section dark">
    <div class="container">
        <h2 style="color: white; margin-bottom: 1rem; text-align: center;">Paket Menginap Kami</h2>
        <p style="color: rgba(255, 255, 255, 0.8); text-align: center; margin-bottom: 3rem;">
            Pilih paket yang sesuai dengan budget dan gaya liburan Anda
        </p>
        
        <div class="packages-grid">
            <?php
            // Get all room types
            $allPackages = $db->fetchAll("
                SELECT id, type_name, base_price, description, max_occupancy
                FROM room_types
                ORDER BY base_price ASC
            ");
            
            foreach ($allPackages as $pkg):
            ?>
            <div class="package-full-card">
                <div class="package-img" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"></div>
                <div class="package-details">
                    <h3><?php echo htmlize($pkg['type_name']); ?></h3>
                    <p><?php echo htmlize($pkg['description'] ?? 'Kamar dengan fasilitas mewah dan pemandangan menakjubkan'); ?></p>
                    <div class="package-meta">
                        <span>👥 Hingga <?php echo $pkg['max_occupancy']; ?> tamu</span>
                    </div>
                    <div class="package-footer">
                        <div class="price" style="color: #6366f1; font-size: 1.5rem; font-weight: 700;">
                            <?php echo formatCurrency($pkg['base_price']); ?><br>
                            <span style="font-size: 0.85rem; color: #94a3b8;">/malam</span>
                        </div>
                        <a href="<?php echo baseUrl('booking.php'); ?>" class="btn btn-small btn-primary">
                            Pesan Sekarang
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Testimonials Section -->
<section class="section testimonials-section">
    <div class="container">
        <h2 style="text-align: center; margin-bottom: 3rem;">Kata Tamu Kami</h2>
        
        <div class="testimonials-grid">
            <div class="testimonial-card">
                <div class="stars">⭐⭐⭐⭐⭐</div>
                <p>"Liburan paling sempurna yang pernah kami alami. Staff yang ramah, fasilitas lengkap, dan pemandangan yang menakjubkan!"</p>
                <div class="testimonial-author">
                    <strong>Budi Santoso</strong>
                    <small>Jakarta</small>
                </div>
            </div>
            
            <div class="testimonial-card">
                <div class="stars">⭐⭐⭐⭐⭐</div>
                <p>"Harga sebanding dengan kualitas. Kamarnya nyaman, makanannya lezat, dan pelayanannya ramah. Akan datang lagi!"</p>
                <div class="testimonial-author">
                    <strong>Siti Nurhaliza</strong>
                    <small>Bandung</small>
                </div>
            </div>
            
            <div class="testimonial-card">
                <div class="stars">⭐⭐⭐⭐⭐</div>
                <p>"Destinasi wajib kunjung untuk honeymoon. Susana romantis, private beach, dan sunset yang indah. Recommended!"</p>
                <div class="testimonial-author">
                    <strong>Ahmad Wijaya</strong>
                    <small>Surabaya</small>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="section cta-section dark">
    <div class="container" style="text-align: center;">
        <h2 style="color: white; margin-bottom: 1rem;">Siap untuk Petualangan?</h2>
        <p style="color: rgba(255, 255, 255, 0.8); margin-bottom: 2rem; font-size: 1.1rem;">
            Jangan tunda lagi, pesan kamar Anda sekarang dan nikmati diskon early bird hingga 20%
        </p>
        <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-explore" style="display: inline-flex; align-items: center; gap: 0.5rem;">
            PESAN SEKARANG
            <i data-feather="arrow-right"></i>
        </a>
    </div>
</section>

<?php include './includes/footer.php'; ?>
