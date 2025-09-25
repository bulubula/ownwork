<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();
require_role(['管理员']);

$pdo = get_pdo();

$name = trim($_GET['name'] ?? '');
$category = trim($_GET['category'] ?? '');
$level = trim($_GET['level'] ?? '');

$where = [];
$params = [];

if ($name !== '') {
    $where[] = 'p.name LIKE :name';
    $params['name'] = '%' . $name . '%';
}
if ($category !== '') {
    $where[] = 'p.category = :category';
    $params['category'] = $category;
}
if ($level !== '') {
    $where[] = 'p.level = :level';
    $params['level'] = $level;
}

$sql = 'SELECT p.name AS project_name, p.category, p.level, p.total_amount, u.name AS manager_name, m.name AS member_name, m.role AS member_role, a.amount
        FROM allocations a
        JOIN projects p ON a.project_id = p.id
        JOIN users m ON a.user_id = m.id
        JOIN users u ON p.manager_id = u.id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY p.name, m.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="allocations.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['项目名称', '项目类别', '项目层级', '项目总金额', '项目负责人', '项目成员', '成员角色', '分配金额']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['project_name'],
            $row['category'],
            $row['level'],
            $row['total_amount'],
            $row['manager_name'],
            $row['member_name'],
            $row['member_role'],
            $row['amount'],
        ]);
    }
    fclose($output);
    exit;
}

$categories = $pdo->query('SELECT DISTINCT category FROM projects ORDER BY category')->fetchAll(PDO::FETCH_COLUMN);
$levels = $pdo->query('SELECT DISTINCT level FROM projects ORDER BY level')->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>分配情况</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <div class="flex">
        <h1>分配情况总览</h1>
        <div><a href="/dashboard.php">返回控制面板</a></div>
    </div>

    <div class="card">
        <form method="get" class="flex" style="gap:10px; flex-wrap: wrap;">
            <div style="flex:1 1 200px;">
                <label>项目名称</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="模糊查询">
            </div>
            <div style="flex:1 1 200px;">
                <label>项目类别</label>
                <select name="category">
                    <option value="">全部</option>
                    <?php foreach ($categories as $option): ?>
                        <option value="<?= e($option) ?>" <?= $option === $category ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex:1 1 200px;">
                <label>项目层级</label>
                <select name="level">
                    <option value="">全部</option>
                    <?php foreach ($levels as $option): ?>
                        <option value="<?= e($option) ?>" <?= $option === $level ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="align-self: flex-end;">
                <button type="submit">筛选</button>
                <a class="badge" href="/admin/allocations.php">重置</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="flex" style="margin-bottom: 10px;">
            <h2>分配明细</h2>
            <a href="/admin/allocations.php?<?= e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'export' => '1']))) ?>">导出CSV</a>
        </div>
        <table class="table">
            <thead>
            <tr>
                <th>项目名称</th>
                <th>类别</th>
                <th>层级</th>
                <th>总金额</th>
                <th>负责人</th>
                <th>成员</th>
                <th>角色</th>
                <th>分配金额</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows): ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['project_name']) ?></td>
                        <td><?= e($row['category']) ?></td>
                        <td><?= e($row['level']) ?></td>
                        <td><?= format_currency($row['total_amount']) ?></td>
                        <td><?= e($row['manager_name']) ?></td>
                        <td><?= e($row['member_name']) ?></td>
                        <td><?= e($row['member_role']) ?></td>
                        <td><?= format_currency($row['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8">暂无数据。</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
