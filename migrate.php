<?php
// migrate.php - Apply database changes for password reset feature
require_once __DIR__ . '/db.php';

try {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "开始数据库迁移...<br><br>";
    
    // 1. Add email column to user_accounts if not exists
    try {
        $pdo->query("SELECT email FROM " . DB_PREFIX . "user_accounts LIMIT 1");
        echo "✓ email 列已存在<br>";
    } catch (Exception $e) {
        echo "添加 email 列到 user_accounts...<br>";
        $pdo->exec("ALTER TABLE `" . DB_PREFIX . "user_accounts` ADD COLUMN `email` varchar(255) DEFAULT NULL AFTER `user_id`, ADD INDEX `idx_email` (`email`)");
        echo "✓ email 列添加成功<br>";
    }
    
    // 2. Create password_resets table if not exists
    $table = DB_PREFIX . 'password_resets';
    $checkTable = $pdo->query("SHOW TABLES LIKE '$table'")->fetch();
    
    if ($checkTable) {
        echo "✓ password_resets 表已存在<br>";
    } else {
        echo "创建 password_resets 表...<br>";
        $sql = "CREATE TABLE `$table` (
          `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          `user_id` int(10) UNSIGNED NOT NULL,
          `token` varchar(64) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_token` (`token`),
          KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        $pdo->exec($sql);
        echo "✓ password_resets 表创建成功<br>";
    }
    
    echo "<br><strong style='color: green;'>✓ 迁移完成！</strong><br><br>";
    echo "<a href='index.php'>返回首页</a> | <a href='findaccount.php'>测试找回功能</a>";
    
} catch (Exception $e) {
    echo "<strong style='color: red;'>迁移失败: " . htmlspecialchars($e->getMessage()) . "</strong><br>";
    echo "<br>如果遇到问题，请检查：<br>";
    echo "1. 数据库连接是否正常<br>";
    echo "2. config.php 中的数据库配置是否正确<br>";
    echo "3. 数据库用户是否有 ALTER TABLE 权限<br>";
}
