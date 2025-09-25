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
        $category = trim($_POST['category'] ?? '');
        $level = trim($_POST['level'] ?? '');
        $totalAmount = (float)($_POST['total_amount'] ?? 0);
        $managerId = (int)($_POST['manager_id'] ?? 0);

        if ($name === '' || $category === '' || $level === '' || $totalAmount <= 0 || $managerId <= 0) {
            $errors[] = '请完整填写项目资料，金额需大于0。';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO projects (name, category, level, total_amount, manager_id) VALUES (:name, :category, :level, :total_amount, :manager_id)');
                $stmt->execute([
                    'name' => $name,
                    'category' => $category,
                    'level' => $level,
                    'total_amount' => $totalAmount,
                    'manager_id' => $managerId,
                ]);
                $success = '项目创建成功。';
            } catch (PDOException $exception) {
                if ((int)$exception->errorInfo[1] === 1062) {
                    $errors[] = '项目名称重复，请更换后重试。';
                } else {
                    $errors[] = '创建项目失败：' . $exception->getMessage();
                }
            }
        }
    }
}

$users = $pdo->query('SELECT id, name, role FROM users ORDER BY name')->fetchAll();
$projects = $pdo->query('SELECT p.*, u.name AS manager_name FROM projects p JOIN users u ON p.manager_id = u.id ORDER BY p.name')->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>项目管理</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <div class="flex">
        <h1>项目管理</h1>
        <div><a href="/dashboard.php">返回控制面板</a></div>
    </div>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert" style="background:#d1e7dd;color:#0f5132;"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>新增项目</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <label>项目名称</label>
            <input type="text" name="name" required>
            <label>项目类别</label>
            <input type="text" name="category" required>
            <label>项目层级</label>
            <input type="text" name="level" required>
            <label>项目总金额</label>
            <input type="number" step="0.01" name="total_amount" required>
            <label>项目负责人</label>
            <select name="manager_id" required>
                <option value="">请选择负责人</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?>（<?= e($u['role']) ?>）</option>
                <?php endforeach; ?>
            </select>
            <button type="submit">保存</button>
        </form>
    </div>

    <div class="card">
        <h2>项目列表</h2>
        <table class="table">
            <thead>
            <tr>
                <th>名称</th>
                <th>类别</th>
                <th>层级</th>
                <th>总金额</th>
                <th>负责人</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($projects as $project): ?>
                <tr>
                    <td><?= e($project['name']) ?></td>
                    <td><?= e($project['category']) ?></td>
                    <td><?= e($project['level']) ?></td>
                    <td><?= format_currency($project['total_amount']) ?></td>
                    <td><?= e($project['manager_name']) ?></td>
                    <td>
                        <a href="/admin/project_edit.php?id=<?= (int)$project['id'] ?>">编辑</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
