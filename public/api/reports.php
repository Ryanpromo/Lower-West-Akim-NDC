<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$query = db()->query(
    'SELECT r.id, r.report_type, r.category, r.title, r.description,
       r.activity_date, r.location, u.full_name, u.official_position
     FROM executive_reports r
     JOIN users u ON u.id = r.author_id
     WHERE r.status = "approved"
     ORDER BY r.activity_date DESC, r.id DESC
     LIMIT 20'
);
$reports = [];
$photos = db()->prepare(
    'SELECT id FROM report_media WHERE report_id = :report_id AND media_type = "photo" ORDER BY id LIMIT 5'
);
foreach ($query->fetchAll() as $report) {
    $photos->execute(['report_id' => $report['id']]);
    $reports[] = [
        'id' => (int) $report['id'],
        'type' => $report['report_type'],
        'category' => $report['category'],
        'title' => $report['title'],
        'description' => $report['description'],
        'activityDate' => $report['activity_date'],
        'location' => $report['location'],
        'executive' => $report['full_name'],
        'position' => $report['official_position'],
        'photos' => array_map(
            static fn(array $photo): string => '/report-photo.php?id=' . (int) $photo['id'],
            $photos->fetchAll()
        ),
    ];
}
echo json_encode(['reports' => $reports], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
