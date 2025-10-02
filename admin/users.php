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
        if (!isset($_FILES['user_file']) || !is_uploaded_file($_FILES['user_file']['tmp_name'])) {
            $errors[] = '请上传 Excel 文件。';
        } else {
            $file = $_FILES['user_file'];
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
                            $requiredHeaders = ['姓名', '工号', '角色', '出生日期'];
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
                            $loginIdMap = [];
                            if (!$errors) {
                                $rowCount = count($rows);
                                for ($i = 1; $i < $rowCount; $i++) {
                                    $rowNumber = $i + 1;
                                    $row = $rows[$i];
                                    $name = trim($row[$headerMap['姓名']] ?? '');
                                    $loginId = trim($row[$headerMap['工号']] ?? '');
                                    $role = trim($row[$headerMap['角色']] ?? '');
                                    $rawBirthdate = $row[$headerMap['出生日期']] ?? '';
                                    $birthdate = excel_serial_to_date_string($rawBirthdate);

                                    if ($name === '' || $loginId === '' || $role === '') {
                                        $errors[] = '第 ' . $rowNumber . ' 行存在空值，请检查姓名、工号与角色列。';
                                        continue;
                                    }

                                    if (!in_array($role, USER_ROLES, true)) {
                                        $errors[] = '第 ' . $rowNumber . ' 行的角色“' . $role . '”不在允许范围内。';
                                    }

                                    if ($birthdate === null) {
                                        $errors[] = '第 ' . $rowNumber . ' 行的出生日期无法解析，请使用 YYYY-MM-DD 或 Excel 日期格式。';
                                    }

                                    if (isset($loginIdMap[$loginId])) {
                                        $errors[] = 'Excel 中工号重复：' . $loginId . '（第 ' . $loginIdMap[$loginId] . ' 行与第 ' . $rowNumber . ' 行）。';
                                    } else {
                                        $loginIdMap[$loginId] = $rowNumber;
                                    }

                                    if ($errors) {
                                        continue;
                                    }

                                    $records[] = [
                                        'name' => $name,
                                        'login_id' => $loginId,
                                        'role' => $role,
                                        'birthdate' => $birthdate,
                                    ];
                                }
                            }

                            $mode = $_POST['mode'] ?? 'append';
                            if (!in_array($mode, ['append', 'replace'], true)) {
                                $mode = 'append';
                            }

                            if (!$errors && !$records) {
                                $errors[] = 'Excel 文件中没有可导入的数据行。';
                            }

                            if (!$errors && $records) {
                                $loginIds = array_column($records, 'login_id');
                                if ($mode === 'append') {
                                    $placeholders = implode(',', array_fill(0, count($loginIds), '?'));
                                    $stmt = $pdo->prepare('SELECT login_id FROM users WHERE login_id IN (' . $placeholders . ')');
                                    $stmt->execute($loginIds);
                                    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    if ($existing) {
                                        $errors[] = '以下工号已存在，导入终止：' . implode('、', $existing) . '。';
                                    }
                                }

                                if ($mode === 'replace' && !$errors) {
                                    $placeholders = implode(',', array_fill(0, count($loginIds), '?'));
                                    $stmt = $pdo->prepare("SELECT login_id FROM users WHERE role = '管理员' AND login_id IN (" . $placeholders . ')');
                                    $stmt->execute($loginIds);
                                    $conflicts = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    if ($conflicts) {
                                        $errors[] = '管理员账号与导入工号冲突，导入终止：' . implode('、', $conflicts) . '。';
                                    }
                                }

                                if (!$errors) {
                                    try {
                                        $pdo->beginTransaction();

                                        if ($mode === 'replace') {
                                            $nonAdminIds = $pdo->query("SELECT id FROM users WHERE role <> '管理员'")->fetchAll(PDO::FETCH_COLUMN);
                                            if ($nonAdminIds) {
                                                $placeholders = implode(',', array_fill(0, count($nonAdminIds), '?'));
                                                $stmt = $pdo->prepare('DELETE FROM allocations WHERE user_id IN (' . $placeholders . ')');
                                                $stmt->execute($nonAdminIds);
                                                $stmt = $pdo->prepare('DELETE FROM projects WHERE manager_id IN (' . $placeholders . ')');
                                                $stmt->execute($nonAdminIds);
                                            }
                                            $pdo->exec("DELETE FROM users WHERE role <> '管理员'");
                                        }

                                        $insert = $pdo->prepare('INSERT INTO users (name, login_id, role, birthdate, password_hash) VALUES (:name, :login_id, :role, :birthdate, :password_hash)');
                                        foreach ($records as $record) {
                                            $insert->execute([
                                                'name' => $record['name'],
                                                'login_id' => $record['login_id'],
                                                'role' => $record['role'],
                                                'birthdate' => $record['birthdate'],
                                                'password_hash' => hash_initial_password($record['birthdate']),
                                            ]);
                                        }

                                        $pdo->commit();
                                        $success = '成功导入 ' . count($records) . ' 位用户。默认密码为出生日期（8 位数字）。';
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

// 获取总用户数
$totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

// 设置每页显示数量和当前页码
$perPage = 10;
$page = (int)($_GET['page'] ?? 1);
$page = max(1, min($page, ceil($totalUsers / $perPage)));
$offset = ($page - 1) * $perPage;

// 查询当前页的用户数据
$users = $pdo->prepare('SELECT id, name, login_id, role, birthdate, created_at FROM users ORDER BY id ASC LIMIT :limit OFFSET :offset');
$users->bindValue(':limit', $perPage, PDO::PARAM_INT);
$users->bindValue(':offset', $offset, PDO::PARAM_INT);
$users->execute();
$users = $users->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>用户管理</title>
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>用户管理</h1>
            <small>维护登录账号与角色权限</small>
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
            <h2>批量导入用户</h2>
            <span class="muted">支持本地 Excel（.xlsx）模板，管理员账号将自动保留，可下载 CSV 示例查看字段顺序</span>
        </div>
        <form method="post" enctype="multipart/form-data" class="import-form">
            <input type="hidden" name="action" value="import">
            <label for="user_file">选择 Excel 文件</label>
            <input type="file" name="user_file" id="user_file" accept=".xlsx" required>
            <fieldset class="import-mode">
                <legend>导入模式</legend>
                <label><input type="radio" name="mode" value="append" checked> 仅新增（忽略已存在的工号）</label>
                <label><input type="radio" name="mode" value="replace"> 清空后导入（保留所有管理员账号）</label>
            </fieldset>
            <p class="muted">模板字段顺序需包含：姓名、工号、角色、出生日期。出生日期可为 YYYY-MM-DD、YYYYMMDD 或 Excel 日期格式。</p>
            <p class="muted">导入后，默认登录密码会重置为出生日期对应的 8 位数字。</p>
            <div class="form-actions">
                <a class="ghost-button" href="<?= e(asset_url('templates/user_import_template.xlsx')) ?>" download>下载导入示例</a>
                <button type="submit">上传并导入</button>
            </div>
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
                    <th>工号</th>
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
        
        <!-- 分页控件 -->
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?= e(url_for('admin/users.php')) ?>?page=<?= $page - 1 ?>" class="pagination-link">上一页</a>
            <?php endif; ?>
            
            <?php 
            // 生成页码链接，只显示当前页附近的页码
            $totalPages = ceil($totalUsers / $perPage);
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $startPage + 4);
            
            if ($startPage > 1) {
                echo '<a href="' . e(url_for('admin/users.php')) . '?page=1" class="pagination-link">1</a>';
                if ($startPage > 2) {
                    echo '<span class="pagination-ellipsis">...</span>';
                }
            }
            
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
                <a href="<?= e(url_for('admin/users.php')) ?>?page=<?= $i ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($endPage < $totalPages): ?>
                <?php if ($endPage < $totalPages - 1): ?>
                    <span class="pagination-ellipsis">...</span>
                <?php endif; ?>
                <a href="<?= e(url_for('admin/users.php')) ?>?page=<?= $totalPages ?>" class="pagination-link"><?= $totalPages ?></a>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
                <a href="<?= e(url_for('admin/users.php')) ?>?page=<?= $page + 1 ?>" class="pagination-link">下一页</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
