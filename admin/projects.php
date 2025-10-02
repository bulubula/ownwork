<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/excel.php';

require_login();
require_role(['管理员']);

$pdo = get_pdo();
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'import') {
        if (!isset($_FILES['project_file']) || !is_uploaded_file($_FILES['project_file']['tmp_name'])) {
            $errors[] = '请上传 Excel 文件。';
        } else {
            $file = $_FILES['project_file'];
            if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = '文件上传失败，请重试。';
            } else {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($extension !== 'xlsx') {
                    $errors[] = '仅支持 .xlsx 格式的 Excel 文件。';
                } else {
                    try {
                        $rows = read_xlsx($file['tmp_name']);
                    } catch (RuntimeException $exception) {
                        $rows = [];
                        $errors[] = $exception->getMessage();
                    }

                    if (!$errors) {
                        if (count($rows) < 2) {
                            $errors[] = 'Excel 文件中未找到有效数据行。';
                        } else {
                            $headers = array_map('trim', $rows[0]);
                            $requiredHeaders = ['项目名称', '项目类别', '项目层级', '项目总金额', '项目负责人工号'];
                            $headerMap = [];
                            foreach ($headers as $index => $header) {
                                if ($header !== '') {
                                    $headerMap[$header] = $index;
                                }
                            }
                            foreach ($requiredHeaders as $requiredHeader) {
                                if (!array_key_exists($requiredHeader, $headerMap)) {
                                    $errors[] = 'Excel 中缺少必要列：“' . $requiredHeader . '”。';
                                }
                            }

                            $records = [];
                            $nameMap = [];
                            $managerLoginIds = [];

                            if (!$errors) {
                                $rowCount = count($rows);
                                for ($i = 1; $i < $rowCount; $i++) {
                                    $rowNumber = $i + 1;
                                    $row = $rows[$i];
                                    $name = trim($row[$headerMap['项目名称']] ?? '');
                                    $category = trim($row[$headerMap['项目类别']] ?? '');
                                    $level = trim($row[$headerMap['项目层级']] ?? '');
                                    $amountRaw = $row[$headerMap['项目总金额']] ?? '';
                                    $managerLogin = trim($row[$headerMap['项目负责人工号']] ?? '');

                                    if ($name === '' || $category === '' || $level === '' || $managerLogin === '') {
                                        $errors[] = '第 ' . $rowNumber . ' 行存在空值，请检查项目名称、类别、层级或负责人工号。';
                                        continue;
                                    }

                                    $amountString = is_string($amountRaw) ? trim($amountRaw) : (string) $amountRaw;
                                    $normalizedAmount = null;
                                    if ($amountString === '') {
                                        $normalizedAmount = 0.0;
                                    } elseif (is_numeric($amountString)) {
                                        $normalizedAmount = (float) $amountString;
                                    } else {
                                        $clean = str_replace(['，', ',', ' '], '', $amountString);
                                        if ($clean !== '' && is_numeric($clean)) {
                                            $normalizedAmount = (float) $clean;
                                        }
                                    }

                                    if ($normalizedAmount === null) {
                                        $errors[] = '第 ' . $rowNumber . ' 行的项目总金额格式无效。';
                                    } elseif ($normalizedAmount <= 0) {
                                        $errors[] = '第 ' . $rowNumber . ' 行的项目总金额需大于 0。';
                                    }

                                    if (isset($nameMap[$name])) {
                                        $errors[] = 'Excel 中项目名称重复：' . $name . '（第 ' . $nameMap[$name] . ' 行与第 ' . $rowNumber . ' 行）。';
                                    } else {
                                        $nameMap[$name] = $rowNumber;
                                    }

                                    $managerLoginIds[$managerLogin] = true;

                                    if ($errors) {
                                        continue;
                                    }

                                    $records[] = [
                                        'name' => $name,
                                        'category' => $category,
                                        'level' => $level,
                                        'total_amount' => round($normalizedAmount, 2),
                                        'manager_login' => $managerLogin,
                                        'row' => $rowNumber,
                                    ];
                                }
                            }

                            $mode = $_POST['mode'] ?? 'append';
                            if (!in_array($mode, ['append', 'replace'], true)) {
                                $mode = 'append';
                            }

                            if (!$errors && !$records) {
                                $errors[] = 'Excel 文件中没有可导入的项目数据。';
                            }

                            if (!$errors && $records) {
                                $managerLogins = array_keys($managerLoginIds);
                                if ($managerLogins) {
                                    $placeholders = implode(',', array_fill(0, count($managerLogins), '?'));
                                    $stmt = $pdo->prepare('SELECT id, login_id FROM users WHERE login_id IN (' . $placeholders . ')');
                                    $stmt->execute($managerLogins);
                                    $managers = $stmt->fetchAll();
                                    $managerMap = [];
                                    foreach ($managers as $manager) {
                                        $managerMap[$manager['login_id']] = (int) $manager['id'];
                                    }

                                    foreach ($records as &$record) {
                                        if (!isset($managerMap[$record['manager_login']])) {
                                            $errors[] = '第 ' . $record['row'] . ' 行的负责人工号“' . $record['manager_login'] . '”不存在于用户列表中。';
                                        } else {
                                            $record['manager_id'] = $managerMap[$record['manager_login']];
                                        }
                                    }
                                    unset($record);
                                }

                                if ($mode === 'append' && !$errors) {
                                    $names = array_column($records, 'name');
                                    $placeholders = implode(',', array_fill(0, count($names), '?'));
                                    $stmt = $pdo->prepare('SELECT name FROM projects WHERE name IN (' . $placeholders . ')');
                                    $stmt->execute($names);
                                    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    if ($existing) {
                                        $errors[] = '以下项目名称已存在，导入终止：' . implode('、', $existing) . '。';
                                    }
                                }

                                if (!$errors) {
                                    try {
                                        $pdo->beginTransaction();

                                        if ($mode === 'replace') {
                                            $pdo->exec('DELETE FROM projects');
                                        }

                                        $insert = $pdo->prepare('INSERT INTO projects (name, category, level, total_amount, manager_id) VALUES (:name, :category, :level, :total_amount, :manager_id)');
                                        foreach ($records as $record) {
                                            $insert->execute([
                                                'name' => $record['name'],
                                                'category' => $record['category'],
                                                'level' => $record['level'],
                                                'total_amount' => $record['total_amount'],
                                                'manager_id' => $record['manager_id'],
                                            ]);
                                        }

                                        $pdo->commit();
                                        $success = '成功导入 ' . count($records) . ' 个项目。';
                                    } catch (Throwable $exception) {
                                        $pdo->rollBack();
                                        $errors[] = '导入失败：' . $exception->getMessage();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$stmt = $pdo->query('SELECT p.id, p.name, p.category, p.level, p.total_amount, p.manager_id, u.name AS manager_name, u.login_id AS manager_login_id,
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
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>项目管理</h1>
            <small>维护项目基础信息与负责人</small>
        </div>
        <a class="btn-link" href="<?= e(url_for('dashboard.php')) ?>">返回控制面板</a>
    </header>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2>批量导入项目</h2>
            <span class="muted">支持本地 Excel（.xlsx）模板，可选择仅新增或清空后导入</span>
        </div>
        <form method="post" enctype="multipart/form-data" class="import-form">
            <input type="hidden" name="action" value="import">
            <label for="project_file">选择 Excel 文件</label>
            <input type="file" name="project_file" id="project_file" accept=".xlsx" required>
            <fieldset class="import-mode">
                <legend>导入模式</legend>
                <label><input type="radio" name="mode" value="append" checked> 仅新增（已存在的项目名称将阻止导入）</label>
                <label><input type="radio" name="mode" value="replace"> 清空后导入（会删除现有项目及分配记录）</label>
            </fieldset>
            <p class="muted">模板字段需包含：项目名称、项目类别、项目层级、项目总金额、项目负责人工号。项目负责人需先在用户列表中存在。</p>
            <p class="muted">金额支持 Excel 数值或带千分位的文本，导入后默认保留两位小数。</p>
            <div class="form-actions">
                <button type="submit">上传并导入</button>
            </div>
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
                        <td><?= e($project['manager_name']) ?>（工号：<?= e($project['manager_login_id']) ?>）</td>
                        <td>
                            <?php if ($project['is_completed']): ?>
                                <span class="badge badge-success">已分配完成</span>
                            <?php else: ?>
                                <span class="badge badge-pending">待分配</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn-link" href="<?= e(url_for('admin/project_edit.php')) ?>?id=<?= (int)$project['id'] ?>">编辑</a>
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
