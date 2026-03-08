<?php
/**
 * PUBLIC WEBSITE - Homepage
 * Luxury 5-Star Resort Website
 */

define('PUBLIC_ACCESS', true);
require_once './includes/config.php';
require_once './includes/database.php';

$pageTitle = 'Luxury Beachfront Resort - ' . BUSINESS_NAME;
$additionalCSS = ['css/homepage.css'];

// Get hotel settings and featured packages
$db = PublicDatabase::getInstance();
try {
    // Get featured room types for packages
    $packages = $db->fetchAll("
        SELECT id, type_name as package_name, base_price, description, max_occupancy
        FROM room_types
        ORDER BY base_price ASC
        LIMIT 6
    ");
} catch (Exception $e) {
    $packages = [];
}

// Load web settings from database (destinations, footer logo, etc.)
$webSettingsData = [];
try {
    // Check if settings table exists first
    $tableCheck = $db->fetchOne("SHOW TABLES LIKE 'settings'");
    if ($tableCheck) {
        $settingsRows = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('web_destinations', 'web_footer_logo', 'web_footer_text', 'web_footer_show_logo', 'web_logo', 'web_site_name', 'web_instagram', 'web_whatsapp')");
        foreach ($settingsRows as $sr) {
            $webSettingsData[$sr['setting_key']] = $sr['setting_value'];
        }
    }
} catch (Exception $e) {
    $webSettingsData = [];
} catch (Error $e) {
    $webSettingsData = [];
}

// Parse destinations
$destinations = json_decode($webSettingsData['web_destinations'] ?? '[]', true) ?: [];
// Filter only active destinations
$destinations = array_filter($destinations, function($d) { return !empty($d['active']); });
// Sort by order
usort($destinations, function($a, $b) { return ($a['order'] ?? 0) - ($b['order'] ?? 0); });

?>
<?php include './includes/header.php'; ?>

<!-- HERO SECTION - Full Screen with Background Image -->
<section class="luxury-hero">
    <div class="hero-overlay"></div>
    <div class="hero-content-luxury">
        <div class="destination-card">
            <h1 class="destination-title">Karimunjawa</h1>
            <p class="destination-subtitle">TROPICAL PARADISE IN CENTRAL JAVA, INDONESIA</p>
            <p class="destination-description">
                Discover the most exclusive island retreat with pristine beaches, 
                crystal-clear waters, and world-class accommodations
            </p>
            <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-luxury btn-primary">
                BOOK YOUR ESCAPE
            </a>
        </div>
    </div>
</section>

<!-- FEATURES SECTION - Luxury Amenities -->
<section class="luxury-features">
    <div class="container">
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i data-feather="award"></i>
                </div>
                <h3>World-Class Service</h3>
                <p>
                    Our dedicated concierge team ensures every moment of your stay 
                    is extraordinary and perfectly tailored to your needs.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i data-feather="star"></i>
                </div>
                <h3>Premium Amenities</h3>
                <p>
                    Experience luxury at every turn with our spa, fine dining restaurant, 
                    private beaches, and world-class recreational facilities.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <i data-feather="heart"></i>
                </div>
                <h3>Perfect Destination</h3>
                <p>
                    Whether honeymooning, relaxing, or adventuring, Karimunjawa offers 
                    the perfect setting for unforgettable memories.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ROOM SHOWCASE - Featured Luxury Rooms -->
<section class="luxury-rooms" id="rooms">
    <div class="container">
        <div class="section-header">
            <h2>Our Exclusive Room Collection</h2>
            <p>Handcrafted accommodations for the discerning traveler</p>
        </div>
        
        <div class="rooms-showcase-grid">
            <?php foreach ($packages as $package): ?>
            <div class="room-showcase-card">
                <div class="room-image-container">
                    <div class="room-image" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);"></div>
                    <div class="room-rank"><?php echo strtoupper(substr($package['package_name'], 0, 1)); ?></div>
                </div>
                <div class="room-showcase-info">
                    <h3><?php echo htmlize($package['package_name']); ?></h3>
                    <p class="room-description"><?php echo htmlize(substr($package['description'] ?? '', 0, 80) . '...'); ?></p>
                    <div class="room-details">
                        <span class="room-capacity">👥 Up to <?php echo $package['max_occupancy']; ?> Guests</span>
                    </div>
                    <div class="room-footer">
                        <div class="room-price">
                            <span class="price-label">From</span>
                            <span class="price-value"><?php echo formatCurrency($package['base_price']); ?></span>
                            <span class="price-period">/Night</span>
                        </div>
                        <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-inquiry">
                            INQUIRE
                            <i data-feather="arrow-right" style="width: 16px; height: 16px;"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="view-all-container">
            <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-luxury btn-secondary">
                VIEW ALL ROOMS
            </a>
        </div>
    </div>
</section>

<!-- DESTINATIONS SECTION -->
<?php if (!empty($destinations)): ?>
<section class="luxury-destinations" id="destinations">
    <div class="container">
        <div class="section-header">
            <h2>Explore Karimunjawa</h2>
            <p>Discover the most beautiful destinations around our paradise island</p>
        </div>
        
        <div class="destinations-grid">
            <?php foreach ($destinations as $dest): ?>
            <div class="destination-card-item">
                <div class="dest-image-container">
                    <?php if (!empty($dest['image'])): ?>
                    <img src="<?php echo baseUrl($dest['image']); ?>" alt="<?php echo htmlize($dest['title']); ?>" class="dest-image" loading="lazy">
                    <?php else: ?>
                    <div class="dest-image-placeholder">
                        <i data-feather="map-pin"></i>
                    </div>
                    <?php endif; ?>
                    <div class="dest-overlay">
                        <span class="dest-badge">Destination</span>
                    </div>
                </div>
                <div class="dest-info">
                    <h3><?php echo htmlize($dest['title']); ?></h3>
                    <?php if (!empty($dest['subtitle'])): ?>
                    <p class="dest-subtitle"><?php echo htmlize($dest['subtitle']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($dest['content'])): ?>
                    <p class="dest-content"><?php echo htmlize(mb_substr(strip_tags($dest['content']), 0, 120)) . (mb_strlen(strip_tags($dest['content'])) > 120 ? '...' : ''); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- LUXURY EXPERIENCES SECTION -->
<section class="luxury-experiences">
    <div class="container">
        <div class="section-header">
            <h2>Curated Experiences</h2>
            <p>Create unforgettable memories with our premium services</p>
        </div>
        
        <div class="experiences-grid">
            <div class="experience-card">
                <div class="exp-icon">🏖️</div>
                <h3>Private Beach Access</h3>
                <p>Exclusive access to pristine private beaches with water sports and beach activities</p>
            </div>
            
            <div class="experience-card">
                <div class="exp-icon">🍽️</div>
                <h3>Fine Dining</h3>
                <p>Award-winning cuisine prepared by renowned chefs using the finest local ingredients</p>
            </div>
            
            <div class="experience-card">
                <div class="exp-icon">🧘</div>
                <h3>Wellness & Spa</h3>
                <p>Traditional and modern spa treatments in our exclusive wellness sanctuary</p>
            </div>
            
            <div class="experience-card">
                <div class="exp-icon">⛵</div>
                <h3>Water Adventures</h3>
                <p>Snorkeling, diving, sailing, and island-hopping in crystal-clear tropical waters</p>
            </div>
            
            <div class="experience-card">
                <div class="exp-icon">🎭</div>
                <h3>Cultural Tours</h3>
                <p>Immerse yourself in local culture with guided tours and authentic experiences</p>
            </div>
            
            <div class="experience-card">
                <div class="exp-icon">🌅</div>
                <h3>Sunset Magic</h3>
                <p>Romantic sunset cruises and beachfront dinners you'll remember forever</p>
            </div>
        </div>
    </div>
</section>

<!-- TESTIMONIALS - Guest Reviews Section -->
<section class="luxury-testimonials">
    <div class="container">
        <div class="section-header">
            <h2>What Our Guests Say</h2>
            <p>Real experiences from our valued visitors</p>
        </div>
        
        <div class="testimonials-carousel">
            <div class="testimonial-luxury-card">
                <div class="stars">★★★★★</div>
                <p class="testimonial-text">
                    "An absolutely magnificent escape. The attention to detail is impeccable, 
                    and every staff member treated us like royalty. This is luxury done right."
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">JD</div>
                    <div>
                        <p class="author-name">James Davidson</p>
                        <p class="author-location">New York, USA</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-luxury-card">
                <div class="stars">★★★★★</div>
                <p class="testimonial-text">
                    "The most romantic honeymoon destination imaginable. Perfect beaches, 
                    incredible food, and service that exceeded our expectations at every turn."
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">EC</div>
                    <div>
                        <p class="author-name">Emma & Christopher</p>
                        <p class="author-location">London, UK</p>
                    </div>
                </div>
            </div>
            
            <div class="testimonial-luxury-card">
                <div class="stars">★★★★★</div>
                <p class="testimonial-text">
                    "From the moment we arrived until we left, everything was flawless. 
                    A true 5-star experience in paradise. Already booking our return visit!"
                </p>
                <div class="testimonial-author">
                    <div class="author-avatar">SC</div>
                    <div>
                        <p class="author-name">Sophie Chen</p>
                        <p class="author-location">Singapore</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA SECTION - Final Call to Action -->
<section class="luxury-cta">
    <div class="cta-content">
        <h2>Ready to Experience Paradise?</h2>
        <p>Begin your journey to the most exclusive island retreat</p>
        <div class="cta-buttons">
            <a href="<?php echo baseUrl('booking.php'); ?>" class="btn-luxury btn-primary btn-large">
                BOOK NOW
            </a>
            <a href="#contact" class="btn-luxury btn-secondary btn-large">
                CONTACT US
            </a>
        </div>
    </div>
</section>

<?php include './includes/footer.php'; ?>

