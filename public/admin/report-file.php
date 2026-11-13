<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
$user = require_login();
$mediaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$mediaId) {
    http_response_code(404);
    exit('File not found.');
}
$query = db()->prepare(
    'SELECT m.storage_name, m.original_name, m.mime_type, m.media_type, r.author_id
     FROM report_media m JOIN executive_reports r ON r.id = m.report_id
     WHERE m.id = :id'
);
$query->execute(['id' => $mediaId]);
$media = $query->fetch();
if (!$media || $media['media_type'] !== 'document'
    || ($user['role'] !== 'chairman' && (int) $media['author_id'] !== (int) $user['id'])
    || !preg_match('/^[a-f0-9]{32}\.(pdf|txt)$/', $media['storage_name'])) {
    http_response_code(404);
    exit('File not found.');
}
$path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage'
    . DIRECTORY_SEPARATOR . 'report-media' . DIRECTORY_SEPARATOR . $media['storage_name'];
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('File is no longer available.');
}
$safeName = preg_replace('/[\r\n"\\\\]/', '_', (string) $media['original_name']) ?: 'report-document';
header('Content-Type: ' . $media['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="download"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($path);
