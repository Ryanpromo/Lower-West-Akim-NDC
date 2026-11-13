<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }

    $loaded = true;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $statement = db()->prepare(
        'SELECT id, full_name, username, email, role, official_position, is_active, must_change_password
         FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $statement->fetch() ?: null;
    if (!$user || !(bool) $user['is_active']) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /admin/login.php');
        exit;
    }
    if ((bool) $user['must_change_password']
        && basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) !== 'change-password.php') {
        header('Location: /admin/change-password.php');
        exit;
    }
    return $user;
}

function require_chairman(): array
{
    $user = require_login();
    if ($user['role'] !== 'chairman') {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }
    return $user;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        exit('The form expired. Return to the page and try again.');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function set_flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function audit_log(int $actorId, string $action, ?string $entityType = null, ?int $entityId = null): void
{
    $statement = db()->prepare(
        'INSERT INTO audit_log (actor_id, action, entity_type, entity_id)
         VALUES (:actor_id, :action, :entity_type, :entity_id)'
    );
    $statement->execute([
        'actor_id' => $actorId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
    ]);
}
