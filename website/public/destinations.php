<?php
/**
 * NARAYANA KARIMUNJAWA — Destinations & Blog
 * Karimunjawa Island Travel Guide & Tourist Destinations
 */
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'destinations';
$pageTitle = 'Destinations';

// Load destinations from database
$destinations = [];
try {
    $destRow = dbFetch("SELECT setting_value FROM settings WHERE setting_key = 'web_destinations'");
    if ($destRow && !empty($destRow['setting_value'])) {
        $allDest = json_decode($destRow['setting_value'], true) ?: [];
        // Only show active destinations, sorted by order
        foreach ($allDest as $d) {
            if (!empty($d['active'])) {
                $destinations[] = $d;
            }
        }
        usort($destinations, function($a, $b) { return ($a['order'] ?? 0) - ($b['order'] ?? 0); });
    }
} catch (Exception $e) {}

// Single destination detail view
$selectedDest = null;
if (isset($_GET['id'])) {
    foreach ($destinations as $d) {
        if ($d['id'] === $_GET['id']) {
            $selectedDest = $d;
            $pageTitle = $d['title'];
            break;
        }
    }
}

// Load hero settings for destinations listing page
$_heroRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_dest_%'");
$_heroD = [];
foreach ($_heroRows as $_h) $_heroD[$_h['setting_key']] = $_h['setting_value'];
$destHeroEyebrow  = $_heroD['web_hero_dest_eyebrow']  ?? 'Explore Karimunjawa';
$destHeroTitle     = $_heroD['web_hero_dest_title']     ?? 'Discover the Island';
$destHeroSubtitle  = $_heroD['web_hero_dest_subtitle']  ?? 'Your guide to the most breathtaking destinations and hidden gems of Karimunjawa — from pristine beaches and vibrant coral reefs to lush mangrove forests and unforgettable sunset spots.';
$destHeroBg        = $_heroD['web_hero_dest_background'] ?? '';

require_once __DIR__ . '/includes/header.php';
?>

<?php if ($selectedDest): ?>
<!-- ============== SINGLE DESTINATION DETAIL ============== -->
<section class="dest-hero" <?php if (!empty($selectedDest['image'])): ?>style="background-image: linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.55)), url('<?= BASE_URL ?>/<?= htmlspecialchars($selectedDest['image']) ?>')"<?php endif; ?>>
    <div class="container">
        <div class="dest-hero-content">
            <a href="<?= BASE_URL ?>/destinations.php" class="dest-back-link"><i class="fas fa-arrow-left"></i> All Destinations</a>
            <?php if (!empty($selectedDest['subtitle'])): ?>
            <div class="dest-hero-eyebrow"><?= htmlspecialchars($selectedDest['subtitle']) ?></div>
            <?php endif; ?>
            <h1 class="dest-hero-title"><?= htmlspecialchars($selectedDest['title']) ?></h1>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="dest-detail-content">
            <?php if (!empty($selectedDest['image'])): ?>
            <div class="dest-detail-image">
                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($selectedDest['image']) ?>" alt="<?= htmlspecialchars($selectedDest['title']) ?>">
            </div>
            <?php endif; ?>
            
            <div class="dest-detail-text">
                <?= $selectedDest['content'] ?>
            </div>

            <div class="dest-detail-cta">
                <div class="dest-cta-box">
                    <h3>Interested in visiting <?= htmlspecialchars($selectedDest['title']) ?>?</h3>
                    <p>Book your stay at Narayana Karimunjawa and explore this amazing destination during your trip.</p>
                    <div class="dest-cta-buttons">
                        <a href="<?= BASE_URL ?>/booking.php" class="btn-primary-dest">Book Your Stay</a>
                        <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%2C%20I%27m%20interested%20in%20visiting%20<?= urlencode($selectedDest['title']) ?>" target="_blank" class="btn-outline-dest"><i class="fab fa-whatsapp"></i> Ask Us</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Other Destinations -->
        <?php 
        $otherDest = array_filter($destinations, fn($d) => $d['id'] !== $selectedDest['id']);
        if (!empty($otherDest)):
        ?>
        <div class="dest-other-section">
            <h2 class="dest-other-title">Explore More Destinations</h2>
            <div class="dest-grid">
                <?php foreach (array_slice($otherDest, 0, 3) as $other): ?>
                <a href="<?= BASE_URL ?>/destinations.php?id=<?= urlencode($other['id']) ?>" class="dest-card fade-in">
                    <div class="dest-card-image">
                        <?php if (!empty($other['image'])): ?>
                        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($other['image']) ?>" alt="<?= htmlspecialchars($other['title']) ?>">
                        <?php else: ?>
                        <div class="dest-card-placeholder"><i class="fas fa-mountain"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="dest-card-body">
                        <h3><?= htmlspecialchars($other['title']) ?></h3>
                        <?php if (!empty($other['subtitle'])): ?>
                        <p class="dest-card-subtitle"><?= htmlspecialchars($other['subtitle']) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php else: ?>
<!-- ============== DESTINATIONS LISTING PAGE ============== -->

<!-- Hero -->
<section class="dest-page-hero"<?php if (!empty($destHeroBg)): ?> style="background: linear-gradient(180deg, rgba(0,0,0,0.4), rgba(0,0,0,0.6)), url('<?= BASE_URL ?>/<?= htmlspecialchars($destHeroBg) ?>') center/cover;"<?php endif; ?>>
    <div class="container">
        <div class="dest-page-hero-content">
            <div class="section-eyebrow"><?= htmlspecialchars($destHeroEyebrow) ?></div>
            <h1 class="dest-page-title"><?= $destHeroTitle ?></h1>
            <p class="dest-page-desc"><?= htmlspecialchars($destHeroSubtitle) ?></p>
        </div>
    </div>
</section>

<?php if (empty($destinations)): ?>
<section class="section">
    <div class="container text-center" style="padding: 80px 0;">
        <i class="fas fa-compass" style="font-size: 64px; color: var(--gold); opacity: 0.4;"></i>
        <h2 style="margin-top: 24px; color: var(--dark);">Destinations Coming Soon</h2>
        <p style="color: var(--mid-gray); max-width: 500px; margin: 12px auto 0;">We're preparing our curated guide to the best places in Karimunjawa. Check back soon!</p>
    </div>
</section>
<?php else: ?>

<!-- Featured Destination (first one) -->
<?php $featured = $destinations[0]; ?>
<section class="dest-featured-section">
    <div class="container">
        <a href="<?= BASE_URL ?>/destinations.php?id=<?= urlencode($featured['id']) ?>" class="dest-featured fade-in">
            <div class="dest-featured-image">
                <?php if (!empty($featured['image'])): ?>
                <img src="<?= BASE_URL ?>/<?= htmlspecialchars($featured['image']) ?>" alt="<?= htmlspecialchars($featured['title']) ?>">
                <?php else: ?>
                <div class="dest-card-placeholder" style="height: 100%;"><i class="fas fa-mountain" style="font-size: 64px;"></i></div>
                <?php endif; ?>
            </div>
            <div class="dest-featured-content">
                <div class="dest-featured-badge">Featured Destination</div>
                <h2><?= htmlspecialchars($featured['title']) ?></h2>
                <?php if (!empty($featured['subtitle'])): ?>
                <p class="dest-featured-subtitle"><?= htmlspecialchars($featured['subtitle']) ?></p>
                <?php endif; ?>
                <p class="dest-featured-excerpt"><?= htmlspecialchars(mb_substr(strip_tags($featured['content'] ?? ''), 0, 200)) ?>...</p>
                <span class="dest-read-more">Read More <i class="fas fa-arrow-right"></i></span>
            </div>
        </a>
    </div>
</section>

<!-- All Destinations Grid -->
<?php $gridDest = array_slice($destinations, 1); ?>
<?php if (!empty($gridDest)): ?>
<section class="section">
    <div class="container">
        <div class="section-header text-center fade-in">
            <div class="section-eyebrow">Island Guide</div>
            <h2 class="section-title">More Places to Explore</h2>
            <div class="section-divider"></div>
        </div>
        <div class="dest-grid">
            <?php foreach ($gridDest as $dest): ?>
            <a href="<?= BASE_URL ?>/destinations.php?id=<?= urlencode($dest['id']) ?>" class="dest-card fade-in">
                <div class="dest-card-image">
                    <?php if (!empty($dest['image'])): ?>
                    <img src="<?= BASE_URL ?>/<?= htmlspecialchars($dest['image']) ?>" alt="<?= htmlspecialchars($dest['title']) ?>">
                    <?php else: ?>
                    <div class="dest-card-placeholder"><i class="fas fa-mountain"></i></div>
                    <?php endif; ?>
                    <div class="dest-card-overlay">
                        <span>View Details</span>
                    </div>
                </div>
                <div class="dest-card-body">
                    <h3><?= htmlspecialchars($dest['title']) ?></h3>
                    <?php if (!empty($dest['subtitle'])): ?>
                    <p class="dest-card-subtitle"><?= htmlspecialchars($dest['subtitle']) ?></p>
                    <?php endif; ?>
                    <p class="dest-card-excerpt"><?= htmlspecialchars(mb_substr(strip_tags($dest['content'] ?? ''), 0, 120)) ?>...</p>
                    <span class="dest-read-more">Read More <i class="fas fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php endif; ?>

<!-- CTA Section -->
<section class="dest-cta-section">
    <div class="container text-center">
        <h2>Ready to Explore Karimunjawa?</h2>
        <p>Stay at Narayana and discover all these amazing destinations during your island getaway.</p>
        <div class="dest-cta-buttons" style="justify-content: center;">
            <a href="<?= BASE_URL ?>/booking.php" class="btn-primary-dest">Book Your Stay</a>
            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>" target="_blank" class="btn-outline-dest"><i class="fab fa-whatsapp"></i> Contact Us</a>
        </div>
    </div>
</section>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
