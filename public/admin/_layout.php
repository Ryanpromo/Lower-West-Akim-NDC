<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

function admin_header(string $title, array $user): void
{
    $flash = take_flash();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <meta name="robots" content="noindex, nofollow">
      <title><?= e($title) ?> | Lower West Akim NDC</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
      <link href="/admin/admin.css" rel="stylesheet">
    </head>
    <body class="admin-body">
      <header class="admin-topbar">
        <div class="container admin-topbar-inner">
          <a class="admin-brand" href="/admin/index.php"><span class="admin-logo"><img src="/assets/ndc-logo.svg" alt=""></span><span>LOWER WEST AKIM<small>CONSTITUENCY DASHBOARD</small></span></a>
          <div class="admin-user"><span><?= e($user['full_name']) ?> <small><?= e(ucwords(str_replace('_', ' ', $user['role']))) ?></small></span>
            <form method="post" action="/admin/logout.php"><?= csrf_field() ?><button class="btn btn-sm btn-outline-light" type="submit">Sign out</button></form>
          </div>
        </div>
      </header>
      <main class="container admin-main">
        <nav class="admin-nav" aria-label="Dashboard">
          <a href="/admin/index.php">Overview</a>
          <a href="/admin/reports.php">Activity reports</a>
          <a href="/admin/change-password.php">Change password</a>
          <?php if ($user['role'] !== 'executive'): ?>
            <a href="/admin/content.php?type=news">News &amp; events</a>
            <a href="/admin/content.php?type=events">Events</a>
            <a href="/admin/content.php?type=projects">Projects</a>
            <a href="/admin/content.php?type=executives">Executives</a>
            <a href="/admin/content.php?type=gallery">Gallery</a>
          <?php endif; ?>
          <?php if ($user['role'] === 'chairman'): ?><a href="/admin/users.php">Users &amp; roles</a><?php endif; ?>
        </nav>
        <?php if ($flash): ?>
          <div class="alert alert-<?= e($flash['kind']) ?> alert-dismissible fade show" role="status">
            <?= e($flash['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
        <?php endif; ?>
        <div class="admin-page-heading"><div><p class="admin-kicker">LOWER WEST AKIM CONSTITUENCY</p><h1><?= e($title) ?></h1></div><a class="btn btn-outline-success" href="/">View website ↗</a></div>
    <?php
}

function admin_footer(): void
{
    ?>
      </main>
      <footer class="admin-footer">Official constituency content management · Use HTTPS and keep account details private.</footer>
      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
