<?php
// admin.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

include_once __DIR__ . '/../db.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Auth Check
if (!isset($_SESSION['uid'])) {
    header('Location: login.php'); exit;
}

$uid = (int)$_SESSION['uid'];
$stmt = db()->prepare('SELECT role, email FROM ' . table('user_accounts') . ' WHERE user_id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'super_admin') {
    // If logged in as normal user but trying to access admin, redirect or show error
    // Better to redirect to login.php to allow switching accounts
    header('Location: login.php'); exit;
}

// Handle Role Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_role') {
    $targetId = (int)$_POST['target_id'];
    $newRole = $_POST['new_role'];
    if (in_array($newRole, ['user','admin','super_admin'])) {
        $upd = db()->prepare('UPDATE ' . table('user_accounts') . ' SET role = ? WHERE user_id = ?');
        $upd->execute([$newRole, $targetId]);
    }
    header('Location: admin.php'); exit;
}

// Get Users
$users = db()->query('SELECT user_id, pin, email, role, created_at FROM ' . table('user_accounts') . ' ORDER BY created_at DESC LIMIT 50')->fetchAll();

?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>超级管理面板</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body { background: #f8f9fa; }
    .card { border: none; box-shadow: 0 2px 4px rgba(0,0,0,.05); }
</style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="#">Kebiao Admin</a>
    <div class="d-flex gap-2">
        <a href="../index.php" class="btn btn-outline-light btn-sm">返回课表</a>
    </div>
  </div>
</nav>

<div class="container">
    <!-- Version Control -->
    <div class="card mb-4">
        <div class="card-header bg-white fw-bold">系统版本</div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">当前版本: <span id="localVer" class="text-primary">Loading...</span></h5>
                    <div id="remoteVerInfo" class="text-muted small mt-1">正在检查更新...</div>
                </div>
                <div class="col-md-6 text-md-end">
                    <button id="btnUpdate" class="btn btn-success" disabled>立即更新</button>
                    <div id="updateMsg" class="mt-2 small"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Management -->
    <div class="card">
        <div class="card-header bg-white fw-bold">用户管理 (最近 50 人)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Created</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= h($u['user_id']) ?></td>
                            <td><?= h($u['email'] ?: '-') ?></td>
                            <td>
                                <span class="badge text-bg-<?= $u['role']==='super_admin'?'danger':($u['role']==='admin'?'warning':'secondary') ?>">
                                    <?= h($u['role']) ?>
                                </span>
                            </td>
                            <td><?= h($u['created_at']) ?></td>
                            <td>
                                <form method="post" class="d-flex gap-2" onsubmit="return confirm('确定修改角色？')">
                                    <input type="hidden" name="action" value="update_role">
                                    <input type="hidden" name="target_id" value="<?= $u['user_id'] ?>">
                                    <select name="new_role" class="form-select form-select-sm" style="width:auto">
                                        <option value="user" <?= $u['role']==='user'?'selected':'' ?>>User</option>
                                        <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                                        <option value="super_admin" <?= $u['role']==='super_admin'?'selected':'' ?>>Super Admin</option>
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary">保存</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
async function checkVersion() {
    try {
        const res = await fetch('../version_check.php?action=check');
        const data = await res.json();
        
        document.getElementById('localVer').textContent = data.local_version || 'Unknown';
        
        if (data.has_update) {
            document.getElementById('remoteVerInfo').innerHTML = `发现新版本: <b>${data.remote_version}</b>`;
            const btn = document.getElementById('btnUpdate');
            btn.disabled = false;
            btn.onclick = doUpdate;
        } else {
            document.getElementById('remoteVerInfo').textContent = '当前已是最新版本';
        }
    } catch (e) {
        document.getElementById('remoteVerInfo').textContent = '检查更新失败';
    }
}

async function doUpdate() {
    if (!confirm('确定要更新系统吗？请确保已备份数据。')) return;
    
    const btn = document.getElementById('btnUpdate');
    const msg = document.getElementById('updateMsg');
    btn.disabled = true;
    btn.textContent = '更新中...';
    msg.textContent = '';
    
    try {
        const res = await fetch('../version_check.php?action=update');
        const data = await res.json();
        
        if (data.ok) {
            msg.className = 'mt-2 small text-success';
            msg.textContent = '更新成功！请刷新页面。';
            setTimeout(() => location.reload(), 2000);
        } else {
            msg.className = 'mt-2 small text-danger';
            msg.textContent = '更新失败: ' + (data.error || '未知错误');
            btn.disabled = false;
            btn.textContent = '重试更新';
        }
    } catch (e) {
        msg.className = 'mt-2 small text-danger';
        msg.textContent = '请求失败';
        btn.disabled = false;
        btn.textContent = '重试更新';
    }
}

checkVersion();
</script>
</body>
</html>
