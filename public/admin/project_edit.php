<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();
require_role(['管理员']);

$pdo = get_pdo();
$projectId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
$stmt->execute(['id' => $projectId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    echo '项目不存在';
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $level = trim($_POST['level'] ?? '');
    $totalAmount = (float)($_POST['total_amount'] ?? 0);
    $managerId = (int)($_POST['manager_id'] ?? 0);

    if ($name === '' || $category === '' || $level === '' || $totalAmount <= 0 || $managerId <= 0) {
        $errors[] = '请完整填写项目信息。';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE projects SET name = :name, category = :category, level = :level, total_amount = :total_amount, manager_id = :manager_id WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'category' => $category,
                'level' => $level,
                'total_amount' => $totalAmount,
                'manager_id' => $managerId,
                'id' => $projectId,
            ]);
            $success = '项目已更新。';
            $stmt = $pdo->prepare('SELECT * FROM projects WHERE id = :id');
            $stmt->execute(['id' => $projectId]);
            $project = $stmt->fetch();
        } catch (PDOException $exception) {
            if ((int)$exception->errorInfo[1] === 1062) {
                $errors[] = '项目名称重复，请更换后重试。';
            } else {
                $errors[] = '更新失败：' . $exception->getMessage();
            }
        }
    }
}

$users = $pdo->query('SELECT id, name, role FROM users ORDER BY name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>编辑项目</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>编辑项目</h1>
            <small>当前项目：<?= e($project['name']) ?></small>
        </div>
        <a class="btn-link" href="/admin/projects.php">返回项目管理</a>
    </header>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <form method="post">
            <label>项目名称</label>
            <input type="text" name="name" value="<?= e($project['name']) ?>" required>
            <label>项目类别</label>
            <input type="text" name="category" value="<?= e($project['category']) ?>" required>
            <label>项目层级</label>
            <input type="text" name="level" value="<?= e($project['level']) ?>" required>
            <label>项目总金额</label>
            <input type="number" step="0.01" name="total_amount" value="<?= e($project['total_amount']) ?>" required>
            <label>项目负责人</label>
            <select name="manager_id" required>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $project['manager_id'] == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?>（<?= e($u['role']) ?>）</option>
                <?php endforeach; ?>
            </select>
            <button type="submit">保存</button>
        </form>
    </div>
</div>
</body>
</html>
