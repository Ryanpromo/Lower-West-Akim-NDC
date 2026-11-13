<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = require_login();
if ($user['role'] === 'executive') {
    redirect('/admin/reports.php?status=all');
}
$counts = db()->query(
    "SELECT
      SUM(status = 'published') AS published,
      SUM(status = 'pending') AS pending,
      SUM(status = 'draft') AS drafts
     FROM content_items"
)->fetch() ?: ['published' => 0, 'pending' => 0, 'drafts' => 0];
$pending = [];
if ($user['role'] === 'chairman') {
    $pending = db()->query(
        'SELECT c.id, c.content_type, c.title, c.created_at, u.full_name
         FROM content_items c JOIN users u ON u.id = c.author_id
         WHERE c.status = "pending" ORDER BY c.created_at ASC LIMIT 20'
    )->fetchAll();
}
$recentQuery = db()->prepare(
    'SELECT id, content_type, title, status, updated_at
     FROM content_items WHERE author_id = :author_id
     ORDER BY updated_at DESC LIMIT 8'
);
$recentQuery->execute(['author_id' => $user['id']]);
$recent = $recentQuery->fetchAll();
admin_header('Dashboard overview', $user);
?>
<div class="row g-3 mb-4">
  <?php foreach (['published' => 'Published content', 'pending' => 'Awaiting approval', 'drafts' => 'Drafts'] as $key => $label): ?>
    <div class="col-12 col-md-4"><div class="admin-card"><span class="text-secondary small"><?= e($label) ?></span><strong class="stat-number"><?= (int) ($counts[$key] ?? 0) ?></strong></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-4">
  <section class="col-12 <?= $user['role'] === 'chairman' ? 'col-lg-7' : '' ?>">
    <div class="admin-card">
      <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="mb-0">Your recent content</h2><a class="btn btn-sm btn-success" href="/admin/content.php?type=news">Create content</a></div>
      <?php if (!$recent): ?><p class="text-secondary mb-0">You have not created any content yet. Start with a news update.</p>
      <?php else: ?>
        <div class="table-responsive"><table class="table admin-table align-middle mb-0"><thead><tr><th>Title</th><th>Type</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($recent as $item): ?><tr><td><a href="/admin/content.php?type=<?= e($item['content_type']) ?>&amp;edit=<?= (int) $item['id'] ?>"><?= e($item['title']) ?></a></td><td><?= e(ucfirst($item['content_type'])) ?></td><td><span class="badge text-bg-<?= $item['status'] === 'published' ? 'success' : ($item['status'] === 'pending' ? 'warning' : 'secondary') ?>"><?= e(ucfirst($item['status'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
    </div>
  </section>
  <?php if ($user['role'] === 'chairman'): ?>
    <section class="col-12 col-lg-5"><div class="admin-card"><h2>Approval queue</h2>
      <?php if (!$pending): ?><p class="text-secondary mb-0">No content is waiting for review.</p>
      <?php else: ?>
        <div class="d-grid gap-3"><?php foreach ($pending as $item): ?>
          <div class="border-bottom pb-3">
            <div class="d-flex justify-content-between gap-2"><strong><?= e($item['title']) ?></strong><span class="role-pill"><?= e(ucfirst($item['content_type'])) ?></span></div>
            <p class="small text-secondary mb-2">By <?= e($item['full_name']) ?> · <?= e(date('M j, Y', strtotime($item['created_at']))) ?></p>
            <div class="approval-actions">
              <a class="btn btn-sm btn-outline-success" href="/admin/content.php?type=<?= e($item['content_type']) ?>&amp;edit=<?= (int) $item['id'] ?>">Review</a>
              <form method="post" action="/admin/content.php?type=<?= e($item['content_type']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="decision" value="published"><button class="btn btn-sm btn-success" type="submit">Approve</button></form>
              <form method="post" action="/admin/content.php?type=<?= e($item['content_type']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="decision" value="rejected"><button class="btn btn-sm btn-outline-danger" type="submit">Reject</button></form>
            </div>
          </div>
        <?php endforeach; ?></div>
      <?php endif; ?>
    </div></section>
  <?php endif; ?>
</div>
<div class="notice mt-4">Secretary and Deputy Secretary submissions are sent to the Chairman for approval before appearing publicly. Do not upload sensitive personal information.</div>
<div class="admin-card mt-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
  <div><h2 class="mb-1">Executive activity reporting</h2><p class="text-secondary mb-0">Submit activity and project reports or review your reporting dashboard.</p></div>
  <a class="btn btn-success" href="/admin/reports.php">Open reports dashboard</a>
</div>
<?php admin_footer(); ?>
