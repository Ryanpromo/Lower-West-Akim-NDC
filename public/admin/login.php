<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

$databaseUnavailable = false;
try {
  if (current_user()) {
    redirect('/admin/index.php');
  }
} catch (RuntimeException $exception) {
  error_log('Staff sign-in database error: ' . $exception->getMessage());
  http_response_code(503);
  $databaseUnavailable = true;
}

$error = $databaseUnavailable
  ? 'Sign-in is not configured yet. Please contact the site administrator.'
  : '';
$roles = [
  'chairman' => ['title' => 'Chairman / Admin login', 'note' => 'Leadership, approval and account management access.', 'account_roles' => ['chairman']],
  'secretary' => ['title' => 'Secretary login', 'note' => 'Content, records and reporting access for the secretary team.', 'account_roles' => ['secretary', 'deputy_secretary']],
  'treasurer' => ['title' => 'Treasurer login', 'note' => 'Finance, stewardship and reporting access for the treasurer team.', 'account_roles' => ['treasurer']],
  'executive' => ['title' => 'Executive dashboard login', 'note' => 'Personal reporting, updates and activity submissions.', 'account_roles' => ['executive']],
];
$selectedRole = (string) ($_POST['login_role'] ?? $_GET['role'] ?? 'chairman');
if (!isset($roles[$selectedRole])) {
  $selectedRole = 'chairman';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
  try {
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $statement = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $statement->execute(['username' => $username]);
    $account = $statement->fetch();
    $hashToCheck = $account ? $account['password_hash'] : password_hash('not-a-real-password', PASSWORD_DEFAULT);
    $validPassword = password_verify($password, $hashToCheck);
    $isLocked = $account && $account['locked_until'] && strtotime($account['locked_until']) > time();

    if ($account && $validPassword && (bool) $account['is_active'] && !$isLocked
      && !in_array($account['role'], $roles[$selectedRole]['account_roles'], true)) {
      $error = 'Choose the role assigned to your account, then try again.';
    } elseif ($account && $validPassword && (bool) $account['is_active'] && !$isLocked) {
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int) $account['id'];
      $update = db()->prepare(
        'UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW()
         WHERE id = :id'
      );
      $update->execute(['id' => $account['id']]);
      audit_log((int) $account['id'], 'login');
      redirect($account['role'] === 'executive' ? '/admin/reports.php?status=all' : '/admin/index.php');
    } elseif ($account && (bool) $account['is_active'] && !$validPassword) {
      $attempts = min(255, (int) $account['failed_login_attempts'] + 1);
      $lock = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
      $update = db()->prepare(
        'UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked_until
         WHERE id = :id'
      );
      $update->execute(['attempts' => $attempts, 'locked_until' => $lock, 'id' => $account['id']]);
    }
    if ($error === '') {
      $error = 'Sign-in failed. Check your username and password, or contact the Chairman.';
    }
  } catch (RuntimeException $exception) {
    error_log('Staff sign-in database error: ' . $exception->getMessage());
    http_response_code(503);
    $error = 'Sign-in is temporarily unavailable. Please try again later or contact the site administrator.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Sign in | Lower West Akim NDC</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="/admin/admin.css" rel="stylesheet">
</head>
<body>
  <main class="login-wrap">
    <section class="login-card">
      <a class="admin-brand" href="/"><span class="admin-logo"><img src="/assets/ndc-logo.svg" alt=""></span><span>LOWER WEST AKIM<small>CONSTITUENCY DASHBOARD</small></span></a>
      <h1>Staff sign in</h1>
      <div class="row g-2 mb-4">
        <div class="col-12 col-md-3">
          <button class="role-option <?= $selectedRole === 'chairman' ? 'selected' : '' ?>" type="button" data-role="chairman" data-title="Chairman / Admin login" data-note="Leadership, approval and account management access.">
            <small class="text-uppercase fw-bold text-success">Admin</small>
            <div class="small text-secondary mb-0">Chairman</div>
          </button>
        </div>
        <div class="col-12 col-md-3">
          <button class="role-option <?= $selectedRole === 'secretary' ? 'selected' : '' ?>" type="button" data-role="secretary" data-title="Secretary login" data-note="Content, records and reporting access for the secretary team.">
            <small class="text-uppercase fw-bold text-primary">Secretary</small>
            <div class="small text-secondary mb-0">Secretary</div>
          </button>
        </div>
        <div class="col-12 col-md-3">
          <button class="role-option <?= $selectedRole === 'treasurer' ? 'selected' : '' ?>" type="button" data-role="treasurer" data-title="Treasurer login" data-note="Finance, stewardship and reporting access for the treasurer team.">
            <small class="text-uppercase fw-bold text-info">Treasurer</small>
            <div class="small text-secondary mb-0">Treasurer</div>
          </button>
        </div>
        <div class="col-12 col-md-3">
          <button class="role-option <?= $selectedRole === 'executive' ? 'selected' : '' ?>" type="button" data-role="executive" data-title="Executive dashboard login" data-note="Personal reporting, updates and activity submissions.">
            <small class="text-uppercase fw-bold text-warning">Executive</small>
            <div class="small text-secondary mb-0">Dashboard</div>
          </button>
        </div>
      </div>
      <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/admin/login.php">
        <?= csrf_field() ?>
        <input type="hidden" name="login_role" id="login_role" value="<?= e($selectedRole) ?>">
        <div class="mb-2"><div class="small text-uppercase fw-bold text-secondary" id="login-role-label"><?= e($roles[$selectedRole]['title']) ?></div></div>
        <div class="mb-3"><label class="form-label admin-form-label" for="username">Username</label><input class="form-control" id="username" name="username" autocomplete="username" required autofocus maxlength="60" placeholder="Enter your username"></div>
        <div class="mb-4"><label class="form-label admin-form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required placeholder="Enter your password"></div>
        <button class="btn btn-success w-100" type="submit">Sign in</button>
      </form>
      <p class="mt-3 mb-0 small text-secondary" id="login-role-note"><?= e($roles[$selectedRole]['note']) ?></p>
      <p class="mt-4 mb-0 small"><a href="/">← Return to the constituency website</a></p>
    </section>
  </main>
  <script>
    const roleInputs = document.querySelectorAll('.role-option');
    const loginRoleField = document.getElementById('login_role');
    const loginRoleLabel = document.getElementById('login-role-label');
    const loginRoleNote = document.getElementById('login-role-note');

    const setRole = (role) => {
      const button = Array.from(roleInputs).find((item) => (item.dataset.role || 'chairman') === role) || roleInputs[0];
      roleInputs.forEach((item) => item.classList.toggle('selected', item === button));
      const title = button.dataset.title || 'Staff login';
      const note = button.dataset.note || 'Use your assigned staff credentials.';
      loginRoleField.value = role;
      loginRoleLabel.textContent = title;
      loginRoleNote.textContent = note;
    };

    roleInputs.forEach((button) => {
      button.addEventListener('click', () => setRole(button.dataset.role || 'chairman'));
    });

    setRole(<?= json_encode($selectedRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
  </script>
</body>
</html>
