<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlize($pageTitle ?? BUSINESS_NAME); ?> - Narayana Karimunjawa</title>
    <meta name="description" content="<?php echo htmlize(getConfig('hotel_description')); ?>">
    
    <!-- Icons & Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo assetUrl('images/favicon.svg'); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css">
    
    <!-- Styling -->
    <link rel="stylesheet" href="<?php echo assetUrl('css/website.css'); ?>">
    <?php if (isset($additionalCSS)): foreach ((array)$additionalCSS as $css): ?>
    <link rel="stylesheet" href="<?php echo assetUrl($css); ?>">
    <?php endforeach; endif; ?>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="navbar-container">
            <div class="navbar-brand">
                <a href="<?php echo baseUrl(); ?>" class="logo">
                    <span class="logo-icon">🏨</span>
                    <span class="logo-text">Narayana</span>
                </a>
            </div>
            
            <ul class="navbar-menu">
                <li><a href="<?php echo baseUrl(); ?>" class="nav-link">Home</a></li>
                <li><a href="<?php echo baseUrl('rooms.php'); ?>" class="nav-link">Rooms & Rates</a></li>
                <li><a href="<?php echo baseUrl('booking.php'); ?>" class="nav-link booking-btn">Book Now</a></li>
            </ul>
            
            <button class="mobile-menu-toggle">
                <i data-feather="menu"></i>
            </button>
        </div>
    </nav>
    
    <!-- Mobile Menu -->
    <div class="mobile-menu" id="mobileMenu">
        <ul>
            <li><a href="<?php echo baseUrl(); ?>">Home</a></li>
            <li><a href="<?php echo baseUrl('rooms.php'); ?>">Rooms & Rates</a></li>
            <li><a href="<?php echo baseUrl('booking.php'); ?>" class="btn-mobile-booking">Book Now</a></li>
        </ul>
    </div>
