<?php
// admin/login.php
declare(strict_types=1);
mb_internal_encoding('UTF-8');
session_start();

require_once __DIR__ . '/../db.php';

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

// Check if Super Admin exists
$stmt = db()->query("SELECT COUNT(*) FROM " . table('user_accounts') . " WHERE role = 'super_admin'");
$hasSuperAdmin = (int)$stmt->fetchColumn() > 0;

$error = '';
$success = '';

// Handle Setup (Claim Super Admin)
if (!$hasSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '请输入有效的邮箱';
    } elseif (strlen($pass) < 6) {
        $error = '密码至少需要 6 位';
    } else {
        // Create Super Admin
        // We need a user_id. Since this is a special admin, we can generate one or use a fixed one?
        // Let's generate one like normal users.
        $uid = random_int(100000, 999999);
        // Ensure unique
        while (db()->query("SELECT 1 FROM " . table('user_accounts') . " WHERE user_id=$uid")->fetch()) {
            $uid = random_int(100000, 999999);
        }
        
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $pin = '0000'; // Default PIN, not used for admin login but required by schema
        
        $ins = db()->prepare("INSERT INTO " . table('user_accounts') . " (user_id, pin, email, password_hash, role, profile) VALUES (?, ?, ?, ?, 'super_admin', '{}')");
        $ins->execute([$uid, $pin, $email, $hash]);
        
        $hasSuperAdmin = true;
        $success = '超级管理员创建成功，请登录';
    }
}

// Handle Login
if ($hasSuperAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    
    $stmt = db()->prepare("SELECT user_id, password_hash, role FROM " . table('user_accounts') . " WHERE email = ? AND role = 'super_admin'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['uid'] = (int)$user['user_id'];
        $_SESSION['role'] = 'super_admin'; // Cache role
        header('Location: index.php');
        exit;
    } else {
        $error = '邮箱或密码错误';
    }
}

?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>管理后台登录</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; }
    .card { width: 100%; max-width: 400px; border: none; shadow: 0 4px 12px rgba(0,0,0,0.1); }
</style>
</head>
<body>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <h4 class="card-title text-center mb-4">
            <?= $hasSuperAdmin ? '管理员登录' : '初始化超级管理员' ?>
        </h4>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if (!$hasSuperAdmin): ?>
            <form method="post">
                <input type="hidden" name="action" value="setup">
                <div class="mb-3">
                    <label class="form-label">管理员邮箱</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">设置密码</label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                </div>
                <button class="btn btn-primary w-100">创建管理员</button>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                    <label class="form-label">邮箱</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button class="btn btn-primary w-100">登录</button>
            </form>
        <?php endif; ?>
        
        <div class="text-center mt-3">
            <a href="../index.php" class="text-decoration-none text-muted small">返回首页</a>
        </div>
    </div>
</div>

</body>
</html>
