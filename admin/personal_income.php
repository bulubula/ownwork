<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

require_login();
require_role(['管理员']);

$pdo = get_pdo();

$keyword = trim($_GET['keyword'] ?? '');
$role = trim($_GET['role'] ?? '');

$where = [];
$params = [];

if ($keyword !== '') {
    $where[] = '(u.name LIKE :keyword OR u.login_id LIKE :keyword)';
    $params['keyword'] = '%' . $keyword . '%';
}

if ($role !== '') {
    $where[] = 'u.role = :role';
    $params['role'] = $role;
}

$sql = 'SELECT u.name, u.login_id, u.role, COALESCE(SUM(a.amount), 0) AS total_amount
        FROM users u
        LEFT JOIN allocations a ON a.user_id = u.id';

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' GROUP BY u.id, u.name, u.login_id, u.role ORDER BY total_amount DESC, u.name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="personal_income.csv"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['姓名', '工号', '角色', '分配总额']);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['name'],
            $row['login_id'],
            $row['role'],
            $row['total_amount'],
        ]);
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>个人所得统计</title>
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>个人所得统计</h1>
            <small>按人员汇总奖励分配金额，支持导出</small>
        </div>
        <a class="btn-link" href="<?= e(url_for('dashboard.php')) ?>">返回控制面板</a>
    </header>

    <div class="card">
        <form method="get" class="filter-form">
            <div class="filter-field">
                <label>姓名/工号</label>
                <input type="text" name="keyword" value="<?= e($keyword) ?>" placeholder="模糊搜索">
            </div>
            <div class="filter-field">
                <label>角色</label>
                <select name="role">
                    <option value="">全部</option>
                    <?php foreach (USER_ROLES as $option): ?>
                        <option value="<?= e($option) ?>" <?= $option === $role ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit">筛选</button>
                <a class="ghost-button" href="<?= e(url_for('admin/personal_income.php')) ?>">重置</a>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>人员分配总额</h2>
            <?php
            $query = array_filter([
                'keyword' => $keyword,
                'role' => $role,
                'export' => '1',
            ], static function ($value) {
                return $value !== '';
            });
            $exportUrl = url_for('admin/personal_income.php');
            $queryString = http_build_query($query);
            if ($queryString !== '') {
                $exportUrl .= '?' . $queryString;
            }
            ?>
            <a class="ghost-button" href="<?= e($exportUrl) ?>">导出 CSV</a>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                <tr>
                    <th>姓名</th>
                    <th>工号</th>
                    <th>角色</th>
                    <th>分配总额</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= e($row['name']) ?></td>
                            <td><?= e($row['login_id']) ?></td>
                            <td><span class="badge"><?= e($row['role']) ?></span></td>
                            <td><?= format_currency($row['total_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4">暂无数据。</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
