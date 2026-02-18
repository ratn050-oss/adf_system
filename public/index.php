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
                    Discover a tropical paradise in the exotic Karimunjawa archipelago. 
                    White sand beaches, crystal clear waters, and unforgettable 
                    luxury accommodation await you.
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
                <h2>Why Choose Narayana?</h2>
                <p>
                    Narayana Karimunjawa is the ideal destination for your dream vacation. 
                    We offer the perfect combination of luxury, comfort, and 
                    unparalleled natural beauty.
                </p>
                <ul class="features-list">
                    <li>🏖️ Private Beach with White Sand</li>
                    <li>🌊 Water Sports & Snorkeling</li>
                    <li>🍽️ Fine Dining Restaurant</li>
                    <li>🧘 Spa & Wellness Center</li>
                    <li>🎾 Complete Sports Facilities</li>
                    <li>👥 24/7 Concierge Service</li>
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
        <h2 style="color: white; margin-bottom: 1rem; text-align: center;">Our Room Packages</h2>
        <p style="color: rgba(255, 255, 255, 0.8); text-align: center; margin-bottom: 3rem;">
            Choose a package that suits your budget and vacation style
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
                    <p><?php echo htmlize($pkg['description'] ?? 'Room with luxury amenities and amazing views'); ?></p>
                    <div class="package-meta">
                        <span>👥 Up to <?php echo $pkg['max_occupancy']; ?> guests</span>
                    </div>
                    <div class="package-footer">
                        <div class="price" style="color: #6366f1; font-size: 1.5rem; font-weight: 700;">
                            <?php echo formatCurrency($pkg['base_price']); ?><br>
                            <span style="font-size: 0.85rem; color: #94a3b8;">/night</span>
                        </div>
                        <a href="<?php echo baseUrl('booking.php'); ?>" class="btn btn-small btn-primary">
                            Book Now
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
        <h2 style="text-align: center; margin-bottom: 3rem;">What Our Guests Say</h2>
        
        <div class="testimonials-grid">
            <div class="testimonial-card">
                <div class="stars">⭐⭐⭐⭐⭐</div>
                <p>"The most perfect vacation we've ever experienced. Friendly staff, complete facilities, and amazing views!"</p>
                <div class="testimonial-author">
                    <strong>Michael Johnson</strong>
                    <small>New York</small>
                </div>
            </div>
            
            <div class="testimonial-card">
                <div class="stars">⭐⭐⭐⭐⭐</div>
                <p>"Price matches quality. Comfortable rooms, delicious food, and friendly service. Will definitely come back!"</p>
                <div class="testimonial-author">
                    <strong>Sarah Williams</strong>
                    <small>Los Angeles</small>
                </div>
            </div>
            
            <div class="testimonial-card">
                <div class="stars">⭐⭐⭐⭐⭐</div>
                <p>"Must-visit destination for honeymoon. Romantic atmosphere, private beach, and beautiful sunset. Highly recommended!"</p>
                <div class="testimonial-author">
                    <strong>David Smith</strong>
                    <small>London</small>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="section cta-section dark">
    <div class="container" style="text-align: center;">
        <h2 style="color: white; margin-bottom: 1rem;">Ready for Your Next Adventure?</h2>
        <p style="color: rgba(255, 255, 255, 0.8); margin-bottom: 2rem; font-size: 1.1rem;">
            Don't wait, book your room now and enjoy early bird discount up to 20%
        </p>
        <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-explore" style="display: inline-flex; align-items: center; gap: 0.5rem;">
            BOOK NOW
            <i data-feather="arrow-right"></i>
        </a>
    </div>
</section>

<?php include './includes/footer.php'; ?>
