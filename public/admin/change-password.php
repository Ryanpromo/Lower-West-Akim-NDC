<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
$user = current_user();
if (!$user) {
    redirect('/admin/login.php');
}
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    $query = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $query->execute(['id' => $user['id']]);
    $hash = (string) $query->fetchColumn();

    if (!password_verify($currentPassword, $hash)) {
        $error = 'The current password is incorrect.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'Choose a password with at least 12 characters.';
    } elseif (!hash_equals($newPassword, $confirmation)) {
        $error = 'The new passwords do not match.';
    } elseif (password_verify($newPassword, $hash)) {
        $error = 'Choose a new password that is different from the temporary password.';
    } else {
        $update = db()->prepare(
            'UPDATE users SET password_hash = :password_hash, must_change_password = 0
             WHERE id = :id'
        );
        $update->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $user['id'],
        ]);
        audit_log((int) $user['id'], 'password_changed');
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $success = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Change password | Lower West Akim NDC</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/admin.css" rel="stylesheet">
</head>
<body>
  <main class="login-wrap">
    <section class="login-card">
      <a class="admin-brand" href="/"><span class="admin-logo"><img src="/assets/ndc-logo.svg" alt=""></span><span>LOWER WEST AKIM<small>ACCOUNT SECURITY</small></span></a>
      <h1>Set your password</h1>
      <p>For your account security, change the temporary password before continuing.</p>
      <?php if ($success): ?>
        <div class="alert alert-success" role="status">Password updated successfully.</div>
        <a class="btn btn-success w-100" href="/admin/index.php">Continue to dashboard</a>
      <?php else: ?>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="/admin/change-password.php">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label admin-form-label" for="current_password">Temporary password</label><input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
          <div class="mb-3"><label class="form-label admin-form-label" for="new_password">New password (12+ characters)</label><input class="form-control" id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" required></div>
          <div class="mb-4"><label class="form-label admin-form-label" for="confirm_password">Confirm new password</label><input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required></div>
          <button class="btn btn-success w-100" type="submit">Update password</button>
        </form>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
