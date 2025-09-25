<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const USER_ROLES = ['普通用户', '中层', '高层', '管理员'];

function start_session_once(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function find_user_by_login_id(string $loginId): ?array
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE login_id = :login_id LIMIT 1');
    $stmt->execute(['login_id' => $loginId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function current_user(): ?array
{
    start_session_once();
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function login(string $loginId, string $password): bool
{
    $user = find_user_by_login_id($loginId);
    if (!$user) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    start_session_once();
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}

function logout(): void
{
    start_session_once();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: /index.php');
        exit;
    }
}

function require_role(array $roles): void
{
    $user = current_user();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo '无权限访问';
        exit;
    }
}

function hash_initial_password(string $birthdate): string
{
    $normalized = preg_replace('/[^0-9]/', '', $birthdate);
    if (strlen($normalized) === 8) {
        return password_hash($normalized, PASSWORD_DEFAULT);
    }
    return password_hash($birthdate, PASSWORD_DEFAULT);
}
