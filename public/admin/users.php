<?php
declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
$user = require_chairman();
$temporaryPassword = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reset_password') {
        $targetId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $target = db()->prepare('SELECT id, role FROM users WHERE id = :id');
        $target->execute(['id' => $targetId ?: 0]);
        $targetUser = $target->fetch();
        if (!$targetUser || $targetUser['role'] === 'chairman') {
            set_flash('That account cannot be reset here.', 'danger');
            redirect('/admin/users.php');
        }
        $temporaryPassword = bin2hex(random_bytes(12));
        $reset = db()->prepare(
            'UPDATE users
             SET password_hash = :password_hash, must_change_password = 1,
                 failed_login_attempts = 0, locked_until = NULL
             WHERE id = :id'
        );
        $reset->execute([
            'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'id' => $targetId,
        ]);
        audit_log((int) $user['id'], 'user_password_reset', 'user', (int) $targetId);
        $_SESSION['new_user_password'] = $temporaryPassword;
        redirect('/admin/users.php?reset=1');
    }

    if ($action === 'create') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $role = (string) ($_POST['role'] ?? '');
        $position = trim((string) ($_POST['official_position'] ?? ''));
        if ($name === '' || strlen($name) > 640 || !preg_match('/^[a-z0-9._-]{3,60}$/', $username)
            || strlen($email) > 254
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !in_array($role, ['secretary', 'deputy_secretary', 'treasurer', 'executive'], true)
            || ($role === 'executive' && ($position === '' || strlen($position) > 480))) {
            set_flash('Enter a valid name, username, email and permitted editor role.', 'danger');
            redirect('/admin/users.php');
        }

        $temporaryPassword = bin2hex(random_bytes(12));
        try {
            $insert = db()->prepare(
                'INSERT INTO users (full_name, username, email, password_hash, role, official_position, must_change_password)
                 VALUES (:full_name, :username, :email, :password_hash, :role, :official_position, 1)'
            );
            $insert->execute([
                'full_name' => $name,
                'username' => $username,
                'email' => $email,
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'role' => $role,
                'official_position' => $role === 'executive' ? $position : null,
            ]);
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }
            set_flash('Could not create the account. Check that the username and email are not already in use.', 'danger');
            redirect('/admin/users.php');
        }
        $newId = (int) db()->lastInsertId();
        audit_log((int) $user['id'], 'user_created', 'user', $newId);
        $_SESSION['new_user_password'] = $temporaryPassword;
        redirect('/admin/users.php?created=1');
    }

    if ($action === 'update') {
        $targetId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string) ($_POST['role'] ?? '');
        $isActive = (string) ($_POST['is_active'] ?? '');
        $position = trim((string) ($_POST['official_position'] ?? ''));
        if (!$targetId || !in_array($role, ['secretary', 'deputy_secretary', 'treasurer', 'executive'], true)
            || ($role === 'executive' && ($position === '' || strlen($position) > 480))
            || !in_array($isActive, ['0', '1'], true)) {
            set_flash('Invalid account update.', 'danger');
            redirect('/admin/users.php');
        }
        $target = db()->prepare('SELECT id, role FROM users WHERE id = :id');
        $target->execute(['id' => $targetId]);
        $targetUser = $target->fetch();
        if (!$targetUser || $targetUser['role'] === 'chairman' || (int) $targetId === (int) $user['id']) {
            set_flash('The Chairman account cannot be changed here.', 'danger');
            redirect('/admin/users.php');
        }
        $update = db()->prepare(
            'UPDATE users SET role = :role, official_position = :official_position, is_active = :is_active WHERE id = :id'
        );
        $update->execute([
            'role' => $role,
            'official_position' => $role === 'executive' ? $position : null,
            'is_active' => (int) $isActive,
            'id' => $targetId,
        ]);
        audit_log((int) $user['id'], 'user_role_or_status_changed', 'user', (int) $targetId);
        set_flash('Account permissions updated.');
        redirect('/admin/users.php');
    }
    http_response_code(400);
    exit('Unknown action.');
}

$users = db()->query(
    'SELECT id, full_name, username, email, role, official_position, is_active, last_login_at
     FROM users ORDER BY (role = "chairman") DESC, full_name'
)->fetchAll();
$temporaryPassword = (string) ($_SESSION['new_user_password'] ?? '');
unset($_SESSION['new_user_password']);
admin_header('Users & roles', $user);
?>
<div class="notice mb-4">Only the Chairman can create accounts, assign executive positions, change roles, reset passwords or deactivate users. Executive accounts can submit personal activity and project reports. Secretary and Deputy Secretary accounts can also manage website content.</div>
<?php if ($temporaryPassword !== ''): ?>
  <div class="alert alert-success"><strong>Temporary password generated.</strong> Share it with the user through a secure channel. It is shown only once and must be changed at next sign-in.<div class="mt-2"><code class="fs-5"><?= e($temporaryPassword) ?></code></div></div>
<?php endif; ?>
<div class="row g-4">
  <section class="col-12 col-lg-7"><div class="admin-card">
    <h2>Constituency accounts</h2>
    <div class="table-responsive"><table class="table admin-table align-middle"><thead><tr><th>Name / username</th><th>Role &amp; position</th><th>Status</th><th>Permissions</th></tr></thead><tbody>
      <?php foreach ($users as $account): ?>
        <tr><td><strong><?= e($account['full_name']) ?></strong><br><span class="text-secondary small">@<?= e($account['username']) ?></span></td>
          <td><span class="role-pill"><?= e(ucwords(str_replace('_', ' ', $account['role']))) ?></span><?php if ($account['official_position']): ?><br><span class="small text-secondary"><?= e($account['official_position']) ?></span><?php endif; ?></td>
          <td><?= $account['is_active'] ? 'Active' : 'Disabled' ?></td>
          <td>
            <?php if ($account['role'] === 'chairman' || (int) $account['id'] === (int) $user['id']): ?><span class="text-secondary small">Protected</span>
            <?php else: ?>
              <form class="d-flex flex-wrap gap-2" method="post" action="/admin/users.php">
                <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                <select class="form-select form-select-sm" name="role" aria-label="Role for <?= e($account['full_name']) ?>">
                  <option value="secretary" <?= $account['role'] === 'secretary' ? 'selected' : '' ?>>Secretary</option>
                  <option value="deputy_secretary" <?= $account['role'] === 'deputy_secretary' ? 'selected' : '' ?>>Deputy Secretary</option>
                  <option value="treasurer" <?= $account['role'] === 'treasurer' ? 'selected' : '' ?>>Treasurer</option>
                  <option value="executive" <?= $account['role'] === 'executive' ? 'selected' : '' ?>>Elected Executive</option>
                </select>
                <input class="form-control form-control-sm" name="official_position" maxlength="120" placeholder="Official position" value="<?= e($account['official_position']) ?>" <?= $account['role'] === 'executive' ? 'required' : '' ?> aria-label="Official position for <?= e($account['full_name']) ?>">
                <select class="form-select form-select-sm" name="is_active" aria-label="Status for <?= e($account['full_name']) ?>">
                  <option value="1" <?= $account['is_active'] ? 'selected' : '' ?>>Active</option>
                  <option value="0" <?= !$account['is_active'] ? 'selected' : '' ?>>Disabled</option>
                </select>
                <button class="btn btn-sm btn-outline-success" type="submit">Save</button>
              </form>
              <form class="mt-2" method="post" action="/admin/users.php" onsubmit="return confirm('Reset this account password? The user will need a new temporary password.');">
                <?= csrf_field() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Reset password</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </div></section>
  <section class="col-12 col-lg-5"><div class="admin-card">
    <h2>Create account</h2><p class="small text-secondary">Executives receive a personal reporting dashboard. A one-time password must be changed at first sign-in.</p>
    <form method="post" action="/admin/users.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="mb-3"><label class="form-label admin-form-label" for="full_name">Full name</label><input class="form-control" id="full_name" name="full_name" maxlength="160" required></div>
      <div class="mb-3"><label class="form-label admin-form-label" for="username">Username</label><input class="form-control" id="username" name="username" pattern="[A-Za-z0-9._-]{3,60}" maxlength="60" required></div>
      <div class="mb-3"><label class="form-label admin-form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" maxlength="254" required></div>
      <div class="mb-3"><label class="form-label admin-form-label" for="role">Role</label><select class="form-select" id="role" name="role" required><option value="executive">Elected Executive</option><option value="secretary">Secretary</option><option value="deputy_secretary">Deputy Secretary</option><option value="treasurer">Treasurer</option></select></div>
      <div class="mb-3" id="position-field"><label class="form-label admin-form-label" for="official_position">Official position</label><input class="form-control" id="official_position" name="official_position" maxlength="120" required placeholder="e.g. Constituency Organiser"></div>
      <button class="btn btn-success" type="submit">Create account</button>
    </form>
  </div></section>
</div>
<script>
  document.querySelectorAll('form').forEach(form => {
    const accountRole = form.querySelector('select[name="role"]');
    const officialPosition = form.querySelector('[name="official_position"]');
    if (!accountRole || !officialPosition) return;
    const syncPosition = () => {
      const isExecutive = accountRole.value === 'executive';
      officialPosition.required = isExecutive;
      officialPosition.disabled = !isExecutive;
      if (!isExecutive) officialPosition.value = '';
    };
    accountRole.addEventListener('change', syncPosition);
    syncPosition();
  });
</script>
<?php admin_footer(); ?>
