<?php
require_once __DIR__.'/../../includes/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$article = null;

if ($id) {
  $stmt = db()->prepare("SELECT * FROM knowledge_base WHERE id = ? AND is_published = 1");
  $stmt->execute([$id]);
  $article = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$article) {
  http_response_code(404);
  echo "<h1>Article not found.</h1><a href='" . e(base_url('support/kb.php')) . "'>← Back to Help Center</a>";
  exit;
}
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($article['title']) ?> — NexusFix</title>
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
      max-width: 800px;
      margin: 2rem auto;
      padding: 1rem;
    }

    .back-link {
      display: inline-block;
      color: var(--muted);
      font-size: 0.95rem;
      margin-bottom: 1rem;
      text-decoration: none;
    }

    .back-link:hover {
      color: var(--primary);
    }

    h1 {
      font-size: 1.75rem;
      font-weight: 600;
      margin: 0 0 0.5rem;
    }

    .article-meta {
      display: flex;
      gap: 0.5rem;
      margin: 0.5rem 0 1.5rem;
      flex-wrap: wrap;
      font-size: 0.9rem;
      color: var(--muted);
    }

    .chip {
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

    .article-content {
      line-height: 1.7;
      color: var(--muted);
      margin-bottom: 2rem;
    }

    .article-content p, .article-content ul, .article-content ol {
      margin: 0 0 1rem;
    }

    .article-content ul, .article-content ol {
      padding-left: 1.5rem;
    }

    .btn {
      padding: 0.75rem 1.25rem;
      border-radius: 12px;
      border: 1px solid transparent;
      background: var(--primary);
      color: white;
      text-decoration: none;
      font-weight: 500;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .btn:hover {
      background: #4f9cf9;
      transform: translateY(-1px);
    }

    @media (prefers-reduced-motion: reduce) {
      .btn, .back-link {
        transition: none !important;
      }
    }
  </style>
</head>
<body>
  <?php require __DIR__.'/../../includes/header.php'; ?>
  <main>
    <a href="<?= e(base_url('support/kb.php')) ?>" class="back-link">← Back to Help Center</a>
    <h1><?= e($article['title']) ?></h1>
    <div class="article-meta">
      <span class="chip <?= e($article['category']) ?>"><?= e($article['category']) ?></span>
      <span>Updated <?= (new DateTime($article['updated_at']))->format('M j, Y') ?></span>
    </div>
    <div class="article-content">
      <?= nl2br(e($article['content'])) ?>
    </div>
    <a href="<?= e(base_url('support/contact.php')) ?>" class="btn">Contact Support</a>
  </main>

  <script>
    // Sync theme
    const html = document.documentElement;
    const saved = localStorage.getItem('theme');
    if (saved === 'light') {
      html.setAttribute('data-theme', 'light');
    }
  </script>
</body>
</html>