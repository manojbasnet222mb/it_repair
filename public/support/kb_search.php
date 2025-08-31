<?php
require_once __DIR__.'/../../includes/bootstrap.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$articles = [];

if ($q) {
  try {
    $pdo = db();
    $stmt = $pdo->prepare("
      SELECT 
        id,
        title,
        LEFT(content, 180) as content,
        category,
        DATE_FORMAT(updated_at, '%b %e') as updated_at
      FROM knowledge_base 
      WHERE is_published = 1 
        AND (title LIKE ? OR content LIKE ?)
      ORDER BY 
        CASE WHEN title LIKE ? THEN 0 ELSE 1 END,
        created_at DESC
      LIMIT 50
    ");
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $articles = array_map(function ($a) {
      return [
        'id' => (int)$a['id'],
        'title' => htmlspecialchars($a['title']),
        'excerpt' => htmlspecialchars(strip_tags($a['content'])) . (strlen($a['content']) > 180 ? '…' : ''),
        'category' => $a['category'],
        'updated' => $a['updated_at']
      ];
    }, $articles);
  } catch (Exception $e) {
    error_log("Search API error: " . $e->getMessage());
  }
}

echo json_encode($articles);