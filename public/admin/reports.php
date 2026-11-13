<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = require_login();
$isChairman = $user['role'] === 'chairman';
$categories = [
    'community_engagement' => 'Community engagement',
    'party_organising' => 'Party organising',
    'meeting' => 'Meeting',
    'outreach' => 'Outreach',
    'project_update' => 'Project update',
    'training' => 'Training',
    'other' => 'Other',
];

function report_text_length(string $value): int
{
    $count = preg_match_all('/./us', $value);
    return $count === false ? PHP_INT_MAX : $count;
}

function report_uploads(string $field, string $kind): array
{
    $files = $_FILES[$field] ?? null;
    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $maximumFiles = $kind === 'photo' ? 5 : 3;
    $allowed = $kind === 'photo'
        ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp']
        : ['application/pdf' => 'pdf', 'text/plain' => 'txt'];
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'report-media';
    $saved = [];
    $count = count($files['name']);

    if ($count > $maximumFiles) {
        throw new RuntimeException($kind === 'photo'
            ? 'Upload no more than five photos per report.'
            : 'Upload no more than three supporting documents per report.');
    }

    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Private report storage is unavailable. Contact the website administrator.');
    }

    try {
        for ($index = 0; $index < $count; $index++) {
            $error = (int) $files['error'][$index];
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$index])) {
                throw new RuntimeException('An uploaded file could not be received. Please try again.');
            }
            $maximumSize = $kind === 'photo' ? 5 * 1024 * 1024 : 10 * 1024 * 1024;
            $size = (int) $files['size'][$index];
            if ($size < 1 || $size > $maximumSize) {
                throw new RuntimeException($kind === 'photo'
                    ? 'Each photo must be smaller than 5 MB.'
                    : 'Each PDF or text document must be smaller than 10 MB.');
            }
            $tmpName = (string) $files['tmp_name'][$index];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
            if (!isset($allowed[$mime])) {
                throw new RuntimeException($kind === 'photo'
                    ? 'Photos must be valid JPEG, PNG or WebP images.'
                    : 'Supporting documents must be PDF or plain text files.');
            }
            if ($kind === 'photo') {
                $dimensions = @getimagesize($tmpName);
                if ($dimensions === false || $dimensions[0] > 8000 || $dimensions[1] > 8000
                    || $dimensions[0] * $dimensions[1] > 40000000) {
                    throw new RuntimeException('A photo has invalid or excessively large dimensions.');
                }
            }
            if ($kind === 'document' && $mime === 'application/pdf'
                && file_get_contents($tmpName, false, null, 0, 5) !== '%PDF-') {
                throw new RuntimeException('A supporting document is not a valid PDF file.');
            }

            $extension = $allowed[$mime];
            $storageName = bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = $directory . DIRECTORY_SEPARATOR . $storageName;
            if (!move_uploaded_file($tmpName, $destination)) {
                throw new RuntimeException('A file could not be saved in private storage.');
            }
            $originalName = basename(str_replace('\\', '/', (string) $files['name'][$index]));
            $originalName = preg_replace('/[\x00-\x1F\x7F]/u', '_', $originalName);
            if (!is_string($originalName)
                || !preg_match('/^.{0,255}/us', $originalName, $matches)) {
                $originalName = 'uploaded-file';
            } else {
                $originalName = $matches[0];
            }
            $saved[] = [
                'storage_name' => $storageName,
                'original_name' => $originalName ?: 'uploaded-file',
                'mime_type' => $mime,
                'file_size' => $size,
                'media_type' => $kind,
            ];
        }
    } catch (RuntimeException $exception) {
        foreach ($saved as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file['storage_name'];
            if (is_file($path)) {
                unlink($path);
            }
        }
        throw $exception;
    }

    return $saved;
}

function discard_report_uploads(array $files): void
{
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'report-media';
    foreach ($files as $file) {
        if (isset($file['storage_name']) && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|pdf|txt)$/', $file['storage_name'])) {
            $path = $directory . DIRECTORY_SEPARATOR . $file['storage_name'];
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'read_notifications') {
        $read = db()->prepare(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE recipient_id = :recipient_id AND read_at IS NULL'
        );
        $read->execute(['recipient_id' => $user['id']]);
        redirect('/admin/reports.php');
    }

    if ($action === 'review') {
        if (!$isChairman) {
            http_response_code(403);
            exit('Only the Chairman can review executive reports.');
        }
        $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string) ($_POST['decision'] ?? '');
        $reason = trim((string) ($_POST['rejection_reason'] ?? ''));
        if (!$reportId || !in_array($decision, ['approved', 'rejected'], true)
            || ($decision === 'rejected' && ($reason === '' || report_text_length($reason) > 1000))
            || ($decision === 'approved' && $reason !== '')) {
            set_flash($decision === 'rejected'
                ? 'Add a rejection reason of no more than 1,000 characters.'
                : 'Invalid report review. Clear any rejection reason before approving.', 'danger');
            redirect('/admin/reports.php' . ($reportId ? '?view=' . $reportId : ''));
        }

        $connection = db();
        $connection->beginTransaction();
        $select = $connection->prepare(
            'SELECT id, author_id, title FROM executive_reports
             WHERE id = :id AND status = "pending" FOR UPDATE'
        );
        $select->execute(['id' => $reportId]);
        $report = $select->fetch();
        if (!$report) {
            $connection->rollBack();
            set_flash('That report is no longer waiting for review.', 'warning');
            redirect('/admin/reports.php');
        }

        $update = $connection->prepare(
            'UPDATE executive_reports
             SET status = :status, reviewer_id = :reviewer_id, reviewed_at = UTC_TIMESTAMP(),
                 rejection_reason = :reason
             WHERE id = :id AND status = "pending"'
        );
        $update->execute([
            'status' => $decision,
            'reviewer_id' => $user['id'],
            'reason' => $decision === 'rejected' ? $reason : null,
            'id' => $reportId,
        ]);
        if ($update->rowCount() !== 1) {
            $connection->rollBack();
            set_flash('That report has already been reviewed.', 'warning');
            redirect('/admin/reports.php');
        }
        $approval = $connection->prepare(
            'INSERT INTO report_approvals (report_id, reviewer_id, decision, reason)
             VALUES (:report_id, :reviewer_id, :decision, :reason)'
        );
        $approval->execute([
            'report_id' => $reportId,
            'reviewer_id' => $user['id'],
            'decision' => $decision,
            'reason' => $decision === 'rejected' ? $reason : null,
        ]);
        $message = $decision === 'approved'
            ? 'Your report was approved and is now available publicly.'
            : 'Your report was rejected. Review the report details for the reason.';
        $notify = $connection->prepare(
            'INSERT INTO notifications (recipient_id, report_id, message)
             VALUES (:recipient_id, :report_id, :message)'
        );
        $notify->execute([
            'recipient_id' => $report['author_id'],
            'report_id' => $reportId,
            'message' => substr($message, 0, 255),
        ]);
        audit_log((int) $user['id'], 'report_' . $decision, 'executive_report', (int) $reportId);
        $connection->commit();
        set_flash($decision === 'approved'
            ? 'Report approved and is now available publicly.'
            : 'Report rejected. The executive has been notified with your reason.');
        redirect('/admin/reports.php?view=' . $reportId);
    }

    if ($action !== 'save') {
        http_response_code(400);
        exit('Unknown report action.');
    }

    $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
    $existing = null;
    if ($reportId) {
        $lookup = db()->prepare(
            'SELECT * FROM executive_reports WHERE id = :id AND author_id = :author_id'
        );
        $lookup->execute(['id' => $reportId, 'author_id' => $user['id']]);
        $existing = $lookup->fetch() ?: null;
        if (!$existing || $existing['status'] !== 'rejected') {
            http_response_code(403);
            exit('Only your rejected reports can be edited and resubmitted.');
        }
    }
    $type = (string) ($_POST['report_type'] ?? '');
    $category = (string) ($_POST['category'] ?? '');
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $activityDate = trim((string) ($_POST['activity_date'] ?? ''));
    $location = trim((string) ($_POST['location'] ?? ''));
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $activityDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!in_array($type, ['activity', 'project'], true)
        || !isset($categories[$category])
        || $title === '' || report_text_length($title) > 180
        || $description === '' || report_text_length($description) > 12000
        || $location === '' || report_text_length($location) > 180
        || !$date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $date->format('Y-m-d') !== $activityDate) {
        set_flash('Complete all report fields with valid dates and keep the description under 12,000 characters.', 'danger');
        redirect('/admin/reports.php' . ($reportId ? '?edit=' . $reportId : ''));
    }

    $uploadedFiles = [];
    try {
        $uploadedFiles = report_uploads('photos', 'photo');
        $uploadedFiles = array_merge($uploadedFiles, report_uploads('documents', 'document'));
        $existingCounts = ['photo' => 0, 'document' => 0];
        if ($existing) {
            $count = db()->prepare(
                'SELECT media_type, COUNT(*) AS media_count
                 FROM report_media WHERE report_id = :id GROUP BY media_type'
            );
            $count->execute(['id' => $reportId]);
            foreach ($count->fetchAll() as $row) {
                $existingCounts[$row['media_type']] = (int) $row['media_count'];
            }
        }
        $newPhotos = count(array_filter($uploadedFiles, static fn(array $file): bool => $file['media_type'] === 'photo'));
        $newDocuments = count($uploadedFiles) - $newPhotos;
        if ($existingCounts['photo'] + $newPhotos > 5
            || $existingCounts['document'] + $newDocuments > 3
            || array_sum($existingCounts) + count($uploadedFiles) > 8) {
            throw new RuntimeException('A report can have no more than eight attachments in total.');
        }
    } catch (PDOException $exception) {
        discard_report_uploads($uploadedFiles);
        error_log('Executive report upload validation failed: ' . $exception->getMessage());
        set_flash('Report attachments could not be verified because of a database error. Please try again or contact the website administrator.', 'danger');
        redirect('/admin/reports.php' . ($reportId ? '?edit=' . $reportId : ''));
    } catch (RuntimeException $exception) {
        discard_report_uploads($uploadedFiles);
        set_flash($exception->getMessage(), 'danger');
        redirect('/admin/reports.php' . ($reportId ? '?edit=' . $reportId : ''));
    }

    $connection = db();
    try {
        $connection->beginTransaction();
        if ($existing) {
            $save = $connection->prepare(
                'UPDATE executive_reports
                 SET report_type = :report_type, category = :category, title = :title,
                     description = :description, activity_date = :activity_date, location = :location,
                     status = "pending", reviewer_id = NULL, reviewed_at = NULL, rejection_reason = NULL
                 WHERE id = :id AND author_id = :author_id AND status = "rejected"'
            );
            $save->execute([
                'report_type' => $type, 'category' => $category, 'title' => $title,
                'description' => $description, 'activity_date' => $activityDate, 'location' => $location,
                'id' => $reportId, 'author_id' => $user['id'],
            ]);
            if ($save->rowCount() !== 1) {
                throw new RuntimeException('The rejected report changed before it could be resubmitted. Refresh and try again.');
            }
        } else {
            $save = $connection->prepare(
                'INSERT INTO executive_reports
                 (author_id, report_type, category, title, description, activity_date, location, status)
                 VALUES (:author_id, :report_type, :category, :title, :description, :activity_date, :location, "pending")'
            );
            $save->execute([
                'author_id' => $user['id'], 'report_type' => $type, 'category' => $category,
                'title' => $title, 'description' => $description, 'activity_date' => $activityDate,
                'location' => $location,
            ]);
            $reportId = (int) $connection->lastInsertId();
        }
        if ($uploadedFiles) {
            $insertMedia = $connection->prepare(
                'INSERT INTO report_media
                 (report_id, uploaded_by, media_type, storage_name, original_name, mime_type, file_size)
                 VALUES (:report_id, :uploaded_by, :media_type, :storage_name, :original_name, :mime_type, :file_size)'
            );
            foreach ($uploadedFiles as $file) {
                $insertMedia->execute([
                    'report_id' => $reportId,
                    'uploaded_by' => $user['id'],
                    'media_type' => $file['media_type'],
                    'storage_name' => $file['storage_name'],
                    'original_name' => $file['original_name'] ?: 'uploaded-file',
                    'mime_type' => $file['mime_type'],
                    'file_size' => $file['file_size'],
                ]);
            }
        }

        $chairmen = $connection->query(
            "SELECT id FROM users WHERE role = 'chairman' AND is_active = 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        $notify = $connection->prepare(
            'INSERT INTO notifications (recipient_id, report_id, message)
             VALUES (:recipient_id, :report_id, :message)'
        );
        foreach ($chairmen as $chairmanId) {
            $notify->execute([
                'recipient_id' => $chairmanId,
                'report_id' => $reportId,
                'message' => 'A new executive report is awaiting your review.',
            ]);
        }
        audit_log((int) $user['id'], $existing ? 'report_resubmitted' : 'report_submitted', 'executive_report', (int) $reportId);
        $connection->commit();
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        discard_report_uploads($uploadedFiles);
        error_log('Executive report save failed: ' . $exception->getMessage());
        set_flash('The report could not be saved because of a database error. Please try again or contact the website administrator.', 'danger');
        redirect('/admin/reports.php' . ($reportId ? '?edit=' . $reportId : ''));
    } catch (RuntimeException $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        discard_report_uploads($uploadedFiles);
        set_flash($exception->getMessage(), 'danger');
        redirect('/admin/reports.php' . ($reportId ? '?edit=' . $reportId : ''));
    }
    set_flash('Report submitted and sent to the Chairman for approval.');
    redirect('/admin/reports.php?view=' . $reportId);
}

$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $statusFilter = 'all';
}
$scopeSql = $isChairman ? '1 = 1' : 'author_id = :author_id';
$statsQuery = db()->prepare(
    "SELECT COUNT(*) AS total,
       COALESCE(SUM(status = 'pending'), 0) AS pending,
       COALESCE(SUM(status = 'approved'), 0) AS approved,
       COALESCE(SUM(status = 'rejected'), 0) AS rejected
     FROM executive_reports WHERE $scopeSql"
);
if (!$isChairman) {
    $statsQuery->execute(['author_id' => $user['id']]);
} else {
    $statsQuery->execute();
}
$stats = $statsQuery->fetch() ?: ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$filterSql = $statusFilter === 'all' ? '' : ' AND r.status = :status';
$reportsQuery = db()->prepare(
    "SELECT r.id, r.author_id, r.report_type, r.category, r.title, r.activity_date,
       r.location, r.status, r.rejection_reason, r.created_at, u.full_name, u.official_position
     FROM executive_reports r JOIN users u ON u.id = r.author_id
     WHERE " . ($isChairman ? '1 = 1' : 'r.author_id = :author_id') . $filterSql . "
     ORDER BY FIELD(r.status, 'pending', 'rejected', 'approved'), r.activity_date DESC, r.created_at DESC
     LIMIT 100"
);
$parameters = [];
if (!$isChairman) {
    $parameters['author_id'] = $user['id'];
}
if ($statusFilter !== 'all') {
    $parameters['status'] = $statusFilter;
}
$reportsQuery->execute($parameters);
$reports = $reportsQuery->fetchAll();

$notificationsQuery = db()->prepare(
    'SELECT n.id, n.report_id, n.message, n.read_at, n.created_at, r.title
     FROM notifications n LEFT JOIN executive_reports r ON r.id = n.report_id
     WHERE n.recipient_id = :recipient_id ORDER BY n.created_at DESC LIMIT 10'
);
$notificationsQuery->execute(['recipient_id' => $user['id']]);
$notifications = $notificationsQuery->fetchAll();
$unreadQuery = db()->prepare('SELECT COUNT(*) FROM notifications WHERE recipient_id = :recipient_id AND read_at IS NULL');
$unreadQuery->execute(['recipient_id' => $user['id']]);
$unreadCount = (int) $unreadQuery->fetchColumn();

$executiveStats = [];
if ($isChairman) {
    $executiveStats = db()->query(
        "SELECT u.id, u.full_name, u.official_position, u.role,
           COUNT(r.id) AS report_count,
           COALESCE(SUM(r.status = 'pending'), 0) AS pending_count,
           COALESCE(SUM(r.status = 'approved'), 0) AS approved_count,
           COALESCE(SUM(r.status = 'rejected'), 0) AS rejected_count
         FROM users u LEFT JOIN executive_reports r ON r.author_id = u.id
         WHERE u.role IN ('executive', 'secretary', 'deputy_secretary', 'treasurer') AND u.is_active = 1
         GROUP BY u.id, u.full_name, u.official_position, u.role
         ORDER BY u.official_position, u.full_name"
    )->fetchAll();
}

$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT);
$viewReport = null;
$viewMedia = [];
$approvalHistory = [];
if ($viewId) {
    $detailQuery = db()->prepare(
        'SELECT r.*, u.full_name, u.official_position, u.role, reviewer.full_name AS reviewer_name
         FROM executive_reports r
         JOIN users u ON u.id = r.author_id
         LEFT JOIN users reviewer ON reviewer.id = r.reviewer_id
         WHERE r.id = :id'
    );
    $detailQuery->execute(['id' => $viewId]);
    $viewReport = $detailQuery->fetch() ?: null;
    if (!$viewReport || (!$isChairman && (int) $viewReport['author_id'] !== (int) $user['id'])) {
        http_response_code(404);
        exit('Report not found.');
    }
    $mediaQuery = db()->prepare(
        'SELECT id, media_type, original_name, file_size FROM report_media
         WHERE report_id = :report_id ORDER BY media_type, id'
    );
    $mediaQuery->execute(['report_id' => $viewId]);
    $viewMedia = $mediaQuery->fetchAll();
    $historyQuery = db()->prepare(
        'SELECT a.decision, a.reason, a.created_at, u.full_name
         FROM report_approvals a JOIN users u ON u.id = a.reviewer_id
         WHERE a.report_id = :report_id ORDER BY a.created_at DESC LIMIT 20'
    );
    $historyQuery->execute(['report_id' => $viewId]);
    $approvalHistory = $historyQuery->fetchAll();
}

$editReport = null;
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editId) {
    $editQuery = db()->prepare(
        'SELECT * FROM executive_reports
         WHERE id = :id AND author_id = :author_id AND status = "rejected"'
    );
    $editQuery->execute(['id' => $editId, 'author_id' => $user['id']]);
    $editReport = $editQuery->fetch() ?: null;
}

$formReport = $editReport ?: [];
$defaultCategory = $categories[$formReport['category'] ?? ''] ?? '';
$dashboardTitle = $isChairman ? 'Executive activity reports' : 'Personal dashboard';
admin_header($dashboardTitle, $user);
?>
<div class="row g-3 mb-4">
  <?php foreach (['total' => 'All reports', 'pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label): ?>
    <div class="col-6 col-lg-3"><div class="admin-card report-stat"><span class="text-secondary small"><?= e($label) ?></span><strong class="stat-number"><?= (int) ($stats[$key] ?? 0) ?></strong></div></div>
  <?php endforeach; ?>
</div>

<?php if ($notifications): ?>
  <section class="admin-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="mb-0">Notifications<?php if ($unreadCount): ?> <span class="badge text-bg-danger"><?= $unreadCount ?> new</span><?php endif; ?></h2>
      <?php if ($unreadCount): ?><form method="post" action="/admin/reports.php"><?= csrf_field() ?><input type="hidden" name="action" value="read_notifications"><button class="btn btn-sm btn-outline-secondary" type="submit">Mark all as read</button></form><?php endif; ?>
    </div>
    <div class="d-grid gap-2">
      <?php foreach ($notifications as $notification): ?>
        <a class="report-notification <?= $notification['read_at'] === null ? 'unread' : '' ?>" href="<?= $notification['report_id'] ? '/admin/reports.php?view=' . (int) $notification['report_id'] : '/admin/reports.php' ?>">
          <span><?= e($notification['message']) ?></span><small><?= e(date('M j, Y H:i', strtotime($notification['created_at'] . ' UTC'))) ?> UTC</small>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($isChairman): ?>
  <section class="admin-card mb-4">
    <h2>Executive reporting statistics</h2>
    <?php if (!$executiveStats): ?><p class="text-secondary mb-0">No executive reporting accounts have been created yet. Create accounts under Users &amp; roles.</p>
    <?php else: ?><div class="table-responsive"><table class="table admin-table align-middle mb-0"><thead><tr><th>Executive</th><th>Submitted</th><th>Pending</th><th>Approved</th><th>Rejected</th></tr></thead><tbody>
      <?php foreach ($executiveStats as $executive): ?><tr><td><strong><?= e($executive['full_name']) ?></strong><br><span class="small text-secondary"><?= e($executive['official_position'] ?: ucwords(str_replace('_', ' ', $executive['role']))) ?></span></td><td><?= (int) $executive['report_count'] ?></td><td><?= (int) $executive['pending_count'] ?></td><td><?= (int) $executive['approved_count'] ?></td><td><?= (int) $executive['rejected_count'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($viewReport): ?>
  <section class="admin-card mb-4" id="report-detail">
    <div class="d-flex flex-wrap justify-content-between gap-2"><div><span class="report-status status-<?= e($viewReport['status']) ?>"><?= e(ucfirst($viewReport['status'])) ?></span><span class="role-pill ms-2"><?= e(ucfirst($viewReport['report_type'])) ?> · <?= e($categories[$viewReport['category']] ?? $viewReport['category']) ?></span></div><a class="btn btn-sm btn-outline-secondary" href="/admin/reports.php?status=<?= e($statusFilter) ?>">Close report</a></div>
    <h2 class="mt-3 mb-2"><?= e($viewReport['title']) ?></h2>
    <p class="text-secondary small"><?= e($viewReport['full_name']) ?> · <?= e($viewReport['official_position'] ?: ucwords(str_replace('_', ' ', $viewReport['role'] ?? 'executive'))) ?> · <?= e(date('F j, Y', strtotime($viewReport['activity_date']))) ?> · <?= e($viewReport['location']) ?></p>
    <div class="report-description"><?= nl2br(e($viewReport['description'])) ?></div>
    <?php if ($viewReport['status'] === 'rejected' && $viewReport['rejection_reason']): ?><div class="alert alert-danger mt-3 mb-0"><strong>Reason for rejection:</strong> <?= e($viewReport['rejection_reason']) ?></div><?php endif; ?>
    <?php if ($viewMedia): ?><h3 class="h6 fw-bold mt-4">Attachments</h3><div class="d-flex flex-wrap gap-3">
      <?php foreach ($viewMedia as $media): ?>
        <?php if ($media['media_type'] === 'photo'): ?><a href="/admin/report-photo.php?id=<?= (int) $media['id'] ?>" target="_blank" rel="noopener noreferrer"><img class="report-photo-thumb" src="/admin/report-photo.php?id=<?= (int) $media['id'] ?>" alt="Report photograph"></a>
        <?php else: ?><a class="report-attachment" href="/admin/report-file.php?id=<?= (int) $media['id'] ?>">📎 <?= e($media['original_name']) ?> <small>(<?= number_format((int) $media['file_size'] / 1024) ?> KB)</small></a><?php endif; ?>
      <?php endforeach; ?>
    </div><?php endif; ?>
    <?php if ($approvalHistory): ?><h3 class="h6 fw-bold mt-4">Review history</h3><ul class="list-group list-group-flush"><?php foreach ($approvalHistory as $history): ?><li class="list-group-item px-0"><strong><?= e(ucfirst($history['decision'])) ?></strong> by <?= e($history['full_name']) ?> · <?= e(date('M j, Y H:i', strtotime($history['created_at'] . ' UTC'))) ?> UTC<?php if ($history['reason']): ?><br><span class="text-secondary"><?= e($history['reason']) ?></span><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($isChairman && $viewReport['status'] === 'pending'): ?>
      <form class="review-report-form mt-4" method="post" action="/admin/reports.php?view=<?= (int) $viewReport['id'] ?>" onsubmit="return this.elements.decision.value !== 'rejected' || this.elements.rejection_reason.value.trim() !== '';">
        <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="report_id" value="<?= (int) $viewReport['id'] ?>">
        <label class="form-label admin-form-label" for="decision-<?= (int) $viewReport['id'] ?>">Review decision</label>
        <select class="form-select mb-2" id="decision-<?= (int) $viewReport['id'] ?>" name="decision" required><option value="approved">Approve and publish</option><option value="rejected">Reject and return with reason</option></select>
        <label class="form-label admin-form-label" for="reason-<?= (int) $viewReport['id'] ?>">Rejection reason (required when rejecting)</label>
        <textarea class="form-control mb-3" id="reason-<?= (int) $viewReport['id'] ?>" name="rejection_reason" maxlength="1000" rows="3"></textarea>
        <button class="btn btn-success" type="submit">Save review decision</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>

<div class="row g-4 align-items-start">
  <section class="col-12 <?= $isChairman ? '' : 'col-xl-7' ?>">
    <div class="admin-card">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><h2 class="mb-0"><?= $isChairman ? 'All executive reports' : 'My submitted reports' ?></h2>
        <form method="get" action="/admin/reports.php"><label class="visually-hidden" for="report-status">Filter reports by status</label><select class="form-select form-select-sm" id="report-status" name="status" onchange="this.form.submit()"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option><option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option><option value="approved" <?= $statusFilter === 'approved' ? 'selected' : '' ?>>Approved</option><option value="rejected" <?= $statusFilter === 'rejected' ? 'selected' : '' ?>>Rejected</option></select></form>
      </div>
      <?php if (!$isChairman): ?><p class="small text-secondary mb-3">Your dashboard shows all your submitted activity reports, project updates, notifications and approval status.</p><?php endif; ?>
      <?php if (!$reports): ?><p class="text-secondary mb-0">No reports match this view yet.</p>
      <?php else: ?><div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>Report</th><?php if ($isChairman): ?><th>Executive</th><?php endif; ?><th>Activity date</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($reports as $report): ?><tr>
          <td><strong><?= e($report['title']) ?></strong><br><span class="small text-secondary"><?= e(ucfirst($report['report_type'])) ?> · <?= e($categories[$report['category']] ?? $report['category']) ?></span></td>
          <?php if ($isChairman): ?><td><?= e($report['full_name']) ?><br><span class="small text-secondary"><?= e($report['official_position']) ?></span></td><?php endif; ?>
          <td><?= e(date('M j, Y', strtotime($report['activity_date']))) ?></td>
          <td><span class="report-status status-<?= e($report['status']) ?>"><?= e(ucfirst($report['status'])) ?></span></td>
          <td class="text-nowrap"><a class="btn btn-sm btn-outline-success" href="/admin/reports.php?view=<?= (int) $report['id'] ?>">View</a>
            <?php if (!$isChairman && $report['status'] === 'rejected'): ?><a class="btn btn-sm btn-outline-secondary" href="/admin/reports.php?edit=<?= (int) $report['id'] ?>">Revise</a><?php endif; ?>
          </td>
        </tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </section>

  <section class="col-12 <?= $isChairman ? '' : 'col-xl-5' ?>">
    <?php if (!$editReport): ?>
      <div class="admin-card"><h2>Submit an activity report</h2><p class="small text-secondary">Every report starts as pending. The Chairman reviews it before it can appear on the public website.</p>
    <?php else: ?>
      <div class="admin-card"><h2>Revise rejected report</h2><div class="alert alert-warning small"><?= e($editReport['rejection_reason'] ?? 'Please update this submission and send it for review again.') ?></div>
    <?php endif; ?>
      <form method="post" enctype="multipart/form-data" action="/admin/reports.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($editReport): ?><input type="hidden" name="report_id" value="<?= (int) $editReport['id'] ?>"><?php endif; ?>
        <div class="mb-3"><label class="form-label admin-form-label" for="report-type">Submission type *</label><select class="form-select" id="report-type" name="report_type" required><option value="">Choose one</option><option value="activity" <?= ($formReport['report_type'] ?? '') === 'activity' ? 'selected' : '' ?>>Activity report</option><option value="project" <?= ($formReport['report_type'] ?? '') === 'project' ? 'selected' : '' ?>>Project update</option></select></div>
        <div class="mb-3"><label class="form-label admin-form-label" for="category">Category *</label><select class="form-select" id="category" name="category" required><option value="">Choose a category</option><?php foreach ($categories as $value => $label): ?><option value="<?= e($value) ?>" <?= $defaultCategory === $label ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label admin-form-label" for="report-title">Report title *</label><input class="form-control" id="report-title" name="title" maxlength="180" required value="<?= e($formReport['title'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label admin-form-label" for="report-description">Description *</label><textarea class="form-control" id="report-description" name="description" rows="5" maxlength="12000" required><?= e($formReport['description'] ?? '') ?></textarea></div>
        <div class="row g-3 mb-3"><div class="col-12 col-sm-6"><label class="form-label admin-form-label" for="activity-date">Date of activity *</label><input class="form-control" id="activity-date" name="activity_date" type="date" max="<?= e(date('Y-m-d')) ?>" required value="<?= e($formReport['activity_date'] ?? '') ?>"></div><div class="col-12 col-sm-6"><label class="form-label admin-form-label" for="report-location">Location *</label><input class="form-control" id="report-location" name="location" maxlength="180" required value="<?= e($formReport['location'] ?? '') ?>" placeholder="Town or community"></div></div>
        <div class="mb-3"><label class="form-label admin-form-label" for="report-photos">Photographs (up to 5; JPEG, PNG, WebP; 5 MB each)</label><input class="form-control" id="report-photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple></div>
        <div class="mb-3"><label class="form-label admin-form-label" for="report-documents">Supporting documents (up to 3; PDF or TXT; 10 MB each)</label><input class="form-control" id="report-documents" name="documents[]" type="file" accept="application/pdf,text/plain" multiple></div>
        <?php if ($editReport): ?><p class="small text-secondary">Existing attachments are retained. New attachments may be added when you resubmit.</p><?php endif; ?>
        <button class="btn btn-success" type="submit"><?= $editReport ? 'Resubmit for approval' : 'Submit report' ?></button>
      </form>
    </div>
  </section>
</div>
<div class="notice mt-4">Attachments are private until approval. Approved report photographs may be displayed publicly; supporting documents remain restricted to the executive who submitted them and the Chairman.</div>
<?php admin_footer(); ?>
