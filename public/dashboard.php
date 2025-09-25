<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
$user = current_user();
$pdo = get_pdo();

// Fetch projects related to the user
$managedProjects = [];
if ($user['role'] !== '管理员') {
    $stmt = $pdo->prepare('SELECT * FROM projects WHERE manager_id = :manager_id ORDER BY name');
    $stmt->execute(['manager_id' => $user['id']]);
    $managedProjects = $stmt->fetchAll();
}

$stmt = $pdo->prepare('SELECT p.*, a.amount FROM allocations a JOIN projects p ON a.project_id = p.id WHERE a.user_id = :user_id ORDER BY p.name');
$stmt->execute(['user_id' => $user['id']]);
$participatingProjects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>控制面板</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <div class="flex">
        <h1>欢迎，<?= e($user['name']) ?>（<?= e($user['role']) ?>）</h1>
        <div>
            <a href="/index.php?action=logout">退出登录</a>
        </div>
    </div>

    <?php if ($user['role'] === '管理员'): ?>
        <div class="card">
            <h2>管理员菜单</h2>
            <ul>
                <li><a href="/admin/users.php">用户管理</a></li>
                <li><a href="/admin/projects.php">项目管理</a></li>
                <li><a href="/admin/allocations.php">分配情况查看与导出</a></li>
            </ul>
        </div>
    <?php else: ?>
        <div class="card">
            <h2>我负责的项目</h2>
            <?php if ($managedProjects): ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>项目名称</th>
                        <th>项目类别</th>
                        <th>项目层级</th>
                        <th>总金额</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($managedProjects as $project): ?>
                        <tr>
                            <td><?= e($project['name']) ?></td>
                            <td><?= e($project['category']) ?></td>
                            <td><?= e($project['level']) ?></td>
                            <td><?= format_currency($project['total_amount']) ?></td>
                            <td><a href="/projects/manage.php?id=<?= (int)$project['id'] ?>">编辑分配</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>当前没有您负责的项目。</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>我参与的项目</h2>
            <?php if ($participatingProjects): ?>
                <table class="table">
                    <thead>
                    <tr>
                        <th>项目名称</th>
                        <th>角色</th>
                        <th>分配金额</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($participatingProjects as $project): ?>
                        <tr>
                            <td><?= e($project['name']) ?></td>
                            <td><?= e($user['role']) ?></td>
                            <td><?= format_currency($project['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>您尚未获得任何项目分配。</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
