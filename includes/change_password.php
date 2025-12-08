<?php
declare(strict_types=1);

// 1. 引入与 index.php 相同的引导文件，确保环境一致
require_once __DIR__ . '/bootstrap.php';

// 2. 权限检查
require_login();
$user = current_user();
$pdo = get_pdo();

$message = '';
$error = '';

// 3. 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass1 = $_POST['new_password'] ?? '';
    $pass2 = $_POST['confirm_password'] ?? '';

    // 简单验证
    if (empty($pass1) || empty($pass2)) {
        $error = "密码不能为空。";
    } elseif ($pass1 !== $pass2) {
        $error = "两次输入的密码不一致。";
    } else {
        // 生成新哈希
        $newHash = password_hash($pass1, PASSWORD_DEFAULT);

        try {
            // 更新数据库 (根据 login_id)
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :ph WHERE login_id = :lid");
            $stmt->execute([
                'ph' => $newHash,
                'lid' => $user['login_id']
            ]);

            if ($stmt->rowCount() > 0) {
                $message = "密码修改成功！下次登录请使用新密码。";
            } else {
                $message = "密码未发生变化。";
            }
        } catch (PDOException $e) {
            $error = "系统错误：" . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>修改密码</title>
    <!-- 使用与首页相同的 CSS -->
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
    <style>
        /* 稍微补充一点内联样式以确保表单美观，防止 styles.css 覆盖不到 */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group input { width: 100%; padding: 8px; box-sizing: border-box; }
        .btn-back { margin-right: 10px; text-decoration: none; color: #666; }
    </style>
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>修改密码</h1>
            <small>为账号 <?= e($user['login_id']) ?> 设置新密码</small>
        </div>
        <!-- 保持统一的返回首页入口 -->
        <a class="btn-link" href="<?= e(url_for('index.php')) ?>">返回首页</a>
    </header>

    <div class="card">
        <div class="card-header">
            <h2>密码重置</h2>
            <span class="muted">请设置一个安全的密码</span>
        </div>
        
        <div class="config-form" style="padding: 20px;">
            <?php if ($error): ?>
                <div class="alert alert-danger" style="color: red; margin-bottom: 15px;"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success" style="color: green; margin-bottom: 15px;"><?= e($message) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="new_password">新密码</label>
                    <input type="password" name="new_password" id="new_password" required placeholder="输入新密码">
                </div>

                <div class="form-group">
                    <label for="confirm_password">确认新密码</label>
                    <input type="password" name="confirm_password" id="confirm_password" required placeholder="再次输入以确认">
                </div>

                <div style="margin-top: 20px;">
                    <button type="submit">确认修改</button>
                    <a href="<?= e(url_for('index.php')) ?>" class="btn-back" style="margin-left: 15px;">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
