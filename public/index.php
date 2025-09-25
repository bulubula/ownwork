<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

start_session_once();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    header('Location: /index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginId = trim($_POST['login_id'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($loginId === '' || $password === '') {
        $error = '请输入用户编号和密码';
    } elseif (!login($loginId, $password)) {
        $error = '登录失败，请检查用户编号或密码';
    } else {
        header('Location: /dashboard.php');
        exit;
    }
}

$user = current_user();
if ($user) {
    header('Location: /dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>奖励分配系统登录</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <h1>奖励分配系统</h1>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" class="card">
        <label for="login_id">用户编号</label>
        <input type="text" id="login_id" name="login_id" required>

        <label for="password">密码</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">登录</button>
    </form>
</div>
</body>
</html>
