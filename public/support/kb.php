<?php
/**
 * Knowledge Base — NexusFix (All-in-One, No Redirects, Live Search)
 * Everything on one page. No clicks. No redirects. Just search & read.
 */

declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';

// Fetch ALL published articles (no search yet)
try {
  $pdo = db();
  $stmt = $pdo->prepare("
    SELECT id, title, content, category, updated_at
    FROM knowledge_base
    WHERE is_published = 1
    ORDER BY FIELD(category, 'Repair', 'Billing', 'Shipping', 'Warranty', 'Account', 'General'), created_at DESC
  ");
  $stmt->execute();
  $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  error_log("KB load error: " . $e->getMessage());
  $articles = [];
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Search our knowledge base for answers to common questions about repairs, billing, and support.">
  <title>Help Center — NexusFix</title>
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
    }

    main {
      max-width: 840px;
      margin: 2rem auto;
      padding: 1rem;
    }

    h2 {
      font-size: 1.85rem;
      font-weight: 600;
      margin-bottom: 0.25rem;
    }

    .subtitle {
      color: var(--muted);
      font-size: 0.95rem;
      margin-bottom: 1.75rem;
    }

    /* Header */
    .header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    /* Search */
    .search-box {
      display: flex;
      gap: 8px;
      align-items: center;
      background: var(--card);
      border: 1px solid var(--field-border);
      border-radius: 12px;
      padding: 4px;
      margin-bottom: 2rem;
      transition: var(--transition);
    }

    .search-box:focus-within {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2);
    }

    .search-box input {
      flex: 1;
      background: transparent;
      border: none;
      color: var(--text);
      padding: 0.65rem 0.8rem;
      font-size: 0.95rem;
      outline: none;
    }

    .search-box input::placeholder {
      color: var(--muted);
      opacity: 0.7;
    }

    /* Article */
    .article {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 1.25rem;
      margin-bottom: 1rem;
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
    }

    .article h3 {
      margin: 0 0 0.5rem;
      font-size: 1.2rem;
      font-weight: 600;
      color: var(--text);
    }

    .article-content {
      color: var(--muted);
      font-size: 0.95rem;
      line-height: 1.6;
      margin: 0.5rem 0 0.75rem;
    }

    .article-meta {
      display: flex;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 0.5rem;
      font-size: 0.85rem;
      color: var(--muted);
    }

    .chip {
      display: inline-flex;
      align-items: center;
      padding: 0.25rem 0.6rem;
      border-radius: 999px;
      font-size: 0.8rem;
      font-weight: 500;
    }

    .chip.Repair { background: rgba(96,165,255,0.15); color: #60a5fa; border: 1px solid rgba(96,165,255,0.3); }
    .chip.Billing { background: rgba(251,191,36,0.15); color: #fbbf24; border: 1px solid rgba(251,191,36,0.3); }
    .chip.Shipping { background: rgba(167,139,250,0.15); color: #a78bfa; border: 1px solid rgba(167,139,250,0.3); }
    .chip.Warranty { background: rgba(163,163,163,0.15); color: #a3a3a3; border: 1px solid rgba(163,163,163,0.3); }
    .chip.Account { background: rgba(106,227,183,0.15); color: #6ee7b7; border: 1px solid rgba(106,227,183,0.3); }
    .chip.General { background: rgba(166,173,187,0.15); color: #a6adbb; border: 1px solid rgba(166,173,187,0.3); }

    .highlight {
      background: #60a5fa33;
      color: var(--primary);
      padding: 0 2px;
      border-radius: 2px;
      font-weight: 600;
    }

    .empty-state {
      text-align: center;
      padding: 4rem 1rem;
      color: var(--muted);
      border: 1px dashed var(--border);
      border-radius: var(--radius);
    }

    .empty-state svg {
      width: 60px;
      height: 60px;
      opacity: 0.3;
      margin-bottom: 1rem;
    }
  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>

  <main aria-labelledby="page-title">
    <!-- REMOVED: Duplicate theme toggle HTML block -->
    <div class="header">
      <div>
        <h2 id="page-title">Help Center</h2>
        <p class="subtitle">Find answers to common questions about repairs, billing, and support.</p>
      </div>
      <!-- The theme toggle is now handled by header.php, no need to duplicate it here -->
    </div>
    <!-- END REMOVAL -->

    <!-- Search -->
    <div class="search-box">
      <input
        type="text"
        id="search-input"
        placeholder="Search help articles…"
        aria-label="Search knowledge base"
        autocomplete="off"
      >
    </div>

    <!-- Articles -->
    <div id="articles-container">
      <?php foreach ($articles as $a): ?>
        <article class="article" data-title="<?= e(strtolower($a['title'])) ?>" data-content="<?= e(strtolower(strip_tags($a['content']))) ?>">
          <h3><?= e($a['title']) ?></h3>
          <div class="article-content" id="content-<?= $a['id'] ?>">
            <?= nl2br(e($a['content'])) ?>
          </div>
          <div class="article-meta">
            <span class="chip <?= e($a['category']) ?>"><?= e($a['category']) ?></span>
            <span>Updated <?= (new DateTime($a['updated_at']))->format('M j') ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- No Results -->
    <div id="empty-state" class="empty-state" style="display:none;">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/>
        <path d="M12 8v4m0 4h.01"/>
      </svg>
      <p>No articles match your search.</p>
    </div>
  </main>

  <script>
    // --- REMOVED: Duplicate theme toggle script block ---
    // The theme logic is now handled by header.php
    // Keeping only the search/highlight logic
    // --- Live Search & Highlight ---
    const searchInput = document.getElementById('search-input');
    const articles = document.querySelectorAll('.article');
    const emptyState = document.getElementById('empty-state');

    function highlightText(text, query) {
      if (!query) return text;
      const regex = new RegExp(`(${query})`, 'gi');
      return text.replace(regex, '<span class="highlight">$1</span>');
    }

    function filterArticles() {
      const q = searchInput.value.trim().toLowerCase();
      let visibleCount = 0;

      articles.forEach(art => {
        const title = art.dataset.title;
        const content = art.dataset.content;
        const match = title.includes(q) || content.includes(q);
        const contentEl = art.querySelector('.article-content');

        if (match && q) {
          // Highlight matches
          const originalText = contentEl.innerHTML;
          const highlighted = highlightText(originalText, q);
          contentEl.innerHTML = highlighted;
        } else if (q) {
          // Still show if matched earlier - might need refinement
          const original = contentEl.textContent;
          const highlighted = highlightText(original, q);
          contentEl.innerHTML = highlighted;
        }

        art.style.display = match ? 'block' : 'none';
        if (match) visibleCount++;
      });

      emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    searchInput.addEventListener('input', filterArticles);

    // Auto-focus search
    document.addEventListener('DOMContentLoaded', () => {
      if (window.innerWidth > 600) {
        searchInput.focus();
      }
    });
  </script>
</body>
</html>