<?php
/**
 * Home Page — NexusFix (Final Working Version)
 */
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$app_name = 'NexusFix — IT Repair & Maintenance';
$user = $_SESSION['user'] ?? null;

// === CORRECT PATHS ===
$imageDir = __DIR__ . '/../assets/images/';
$imageWebPath = '/it_repair/assets/images/';

$allowedTypes = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
$images = [];

if (is_dir($imageDir) && is_readable($imageDir)) {
    $files = array_diff(scandir($imageDir), ['.', '..']);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, $allowedTypes)) {
            $images[] = $imageWebPath . $file; // No encoding needed
        }
    }
}
// Fallback
if (empty($images)) {
    $images = ['https://via.placeholder.com/500x300/121622/60a5fa?text=No+Image+Found'];
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="description" content="Professional IT repair service for laptops, phones, printers, and more. Fast, transparent, and reliable.">
  <title><?= e($app_name) ?></title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="  https://fonts.googleapis.com  ">
  <link rel="preconnect" href="https://fonts.gstatic.com  " crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">

  <!-- AOS Animation Library -->
  <link href="  https://unpkg.com/aos@2.3.4/dist/aos.css  " rel="stylesheet">

  <!-- Main Styles -->
  <link rel="stylesheet" href="../assets/css/styles.css">

  <style>
    :root {
      --bg: #0b0c0f;
      --bg-secondary: #101218;
      --card: #101218;
      --card-2: #121622;
      --text: #e8eaf0;
      --text-secondary: #a6adbb;
      --muted: #a6adbb;
      --border: #1f2430;
      --field-border: #2a3242;
      --primary: #60a5fa;
      --primary-hover: #4f9cf9;
      --accent: #6ee7b7;
      --warning: #fbbf24;
      --danger: #f87171;
      --success: #34d399;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
      --radius: 16px;
      --radius-sm: 12px;
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      --gradient-brand: linear-gradient(90deg, #60a5fa, #3b82f6, #1d4ed8);
      --gradient-accent: linear-gradient(90deg, #6ee7b7, #34d399);
    }

    :root[data-theme="light"] {
      --bg: #f7f8fb;
      --bg-secondary: #f0f1f4;
      --card: #ffffff;
      --card-2: #f9fafb;
      --text: #0b0c0f;
      --text-secondary: #5b6172;
      --muted: #5b6172;
      --border: #e5e7eb;
      --field-border: #cbd5e1;
      --shadow: 0 10px 25px rgba(15,23,42,.08);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.1);
      --gradient-brand: linear-gradient(90deg, #3b82f6, #1d4ed8, #1e40af);
      --gradient-accent: linear-gradient(90deg, #34d399, #10b981);
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      line-height: 1.65;
      margin: 0;
      padding: 0;
      transition: background 0.4s ease, color 0.4s ease;
    }

    h1, h2, h3, h4 {
      font-family: 'Manrope', 'Inter', sans-serif;
      font-weight: 800;
      line-height: 1.15;
      margin-top: 0;
      letter-spacing: -0.02em;
    }

    h1 {
      font-size: clamp(2.25rem, 5vw, 3.75rem);
      margin-bottom: 1.2rem;
      background: var(--gradient-brand);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      color: transparent;
    }

    h2 {
      font-size: clamp(1.8rem, 3.5vw, 2.75rem);
      margin-bottom: 1.5rem;
      color: var(--text);
      text-align: center;
    }

    p {
      color: var(--text-secondary);
      margin: 0 0 1rem;
    }

    a {
      color: var(--primary);
      text-decoration: none;
      transition: color 0.2s ease;
    }

    a:hover {
      color: var(--primary-hover);
    }

    /* Scroll Padding Fix */
    html {
      scroll-padding-top: 80px;
    }

    #services, #how, #trust, #contact {
      scroll-margin-top: 80px;
    }

    main {
      max-width: 1200px;
      margin: 0 auto;
      padding: 2rem 1rem;
    }

    section {
      margin-bottom: 6rem;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      padding: 0.85rem 1.75rem;
      border-radius: var(--radius-sm);
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: var(--transition);
      border: 1px solid transparent;
      text-decoration: none;
      box-shadow: 0 4px 12px rgba(96, 165, 250, 0.15);
    }

    .btn.primary {
      background: var(--primary);
      color: white;
    }

    .btn.primary:hover {
      background: #4f9cf9;
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(96, 165, 250, 0.25);
    }

    .btn.outline {
      background: transparent;
      border-color: var(--primary);
      color: var(--primary);
    }

    .btn.outline:hover {
      background: rgba(96, 165, 250, 0.08);
      transform: translateY(-1px);
    }

    .btn.big {
      font-size: 1.1rem;
      padding: 0.95rem 2rem;
    }

    .btn svg {
      transition: transform 0.3s ease;
    }

    .btn:hover svg {
      transform: translateX(2px);
    }

    /* HERO */
    .hero {
      display: flex;
      flex-wrap: wrap;
      gap: 4rem;
      align-items: center;
      margin-bottom: 6rem;
    }

    .hero-copy {
      flex: 1;
      min-width: 300px;
    }

    .hero-copy .subtitle {
      color: var(--text-secondary);
      font-size: 1.25rem;
      margin-bottom: 2rem;
      max-width: 600px;
      font-weight: 500;
    }

    .hero-cta-row {
      display: flex;
      gap: 1.25rem;
      flex-wrap: wrap;
      margin-bottom: 2.5rem;
    }

    .hero-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 1.75rem;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .hero-badge {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-weight: 500;
    }

    .hero-badge svg {
      color: var(--accent);
    }

    /* === MODERN IMAGE SLIDER === */
    .hero-visual {
      flex: 1;
      min-width: 300px;
      position: relative;
    }

    .image-slider-container {
      position: relative;
      width: 100%;
      max-width: 500px;
      height: 300px;
      border-radius: var(--radius);
      overflow: hidden;
      box-shadow: var(--shadow);
      border: 1px solid var(--border);
      background: var(--card);
      perspective: 1000px;
    }

    .image-slider {
      position: relative;
      width: 100%;
      height: 100%;
      transform-style: preserve-3d;
    }

    .slider-image {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: var(--radius);
      display: block;
      opacity: 0;
      transition: all 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    .slider-image.active {
      opacity: 1;
      transform: translateX(0);
      z-index: 2;
    }

    .slider-image.prev-slide {
      transform: translateX(-100%);
      opacity: 0;
    }

    .slider-image.next-slide {
      transform: translateX(100%);
      opacity: 0;
    }

    .slider-image.slide-in-left {
      transform: translateX(0);
      opacity: 1;
    }

    .slider-image.slide-out-left {
      transform: translateX(-100%);
      opacity: 0;
    }

    .slider-image.slide-in-right {
      transform: translateX(0);
      opacity: 1;
    }

    .slider-image.slide-out-right {
      transform: translateX(100%);
      opacity: 0;
    }

    /* Navigation Controls - Hidden by default */
    .slider-controls {
      opacity: 0;
      transition: opacity 0.3s ease;
    }

    .image-slider-container:hover .slider-controls {
      opacity: 1;
    }

    /* Navigation Buttons */
    .nav-button {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      color: white;
      border: none;
      width: 50px;
      height: 50px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      font-weight: bold;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      z-index: 10;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }

    .nav-button:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-50%) scale(1.1);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
    }

    .nav-button:active {
      transform: translateY(-50%) scale(0.95);
    }

    .prev-button {
      left: 20px;
    }

    .next-button {
      right: 20px;
    }

    /* Image Counter */
    .image-counter {
      position: absolute;
      bottom: 20px;
      left: 50%;
      transform: translateX(-50%);
      background: rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      color: white;
      padding: 8px 16px;
      border-radius: 25px;
      font-size: 14px;
      font-weight: 500;
      z-index: 10;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      transition: all 0.3s ease;
    }

    /* Cards */
    .cards {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      margin-bottom: 3rem;
    }

    .service-card {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 2rem;
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
      display: flex;
      flex-direction: column;
      gap: 1rem;
      position: relative;
      overflow: hidden;
    }

    .service-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: var(--gradient-accent);
      transform: scaleX(0);
      transform-origin: left;
      transition: transform 0.4s ease;
    }

    .service-card:hover::before {
      transform: scaleX(1);
    }

    .service-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow);
    }

    .service-icon {
      font-size: 2rem;
      color: var(--primary);
    }

    .service-card h3 {
      margin: 0;
      font-size: 1.35rem;
      color: var(--text);
    }

    .service-card p {
      margin: 0;
      color: var(--text-secondary);
      flex-grow: 1;
    }

    /* How It Works */
    .steps-container {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      margin-top: 2.5rem;
      position: relative;
    }

    .steps-container::before {
      content: '';
      position: absolute;
      top: 20px;
      left: 50px;
      right: 50px;
      height: 2px;
      background-color: var(--border);
      z-index: 1;
    }

    .step {
      display: flex;
      flex-direction: column;
      align-items: center;
      flex: 1;
      min-width: 180px;
      position: relative;
      z-index: 2;
    }

    .step-number {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      background: var(--primary);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      font-size: 1.2rem;
      margin-bottom: 1.25rem;
      transition: transform 0.3s ease;
    }

    .step:hover .step-number {
      transform: scale(1.1);
    }

    .step-content h3 {
      margin: 0 0 0.5rem;
      font-size: 1.2rem;
      color: var(--text);
    }

    .step-content p {
      margin: 0;
      color: var(--text-secondary);
      font-size: 0.95rem;
    }

    /* Trust & Features */
    .features-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1.75rem;
      margin-top: 2rem;
    }

    .feature-item {
      display: flex;
      align-items: flex-start;
      gap: 0.85rem;
      opacity: 0;
      transform: translateY(10px);
      transition: opacity 0.5s ease, transform 0.5s ease;
    }

    /* Contact */
    .contact-section {
      background: linear-gradient(180deg, var(--card), var(--card-2));
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 3rem;
      text-align: center;
      box-shadow: var(--shadow-sm);
      max-width: 800px;
      margin: 0 auto;
    }

    .contact-info {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 3rem;
      margin: 2.5rem 0;
    }

    .contact-item a {
      font-weight: 600;
      font-size: 1.15rem;
      transition: color 0.2s ease;
    }

    .contact-item a:hover {
      color: var(--primary-hover);
    }

    .contact-note {
      color: var(--muted);
      font-size: 0.9rem;
      margin-top: 1.5rem;
      opacity: 0.8;
    }

    /* Footer */
    .site-footer {
      text-align: center;
      padding: 3rem 1rem 2rem;
      color: var(--muted);
      font-size: 0.95rem;
      border-top: 1px solid var(--border);
      margin-top: 2rem;
    }

    .site-footer-links {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 2rem;
      margin: 1.5rem 0;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .hero {
        flex-direction: column;
        text-align: center;
      }
      .hero-badges {
        justify-content: center;
      }
      .steps-container::before {
        display: none;
      }
      .step {
        min-width: 140px;
      }
    }

    @media (max-width: 480px) {
      .hero-cta-row {
        flex-direction: column;
      }
      .btn {
        width: 100%;
      }
      h1 {
        font-size: 2.5rem;
      }
      h2 {
        font-size: 2rem;
      }
    }
  </style>
</head>
<body>

  <?php require __DIR__.'/../includes/header.php'; ?>

  <main>

    <!-- HERO -->
    <section class="hero" data-aos="fade-up">
      <div class="hero-copy">
        <h1>Future-Ready IT Repair & Maintenance</h1>
        <p class="subtitle">
          Same-day diagnostics. Transparent status tracking. Certified technicians.
          Built for laptops, desktops, phones, printers & more.
        </p>
        <div class="hero-cta-row">
          <a class="btn primary big" href="<?= e(base_url('customer/request_new.php')) ?>">
            Start a Repair
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><polyline points="12 5 19 12 12 19"></polyline></svg>
          </a>
          <a class="btn outline big" href="<?= e(base_url('customer/requests.php')) ?>">Track Status</a>
        </div>
        <div class="hero-badges">
          <span class="hero-badge" data-aos="fade-left" data-aos-delay="200">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>
            Free diagnostics
          </span>
          <span class="hero-badge" data-aos="fade-left" data-aos-delay="400">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            Warranty on repairs
          </span>
          <span class="hero-badge" data-aos="fade-left" data-aos-delay="600">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
            In-store & on-site options
          </span>
        </div>
      </div>

      <!-- MODERN IMAGE SLIDER -->
      <div class="hero-visual" data-aos="fade-left" data-aos-delay="300">
        <div class="image-slider-container">
          <div class="image-slider" id="imageSlider">
            <?php foreach ($images as $index => $image): ?>
              <img src="<?= e($image) ?>" alt="IT Repair Service" class="slider-image <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
            <?php endforeach; ?>
          </div>
          <!-- Controls hidden by default, shown on hover -->
          <div class="slider-controls">
            <button class="nav-button prev-button" id="prevBtn">&#8249;</button>
            <button class="nav-button next-button" id="nextBtn">&#8250;</button>
            <div class="image-counter" id="imageCounter">1 / <?= count($images) ?></div>
          </div>
        </div>
      </div>
    </section>

    <!-- SERVICES -->
    <section id="services">
      <h2 data-aos="fade-up">Our Services</h2>
      <div class="cards">
        <article class="service-card" data-aos="fade-up" data-aos-delay="100">
          <div class="service-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          </div>
          <h3>Computers & Laptops</h3>
          <p>Boot issues, data recovery, upgrades, thermal service, board-level repair.</p>
        </article>
        <article class="service-card" data-aos="fade-up" data-aos-delay="200">
          <div class="service-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect><line x1="12" y1="18" x2="12.01" y2="18"></line></svg>
          </div>
          <h3>Phones & Tablets</h3>
          <p>Screens, batteries, charge ports, water damage, camera & speaker fixes.</p>
        </article>
        <article class="service-card" data-aos="fade-up" data-aos-delay="300">
          <div class="service-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
          </div>
          <h3>Printers & Peripherals</h3>
          <p>Setup, networking, driver fixes, feed issues, maintenance kits.</p>
        </article>
        <article class="service-card" data-aos="fade-up" data-aos-delay="400">
          <div class="service-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
          <h3>On-site & Remote</h3>
          <p>Business/consumer visits, network troubleshooting, secure remote assist.</p>
        </article>
      </div>
    </section>

    <!-- HOW IT WORKS -->
    <section id="how" class="how-it-works">
      <h2 data-aos="fade-up">How It Works</h2>
      <div class="steps-container" data-aos="fade-up" data-aos-delay="100">
        <div class="step">
          <div class="step-number">1</div>
          <div class="step-content">
            <h3>Book Online</h3>
            <p>Describe the issue & device.</p>
          </div>
        </div>
        <div class="step">
          <div class="step-number">2</div>
          <div class="step-content">
            <h3>Free Diagnostics</h3>
            <p>Get a quote & approve.</p>
          </div>
        </div>
        <div class="step">
          <div class="step-number">3</div>
          <div class="step-content">
            <h3>Repair & Check</h3>
            <p>Quality checks performed.</p>
          </div>
        </div>
        <div class="step">
          <div class="step-number">4</div>
          <div class="step-content">
            <h3>Pay & Collect</h3>
            <p>Live status updates.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- TRUST / FEATURES -->
    <section id="trust">
      <h2 data-aos="fade-up">Why Choose NexusFix?</h2>
      <div class="features-grid">
        <div class="feature-item" data-aos="fade-up" data-aos-delay="100">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9 12l2 2 4-4"></path></svg>
          </div>
          <div>
            <h3>Certified Techs</h3>
            <p>Qualified professionals you can trust.</p>
          </div>
        </div>
        <div class="feature-item" data-aos="fade-up" data-aos-delay="200">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
          </div>
          <div>
            <h3>Transparent Process</h3>
            <p>Clear timelines & notifications.</p>
          </div>
        </div>
        <div class="feature-item" data-aos="fade-up" data-aos-delay="300">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
          </div>
          <div>
            <h3>Data Security</h3>
            <p>Secure handling of your information.</p>
          </div>
        </div>
        <div class="feature-item" data-aos="fade-up" data-aos-delay="400">
          <div class="feature-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"></circle><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"></polyline></svg>
          </div>
          <div>
            <h3>Workmanship Warranty</h3>
            <p>Confidence in our repairs.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- CONTACT -->
    <section id="contact">
      <div class="contact-section" data-aos="zoom-in">
        <h2>Get In Touch</h2>
        <div class="contact-info">
          <div class="contact-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
            <a href="mailto:testmikebravo@gmail.com">testmikebravo@gmail.com</a>
          </div>
          <div class="contact-item">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
            <a href="tel:+9779869666566">+977-9869666566</a>
          </div>
        </div>
        <p class="contact-note">Thank You For Visiting Our Page</p>
      </div>
    </section>

  </main>

  <footer class="site-footer">
    <span>© <?= e(date('Y')) ?> NexusFix</span>
    <div class="site-footer-links">
      <a href="#privacy">Privacy Policy</a>
      <a href="#terms">Terms of Service</a>
      <a href="<?= e(base_url('support/kb.php')) ?>">Help Center</a>
    </div>
  </footer>

  <!-- AOS JS -->
  <script src="https://unpkg.com/aos@2.3.4/dist/aos.js  "></script>
  <script>
    AOS.init({
      duration: 700,
      easing: 'ease-out-cubic',
      once: false,
      mirror: true,
      offset: 100
    });

    // Theme Toggle
    const htmlElement = document.documentElement;
    const themeToggle = document.getElementById('theme-toggle');

    function applyTheme(theme) {
      htmlElement.setAttribute('data-theme', theme);
      if (themeToggle) themeToggle.checked = (theme === 'light');
    }

    const savedTheme = localStorage.getItem('theme') || 'dark';
    applyTheme(savedTheme);

    if (themeToggle) {
      themeToggle.addEventListener('change', () => {
        const newTheme = themeToggle.checked ? 'light' : 'dark';
        applyTheme(newTheme);
        localStorage.setItem('theme', newTheme);
      });
    }

    // Modern Image Slider Functionality with Auto-play
    document.addEventListener('DOMContentLoaded', function() {
      const images = <?= json_encode($images) ?>;
      const slider = document.getElementById('imageSlider');
      const imageElements = document.querySelectorAll('.slider-image');
      const prevBtn = document.getElementById('prevBtn');
      const nextBtn = document.getElementById('nextBtn');
      const imageCounter = document.getElementById('imageCounter');
      
      let currentIndex = 0;
      let isAnimating = false;
      let autoplayInterval;
      
      // === AUTO-PLAY INTERVAL SETTING ===
      // Change this value to modify auto-play timing (in milliseconds)
      // 2000 = 2 seconds, 3000 = 3 seconds, 4000 = 4 seconds, etc.
      const autoplayDelay = 2000; // 2 seconds
      // === END AUTO-PLAY INTERVAL SETTING ===
      
      // Initialize auto-play
      function startAutoplay() {
        autoplayInterval = setInterval(() => {
          slide('next');
        }, autoplayDelay);
      }
      
      // Stop auto-play
      function stopAutoplay() {
        if (autoplayInterval) {
          clearInterval(autoplayInterval);
        }
      }
      
      // Restart auto-play
      function restartAutoplay() {
        stopAutoplay();
        startAutoplay();
      }
      
      function updateSlider() {
        // Remove all animation classes
        imageElements.forEach(img => {
          img.classList.remove('active', 'prev-slide', 'next-slide', 'slide-in-left', 'slide-out-left', 'slide-in-right', 'slide-out-right');
        });
        
        // Set current image as active
        imageElements[currentIndex].classList.add('active');
        imageCounter.textContent = (currentIndex + 1) + ' / ' + images.length;
      }
      
      function slide(direction) {
        if (isAnimating) return;
        
        isAnimating = true;
        const oldIndex = currentIndex;
        
        if (direction === 'next') {
          currentIndex = (currentIndex + 1) % images.length;
          imageElements[oldIndex].classList.add('slide-out-left');
          imageElements[currentIndex].classList.add('slide-in-right');
        } else {
          currentIndex = (currentIndex - 1 + images.length) % images.length;
          imageElements[oldIndex].classList.add('slide-out-right');
          imageElements[currentIndex].classList.add('slide-in-left');
        }
        
        imageCounter.textContent = (currentIndex + 1) + ' / ' + images.length;
        
        setTimeout(() => {
          updateSlider();
          isAnimating = false;
        }, 600);
      }
      
      // Event listeners for manual navigation
      prevBtn.addEventListener('click', () => {
        slide('prev');
        restartAutoplay(); // Restart autoplay after manual interaction
      });
      
      nextBtn.addEventListener('click', () => {
        slide('next');
        restartAutoplay(); // Restart autoplay after manual interaction
      });
      
      // Keyboard navigation
      document.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowLeft') {
          slide('prev');
          restartAutoplay();
        } else if (e.key === 'ArrowRight') {
          slide('next');
          restartAutoplay();
        }
      });
      
      // Pause autoplay on hover
      slider.addEventListener('mouseenter', () => {
        stopAutoplay();
      });
      
      slider.addEventListener('mouseleave', () => {
        startAutoplay();
      });
      
      // Touch/swipe support for mobile
      let startX = 0;
      let endX = 0;
      
      slider.addEventListener('touchstart', function(e) {
        stopAutoplay();
        startX = e.touches[0].clientX;
      });
      
      slider.addEventListener('touchend', function(e) {
        endX = e.changedTouches[0].clientX;
        const diff = startX - endX;
        
        if (Math.abs(diff) > 50) { // Minimum swipe distance
          if (diff > 0) {
            slide('next');
          } else {
            slide('prev');
          }
          restartAutoplay();
        } else {
          startAutoplay(); // Resume autoplay if not a valid swipe
        }
      });
      
      // Start auto-play when page loads
      startAutoplay();
    });
  </script>

</body>
</html>