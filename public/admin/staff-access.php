<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
$links = [
    'chairman' => [
        'title' => 'Chairman / Admin',
        'description' => 'Leadership, approvals, account management and policy oversight.',
        'url' => '/admin/login.php?role=chairman',
    ],
    'secretary' => [
        'title' => 'Secretary',
        'description' => 'Content management, records, reporting and coordination access.',
        'url' => '/admin/login.php?role=secretary',
    ],
    'treasurer' => [
        'title' => 'Treasurer',
        'description' => 'Financial stewardship, reporting access and official records.',
        'url' => '/admin/login.php?role=treasurer',
    ],
    'executive' => [
        'title' => 'Executive',
        'description' => 'Personal dashboard, report submissions and review status tracking.',
        'url' => '/admin/login.php?role=executive',
    ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Staff access | Lower West Akim NDC</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/admin.css" rel="stylesheet">
</head>
<body class="admin-body">
  <header class="admin-topbar">
    <div class="container admin-topbar-inner">
      <a class="admin-brand" href="/"><span class="admin-logo"><img src="/assets/ndc-logo.svg" alt=""></span><span>LOWER WEST AKIM<small>CONSTITUENCY PORTAL</small></span></a>
      <div class="admin-user"><span>Staff access</span></div>
    </div>
  </header>
  <main class="container admin-main">
    <div class="admin-page-heading">
      <div>
        <p class="admin-kicker">LOWER WEST AKIM CONSTITUENCY</p>
        <h1>Staff sign in</h1>
      </div>
      <a class="btn btn-outline-success" href="/">View website ↗</a>
    </div>

    <div class="notice mb-4">Choose your role below to sign in to the correct official dashboard. Chairman access is reserved for the constituency leadership.</div>

    <div class="row g-3">
      <?php foreach ($links as $entry): ?>
        <div class="col-12 col-md-6 col-xl-3">
          <div class="admin-card h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <span class="role-pill"><?= e($entry['title']) ?></span>
            </div>
            <p class="small text-secondary mb-3"><?= e($entry['description']) ?></p>
            <div class="d-grid gap-2">
              <a class="btn btn-success btn-sm" href="<?= e($entry['url']) ?>">Sign in</a>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="admin-card mt-4">
      <h2>Need access?</h2>
      <p class="mb-3 text-secondary">New staff accounts are created and managed by the Chairman from the secure users dashboard.</p>
      <a class="btn btn-success" href="/admin/login.php?role=chairman">Continue to Chairman sign in</a>
    </div>
  </main>
  <footer class="admin-footer">Official constituency staff access · Use the correct role dashboard for your account.</footer>
</body>
</html>
