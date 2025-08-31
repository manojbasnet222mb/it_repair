<?php
// serve_temp.php
$path = __DIR__ . '/temp/' . basename($_GET['f']);
if (file_exists($path) && is_file($path)) {
    header('Content-Type: ' . mime_content_type($path));
    readfile($path);
} else {
    http_response_code(404);
    die('File not found.');
}