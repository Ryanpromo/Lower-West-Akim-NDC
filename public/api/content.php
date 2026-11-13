<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=120');

$query = db()->query(
    'SELECT id, content_type, title, position, body, event_date, image_path, created_at
     FROM content_items
     WHERE status = "published"
     ORDER BY COALESCE(event_date, created_at) DESC, id DESC
     LIMIT 100'
);
$content = [
    'news' => [],
    'events' => [],
    'projects' => [],
    'executives' => [],
    'gallery' => [],
];
foreach ($query->fetchAll() as $item) {
    $type = match ($item['content_type']) {
        'event' => 'events',
        'project' => 'projects',
        'executive' => 'executives',
        default => $item['content_type'],
    };
    $eventDate = $item['event_date']
        ? (new DateTimeImmutable($item['event_date'], new DateTimeZone('UTC')))->format(DATE_ATOM)
        : null;
    $createdAt = (new DateTimeImmutable($item['created_at'], new DateTimeZone('UTC')))->format(DATE_ATOM);
    $content[$type][] = [
        'id' => (int) $item['id'],
        'title' => $item['title'],
        'position' => $item['position'],
        'body' => $item['body'],
        'eventDate' => $eventDate,
        'image' => $item['image_path'] ? '/uploads/' . $item['image_path'] : null,
        'createdAt' => $createdAt,
    ];
}
echo json_encode($content, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
