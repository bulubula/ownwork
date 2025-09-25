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
$stmt = $pdo->query('SELECT p.id, p.name, p.category, p.level, p.total_amount, p.manager_id, u.name AS manager_name,
    COALESCE(stats.allocated_sum, 0) AS allocated_sum, COALESCE(stats.allocation_count, 0) AS allocation_count
    FROM projects p
    JOIN users u ON p.manager_id = u.id
    LEFT JOIN (
        SELECT project_id, SUM(amount) AS allocated_sum, COUNT(*) AS allocation_count
        FROM allocations
        GROUP BY project_id
    ) AS stats ON stats.project_id = p.id
    ORDER BY p.name');
$projects = $stmt->fetchAll();
$completedCount = 0;
foreach ($projects as &$project) {
    $memberCount = (int)($project['allocation_count'] ?? 0);
    $allocatedSum = (float)($project['allocated_sum'] ?? 0.0);
    $totalAmount = (float)$project['total_amount'];
    $project['is_completed'] = $memberCount > 0 && abs($allocatedSum - $totalAmount) <= 0.01;
    if ($project['is_completed']) {
        $completedCount++;
    }
}
unset($project);
$pendingCount = count($projects) - $completedCount;
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
    <header class="page-header">
        <div class="title-group">
            <h1>项目管理</h1>
            <small>维护项目基础信息与负责人</small>
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
        <div class="card-header">
            <h2>项目列表</h2>
            <span class="muted">已完成 <?= $completedCount ?> 个 ｜ 待完成 <?= $pendingCount ?> 个</span>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th>名称</th>
                    <th>类别</th>
                    <th>层级</th>
                    <th>总金额</th>
                    <th>负责人</th>
                    <th>分配状态</th>
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
                            <?php if ($project['is_completed']): ?>
                                <span class="badge badge-success">已分配完成</span>
                            <?php else: ?>
                                <span class="badge badge-pending">待分配</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn-link" href="/admin/project_edit.php?id=<?= (int)$project['id'] ?>">编辑</a>
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
