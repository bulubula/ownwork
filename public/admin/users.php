<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();
require_role(['管理员']);

$pdo = get_pdo();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $loginId = trim($_POST['login_id'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');

        if ($name === '' || $loginId === '' || $role === '' || $birthdate === '') {
            $errors[] = '请完整填写用户信息。';
        } elseif (!in_array($role, USER_ROLES, true)) {
            $errors[] = '用户角色无效。';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO users (name, login_id, role, birthdate, password_hash) VALUES (:name, :login_id, :role, :birthdate, :password_hash)');
                $stmt->execute([
                    'name' => $name,
                    'login_id' => $loginId,
                    'role' => $role,
                    'birthdate' => $birthdate,
                    'password_hash' => hash_initial_password($birthdate),
                ]);
                $pdo->commit();
                $success = '成功创建用户。默认密码为出生日期（8位数字）。';
            } catch (PDOException $exception) {
                $pdo->rollBack();
                if ((int)$exception->errorInfo[1] === 1062) {
                    $errors[] = '用户编号重复，请更换后重试。';
                } else {
                    $errors[] = '创建用户失败：' . $exception->getMessage();
                }
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT birthdate FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if ($user) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $stmt->execute([
                'password_hash' => hash_initial_password($user['birthdate']),
                'id' => $userId,
            ]);
            $success = '密码已重置为出生日期（8位数字）。';
        }
    }
}

$users = $pdo->query('SELECT id, name, login_id, role, birthdate, created_at FROM users ORDER BY id ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>用户管理</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>用户管理</h1>
            <small>维护登录账号与角色权限</small>
        </div>
        <a class="btn-link" href="/dashboard.php">返回控制面板</a>
    </header>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>新增用户</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <label>姓名</label>
            <input type="text" name="name" required>
            <label>用户编号</label>
            <input type="text" name="login_id" required>
            <label>角色</label>
            <select name="role" required>
                <?php foreach (USER_ROLES as $role): ?>
                    <option value="<?= e($role) ?>"><?= e($role) ?></option>
                <?php endforeach; ?>
            </select>
            <label>出生日期（格式：YYYY-MM-DD）</label>
            <input type="date" name="birthdate" required>
            <button type="submit">保存</button>
        </form>
    </div>

    <div class="card">
        <h2>用户列表</h2>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>姓名</th>
                    <th>用户编号</th>
                    <th>角色</th>
                    <th>出生日期</th>
                    <th>创建时间</th>
                    <th>操作</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <tr>
                        <td><?= (int)$row['id'] ?></td>
                        <td><?= e($row['name']) ?></td>
                        <td><?= e($row['login_id']) ?></td>
                        <td><span class="badge"><?= e($row['role']) ?></span></td>
                        <td><?= e($row['birthdate']) ?></td>
                        <td><?= e($row['created_at']) ?></td>
                        <td>
                            <form method="post" class="inline" onsubmit="return confirm('确认将密码重置为出生日期（8位数字）吗？');">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                                <button type="submit" class="secondary">重置密码</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
