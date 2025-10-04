<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/config_helper.php';

require_login();
$user = current_user();
$pdo = get_pdo();

$projectId = (int)($_GET['project_id'] ?? 0);
$stmt = $pdo->prepare('SELECT p.*, u.name AS manager_name, u.login_id AS manager_id, u.login_id AS manager_login_id FROM projects p JOIN users u ON p.manager_id = u.login_id WHERE p.project_id = :project_id');
$stmt->execute(['project_id' => $projectId]);
$project = $stmt->fetch();

if (!$project) {
    http_response_code(404);
    echo '项目不存在';
    exit;
}

if ($user['role'] !== '管理员' && (int)$project['manager_id'] !== (int)$user['login_id']) {
    http_response_code(403);
    echo '只有项目负责人可以填写该项目的分配信息。';
    exit;
}

// 检查全局分配功能是否开启
$allocation_enabled = get_config('allocation_enabled', true);
if (!$allocation_enabled && $user['role'] !== '管理员') {
    http_response_code(403);
    echo '分配功能已被管理员关闭，请联系管理员。';
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
        // 添加成员操作现在仅在前端完成，不直接操作数据库
        $success = '成员已添加到列表，请填写分配金额并保存。';
    } elseif ($action === 'remove_member') {
        // 删除成员操作现在仅在前端完成，不直接操作数据库
        $success = '成员已从列表中移除，请保存更改。';
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

                // 获取用户信息进行校验
                $stmt = $pdo->prepare('SELECT role, login_id FROM users WHERE login_id = :login_id');
                $stmt->execute(['login_id' => $allocationId]);
                $member = $stmt->fetch();
                if (!$member) {
                    $errors[] = '存在无效的用户记录。';
                    break;
                }

                if ($member['role'] === '高层') {
                    $errors[] = '项目成员中不能包含高层人员。';
                    break;
                }

                if ($member['role'] === '中层') {
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
                        
                        // 先删除该项目的所有现有分配记录
                        $deleteStmt = $pdo->prepare('DELETE FROM allocations WHERE project_id = :project_id');
                        $deleteStmt->execute(['project_id' => $projectId]);
                        
                        // 然后插入所有当前分配记录
                        foreach ($records as $record) {
                            $stmt = $pdo->prepare('INSERT INTO allocations (project_id, user_id, amount) VALUES (:project_id, :user_id, :amount)');
                            $stmt->execute([
                                'project_id' => $projectId,
                                'user_id' => $record['id'],
                                'amount' => $record['amount']
                            ]);
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

$stmt = $pdo->prepare('SELECT a.id, a.amount, u.login_id AS user_id, u.name, u.role, u.login_id FROM allocations a JOIN users u ON a.user_id = u.login_id WHERE a.project_id = :project_id ORDER BY u.name');
$stmt->execute(['project_id' => $projectId]);
$allocations = $stmt->fetchAll();

$remainingSlots = max(0, 15 - count($allocations));

$memberIds = array_column($allocations, 'user_id');
$availableMembers = $pdo->prepare('SELECT login_id AS id, name, role, login_id FROM users WHERE role <> "高层" ORDER BY name');
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
    <link rel="stylesheet" href="<?= e(asset_url('styles.css')) ?>">
</head>
<body>
<div class="container">
    <header class="page-header">
        <div class="title-group">
            <h1>分配管理</h1>
            <small>当前项目：<?= e($project['name']) ?></small>
        </div>
        <a class="btn-link" href="<?= e(url_for('dashboard.php')) ?>">返回控制面板</a>
    </header>
    <p class="project-meta">项目类别：<?= e($project['category']) ?> ｜ 项目层级：<?= e($project['level']) ?> ｜ 总金额：<?= format_currency($project['total_amount']) ?> ｜ 项目负责人：<?= e($project['manager_name']) ?>（工号：<?= e($project['manager_login_id']) ?>）</p>

    <?php if (!$allocation_enabled): ?>
        <div class="alert alert-info">系统提示：分配功能已被管理员暂时关闭，当前仅供查看。</div>
    <?php endif; ?>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><?= e($message) ?></div>
    <?php endforeach; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2>项目成员（最多15人）</h2>
            <span class="muted">剩余名额 <?= $remainingSlots ?> 人</span>
        </div>
        
        <div class="allocation-notice">
            <p class="small-text">温馨提醒：单个中层人员的分配金额不能超过项目总金额的10%，中层人员的分配金额合计不能超过项目总金额的30%。</p>
        </div>
        <form class="member-actions"<?= !$allocation_enabled ? ' disabled' : '' ?>>
            <div class="member-picker">
                <div class="member-search-group">
                    <label class="member-search-label" for="member-search">搜索成员</label>
                    <input type="text" id="member-search" class="member-search" placeholder="输入关键字"<?= !$allocation_enabled ? ' disabled' : '' ?>>
                </div>
                
                <div class="member-select-group">
                    <label class="member-search-label" for="member-select">选择成员</label>
                    <select id="member-select" required<?= !$allocation_enabled ? ' disabled' : '' ?>>
                        <option value="">选择成员</option>
                        <?php foreach ($allUsers as $candidate): ?>
                            <option value="<?= e($candidate['login_id']) ?>" data-name="<?= e($candidate['name']) ?>" data-login="<?= e($candidate['login_id']) ?>" data-role="<?= e($candidate['role']) ?>">
                                <?= e($candidate['name']) ?>（<?= e($candidate['login_id']) ?>）
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit"<?= !$allocation_enabled ? ' disabled' : '' ?>>添加</button>
            </div>
        </form>

        <?php if ($allocations): ?>
            <form method="post"<?= !$allocation_enabled ? ' disabled' : '' ?>>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                        <tr>
                            <th>成员</th>
                            <th>工号</th>
                            <th>角色</th>
                            <th>分配金额</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allocations as $allocation): ?>
                            <tr>
                                <td><?= e($allocation['name']) ?></td>
                                <td><?= e($allocation['login_id']) ?></td>
                                <td><?= e($allocation['role']) ?></td>
                                <td>
                                    <input type="hidden" name="allocation_id[]" value="<?= e($allocation['login_id']) ?>">
                                    <input type="number" step="0.01" name="amount[]" value="<?= e($allocation['amount']) ?>" required<?= !$allocation_enabled ? ' disabled' : '' ?>>
                                </td>
                                <td>
                                    <button type="button" name="remove" value="<?= e($allocation['login_id']) ?>" class="secondary" onclick="return confirm('确认删除该成员？');"<?= !$allocation_enabled ? ' disabled' : '' ?>>删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="allocation-summary">
                    <span class="allocation-total">当前分配合计金额：<span id="total-amount"><?= format_currency(array_sum(array_column($allocations, 'amount'))) ?></span></span>
                </div>
                <button type="submit" name="save_allocations" value="1"<?= !$allocation_enabled ? ' disabled' : '' ?>>保存分配金额</button>
            </form>
        <?php else: ?>
            <p class="muted">尚未添加任何项目成员。</p>
        <?php endif; ?>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var searchInput = document.getElementById('member-search');
        var select = document.getElementById('member-select');
        var addMemberForm = document.querySelector('.member-actions');
        var allocationsTable = document.querySelector('.table tbody');
        var saveAllocationsForm = document.querySelector('.table-wrapper').closest('form');
        var remainingSlotsElement = document.querySelector('.muted span');
        var totalAmountElement = document.getElementById('total-amount');
        
        if (!searchInput || !select || !addMemberForm || !allocationsTable) {
            return;
        }

        var options = Array.prototype.slice.call(select.querySelectorAll('option'));
        var allocations = []; // 存储当前分配的成员

        // 初始化现有分配
        document.querySelectorAll('.table tbody tr').forEach(function(row) {
            var userId = row.querySelector('input[name="allocation_id[]"]').value;
            var name = row.cells[0].textContent;
            var loginId = row.cells[1].textContent;
            var role = row.cells[2].textContent;
            var amount = row.querySelector('input[name="amount[]"]').value;
            
            allocations.push({
                userId: userId,
                name: name,
                loginId: loginId,
                role: role,
                amount: amount
            });
        });

        // 计算并更新合计金额
        function updateTotalAmount() {
            var total = 0;
            allocations.forEach(function(allocation) {
                total += parseFloat(allocation.amount) || 0;
            });
            
            if (totalAmountElement) {
                totalAmountElement.textContent = '¥' + total.toFixed(2);
            }
        }

        // 初始计算合计金额
        updateTotalAmount();

        function applyFilter(keyword) {
            var normalized = keyword.trim().toLowerCase();
            var firstVisible = null;
            options.forEach(function (option) {
                if (!option.value) {
                    option.hidden = false;
                    return;
                }
                var text = option.textContent.toLowerCase();
                var matches = normalized === '' || text.indexOf(normalized) !== -1;
                option.hidden = !matches;
                if (matches && !firstVisible) {
                    firstVisible = option;
                }
            });

            if (normalized !== '') {
                if (firstVisible) {
                    select.value = firstVisible.value;
                } else {
                    select.value = '';
                }
            }
        }

        searchInput.addEventListener('input', function () {
            applyFilter(searchInput.value);
        });

        // 监听金额输入框的变化
        document.addEventListener('input', function(e) {
            if (e.target.name === 'amount[]') {
                // 更新对应分配的金额
                var row = e.target.closest('tr');
                if (row) {
                    var userIdInput = row.querySelector('input[name="allocation_id[]"]');
                    if (userIdInput) {
                        var userId = userIdInput.value;
                        var amount = e.target.value;
                        
                        // 更新内存中的分配数据
                        allocations.forEach(function(allocation) {
                            if (allocation.userId === userId) {
                                allocation.amount = amount;
                            }
                        });
                        
                        // 更新合计金额
                        updateTotalAmount();
                    }
                }
            }
        });

        // 添加成员功能
        addMemberForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            var selectedOption = select.options[select.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                alert('请选择一个成员');
                return;
            }
            
            var userId = selectedOption.value;
            var name = selectedOption.textContent.split('（')[0];
            var loginId = selectedOption.getAttribute('data-login') || userId;
            
            // 从选项的dataset中获取角色信息，如果不存在则使用默认值
            var role = selectedOption.dataset.role || '员工';
            
            // 检查是否已添加
            var exists = allocations.some(function(allocation) {
                return allocation.userId === userId;
            });
            
            if (exists) {
                alert('该成员已添加到项目中');
                return;
            }
            
            // 检查名额限制
            if (allocations.length >= 15) {
                alert('项目成员已达到最大数量（15人）');
                return;
            }
            
            // 添加到分配列表
            var allocation = {
                userId: userId,
                name: name,
                loginId: loginId,
                role: role,
                amount: '0.00'
            };
            
            allocations.push(allocation);
            updateAllocationsTable();
            updateAvailableMembers();
            
            // 重置选择
            select.value = '';
            searchInput.value = '';
            applyFilter('');
            
            // 更新剩余名额
            updateRemainingSlots();
            
            // 更新合计金额
            updateTotalAmount();
        });

        // 删除成员功能
        saveAllocationsForm.addEventListener('click', function(e) {
            if (e.target.tagName === 'BUTTON' && e.target.name === 'remove') {
                e.preventDefault();
                
                var userId = e.target.value;
                
                // 从分配列表中移除
                allocations = allocations.filter(function(allocation) {
                    return allocation.userId !== userId;
                });
                
                updateAllocationsTable();
                updateAvailableMembers();
                updateRemainingSlots();
                
                // 更新合计金额
                updateTotalAmount();
            }
        });

        // 更新分配表格
        function updateAllocationsTable() {
            // 清空表格
            allocationsTable.innerHTML = '';
            
            // 添加所有分配成员
            allocations.forEach(function(allocation) {
                var row = document.createElement('tr');
                
                row.innerHTML = `
                    <td>${allocation.name}</td>
                    <td>${allocation.loginId}</td>
                    <td>${allocation.role}</td>
                    <td>
                        <input type="hidden" name="allocation_id[]" value="${allocation.userId}">
                        <input type="number" step="0.01" name="amount[]" value="${allocation.amount}" required>
                    </td>
                    <td>
                        <button type="button" name="remove" value="${allocation.userId}" class="secondary" onclick="return confirm('确认删除该成员？');">删除</button>
                    </td>
                `;
                
                allocationsTable.appendChild(row);
            });
        }

        // 更新可用成员列表
        function updateAvailableMembers() {
            // 获取当前已分配的用户ID
            var allocatedUserIds = allocations.map(function(allocation) {
                return allocation.userId;
            });
            
            // 更新选项的可见性
            options.forEach(function(option) {
                if (!option.value) return;
                
                var userId = option.value;
                option.hidden = allocatedUserIds.includes(userId);
            });
        }

        // 更新剩余名额
        function updateRemainingSlots() {
            var remaining = Math.max(0, 15 - allocations.length);
            if (remainingSlotsElement) {
                remainingSlotsElement.textContent = '剩余名额 ' + remaining + ' 人';
            }
        }

        // 保存分配功能
        saveAllocationsForm.addEventListener('submit', function(e) {
            // 确保所有分配数据都包含在表单中
            // 这里不需要特殊处理，因为表格已经包含了所有需要的数据
        });
    });
</script>
</body>
</html>