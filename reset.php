<?php
/**
 * 重置密码页面
 */
session_start();
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$valid_token = false;
$user_id = 0;

if (empty($token)) {
    die('无效的链接');
}

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    
    // 验证Token
    $stmt = $pdo->prepare("SELECT user_id, created_at FROM " . table('password_resets') . " WHERE token = ?");
    $stmt->execute([$token]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reset) {
        // 检查有效期 (例如 24小时)
        if (time() - strtotime($reset['created_at']) > 86400) {
            $error = '链接已过期，请重新申请';
        } else {
            $valid_token = true;
            $user_id = $reset['user_id'];
        }
    } else {
        $error = '无效的重置链接';
    }
    
    // 处理重置
    if ($valid_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pin1 = $_POST['pin1'] ?? '';
        $pin2 = $_POST['pin2'] ?? '';
        
        if (!preg_match('/^\d{4}$/', $pin1)) {
            $error = '密码必须是4位数字';
        } elseif ($pin1 !== $pin2) {
            $error = '两次输入的密码不一致';
        } else {
            // 更新密码
            $stmt = $pdo->prepare("UPDATE " . table('user_accounts') . " SET pin = ? WHERE user_id = ?");
            $stmt->execute([$pin1, $user_id]);
            
            // 删除Token
            $stmt = $pdo->prepare("DELETE FROM " . table('password_resets') . " WHERE token = ?");
            $stmt->execute([$token]);
            
            $success = '密码重置成功！';
            $valid_token = false; // 防止重复提交
        }
    }
    
} catch (Exception $e) {
    $error = '系统错误: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>重置密码</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { border: none; box-shadow: 0 0 20px rgba(0,0,0,0.05); width: 100%; max-width: 400px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-body p-4">
            <h4 class="card-title text-center mb-4">重置密码</h4>
            
            <?php if ($success): ?>
                <div class="alert alert-success text-center">
                    <i class="bi bi-check-circle-fill display-4 d-block mb-3"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
                <div class="d-grid">
                    <a href="index.php" class="btn btn-primary">前往登录</a>
                </div>
            <?php elseif ($error && !$valid_token): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <div class="d-grid">
                    <a href="findaccount.php" class="btn btn-outline-secondary">重新找回</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">新密码 (4位数字)</label>
                        <input type="password" name="pin1" class="form-control" required pattern="\d{4}" maxlength="4" inputmode="numeric">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">确认新密码</label>
                        <input type="password" name="pin2" class="form-control" required pattern="\d{4}" maxlength="4" inputmode="numeric">
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">确认重置</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
