<?php
/**
 * Fix index.php hero section
 * https://narayanakarimunjawa.com/fix-hero.php?token=fixhero2026
 */
if (($_GET['token'] ?? '') !== 'fixhero2026') die('Token required');

$indexFile = __DIR__ . '/index.php';
$content = file_get_contents($indexFile);

// Pattern lama (berbagai versi yang mungkin ada)
$patterns = [
    '/<div class="hero-bg"[^>]*>.*?<\/div>/s',
];

// Code yang benar
$replacement = '<div class="hero-bg"<?php if (!empty($heroBg)): ?> style="background-image: url(\'<?php echo (strpos($heroBg, \'http\') === 0) ? htmlspecialchars($heroBg) : BASE_URL . \'/\' . htmlspecialchars($heroBg); ?>\')"<?php endif; ?>></div>';

// Backup dulu
copy($indexFile, $indexFile . '.backup');

// Cari dan ganti bagian hero-bg
$newContent = preg_replace(
    '/<div class="hero-bg"[^>]*><\/div>/',
    $replacement,
    $content
);

if ($newContent && $newContent !== $content) {
    file_put_contents($indexFile, $newContent);
    echo "<h2 style='color:green'>✓ Fixed!</h2>";
    echo "<p>Hero background code has been updated.</p>";
    echo "<p><a href='/' style='font-size:20px'>→ Check Website</a></p>";
} else {
    // Manual fix - replace entire hero section
    $heroSection = '<!-- Hero -->
<section class="hero">
<?php 
$heroUrl = "";
if (!empty($heroBg)) {
    $heroUrl = (strpos($heroBg, "http") === 0) ? $heroBg : BASE_URL . "/" . $heroBg;
}
?>
    <div class="hero-bg"<?php if($heroUrl): ?> style="background-image: url(\'<?php echo htmlspecialchars($heroUrl); ?>\')"<?php endif; ?>></div>
    <div class="container">';
    
    $newContent = preg_replace(
        '/<!-- Hero -->.*?<div class="container">/s',
        $heroSection,
        $content
    );
    
    if ($newContent) {
        file_put_contents($indexFile, $newContent);
        echo "<h2 style='color:green'>✓ Fixed (method 2)!</h2>";
        echo "<p><a href='/' style='font-size:20px'>→ Check Website</a></p>";
    } else {
        echo "<h2 style='color:red'>Could not auto-fix</h2>";
        echo "<p>Manual fix needed.</p>";
    }
}

echo "<hr><p style='color:red'><b>DELETE THIS FILE AFTER USE!</b></p>";
