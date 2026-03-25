<?php
/**
 * NARAYANA KARIMUNJAWA — Activities & Experiences
 * What to Do During Your Stay — Inspirational Travel Guide
 */
$_cfg = __DIR__ . '/config/config.php';
if (!file_exists($_cfg)) $_cfg = dirname(__DIR__) . '/config/config.php';
require_once $_cfg;

$currentPage = 'activities';
$pageTitle = 'Activities & Experiences';

// Hero settings
$_heroRows = dbFetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'web_hero_act_%'");
$_heroA = [];
foreach ($_heroRows as $_h) $_heroA[$_h['setting_key']] = $_h['setting_value'];
$actHeroEyebrow  = $_heroA['web_hero_act_eyebrow']  ?? 'Karimunjawa Islands';
$actHeroTitle    = $_heroA['web_hero_act_title']    ?? 'Things to Do During<br>Your <em>Island Stay</em>';
$actHeroSubtitle = $_heroA['web_hero_act_subtitle'] ?? 'Karimunjawa is more than a destination — it\'s a world of its own. Here\'s what awaits you.';
$actHeroBg       = $_heroA['web_hero_act_background'] ?? 'https://images.unsplash.com/photo-1589308078059-be1415eab4c3?w=1920&q=80';

// Activities — editorial guide style
$activities = [
    [
        'id'       => 'snorkeling',
        'eyebrow'  => 'Underwater World',
        'title'    => 'Snorkelling in Crystal Waters',
        'image'    => 'https://images.unsplash.com/photo-1534258936925-c58bed479fcb?w=900&q=80',
        'body'     => 'The waters around Karimunjawa are among the clearest in Java. Just a short boat ride from Narayana, you\'ll be floating above <strong>vibrant coral gardens</strong> alive with clownfish, parrotfish, and sea turtles. Look down and you\'ll see coral structures that rise like underwater mountains — some reaching several metres from the sandy floor. The visibility here often exceeds 15 metres, making it feel like swimming inside an aquarium. Whether you\'re a first-timer or an experienced snorkeller, the reefs of Karimunjawa never disappoint.',
        'details'  => ['Best time: 7am–11am', 'Distance from hotel: ~15 min by boat', 'Suitable for all ages', 'Equipment available nearby'],
    ],
    [
        'id'       => 'island-hopping',
        'eyebrow'  => 'Island Exploration',
        'title'    => 'Hopping Between Islands',
        'image'    => 'https://images.unsplash.com/photo-1559128010-7c1ad6e1b6a5?w=900&q=80',
        'body'     => 'The Karimunjawa archipelago is made up of 27 islands — most of them uninhabited and <strong>entirely untouched</strong>. Spend a day sailing between them and you\'ll find white sand beaches with no footprints, shallow lagoons with water the colour of aquamarine glass, and hillsides draped in tropical forest. From Narayana, you can arrange a traditional wooden boat to take you wherever the wind goes. Stop at Geleang Island for snorkelling, Cemara Kecil for its leaning palm trees, or Menjangan Besar to watch the sunset from a deserted shore.',
        'details'  => ['Full day excursion', 'Customisable route', 'Bring sunscreen & hat', 'Lunch included in most trips'],
    ],
    [
        'id'       => 'sunset',
        'eyebrow'  => 'Golden Hour',
        'title'    => 'Sunsets from the Hilltop',
        'image'    => 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=900&q=80',
        'body'     => 'Narayana is uniquely positioned <strong>close to the island\'s highest viewpoint</strong>, and the sunsets here are unlike anything you\'ve seen. As the sun drops behind the Java Sea, the sky turns a deep amber — silhouetting the palm trees, fishing boats, and distant islands in gold. Many of our guests say the sunset walk is the highlight of their entire trip. You can reach the viewpoint on foot or by motorbike in under ten minutes from the hotel. Bring your camera, arrive about 30 minutes before sunset, and simply watch.',
        'details'  => ['5-10 min from hotel', 'Best visited on clear evenings', 'Walk or motorbike', 'Best from April–October'],
    ],
    [
        'id'       => 'diving',
        'eyebrow'  => 'Deep Exploration',
        'title'    => 'Scuba Diving the Marine Park',
        'image'    => 'https://images.unsplash.com/photo-1682687220742-aba13b6e50ba?w=900&q=80',
        'body'     => 'Karimunjawa is a designated <strong>Marine National Park</strong>, meaning the reefs here are protected and thriving. Below the surface you\'ll find giant sea fans, staghorn coral colonies, and a rich variety of reef fish. Divers regularly encounter reef sharks, Napoleon wrasse, and on lucky days — whale sharks passing through the deeper channels. The underwater topography is dramatic, with walls dropping into blue water and swim-throughs carved by centuries of current. Dive operators near the hotel can arrange everything, from introductory dives for beginners to multi-tank trips for certified divers.',
        'details'  => ['Multiple dive sites nearby', 'Beginner & advanced options', 'Rental equipment available', 'Best visibility: April–September'],
    ],
    [
        'id'       => 'mangrove',
        'eyebrow'  => 'Nature & Ecosystem',
        'title'    => 'Exploring Mangrove Forests',
        'image'    => 'https://images.unsplash.com/photo-1504681869696-d977211a5f4c?w=900&q=80',
        'body'     => 'Along the southern shores of Karimunjawa stretch dense <strong>mangrove forests</strong> — one of the most important ecosystems on the island. Paddle or glide through narrow waterways shaded by arching roots, and you\'ll enter a world of deep quiet. Kingfishers dart between branches, mudskippers skip across the water, and the occasional monitor lizard watches from the bank. The mangroves are the nursery of the sea — sheltering juvenile fish, filtering the water, and protecting the coastline. A short trip from Narayana, this is a journey worth making for any nature lover.',
        'details'  => ['Calm & quiet environment', 'Great for photography', 'Morning visits recommended', 'Boat or kayak options'],
    ],
    [
        'id'       => 'motorbike',
        'eyebrow'  => 'Freedom to Roam',
        'title'    => 'Exploring the Island by Motorbike',
        'image'    => 'https://images.unsplash.com/photo-1558981806-ec527fa84c39?w=900&q=80',
        'body'     => 'The best way to discover Karimunjawa is on a motorbike, with no itinerary and all the time in the world. The island is small enough to circle in a morning, but rich enough to fill a week. Ride past <strong>fishing villages</strong> where colourful boats are pulled up on the beach, stop at a local warung for grilled fish, or follow a dirt track into the jungle to find a hidden viewpoint. You\'ll pass coconut groves, traditional houses, and small temples. Motorbikes are available for rental just a short walk from Narayana — your reception team can point you in the right direction.',
        'details'  => ['Rentals available nearby', 'Easy island roads', 'Full or half-day', 'Helmets provided'],
    ],
];

require_once __DIR__ . '/includes/header.php';
?>

<!-- Hero — same as Home -->
<section class="hero hero-activities">
    <div class="hero-bg" style="background-image: url('<?= htmlspecialchars($actHeroBg) ?>');"></div>
    <div class="container">
        <div class="hero-content">
            <div class="hero-eyebrow"><?= htmlspecialchars($actHeroEyebrow) ?></div>
            <h1><?= $actHeroTitle ?></h1>
            <p class="hero-text"><?= htmlspecialchars($actHeroSubtitle) ?></p>
            <div class="btn-group">
                <a href="#activities-guide" class="btn btn-white btn-lg">Discover More</a>
                <a href="<?= BASE_URL ?>/booking.php" class="btn btn-outline-white btn-lg">Book Your Stay</a>
            </div>
        </div>
    </div>
</section>

<!-- Intro -->
<section class="section" style="padding: 80px 0;">
    <div class="container">
        <div class="act-intro fade-in">
            <div class="act-intro-text">
                <div class="section-eyebrow">During Your Stay</div>
                <h2 class="section-title">Life at Narayana</h2>
                <div class="divider"></div>
                <p style="color: var(--warm-gray); font-size: 1.02rem; line-height: 1.95; max-width: 560px;">
                    Narayana sits at the heart of Karimunjawa — a cluster of islands in the Java Sea where the water is warm, the reefs are alive, and the pace of life slows to something worth remembering. Here is what you can do, see, and feel during your time with us.
                </p>
            </div>
            <div class="act-intro-meta">
                <div class="act-intro-stat">
                    <span class="act-intro-stat-num"><?= count($activities) ?></span>
                    <span class="act-intro-stat-label">Experiences</span>
                </div>
                <div class="act-intro-stat">
                    <span class="act-intro-stat-num">27</span>
                    <span class="act-intro-stat-label">Islands</span>
                </div>
                <div class="act-intro-stat">
                    <span class="act-intro-stat-num">∞</span>
                    <span class="act-intro-stat-label">Memories</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Activities — Editorial Alternating Layout -->
<div id="activities-guide">
<?php foreach ($activities as $i => $act):
    $reverse = $i % 2 !== 0;
?>
<section class="act-story <?= $reverse ? 'act-story-reverse' : '' ?>">
    <div class="container">
        <div class="act-story-inner fade-in">

            <!-- Photo -->
            <div class="act-story-image">
                <img src="<?= htmlspecialchars($act['image']) ?>" alt="<?= htmlspecialchars($act['title']) ?>" loading="lazy">
                <div class="act-story-image-label"><?= htmlspecialchars($act['eyebrow']) ?></div>
            </div>

            <!-- Text -->
            <div class="act-story-content">
                <div class="act-story-num"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></div>
                <div class="section-eyebrow"><?= htmlspecialchars($act['eyebrow']) ?></div>
                <h2><?= htmlspecialchars($act['title']) ?></h2>
                <div class="divider"></div>
                <p class="act-story-body"><?= $act['body'] ?></p>

                <ul class="act-story-details">
                    <?php foreach ($act['details'] as $d): ?>
                    <li><i class="fas fa-circle-dot"></i> <?= htmlspecialchars($d) ?></li>
                    <?php endforeach; ?>
                </ul>

                <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%20Narayana%2C%20I%27d%20like%20to%20know%20more%20about%20<?= urlencode($act['title']) ?>" target="_blank" class="act-story-link">
                    Ask us about this <i class="fas fa-arrow-right"></i>
                </a>
            </div>

        </div>
    </div>
</section>
<?php endforeach; ?>
</div>

<!-- Final CTA -->
<section class="cta-section">
    <div class="container">
        <div class="section-eyebrow" style="color:var(--gold-light);">Ready?</div>
        <h2>Start Planning Your Stay</h2>
        <p>Book a room at Narayana and explore everything Karimunjawa has to offer — right from your doorstep.</p>
        <div class="btn-group" style="justify-content:center;">
            <a href="<?= BASE_URL ?>/booking.php" class="btn btn-gold btn-lg">Reserve a Room</a>
            <a href="https://wa.me/<?= BUSINESS_WHATSAPP ?>?text=Hi%20Narayana%2C%20I%27d%20like%20to%20know%20more%20about%20activities%20during%20my%20stay" target="_blank" class="btn btn-outline-white btn-lg">
                <i class="fab fa-whatsapp"></i> Ask Us Anything
            </a>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
