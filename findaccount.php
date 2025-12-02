<?php
/**
 * 找回账号/密码
 */
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/smtp.php';

$message = '';
$error = '';
$accounts = [];

// 处理请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'find') {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '请输入有效的邮箱地址';
        } else {
            try {
                $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $stmt = $pdo->prepare("SELECT user_id, profile FROM " . table('user_accounts') . " WHERE email = ?");
                $stmt->execute([$email]);
                $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($accounts)) {
                    $error = '未找到该邮箱关联的账号';
                }
            } catch (Exception $e) {
                $error = '查询失败: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'send_reset') {
        $user_id = $_POST['user_id'] ?? 0;
        $email = $_POST['email'] ?? '';
        
        try {
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            
            // 验证用户和邮箱匹配
            $stmt = $pdo->prepare("SELECT user_id FROM " . table('user_accounts') . " WHERE user_id = ? AND email = ?");
            $stmt->execute([$user_id, $email]);
            if (!$stmt->fetch()) {
                throw new Exception('账号验证失败');
            }
            
            // 生成Token
            $token = bin2hex(random_bytes(32));
            
            // 保存Token
            $stmt = $pdo->prepare("INSERT INTO " . table('password_resets') . " (user_id, token) VALUES (?, ?)");
            $stmt->execute([$user_id, $token]);
            
            // 发送邮件
            if (!defined('SMTP_HOST') || !SMTP_HOST) {
                throw new Exception('系统未配置邮件服务');
            }
            
            $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']) . "/reset.php?token=$token";
            
            $tpl_file = __DIR__ . '/emailtemplate/' . (defined('SMTP_TEMPLATE') ? SMTP_TEMPLATE : 'default.html');
            if (!file_exists($tpl_file)) $tpl_file = __DIR__ . '/emailtemplate/default.html';
            
            $content = file_get_contents($tpl_file);
            $content = str_replace('{RESET_LINK}', $reset_link, $content);
            $content = str_replace('{YEAR}', date('Y'), $content);
            $content = str_replace('{CONTACT_EMAIL}', defined('SMTP_USER') ? SMTP_USER : '', $content);
            
            $smtp = new SmtpMailer(SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS);
            $smtp->send($email, '重置您的课表密码', $content);
            
            $message = '重置链接已发送到您的邮箱，请查收。';
            $accounts = []; // 清空列表，显示成功信息
            
        } catch (Exception $e) {
            $error = '发送失败: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>找回账号</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { border: none; box-shadow: 0 0 20px rgba(0,0,0,0.05); width: 100%; max-width: 450px; }
        .account-item { cursor: pointer; transition: all 0.2s; }
        .account-item:hover { background-color: #f8f9fa; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-body p-4">
            <h4 class="card-title text-center mb-4">找回账号</h4>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?></div>
                <div class="d-grid">
                    <a href="index.php" class="btn btn-primary">返回登录</a>
                </div>
            <?php else: ?>
            
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if (empty($accounts)): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="find">
                        <div class="mb-3">
                            <label class="form-label">邮箱地址</label>
                            <input type="email" name="email" class="form-control" required placeholder="请输入注册时使用的邮箱">
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">查找账号</button>
                            <a href="index.php" class="btn btn-link text-decoration-none">返回登录</a>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-3">找到以下关联账号，点击发送重置邮件：</p>
                    <div class="list-group mb-3">
                        <?php foreach ($accounts as $acc): 
                            $profile = json_decode($acc['profile'] ?? '{}', true);
                            $name = $profile['name'] ?? '未命名用户';
                        ?>
                            <form method="POST" class="list-group-item list-group-item-action account-item d-flex justify-content-between align-items-center">
                                <input type="hidden" name="action" value="send_reset">
                                <input type="hidden" name="user_id" value="<?= $acc['user_id'] ?>">
                                <input type="hidden" name="email" value="<?= htmlspecialchars($_POST['email']) ?>">
                                <div>
                                    <div class="fw-bold">ID: <?= $acc['user_id'] ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($name) ?></small>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary">发送邮件</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center">
                        <a href="findaccount.php" class="text-decoration-none text-muted small">换个邮箱试试</a>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
