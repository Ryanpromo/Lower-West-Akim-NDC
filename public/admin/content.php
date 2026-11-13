<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = require_login();
$types = [
    'news' => 'News',
    'event' => 'Events',
    'project' => 'Projects',
    'executive' => 'Executive profiles',
    'gallery' => 'Gallery',
];
$requestedType = (string) ($_GET['type'] ?? 'news');
$isContentEditor = in_array($user['role'], ['chairman', 'secretary', 'deputy_secretary', 'treasurer'], true);
if (!$isContentEditor) {
    http_response_code(403);
    exit('Your account can submit activity reports but cannot manage public website content.');
}
$type = match ($requestedType) {
    'news' => 'news',
    'events', 'event' => 'event',
    'projects', 'project' => 'project',
    'executives', 'executive' => 'executive',
    'gallery' => 'gallery',
    default => 'news',
};
$typeLabel = $types[$type];
$isChairman = $user['role'] === 'chairman';
$uploadDirectory = __DIR__ . '/../uploads';

function content_upload(): ?string
{
    global $uploadDirectory;
    if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The image upload failed. Try another image.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Choose an image smaller than 5 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $formats = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($file['tmp_name']);
    if (!isset($formats[$mime]) || $dimensions === false) {
        throw new RuntimeException('Use a valid JPEG, PNG or WebP image. SVG files are not accepted.');
    }
    if ($dimensions[0] > 8000 || $dimensions[1] > 8000 || $dimensions[0] * $dimensions[1] > 40000000) {
        throw new RuntimeException('The image dimensions are too large. Resize it before uploading.');
    }
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The image folder is unavailable. Contact the website administrator.');
    }
    $filename = bin2hex(random_bytes(20)) . '.' . $formats[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . '/' . $filename)) {
        throw new RuntimeException('The image could not be saved. Check the hosting file permissions.');
    }
    return $filename;
}

function remove_content_image(?string $filename): void
{
    global $uploadDirectory;
    if ($filename === null || !preg_match('/^[a-f0-9]{40}\.(jpg|png|webp)$/', $filename)) {
        return;
    }
    $path = $uploadDirectory . '/' . $filename;
    if (is_file($path)) {
        unlink($path);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'review') {
        if (!$isChairman) {
            http_response_code(403);
            exit('Only the Chairman can approve content.');
        }
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $decision = (string) ($_POST['decision'] ?? '');
        if (!$id || !in_array($decision, ['published', 'rejected'], true)) {
            set_flash('Invalid review action.', 'danger');
            redirect('/admin/index.php');
        }
        $review = db()->prepare(
            'UPDATE content_items
             SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW()
             WHERE id = :id AND status = "pending"'
        );
        $review->execute(['status' => $decision, 'reviewed_by' => $user['id'], 'id' => $id]);
        if ($review->rowCount() !== 1) {
            set_flash('This item is no longer waiting for approval.', 'warning');
        } else {
            audit_log((int) $user['id'], 'content_' . $decision, 'content', (int) $id);
            set_flash($decision === 'published' ? 'Content approved and published.' : 'Content rejected.');
        }
        redirect('/admin/index.php');
    }

    if ($action === 'delete') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $lookup = db()->prepare('SELECT id, author_id, image_path FROM content_items WHERE id = :id AND content_type = :type');
        $lookup->execute(['id' => $id ?: 0, 'type' => $type]);
        $item = $lookup->fetch();
        if (!$item || (!$isChairman && (int) $item['author_id'] !== (int) $user['id'])) {
            http_response_code(404);
            exit('Content not found or you do not have permission to delete it.');
        }
        if (!$isChairman && $item['status'] === 'published') {
            http_response_code(403);
            exit('Published content can only be deleted by the Chairman.');
        }
        $delete = db()->prepare('DELETE FROM content_items WHERE id = :id');
        $delete->execute(['id' => $item['id']]);
        remove_content_image($item['image_path']);
        audit_log((int) $user['id'], 'content_deleted', 'content', (int) $item['id']);
        set_flash('Content deleted.');
        redirect('/admin/content.php?type=' . rawurlencode($type));
    }

    if ($action !== 'save') {
        http_response_code(400);
        exit('Unknown action.');
    }
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $existing = null;
    if ($id) {
        $lookup = db()->prepare('SELECT * FROM content_items WHERE id = :id AND content_type = :type');
        $lookup->execute(['id' => $id, 'type' => $type]);
        $existing = $lookup->fetch() ?: null;
        if (!$existing || (!$isChairman && (int) $existing['author_id'] !== (int) $user['id'])) {
            http_response_code(404);
            exit('Content not found or you do not have permission to edit it.');
        }
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $position = trim((string) ($_POST['position'] ?? ''));
    $eventDate = trim((string) ($_POST['event_date'] ?? ''));
    if ($title === '' || strlen($title) > 720 || strlen($body) > 20000
        || ($type !== 'gallery' && $body === '') || strlen($position) > 480) {
        set_flash('Enter a title, a description of no more than 20,000 characters, and a valid position where applicable.', 'danger');
        redirect('/admin/content.php?type=' . rawurlencode($type) . ($id ? '&edit=' . $id : ''));
    }

    $parsedEventDate = null;
    if ($eventDate !== '') {
        try {
            $parsedEventDate = (new DateTimeImmutable($eventDate, new DateTimeZone('Africa/Accra')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Exception $exception) {
            set_flash('Enter a valid event date.', 'danger');
            redirect('/admin/content.php?type=event' . ($id ? '&edit=' . $id : ''));
        }
    }
    if ($type === 'event' && $parsedEventDate === null) {
        set_flash('Add the date and time for this event.', 'danger');
        redirect('/admin/content.php?type=event' . ($id ? '&edit=' . $id : ''));
    }

    $uploadedImage = null;
    try {
        $uploadedImage = content_upload();
    } catch (RuntimeException $exception) {
        set_flash($exception->getMessage(), 'danger');
        redirect('/admin/content.php?type=' . rawurlencode($type) . ($id ? '&edit=' . $id : ''));
    }
    $imagePath = $uploadedImage ?? ($existing['image_path'] ?? null);
    if ($type === 'gallery' && $imagePath === null) {
        set_flash('Upload an image for each gallery item.', 'danger');
        redirect('/admin/content.php?type=gallery' . ($id ? '&edit=' . $id : ''));
    }
    $status = $isChairman ? (string) ($_POST['status'] ?? 'draft') : 'pending';
    if (!in_array($status, ['draft', 'pending', 'published'], true)) {
        $status = 'draft';
    }
    $reviewedBy = $status === 'published' && $isChairman ? $user['id'] : null;
    $reviewedAt = $reviewedBy === null ? null : date('Y-m-d H:i:s');
    if ($id) {
        $save = db()->prepare(
            'UPDATE content_items
             SET title = :title, position = :position, body = :body, event_date = :event_date,
                 image_path = :image_path, status = :status, reviewed_by = :reviewed_by,
                 reviewed_at = :reviewed_at
             WHERE id = :id'
        );
        $save->execute([
            'title' => $title, 'position' => $position ?: null, 'body' => $body,
            'event_date' => $parsedEventDate, 'image_path' => $imagePath, 'status' => $status,
            'reviewed_by' => $reviewedBy, 'reviewed_at' => $reviewedAt, 'id' => $id,
        ]);
        if ($uploadedImage !== null && $existing['image_path'] !== $uploadedImage) {
            remove_content_image($existing['image_path']);
        }
        audit_log((int) $user['id'], 'content_updated', 'content', (int) $id);
    } else {
        $save = db()->prepare(
            'INSERT INTO content_items
             (content_type, title, position, body, event_date, image_path, status, author_id, reviewed_by, reviewed_at)
             VALUES (:type, :title, :position, :body, :event_date, :image_path, :status, :author_id, :reviewed_by, :reviewed_at)'
        );
        $save->execute([
            'type' => $type, 'title' => $title, 'position' => $position ?: null, 'body' => $body,
            'event_date' => $parsedEventDate, 'image_path' => $imagePath, 'status' => $status,
            'author_id' => $user['id'], 'reviewed_by' => $reviewedBy, 'reviewed_at' => $reviewedAt,
        ]);
        $id = (int) db()->lastInsertId();
        audit_log((int) $user['id'], 'content_created', 'content', $id);
    }
    set_flash($status === 'published' ? 'Content saved and published.' : ($status === 'draft' ? 'Draft saved.' : 'Content submitted to the Chairman for approval.'));
    redirect('/admin/content.php?type=' . rawurlencode($type));
}

$editing = null;
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($editId) {
    $lookup = db()->prepare('SELECT * FROM content_items WHERE id = :id AND content_type = :type');
    $lookup->execute(['id' => $editId, 'type' => $type]);
    $editing = $lookup->fetch() ?: null;
    if (!$editing || (!$isChairman && (int) $editing['author_id'] !== (int) $user['id'])) {
        http_response_code(404);
        exit('Content not found or you do not have permission to edit it.');
    }
}
$itemsQuery = db()->prepare(
    'SELECT c.id, c.title, c.position, c.status, c.image_path, c.updated_at, u.full_name
     FROM content_items c JOIN users u ON u.id = c.author_id
     WHERE c.content_type = :type AND (:is_chairman = 1 OR c.author_id = :author_id)
     ORDER BY c.updated_at DESC LIMIT 100'
);
$itemsQuery->execute([
    'type' => $type,
    'is_chairman' => $isChairman ? 1 : 0,
    'author_id' => $user['id'],
]);
$items = $itemsQuery->fetchAll();
admin_header($typeLabel, $user);
?>
<div class="row g-4">
  <section class="col-12 col-xl-7"><div class="admin-card">
    <h2><?= e($typeLabel) ?> content</h2>
    <div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>Title</th><?php if ($type === 'executive'): ?><th>Position</th><?php endif; ?><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
      <?php foreach ($items as $item): ?>
        <tr><td><strong><?= e($item['title']) ?></strong><?php if ($user['role'] === 'chairman'): ?><br><span class="text-secondary small">By <?= e($item['full_name']) ?></span><?php endif; ?></td>
          <?php if ($type === 'executive'): ?><td><?= e($item['position']) ?></td><?php endif; ?>
          <td><span class="badge text-bg-<?= $item['status'] === 'published' ? 'success' : ($item['status'] === 'pending' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($item['status'])) ?></span></td>
          <td><?= e(date('M j, Y', strtotime($item['updated_at']))) ?></td>
          <td><div class="approval-actions"><a class="btn btn-sm btn-outline-success" href="/admin/content.php?type=<?= e($type) ?>&amp;edit=<?= (int) $item['id'] ?>">Edit</a>
            <form method="post" action="/admin/content.php?type=<?= e($type) ?>" onsubmit="return confirm('Delete this content item and its uploaded image? This cannot be undone.');">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
            </form></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="<?= $type === 'executive' ? '5' : '4' ?>" class="text-secondary">No content here yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div></section>
  <section class="col-12 col-xl-5"><div class="admin-card">
    <h2><?= $editing ? 'Edit item' : 'Create new item' ?></h2>
    <?php if (!$isChairman): ?><p class="small text-secondary">Your submission will be reviewed by the Chairman before publication.</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="/admin/content.php?type=<?= e($type) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
      <div class="mb-3"><label class="form-label admin-form-label" for="content-title">Title *</label><input class="form-control" id="content-title" name="title" maxlength="180" required value="<?= e($editing['title'] ?? '') ?>"></div>
      <?php if ($type === 'executive'): ?><div class="mb-3"><label class="form-label admin-form-label" for="position">Position</label><input class="form-control" id="position" name="position" maxlength="120" value="<?= e($editing['position'] ?? '') ?>" placeholder="e.g. Constituency Chairman"></div><?php endif; ?>
      <?php if ($type === 'event'): ?><div class="mb-3"><label class="form-label admin-form-label" for="event-date">Date and time *</label><input class="form-control" id="event-date" name="event_date" type="datetime-local" required value="<?= !empty($editing['event_date']) ? e(date('Y-m-d\TH:i', strtotime($editing['event_date']))) : '' ?>"></div><?php endif; ?>
      <div class="mb-3"><label class="form-label admin-form-label" for="body"><?= $type === 'gallery' ? 'Caption / description' : 'Description *' ?></label><textarea class="form-control" id="body" name="body" rows="5" maxlength="20000" <?= $type === 'gallery' ? '' : 'required' ?>><?= e($editing['body'] ?? '') ?></textarea></div>
      <div class="mb-3"><label class="form-label admin-form-label" for="image">Image (JPEG, PNG or WebP; maximum 5 MB)</label><input class="form-control" id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"><?php if (!empty($editing['image_path'])): ?><div class="mt-2"><img class="content-image" src="/uploads/<?= e($editing['image_path']) ?>" alt="Current image"></div><?php endif; ?></div>
      <?php if ($isChairman): ?><div class="mb-3"><label class="form-label admin-form-label" for="status">Save as</label><select class="form-select" id="status" name="status"><option value="draft" <?= ($editing['status'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option><option value="pending" <?= ($editing['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending review</option><option value="published" <?= ($editing['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option></select></div><?php else: ?><p class="small text-secondary">Submitted items are automatically queued for the Chairman’s approval.</p><?php endif; ?>
      <button class="btn btn-success" type="submit"><?= $editing ? 'Save changes' : 'Submit content' ?></button>
      <?php if ($editing): ?><a class="btn btn-outline-secondary" href="/admin/content.php?type=<?= e($type) ?>">Cancel</a><?php endif; ?>
    </form>
  </div></section>
</div>
<?php admin_footer(); ?>
