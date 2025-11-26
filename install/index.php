<?php
/**
 * 课表系统安装程序
 * 用于初始化数据库和配置文件
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 检查是否已安装
if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
    if (defined('INSTALLED') && INSTALLED === true) {
        die('系统已安装！如需重新安装，请删除 config.php 文件。');
    }
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // 测试数据库连接
        $db_host = trim($_POST['db_host'] ?? '');
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';
        $db_prefix = trim($_POST['db_prefix'] ?? 'kb_');
        
        try {
            $dsn = "mysql:host={$db_host};charset=utf8mb4";
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // 检查数据库是否存在
            $stmt = $pdo->query("SHOW DATABASES LIKE '{$db_name}'");
            if ($stmt->rowCount() == 0) {
                // 创建数据库
                $pdo->exec("CREATE DATABASE `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            }
            
            // 保存到session
            $_SESSION['install_config'] = [
                'db_host' => $db_host,
                'db_name' => $db_name,
                'db_user' => $db_user,
                'db_pass' => $db_pass,
                'db_prefix' => $db_prefix
            ];
            
            header('Location: ?step=2');
            exit;
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        }
    } elseif ($step == 2) {
        // 创建表结构
        if (!isset($_SESSION['install_config'])) {
            header('Location: ?step=1');
            exit;
        }
        
        $config = $_SESSION['install_config'];
        
        try {
            $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
            // 读取SQL文件
            $sql = file_get_contents(__DIR__ . '/../db.sql');
            
            // 替换表前缀
            $prefix = $config['db_prefix'];
            $tables = ['lab_uploads', 'shared_links', 'user_accounts', 'user_lab_schedule', 'user_schedule'];
            foreach ($tables as $table) {
                $sql = str_replace("`{$table}`", "`{$prefix}{$table}`", $sql);
            }
            
            // 执行SQL
            $pdo->exec($sql);
            
            // 生成配置文件
            $config_content = "<?php\n";
            $config_content .= "// 数据库配置\n";
            $config_content .= "define('DB_HOST', '{$config['db_host']}');\n";
            $config_content .= "define('DB_NAME', '{$config['db_name']}');\n";
            $config_content .= "define('DB_USER', '{$config['db_user']}');\n";
            $config_content .= "define('DB_PASS', '" . addslashes($config['db_pass']) . "');\n";
            $config_content .= "define('DB_PREFIX', '{$config['db_prefix']}');\n";
            $config_content .= "define('DB_CHARSET', 'utf8mb4');\n\n";
            $config_content .= "// 安全配置\n";
            $config_content .= "define('INSTALLED', true);\n";
            
            file_put_contents(__DIR__ . '/../config.php', $config_content);
            
            header('Location: ?step=3');
            exit;
        } catch (Exception $e) {
            $error = '安装失败: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>课表系统安装向导</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .install-container { max-width: 600px; margin: 50px auto; }
        .install-card { background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); }
        .install-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 15px 15px 0 0; }
        .step-indicator { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .step { flex: 1; text-align: center; position: relative; }
        .step::after { content: ''; position: absolute; top: 15px; left: 50%; width: 100%; height: 2px; background: #ddd; z-index: -1; }
        .step:last-child::after { display: none; }
        .step.active .step-number { background: #667eea; color: white; }
        .step.completed .step-number { background: #28a745; color: white; }
        .step-number { width: 30px; height: 30px; border-radius: 50%; background: #ddd; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-card">
            <div class="install-header text-center">
                <h2 class="mb-0">🎓 课表系统安装向导</h2>
                <p class="mb-0 mt-2 opacity-75">KeBiao v2.0</p>
            </div>
            
            <div class="card-body p-4">
                <div class="step-indicator">
                    <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>">
                        <div class="step-number">1</div>
                        <div class="small mt-2">数据库配置</div>
                    </div>
                    <div class="step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'completed' : '' ?>">
                        <div class="step-number">2</div>
                        <div class="small mt-2">安装数据表</div>
                    </div>
                    <div class="step <?= $step >= 3 ? 'active' : '' ?>">
                        <div class="step-number">3</div>
                        <div class="small mt-2">完成</div>
                    </div>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>
                
                <?php if ($step == 1): ?>
                    <h5 class="mb-3">步骤 1: 配置数据库</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">数据库主机</label>
                            <input type="text" class="form-control" name="db_host" value="localhost" required>
                            <small class="form-text text-muted">通常为 localhost</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">数据库名</label>
                            <input type="text" class="form-control" name="db_name" value="kebiaov2" required>
                            <small class="form-text text-muted">如果数据库不存在，将自动创建</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">数据库用户名</label>
                            <input type="text" class="form-control" name="db_user" value="root" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">数据库密码</label>
                            <input type="password" class="form-control" name="db_pass">
                            <small class="form-text text-muted">如果没有密码，留空即可</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">数据表前缀</label>
                            <input type="text" class="form-control" name="db_prefix" value="kb_" required>
                            <small class="form-text text-muted">例如: kb_user_accounts</small>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">下一步</button>
                    </form>
                <?php elseif ($step == 2): ?>
                    <h5 class="mb-3">步骤 2: 安装数据表</h5>
                    <p class="text-muted">点击下方按钮开始创建数据表...</p>
                    <form method="POST">
                        <button type="submit" class="btn btn-primary w-100">开始安装</button>
                    </form>
                <?php elseif ($step == 3): ?>
                    <div class="text-center py-4">
                        <div class="mb-4">
                            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#28a745" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <path d="M9 12l2 2 4-4"></path>
                            </svg>
                        </div>
                        <h5 class="text-success mb-3">安装成功！</h5>
                        <p class="text-muted mb-4">课表系统已成功安装，您现在可以开始使用了。</p>
                        <a href="../index.php" class="btn btn-primary">进入系统</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="text-center mt-3 text-white">
            <small>&copy; 2025 KeBiao v2 · 课表管理系统</small>
        </div>
    </div>
</body>
</html>
