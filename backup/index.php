<?php
/**
 * 数据库备份工具
 */
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../db.php';

$message = '';
$error = '';

// 检查是否已安装
if (!defined('INSTALLED') || !INSTALLED) {
    die('系统尚未安装');
}

// 处理备份请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    
    // 验证密码
    if ($password !== BACKUP_PASSWORD) {
        $error = '备份密码错误';
    } else {
        try {
            $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            $tables = [];
            $stmt = $pdo->query("SHOW TABLES");
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }
            
            $content = "-- 课表系统备份文件\n";
            $content .= "-- 生成时间: " . date('Y-m-d H:i:s') . "\n";
            // 保存密码哈希用于还原验证
            $content .= "-- BACKUP_PASSWORD_HASH: " . password_hash($password, PASSWORD_DEFAULT) . "\n";
            $content .= "-- TABLE_PREFIX: " . DB_PREFIX . "\n\n";
            
            foreach ($tables as $table) {
                // 只备份当前前缀的表
                if (strpos($table, DB_PREFIX) !== 0) continue;
                
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll();
                
                if (count($rows) > 0) {
                    $content .= "-- Table: $table\n";
                    foreach ($rows as $row) {
                        $fields = array_map(function($value) use ($pdo) {
                            if ($value === null) return 'NULL';
                            return $pdo->quote($value);
                        }, $row);
                        
                        $content .= "INSERT INTO `$table` (`" . implode('`, `', array_keys($row)) . "`) VALUES (" . implode(', ', $fields) . ");\n";
                    }
                    $content .= "\n";
                }
            }
            
            $filename = 'backup_' . date('Ymd_His') . '.sql';
            $filepath = __DIR__ . '/db/' . $filename;
            
            if (file_put_contents($filepath, $content)) {
                $message = "备份成功！文件已保存为: $filename";
            } else {
                $error = "写入文件失败，请检查 backup/db/ 目录权限";
            }
            
        } catch (Exception $e) {
            $error = "备份失败: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统备份</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; padding-top: 50px; }
        .container { max-width: 500px; }
        .card { border: none; shadow: 0 0 15px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <div class="container">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-database-down"></i> 数据库备份</h5>
            </div>
            <div class="card-body">
                <?php if ($message): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">请输入备份密码</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> 开始备份
                        </button>
                        <a href="../index.php" class="btn btn-outline-secondary">返回首页</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
