<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

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

// 获取总记录数的SQL
$countSql = 'SELECT COUNT(*) FROM allocations a
        JOIN projects p ON a.project_id = p.id
        JOIN users m ON a.user_id = m.id
        JOIN users u ON p.manager_id = u.id';
if ($where) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();

// 设置每页显示数量和当前页码
$perPage = 10;
$page = (int)($_GET['page'] ?? 1);
$page = max(1, min($page, ceil($totalRows / $perPage)));
$offset = ($page - 1) * $perPage;

// 查询当前页的数据
$sql = 'SELECT p.name AS project_name, p.category, p.level, p.total_amount,
        u.name AS manager_name, u.login_id AS manager_login_id,
        m.name AS member_name, m.login_id AS member_login_id, m.role AS member_role, a.amount

        FROM allocations a
        JOIN projects p ON a.project_id = p.id
        JOIN users m ON a.user_id = m.id
        JOIN users u ON p.manager_id = u.id';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY p.name, m.name
          LIMIT :limit OFFSET :offset';

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="allocations.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['项目名称', '项目类别', '项目层级', '项目总金额', '项目负责人', '负责人工号', '项目成员', '成员工号', '成员角色', '分配金额']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['project_name'],
            $row['category'],
            $row['level'],
            $row['total_amount'],
            $row['manager_name'],
            $row['manager_login_id'],
            $row['member_name'],
            $row['member_login_id'],
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
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>分配情况总览</h1>
            <small>按项目维度筛选并导出所有奖励分配记录</small>
        </div>
        <a class="btn-link" href="<?= e(url_for('dashboard.php')) ?>">返回控制面板</a>
    </header>

    <div class="card">
        <form method="get" class="filter-form">
            <div class="filter-field">
                <label>项目名称</label>
                <input type="text" name="name" value="<?= e($name) ?>" placeholder="模糊查询">
            </div>
            <div class="filter-field">
                <label>项目类别</label>
                <select name="category">
                    <option value="">全部</option>
                    <?php foreach ($categories as $option): ?>
                        <option value="<?= e($option) ?>" <?= $option === $category ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <label>项目层级</label>
                <select name="level">
                    <option value="">全部</option>
                    <?php foreach ($levels as $option): ?>
                        <option value="<?= e($option) ?>" <?= $option === $level ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit">筛选</button>
                <a class="ghost-button" href="<?= e(url_for('admin/allocations.php')) ?>">重置</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>分配明细</h2>
            <a class="ghost-button" href="<?= e(url_for('admin/allocations.php')) ?>?<?= e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'export' => '1']))) ?>">导出 CSV</a>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th>项目名称</th>
                    <th>类别</th>
                    <th>层级</th>
                    <th>总金额</th>
                    <th>负责人</th>
                    <th>负责人工号</th>
                    <th>成员</th>
                    <th>成员工号</th>
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
                            <td><?= e($row['manager_login_id']) ?></td>
                            <td><?= e($row['member_name']) ?></td>
                            <td><?= e($row['member_login_id']) ?></td>
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
        
        <!-- 分页控件 -->
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(url_for('admin/allocations.php')) ?>?<?= e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'page' => $page - 1]))) ?>" class="pagination-link">上一页</a>
            <?php endif; ?>
            
            <?php 
            // 生成页码链接，只显示当前页附近的页码
            $totalPages = ceil($totalRows / $perPage);
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $startPage + 4);
            
            if ($startPage > 1) {
                echo '<a href="' . e(url_for('admin/allocations.php')) . '?' . e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'page' => 1]))) . '" class="pagination-link">1</a>';
                if ($startPage > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
                <a href="<?= e(url_for('admin/allocations.php')) ?>?<?= e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'page' => $i]))) ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="<?= e(url_for('admin/allocations.php')) ?>?<?= e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'page' => $totalPages]))) ?>" class="pagination-link"><?= $totalPages ?></a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="<?= e(url_for('admin/allocations.php')) ?>?<?= e(http_build_query(array_filter(['name' => $name, 'category' => $category, 'level' => $level, 'page' => $page + 1]))) ?>" class="pagination-link">下一页</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
