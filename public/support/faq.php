<?php
/**
 * Support FAQ — NexusFix
 * Professional FAQ page with accordion layout, dark/light mode, and clean UX.
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
// No login required — guests can view FAQs
?>

<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Frequently asked questions about device repair, turnaround time, pricing, and support.">
  <title>Support Center — NexusFix</title>
  <link rel="stylesheet" href="../../assets/css/styles.css">
  <style>
    :root {
      --bg: #0b0c0f;
      --card: #101218;
      --text: #e8eaf0;
      --muted: #a6adbb;
      --border: #1f2430;
      --field-border: #2a3242;
      --primary: #60a5fa;
      --accent: #6ee7b7;
      --danger: #f87171;
      --shadow: 0 10px 30px rgba(0,0,0,.35);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.18);
      --radius: 16px;
      --transition: all 0.2s ease;
    }

    [data-theme="light"] {
      --bg: #f7f8fb;
      --card: #ffffff;
      --text: #0b0c0f;
      --muted: #5b6172;
      --border: #e5e7eb;
      --field-border: #cbd5e1;
      --shadow: 0 10px 25px rgba(15,23,42,.08);
      --shadow-sm: 0 4px 12px rgba(0,0,0,.1);
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      line-height: 1.5;
      margin: 0;
      padding: 0;
      transition: background 0.3s ease;
    }

    main {
      max-width: 800px;
      margin: 2rem auto;
      padding: 1rem;
    }

    h2 {
      font-size: 1.75rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-bottom: 1.5rem;
    }

    /* Theme Toggle */
    .theme-toggle {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      color: var(--muted);
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 44px;
      height: 24px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: var(--field-border);
      transition: .3s;
      border-radius: 24px;
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background: white;
      transition: .3s;
      border-radius: 50%;
    }

    input:checked + .slider {
      background: var(--primary);
    }

    input:checked + .slider:before {
      transform: translateX(20px);
    }

    /* FAQ Accordion */
    .faq-item {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
    }

    .faq-header {
      padding: 1rem 1.25rem;
      font-size: 1.05rem;
      font-weight: 600;
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: var(--transition);
      color: var(--text);
    }

    .faq-header:hover {
      background: rgba(255,255,255,0.06);
    }

    .faq-header::after {
      content: '+';
      font-size: 1.5rem;
      color: var(--muted);
      transition: transform 0.2s ease;
    }

    .faq-item.active .faq-header::after {
      content: '−';
    }

    .faq-body {
      padding: 0;
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease, padding 0.3s ease;
    }

    .faq-body-content {
      padding: 0 1.25rem 1rem;
      color: var(--muted);
      border-top: 1px solid var(--field-border);
    }

    .faq-item.active .faq-body {
      max-height: 300px;
      padding: 1rem 0;
    }

    /* Actions */
    .actions {
      display: flex;
      gap: 12px;
      margin-top: 2rem;
      flex-wrap: wrap;
    }

    .btn {
      padding: 0.75rem 1.25rem;
      border-radius: 12px;
      border: 1px solid transparent;
      background: rgba(255,255,255,0.06);
      color: var(--text);
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      transition: var(--transition);
      font-size: 1rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .btn.primary {
      background: var(--primary);
      color: white;
    }

    .btn.primary:hover {
      background: #4f9cf9;
      transform: translateY(-1px);
    }

    .btn.subtle {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--field-border);
    }

    .btn.subtle:hover {
      background: rgba(255,255,255,0.06);
    }

    @media (prefers-reduced-motion: reduce) {
      * {
        transition: none !important;
        animation: none !important;
      }
    }
  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main aria-labelledby="page-title">
    <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
      <div>
        <h2 id="page-title">Frequently Asked Questions</h2>
        <p class="subtitle">Find answers to common questions about repairs, pricing, and support.</p>
      </div>

      <div class="theme-toggle">
        <span>Dark</span>
        <label class="toggle-switch">
          <input type="checkbox" id="theme-toggle">
          <span class="slider"></span>
        </label>
        <span>Light</span>
      </div>
    </div>

    <!-- FAQ List -->
    <div class="faq-list" role="tablist">
      <!-- Q1 -->
      <div class="faq-item" id="faq1">
        <button class="faq-header" aria-expanded="false" aria-controls="faq1-body" role="tab">
          How long does a typical repair take?
        </button>
        <div class="faq-body" id="faq1-body" role="tabpanel" aria-labelledby="faq1">
          <div class="faq-body-content">
            Most repairs are completed within 3–5 business days. Diagnostic-only requests take 1–2 days. Complex repairs (e.g., motherboard issues) may take 5–7 days. You’ll receive email updates at every stage.
          </div>
        </div>
      </div>

      <!-- Q2 -->
      <div class="faq-item" id="faq2">
        <button class="faq-header" aria-expanded="false" aria-controls="faq2-body" role="tab">
          Do you offer pickup and delivery?
        </button>
        <div class="faq-body" id="faq2-body" role="tabpanel" aria-labelledby="faq2">
          <div class="faq-body-content">
            Yes! We offer free pickup and delivery within Kathmandu Valley. When submitting your request, select "Pickup" or "On-site" service, and we’ll contact you to schedule a time.
          </div>
        </div>
      </div>

      <!-- Q3 -->
      <div class="faq-item" id="faq3">
        <button class="faq-header" aria-expanded="false" aria-controls="faq3-body" role="tab">
          Is my device insured during repair?
        </button>
        <div class="faq-body" id="faq3-body" role="tabpanel" aria-labelledby="faq3">
          <div class="faq-body-content">
            Yes. All devices are covered under our care, custody, and control policy. We maintain insurance for accidental damage, theft, or loss while in our possession.
          </div>
        </div>
      </div>

      <!-- Q4 -->
      <div class="faq-item" id="faq4">
        <button class="faq-header" aria-expanded="false" aria-controls="faq4-body" role="tab">
          What if my device can't be repaired?
        </button>
        <div class="faq-body" id="faq4-body" role="tabpanel" aria-labelledby="faq4">
          <div class="faq-body-content">
            If a repair isn't possible, we’ll provide a full diagnostic report and discuss options: replacement parts, data recovery, or safe disposal. No charges apply unless you approve next steps.
          </div>
        </div>
      </div>

      <!-- Q5 -->
      <div class="faq-item" id="faq5">
        <button class="faq-header" aria-expanded="false" aria-controls="faq5-body" role="tab">
          Can I get a quote before approving the repair?
        </button>
        <div class="faq-body" id="faq5-body" role="tabpanel" aria-labelledby="faq5">
          <div class="faq-body-content">
            Absolutely. After diagnosis, we’ll send a detailed quote with labor, parts, and tax. You can approve, request changes, or cancel — no fees apply until you confirm.
          </div>
        </div>
      </div>

      <!-- Q6 -->
      <div class="faq-item" id="faq6">
        <button class="faq-header" aria-expanded="false" aria-controls="faq6-body" role="tab">
          Do you repair water-damaged devices?
        </button>
        <div class="faq-body" id="faq6-body" role="tabpanel" aria-labelledby="faq6">
          <div class="faq-body-content">
            Yes. We specialize in liquid damage recovery. Immediate power-off is critical. Bring your device in as soon as possible — we’ll clean, dry, and test internal components.
          </div>
        </div>
      </div>

      <!-- Q7 -->
      <div class="faq-item" id="faq7">
        <button class="faq-header" aria-expanded="false" aria-controls="faq7-body" role="tab">
          How do I track my repair status?
        </button>
        <div class="faq-body" id="faq7-body" role="tabpanel" aria-labelledby="faq7">
          <div class="faq-body-content">
            Log in to your dashboard and go to <strong>My Requests</strong>. Each repair shows real-time status (Received, In Repair, Billed, Shipped, Delivered) and timeline updates.
          </div>
        </div>
      </div>
    </div>

    <!-- Still need help? -->
    <div class="actions" style="margin-top:3rem;">
      <a href="<?= e(base_url('support/contact.php')) ?>" class="btn primary">Contact Support</a>
      <a href="<?= e(base_url('customer/dashboard.php')) ?>" class="btn subtle">Back to Dashboard</a>
    </div>
  </main>

  <script>
    // --- Theme Toggle ---
    const html = document.documentElement;
    const toggle = document.getElementById('theme-toggle');

    const savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
      html.setAttribute('data-theme', 'light');
      toggle.checked = true;
    }

    toggle.addEventListener('change', () => {
      if (toggle.checked) {
        html.setAttribute('data-theme', 'light');
        localStorage.setItem('theme', 'light');
      } else {
        html.setAttribute('data-theme', 'dark');
        localStorage.setItem('theme', 'dark');
      }
    });

    // --- FAQ Accordion ---
    document.querySelectorAll('.faq-header').forEach(button => {
      button.addEventListener('click', () => {
        const item = button.closest('.faq-item');
        const expanded = button.getAttribute('aria-expanded') === 'true';

        // Close all
        document.querySelectorAll('.faq-item').forEach(i => {
          i.classList.remove('active');
          i.querySelector('.faq-header').setAttribute('aria-expanded', 'false');
        });

        // Open clicked
        if (!expanded) {
          item.classList.add('active');
          button.setAttribute('aria-expanded', 'true');
        }
      });
    });

    // Auto-focus first FAQ on load
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelector('.faq-header')?.setAttribute('tabindex', '0');
    });
  </script>
</body>
</html>