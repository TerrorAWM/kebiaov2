<?php 
/**
 * 数据库配置文件
 * 加载配置并提供表名辅助函数
 */

// 加载配置文件
if (!file_exists(__DIR__ . '/config.php')) {
    // 如果配置文件不存在，重定向到安装页面
    if (!defined('INSTALLING') && php_sapi_name() !== 'cli') {
        $install_path = rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/install/';
        header('Location: ' . $install_path);
        exit;
    }
    // CLI或安装过程中，使用默认配置
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'kebiaov2');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_PREFIX', 'kb_');
    define('DB_CHARSET', 'utf8mb4');
} else {
    require_once __DIR__ . '/config.php';
}

// 构建DSN
define('DB_DSN', 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET);

/**
 * 获取带前缀的表名
 * @param string $table 表名（不含前缀）
 * @return string 完整表名（含前缀）
 */
function table($table) {
    return DB_PREFIX . $table;
}
/* ================================================= */
?>