<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

start_session_once();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
    header('Location: ' . url_for('index.php'));
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
        header('Location: ' . url_for('dashboard.php'));
        exit;
    }
}

$user = current_user();
if ($user) {
    header('Location: ' . url_for('dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>奖励分配系统登录</title>
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="auth-layout">
    <div class="card">
        <h1>奖励分配系统</h1>
        <p class="muted">请使用个人用户编号与出生日期默认密码登录系统，首次登录后请及时修改密码。</p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <label for="login_id">用户编号</label>
            <input type="text" id="login_id" name="login_id" required>

            <label for="password">密码</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">登录</button>
        </form>
    </div>
</div>
</body>
</html>
