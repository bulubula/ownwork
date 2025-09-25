<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();
$user = current_user();
$pdo = get_pdo();

$projectId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT p.*, u.name AS manager_name, u.id AS manager_id FROM projects p JOIN users u ON p.manager_id = u.id WHERE p.id = :id');
$stmt->execute(['id' => $projectId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    echo '项目不存在';
    exit;
}

if ($user['role'] !== '管理员' && (int)$project['manager_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo '只有项目负责人可以填写该项目的分配信息。';
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === '') {
        if (isset($_POST['save_allocations'])) {
            $action = 'save_allocations';
        } elseif (isset($_POST['remove'])) {
            $action = 'remove_member';
        }
    }

    if ($action === 'add_member') {
        $memberId = (int)($_POST['user_id'] ?? 0);
        if ($memberId <= 0) {
            $errors[] = '请选择要添加的项目成员。';
        } else {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id');
            $stmt->execute(['id' => $memberId]);
            $member = $stmt->fetch();
            if (!$member) {
                $errors[] = '用户不存在。';
            } elseif ($member['role'] === '高层') {
                $errors[] = '项目成员中不能包含高层人员。';
            } else {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM allocations WHERE project_id = :project_id');
                $countStmt->execute(['project_id' => $projectId]);
                $memberCount = (int)$countStmt->fetchColumn();
                if ($memberCount >= 15) {
                    $errors[] = '项目成员最多只能有15人。';
                } else {
                    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM allocations WHERE project_id = :project_id AND user_id = :user_id');
                    $existsStmt->execute(['project_id' => $projectId, 'user_id' => $memberId]);
                    if ((int)$existsStmt->fetchColumn() > 0) {
                        $errors[] = '该用户已在项目成员中。';
                    } else {
                        $insertStmt = $pdo->prepare('INSERT INTO allocations (project_id, user_id, amount) VALUES (:project_id, :user_id, 0)');
                        $insertStmt->execute(['project_id' => $projectId, 'user_id' => $memberId]);
                        $success = '已添加成员，请填写分配金额。';
                    }
                }
            }
        }
    } elseif ($action === 'remove_member') {
        $allocationId = isset($_POST['remove']) ? (int)$_POST['remove'] : (int)($_POST['allocation_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM allocations WHERE id = :id AND project_id = :project_id');
        $stmt->execute(['id' => $allocationId, 'project_id' => $projectId]);
        $success = '成员已移除。';
    } elseif ($action === 'save_allocations') {
        $allocationIds = $_POST['allocation_id'] ?? [];
        $amounts = $_POST['amount'] ?? [];

        if (!is_array($allocationIds) || !is_array($amounts) || count($allocationIds) !== count($amounts)) {
            $errors[] = '提交的数据无效。';
        } else {
            $totalAmount = (float)$project['total_amount'];
            $sum = 0.0;
            $middleSum = 0.0;
            $middleLimit = $totalAmount * 0.30;
            $singleMiddleLimit = $totalAmount * 0.10;

            $records = [];
            foreach ($allocationIds as $index => $allocationId) {
                $allocationId = (int)$allocationId;
                $rawAmount = $amounts[$index];
                if (!is_numeric($rawAmount)) {
                    $errors[] = '金额必须为数字。';
                    break;
                }
                $amount = round((float)$rawAmount, 2);
                if ($amount < 0) {
                    $errors[] = '金额不能为负数。';
                    break;
                }

                $stmt = $pdo->prepare('SELECT a.id, a.user_id, u.role FROM allocations a JOIN users u ON a.user_id = u.id WHERE a.id = :id AND a.project_id = :project_id');
                $stmt->execute(['id' => $allocationId, 'project_id' => $projectId]);
                $allocation = $stmt->fetch();
                if (!$allocation) {
                    $errors[] = '存在无效的分配记录。';
                    break;
                }

                if ($allocation['role'] === '高层') {
                    $errors[] = '项目成员中不能包含高层人员。';
                    break;
                }

                if ($allocation['role'] === '中层') {
                    if ($amount > $singleMiddleLimit + 0.0001) {
                        $errors[] = '单个中层人员的分配金额不能超过项目总金额的10%。';
                        break;
                    }
                    $middleSum += $amount;
                }

                $sum += $amount;
                $records[] = ['id' => $allocationId, 'amount' => $amount];
            }

            if (!$errors) {
                if (count($records) === 0) {
                    $errors[] = '请至少添加一名项目成员。';
                } elseif (abs($sum - $totalAmount) > 0.01) {
                    $errors[] = '所有成员的分配金额之和必须等于项目总金额。';
                } elseif ($middleSum > $middleLimit + 0.0001) {
                    $errors[] = '中层人员的分配金额合计不能超过项目总金额的30%。';
                } else {
                    try {
                        $pdo->beginTransaction();
                        foreach ($records as $record) {
                            $stmt = $pdo->prepare('UPDATE allocations SET amount = :amount WHERE id = :id');
                            $stmt->execute(['amount' => $record['amount'], 'id' => $record['id']]);
                        }
                        $pdo->commit();
                        $success = '分配金额已保存。';
                    } catch (PDOException $exception) {
                        $pdo->rollBack();
                        $errors[] = '保存分配金额失败：' . $exception->getMessage();
                    }
                }
            }
        }
    }
}

$stmt = $pdo->prepare('SELECT a.id, a.amount, u.id AS user_id, u.name, u.role FROM allocations a JOIN users u ON a.user_id = u.id WHERE a.project_id = :project_id ORDER BY u.name');
$stmt->execute(['project_id' => $projectId]);
$allocations = $stmt->fetchAll();

$memberIds = array_column($allocations, 'user_id');
$availableMembers = $pdo->prepare('SELECT id, name, role FROM users WHERE role <> "高层" ORDER BY name');
$availableMembers->execute();
$allUsers = array_filter($availableMembers->fetchAll(), static function ($row) use ($memberIds) {
    return !in_array($row['id'], $memberIds, true);
});
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>项目分配管理</title>
    <link rel="stylesheet" href="/styles.css">
</head>
<body>
<div class="container">
    <div class="flex">
        <h1>分配管理：<?= e($project['name']) ?></h1>
        <div><a href="/dashboard.php">返回控制面板</a></div>
    </div>
    <p>项目类别：<?= e($project['category']) ?> ｜ 项目层级：<?= e($project['level']) ?> ｜ 总金额：<?= format_currency($project['total_amount']) ?></p>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert" style="background:#d1e7dd;color:#0f5132;"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <h2>项目成员（最多15人）</h2>
        <form method="post" class="inline" style="margin-bottom: 15px;">
            <input type="hidden" name="action" value="add_member">
            <select name="user_id" required>
                <option value="">选择成员</option>
                <?php foreach ($allUsers as $candidate): ?>
                    <option value="<?= (int)$candidate['id'] ?>"><?= e($candidate['name']) ?>（<?= e($candidate['role']) ?>）</option>
                <?php endforeach; ?>
            </select>
            <button type="submit">添加成员</button>
        </form>

        <?php if ($allocations): ?>
            <form method="post">
                <table class="table">
                    <thead>
                    <tr>
                        <th>成员</th>
                        <th>角色</th>
                        <th>分配金额</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allocations as $allocation): ?>
                        <tr>
                            <td><?= e($allocation['name']) ?></td>
                            <td><?= e($allocation['role']) ?></td>
                            <td>
                                <input type="hidden" name="allocation_id[]" value="<?= (int)$allocation['id'] ?>">
                                <input type="number" step="0.01" name="amount[]" value="<?= e($allocation['amount']) ?>" required>
                            </td>
                            <td>
                                <button type="submit" name="remove" value="<?= (int)$allocation['id'] ?>" class="secondary" onclick="return confirm('确认删除该成员？');">删除</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" name="save_allocations" value="1">保存分配金额</button>
            </form>
        <?php else: ?>
            <p>尚未添加任何项目成员。</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
