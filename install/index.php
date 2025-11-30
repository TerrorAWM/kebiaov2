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
        $current_step = $_GET['step'] ?? 1;
        
        // 如果不是在第3步（完成页面），则返回404
        if ($current_step != 3) {
            http_response_code(404);
            exit;
        }
        // step=3时允许显示完成页面
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
        $backup_pass = trim($_POST['backup_pass'] ?? '');
        $db_prefix = trim($_POST['db_prefix'] ?? 'kb_');
        
        if (empty($backup_pass)) {
            $error = '请设置备份密码';
        } else {

        
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
                'backup_pass' => $backup_pass,
                'db_prefix' => $db_prefix
            ];
            
            header('Location: ?step=2');
            exit;
        } catch (PDOException $e) {
            $error = '数据库连接失败: ' . $e->getMessage();
        }
        }
    } elseif ($step == 2) {
        // 创建表结构
        if (!isset($_SESSION['install_config'])) {
            header('Location: ?step=1');
            exit;
        }
        
        $config = $_SESSION['install_config'];
        $action = $_POST['action'] ?? 'install';
        
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
            
            // 执行建表SQL
            $pdo->exec($sql);
            
            // 如果是还原备份
            if ($action === 'restore') {
                $backup_file = $_POST['backup_file'] ?? '';
                $restore_pass = $_POST['restore_pass'] ?? '';
                
                if (!file_exists($backup_file)) throw new Exception('备份文件不存在');
                
                // 验证密码
                $handle = fopen($backup_file, 'r');
                $header = fread($handle, 1024);
                fclose($handle);
                
                if (!preg_match('/-- BACKUP_PASSWORD_HASH: (\S+)/', $header, $matches)) {
                    throw new Exception('备份文件格式错误或未包含密码哈希');
                }
                
                if (!password_verify($restore_pass, $matches[1])) {
                    throw new Exception('备份密码错误');
                }
                
                // 获取旧前缀
                $old_prefix = 'kb_';
                if (preg_match('/-- TABLE_PREFIX: (\S+)/', $header, $matches_p)) {
                    $old_prefix = $matches_p[1];
                }
                
                // 读取并执行备份数据
                $backup_sql = file_get_contents($backup_file);
                $backup_sql = str_replace("`{$old_prefix}", "`{$prefix}", $backup_sql);
                $pdo->exec($backup_sql);
            }
            
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
            $config_content .= "define('BACKUP_PASSWORD', '" . addslashes($config['backup_pass']) . "');\n";
            
            $config_file = __DIR__ . '/../config.php';
            
            // 检查目录是否可写
            if (!is_writable(dirname($config_file))) {
                throw new Exception('目录权限不足！请确保 ' . dirname($config_file) . ' 目录可写。<br>Linux/Mac: chmod 755 ' . dirname($config_file));
            }
            
            // 尝试写入配置文件
            if (file_put_contents($config_file, $config_content) === false) {
                throw new Exception('无法写入配置文件！请检查目录权限。');
            }
            
            header('Location: ?step=3');
            exit;
        } catch (Exception $e) {
            $error = '安装/还原失败: ' . $e->getMessage();
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { 
            background-color: #f8f9fa;
            min-height: 100vh;
            padding: 20px 0;
        }
        .install-container { 
            max-width: 700px;
            margin: 0 auto;
        }
        .progress-header {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .install-card { 
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            position: relative;
            margin-bottom: 10px;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #dee2e6;
            z-index: -1;
        }
        .step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid #dee2e6;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            transition: all 0.3s;
            margin: 0 auto;
        }
        .step.active .step-circle {
            background: #0d6efd;
            border-color: #0d6efd;
            color: white;
        }
        .step.completed .step-circle {
            background: #198754;
            border-color: #198754;
            color: white;
        }
        .step-label {
            margin-top: 8px;
            font-size: 14px;
            color: #6c757d;
        }
        .step.active .step-label {
            color: #0d6efd;
            font-weight: 500;
        }
        .step.completed .step-label {
            color: #198754;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 6px;
        }
        .success-icon {
            color: #198754;
            font-size: 64px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <!-- Header -->
        <div class="text-center mb-4">
            <h2 class="fw-bold text-primary"><i class="bi bi-calendar-check"></i> 课表系统</h2>
            <p class="text-muted">安装向导</p>
        </div>

        <!-- Progress Steps -->
        <div class="progress-header">
            <div class="step-indicator">
                <div class="step <?= $step >= 1 ? 'active' : '' ?> <?= $step > 1 ? 'completed' : '' ?>">
                    <div class="step-circle">
                        <?php if ($step > 1): ?>
                            <i class="bi bi-check-lg"></i>
                        <?php else: ?>
                            1
                        <?php endif; ?>
                    </div>
                    <div class="step-label">数据库配置</div>
                </div>
                <div class="step <?= $step >= 2 ? 'active' : '' ?> <?= $step > 2 ? 'completed' : '' ?>">
                    <div class="step-circle">
                        <?php if ($step > 2): ?>
                            <i class="bi bi-check-lg"></i>
                        <?php else: ?>
                            2
                        <?php endif; ?>
                    </div>
                    <div class="step-label">安装数据表</div>
                </div>
                <div class="step <?= $step >= 3 ? 'active' : '' ?>">
                    <div class="step-circle">3</div>
                    <div class="step-label">完成</div>
                </div>
            </div>
        </div>
        
        <!-- Main Content Card -->
        <div class="install-card">
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <?= htmlspecialchars($success) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($step == 1): ?>
                    <h5 class="card-title mb-4"><i class="bi bi-database"></i> 步骤 1: 配置数据库</h5>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">数据库主机 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="db_host" value="localhost" required>
                            <div class="form-text">通常为 localhost 或 127.0.0.1</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">数据库名 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="db_name" value="kebiaov2" required>
                            <div class="form-text">如果数据库不存在，将自动创建</div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">数据库用户名 <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="db_user" value="root" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">数据库密码</label>
                                <input type="password" class="form-control" name="db_pass" placeholder="如无密码则留空">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">备份密码 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="backup_pass" required>
                            <div class="form-text">用于后续备份和还原数据的密码</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">数据表前缀 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="db_prefix" value="kb_" required>
                            <div class="form-text">例如: kb_user_accounts，可自定义</div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-arrow-right-circle"></i> 下一步
                            </button>
                        </div>
                    </form>
                <?php elseif ($step == 2): ?>
                    <h5 class="card-title mb-4"><i class="bi bi-gear"></i> 步骤 2: 安装/还原</h5>
                    
                    <?php 
                    $backups = glob(__DIR__ . '/../backup/db/*.sql');
                    if (!empty($backups)): 
                    ?>
                    <div class="card mb-4 border-primary">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-cloud-download"></i> 从备份还原
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="restore">
                                <div class="mb-3">
                                    <label class="form-label">选择备份文件</label>
                                    <select name="backup_file" class="form-select">
                                        <?php foreach ($backups as $file): ?>
                                            <option value="<?= htmlspecialchars($file) ?>">
                                                <?= htmlspecialchars(basename($file)) ?> 
                                                (<?= date('Y-m-d H:i', filemtime($file)) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">备份密码</label>
                                    <input type="password" name="restore_pass" class="form-control" required placeholder="请输入创建备份时的密码">
                                </div>
                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> 还原数据
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="text-center mb-3 text-muted">- 或者 -</div>
                    <?php endif; ?>

                    <div class="card border-success">
                        <div class="card-header bg-success text-white">
                            <i class="bi bi-stars"></i> 全新安装
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                将创建新的空数据表。
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="install">
                                <button type="submit" class="btn btn-success w-100 btn-lg">
                                    <i class="bi bi-play-circle"></i> 开始全新安装
                                </button>
                            </form>
                        </div>
                    </div>
                <?php elseif ($step == 3): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-check-circle success-icon"></i>
                        <h4 class="text-success mt-3 mb-2">安装成功！</h4>
                        <p class="text-muted mb-4">课表系统已成功安装并配置完成。</p>
                        
                        <div class="alert alert-success text-start mb-4">
                            <h6 class="alert-heading"><i class="bi bi-check2-square"></i> 安装完成清单</h6>
                            <ul class="mb-0 small">
                                <li>✓ 数据库表已创建</li>
                                <li>✓ 配置文件已生成</li>
                                <li>✓ 系统已锁定（install目录已禁用访问）</li>
                            </ul>
                        </div>
                        
                        <div class="alert alert-warning text-start mb-4">
                            <h6 class="alert-heading"><i class="bi bi-shield-exclamation"></i> 安全提示</h6>
                            <p class="small mb-2">为了系统安全，建议您：</p>
                            <ul class="mb-0 small">
                                <li>立即注册管理员账号</li>
                                <li>妥善保管数据库连接信息</li>
                                <li>定期备份数据</li>
                            </ul>
                        </div>
                        
                        <div class="d-grid gap-2 col-md-8 mx-auto">
                            <a href="../register.php" class="btn btn-success btn-lg">
                                <i class="bi bi-person-plus"></i> 立即注册账号
                            </a>
                            <a href="../index.php" class="btn btn-outline-primary">
                                <i class="bi bi-box-arrow-in-right"></i> 进入系统首页
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
