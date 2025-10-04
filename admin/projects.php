<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/excel.php';

require_login();
require_role(['管理员']);

$pdo = get_pdo();
$errors = [];
$success = '';

// 处理Excel导入
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

                            // 固定为仅新增模式
                            $mode = 'append';

                            if (!$errors && !$records) {
                                $errors[] = 'Excel 文件中没有可导入的项目数据。';
                            }

                            if (!$errors && $records) {
                                $managerLogins = array_keys($managerLoginIds);
                                if ($managerLogins) {
                                    $placeholders = implode(',', array_fill(0, count($managerLogins), '?'));
                                    $stmt = $pdo->prepare('SELECT login_id FROM users WHERE login_id IN (' . $placeholders . ')');
                                    $stmt->execute($managerLogins);
                                    $existingLogins = $stmt->fetchAll(PDO::FETCH_COLUMN);
                                    $existingLoginMap = array_flip($existingLogins);

                                    foreach ($records as &$record) {
                                        if (!isset($existingLoginMap[$record['manager_login']])) {
                                            $errors[] = '第 ' . $record['row'] . ' 行的负责人工号“' . $record['manager_login'] . '”不存在于用户列表中。';
                                        } else {
                                            // 直接使用工号作为manager_id
                                            $record['manager_id'] = $record['manager_login'];
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

                                        // 获取当前最大的project_id值，如果没有则从1开始
                                        $maxIdStmt = $pdo->query('SELECT MAX(project_id) FROM projects');
                                        $maxId = $maxIdStmt->fetchColumn();
                                        $currentId = $maxId ? (int)$maxId : 0;

                                        // 插入项目数据，手动生成project_id依次增加
                                        $insert = $pdo->prepare('INSERT INTO projects (project_id, name, category, level, total_amount, manager_id) VALUES (:project_id, :name, :category, :level, :total_amount, :manager_id)');
                                        foreach ($records as $record) {
                                            $currentId++;
                                            $insert->execute([
                                                'project_id' => $currentId,
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

// 获取搜索参数
$searchName = trim($_GET['name'] ?? '');
$searchManager = trim($_GET['manager'] ?? '');
$searchStatus = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 计算并缓存已完成和未完成项目ID数组
function getProjectStatusArrays(PDO $pdo): array {
    $completedProjectIds = [];
    $pendingProjectIds = [];
    
    // 从分配表计算每个项目的总分配金额
    $stmt = $pdo->query('SELECT a.project_id, SUM(a.amount) as allocated_sum, p.total_amount FROM allocations a JOIN projects p ON a.project_id = p.project_id GROUP BY a.project_id, p.total_amount');
    $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取所有项目ID
    $allProjectsStmt = $pdo->query('SELECT project_id, total_amount FROM projects');
    $allProjects = $allProjectsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 创建项目ID到总金额的映射
    $projectAmountMap = [];
    foreach ($allProjects as $project) {
        $projectAmountMap[$project['project_id']] = $project['total_amount'];
    }
    
    // 创建已分配金额的映射
    $allocatedAmountMap = [];
    foreach ($allocations as $allocation) {
        $allocatedAmountMap[$allocation['project_id']] = (float)$allocation['allocated_sum'];
    }
    
    // 分类项目ID
    foreach ($projectAmountMap as $projectId => $totalAmount) {
        $allocatedAmount = $allocatedAmountMap[$projectId] ?? 0;
        // 比较分配金额和项目总金额是否相等（考虑浮点精度问题）
        if (abs($allocatedAmount - $totalAmount) <= 0.01 && $allocatedAmount > 0) {
            $completedProjectIds[] = $projectId;
        } else {
            $pendingProjectIds[] = $projectId;
        }
    }
    
    return [
        'completed' => $completedProjectIds,
        'pending' => $pendingProjectIds
    ];
}

// 获取项目状态数组
$projectStatusArrays = getProjectStatusArrays($pdo);
$completedProjectIds = $projectStatusArrays['completed'];
$pendingProjectIds = $projectStatusArrays['pending'];

// 统计信息
$totalProjects = count($completedProjectIds) + count($pendingProjectIds);
$completedProjects = count($completedProjectIds);
$pendingProjects = count($pendingProjectIds);

// 构建基础查询（包含项目名称和负责人筛选）
$whereConditions = [];
$params = [];

if ($searchName) {
    $whereConditions[] = 'p.name LIKE CONCAT(\'%\', ?, \'%\')';
    $params[] = $searchName;
}

if ($searchManager) {
    $whereConditions[] = '(u.name LIKE CONCAT(\'%\', ?, \'%\') OR u.login_id LIKE CONCAT(\'%\', ?, \'%\'))';
    $params[] = $searchManager;
    $params[] = $searchManager;
}

// 根据状态筛选添加项目ID条件
$filteredProjectIds = [];
if ($searchStatus === 'completed') {
    $filteredProjectIds = $completedProjectIds;
} elseif ($searchStatus === 'pending') {
    $filteredProjectIds = $pendingProjectIds;
} else {
    // 全部状态
    $filteredProjectIds = array_merge($completedProjectIds, $pendingProjectIds);
}

// 如果有ID筛选，则添加到WHERE条件
if (!empty($filteredProjectIds)) {
    $idPlaceholders = implode(',', array_fill(0, count($filteredProjectIds), '?'));
    $whereConditions[] = "p.project_id IN ($idPlaceholders)";
    // 确保所有项目ID都是整数类型
    $intProjectIds = array_map('intval', $filteredProjectIds);
    $params = array_merge($params, $intProjectIds);
}

// 构建完整的WHERE子句
$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// 计算筛选后的项目总数
$countQuery = "SELECT COUNT(DISTINCT p.project_id) FROM projects p JOIN users u ON p.manager_id = u.login_id $whereClause";
$countStmt = $pdo->prepare($countQuery);

// 使用bindParam明确指定参数类型
$paramIndex = 1;
foreach ($params as $param) {
    $countStmt->bindValue($paramIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$countStmt->execute();
$filteredTotalProjects = $countStmt->fetchColumn();

// 分页查询当前页的项目数据
$searchQuery = "SELECT p.*, u.name as manager_name, u.login_id as manager_login_id FROM projects p JOIN users u ON p.manager_id = u.login_id $whereClause ORDER BY p.project_id DESC LIMIT ? OFFSET ?";
$searchStmt = $pdo->prepare($searchQuery);

// 使用bindParam明确指定参数类型
$paramIndex = 1;
foreach ($params as $param) {
    $searchStmt->bindValue($paramIndex++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$searchStmt->bindValue($paramIndex++, (int)$perPage, PDO::PARAM_INT);
$searchStmt->bindValue($paramIndex++, (int)$offset, PDO::PARAM_INT);
$searchStmt->execute();
$projects = $searchStmt->fetchAll(PDO::FETCH_ASSOC);

// 为每个项目添加is_completed状态
foreach ($projects as &$project) {
    $projectId = $project['project_id'];
    $project['is_completed'] = in_array($projectId, $completedProjectIds);
}
unset($project);

// 计算总页数
$totalPages = ceil($filteredTotalProjects / $perPage);

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
            <p class="muted-small">模板字段需包含：项目名称、项目类别、项目层级、项目总金额、项目负责人工号。项目负责人需先在用户列表中存在。金额支持 Excel 数值或带千分位的文本，导入后默认保留两位小数。</p>
            <div class="form-actions">
                <a class="ghost-button" href="<?= e(asset_url('templates/project_import_template.xlsx')) ?>" download>下载导入示例</a>
                <button type="submit">上传并导入</button>
            </div>
        </form>
    </div>

    <div class="card">
        <form method="get" class="filter-form">
                <div class="filter-field">
                    <label>项目名称</label>
                    <input type="text" name="name" value="<?= e($searchName) ?>" placeholder="模糊搜索">
                </div>
                <div class="filter-field">
                    <label>项目负责人</label>
                    <input type="text" name="manager" value="<?= e($searchManager) ?>" placeholder="姓名或工号">
                </div>
                <div class="filter-field">
                    <label>分配状态</label>
                    <select name="status">
                        <option value="">全部</option>
                        <option value="completed" <?= $searchStatus === 'completed' ? 'selected' : '' ?>>已分配完成</option>
                        <option value="pending" <?= $searchStatus === 'pending' ? 'selected' : '' ?>>待分配</option>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit">筛选</button>
                    <a class="ghost-button" href="<?= e(url_for('admin/projects.php')) ?>">重置</a>
                </div>
        </form>
        
        <div class="card-header">
            <h2>项目列表</h2>
            <span class="muted">项目总数：<?= $totalProjects ?> 个，完成分配 <?= $completedProjects ?> 个，未完成 <?= $pendingProjects ?> 个</span>
            <span class="muted" style="margin-left: 20px;">当前筛选结果：<?= $filteredTotalProjects ?> 个项目</span>
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
                            <a class="btn-link" href="<?= e(url_for('admin/project_edit.php')) ?>?id=<?= (int)$project['project_id'] ?>">编辑</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 美化的分页控件 -->
        <div class="pagination">
            <!-- 计算总页数（使用筛选后的项目数量） -->
            <?php 
            $currentProjectCount = count($projects);
            ?>
            <div class="pagination-info">
                <span>第 <?= $page ?> 页，共 <?= $totalPages ?> 页</span>
                <span>每页显示 <?= $perPage ?> 条，共 <?= $filteredTotalProjects ?> 条记录</span>
            </div>
            
            <div class="pagination-controls">
                <?php if ($page > 1): ?>
                    <a href="<?= e(url_for('admin/projects.php')) ?>?<?= e(http_build_query(array_filter(['name' => $searchName, 'manager' => $searchManager, 'status' => $searchStatus, 'page' => $page - 1]))) ?>" class="pagination-link">
                        <i class="icon-left"></i> 上一页
                    </a>
                <?php endif; ?>
                
                <?php 
                // 生成页码链接，只显示当前页附近的页码
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $startPage + 4);
                
                if ($startPage > 1) {
                    echo '<a href="' . e(url_for('admin/projects.php')) . '?' . e(http_build_query(array_filter(['name' => $searchName, 'manager' => $searchManager, 'status' => $searchStatus, 'page' => 1]))) . '" class="pagination-link">1</a>';
                    if ($startPage > 2) {
                        echo '<span class="pagination-ellipsis">...</span>';
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <a href="<?= e(url_for('admin/projects.php')) ?>?<?= e(http_build_query(array_filter(['name' => $searchName, 'manager' => $searchManager, 'status' => $searchStatus, 'page' => $i]))) ?>" class="pagination-link <?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                    <a href="<?= e(url_for('admin/projects.php')) ?>?<?= e(http_build_query(array_filter(['name' => $searchName, 'manager' => $searchManager, 'status' => $searchStatus, 'page' => $totalPages]))) ?>" class="pagination-link"><?= $totalPages ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="<?= e(url_for('admin/projects.php')) ?>?<?= e(http_build_query(array_filter(['name' => $searchName, 'manager' => $searchManager, 'status' => $searchStatus, 'page' => $page + 1]))) ?>" class="pagination-link">
                        下一页 <i class="icon-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
