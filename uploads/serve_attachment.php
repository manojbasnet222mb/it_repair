<?php
declare(strict_types=1);

require_once __DIR__.'/../../includes/bootstrap.php';
require_role('customer');

$file = $_GET['file'] ?? '';
$file = str_replace(['..', '\\'], '', $file); // prevent path traversal

// Build full path
$filepath = __DIR__ . '/../../uploads/requests/' . $file;

if (!file_exists($filepath)) {
    http_response_code(404);
    echo "File not found: " . htmlspecialchars($filepath);
    exit;
}

$mime = mime_content_type($filepath) ?: 'application/octet-stream';
header("Content-Type: $mime");
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
