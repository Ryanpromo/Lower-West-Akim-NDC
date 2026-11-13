<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../app/db.php';

$username = strtolower(trim($argv[1] ?? ''));
$name = trim($argv[2] ?? '');
$email = strtolower(trim($argv[3] ?? ''));
$password = getenv('INITIAL_CHAIRMAN_PASSWORD') ?: '';

if ($username === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($password) < 12) {
    fwrite(STDERR, "Usage: set INITIAL_CHAIRMAN_PASSWORD to a unique 12+ character password, then run:\n");
    fwrite(STDERR, "  php bin/create-chairman.php username \"Full Name\" email@example.com\n");
    exit(2);
}

$connection = db();
$chairmanCount = (int) $connection->query(
    "SELECT COUNT(*) FROM users WHERE role = 'chairman'"
)->fetchColumn();
if ($chairmanCount !== 0) {
    fwrite(STDERR, "A chairman account already exists. Use the dashboard to manage editor accounts.\n");
    exit(1);
}

try {
    $statement = $connection->prepare(
        "INSERT INTO users (full_name, username, email, password_hash, role, official_position, must_change_password)
         VALUES (:full_name, :username, :email, :password_hash, 'chairman', 'Constituency Chairman', 0)"
    );
    $statement->execute([
        'full_name' => $name,
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
} catch (PDOException $exception) {
    if ($exception->getCode() !== '23000') {
        throw $exception;
    }
    fwrite(STDERR, "Chairman account was not created. Check that the username and email are unique.\n");
    exit(1);
} finally {
    putenv('INITIAL_CHAIRMAN_PASSWORD');
}

fwrite(STDOUT, "Chairman account created. Sign in at /admin/login.php over HTTPS.\n");
