<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
$mediaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$mediaId) {
    http_response_code(404);
    exit('Photo not found.');
}
$query = db()->prepare(
    'SELECT m.storage_name, m.mime_type
     FROM report_media m JOIN executive_reports r ON r.id = m.report_id
     WHERE m.id = :id AND m.media_type = "photo" AND r.status = "approved"'
);
$query->execute(['id' => $mediaId]);
$media = $query->fetch();
if (!$media || !in_array($media['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)
    || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $media['storage_name'])) {
    http_response_code(404);
    exit('Photo not found.');
}
$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'report-media' . DIRECTORY_SEPARATOR . $media['storage_name'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('Photo is no longer available.');
}
header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
