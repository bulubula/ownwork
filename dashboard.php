<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/config_helper.php';

require_login();
$user = current_user();
$pdo = get_pdo();

// 处理配置变更
$configSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === '管理员') {
    if (isset($_POST['update_config'])) {
        $allocationEnabled = isset($_POST['allocation_enabled']) && $_POST['allocation_enabled'] === '1';
        if (set_config('allocation_enabled', $allocationEnabled)) {
            $configSuccess = '系统设置已更新';
        }
    }
}

// Fetch projects related to the user
$managedProjects = [];
if ($user['role'] !== '管理员') {
    // 查询负责的项目，并计算分配总额
    $stmt = $pdo->prepare('SELECT p.*, COALESCE(SUM(a.amount), 0) AS allocated_sum 
                            FROM projects p 
                            LEFT JOIN allocations a ON p.project_id = a.project_id 
                            WHERE p.manager_id = :manager_id 
                            GROUP BY p.project_id 
                            ORDER BY p.name');
    $stmt->execute(['manager_id' => $user['login_id']]);
    $managedProjects = $stmt->fetchAll();
}

// 查询参与的项目，并计算分配总额
$stmt = $pdo->prepare('SELECT p.*, a.amount, COALESCE(SUM(a2.amount), 0) AS allocated_sum 
                        FROM allocations a 
                        JOIN projects p ON a.project_id = p.project_id 
                        LEFT JOIN allocations a2 ON p.project_id = a2.project_id 
                        WHERE a.user_id = :user_id 
                        GROUP BY p.project_id, a.amount 
                        ORDER BY p.name');
$stmt->execute(['user_id' => $user['login_id']]);
$participatingProjects = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>控制面板</title>
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>欢迎，<?= e($user['name']) ?></h1>
            <small>当前角色：<?= e($user['role']) ?></small>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <a class="btn-link" href="<?= e(url_for('includes/change_password.php')) ?>">修改密码</a>
            <span style="color: #ccc;">|</span>
            <a class="btn-link" href="<?= e(url_for('index.php')) ?>?action=logout">退出登录</a>
        </div>
    </header>

    <?php if ($user['role'] === '管理员'): ?>
        <div class="card">
            <div class="card-header">
                <h2>管理员菜单</h2>
                <span class="muted">快速进入后台管理模块</span>
            </div>
            <div class="actions">
                <a class="ghost-button" href="<?= e(url_for('admin/users.php')) ?>">用户管理</a>
                <a class="ghost-button" href="<?= e(url_for('admin/projects.php')) ?>">项目管理</a>
                <a class="ghost-button" href="<?= e(url_for('admin/allocations.php')) ?>">分配明细</a>
                <a class="ghost-button" href="<?= e(url_for('admin/personal_income.php')) ?>">个人所得</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>系统设置</h2>
                <span class="muted">全局功能控制</span>
            </div>
            <div class="config-form">
                <?php if ($configSuccess): ?>
                    <div class="alert alert-success"><?= e($configSuccess) ?></div>
                <?php endif; ?>
                
                <form method="post">
                    <input type="hidden" name="update_config" value="1">
                    <div class="form-group">
                        <label for="allocation_enabled">分配功能开关</label>
                        <div class="switch-container">
                            <label class="switch">
                                <input type="checkbox" id="allocation_enabled" name="allocation_enabled" value="1"<?= get_config('allocation_enabled') ? ' checked' : '' ?>>
                                <span class="slider"></span>
                            </label>
                            <span class="switch-label"><?= get_config('allocation_enabled') ? '已开启' : '已关闭' ?></span>
                        </div>
                        <small class="help-text">控制是否允许项目负责人进行分配操作</small>
                    </div>
                    <button type="submit">保存设置</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-header">
                <h2>我负责的项目</h2>
                <span class="muted">共 <?= count($managedProjects) ?> 个</span>
            </div>
            <?php if ($managedProjects): ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>项目名称</th>
                            <th>项目类别</th>
                            <th>项目层级</th>
                            <th>总金额</th>
                            <th>分配状态</th>
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
                                <td>
                                    <?php if ($project['allocated_sum'] && abs($project['allocated_sum'] - $project['total_amount']) <= 0.01): ?>
                                        <span class="badge badge-success">已分配完成</span>
                                    <?php elseif ($project['allocated_sum'] > 0): ?>
                                        <span class="badge badge-pending">未完成分配</span>
                                    <?php else: ?>
                                        <span class="badge badge-info">未分配</span>
                                    <?php endif; ?>
                                </td>
                                <td><a class="btn-link" href="<?= e(url_for('projects/manage.php')) ?>?project_id=<?= (int)$project['project_id'] ?>">编辑分配</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted">当前没有您负责的项目。</p>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>我参与的项目</h2>
                <span class="muted">共 <?= count($participatingProjects) ?> 个</span>
            </div>
            <?php if ($participatingProjects): ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>项目名称</th>
                            <th>角色</th>
                            <th>分配金额</th>
                            <th>分配状态</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($participatingProjects as $project): ?>
                            <tr>
                                <td><?= e($project['name']) ?></td>
                                <td><?= e($user['role']) ?></td>
                                <td><?= format_currency($project['amount']) ?></td>
                                <td>
                                    <?php if ($project['allocated_sum'] && abs($project['allocated_sum'] - $project['total_amount']) <= 0.01): ?>
                                        <span class="badge badge-success">已分配完成</span>
                                    <?php else: ?>
                                        <span class="badge badge-pending">待分配</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted">您尚未获得任何项目分配。</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
