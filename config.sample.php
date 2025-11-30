<?php
// config.sample.php - 配置文件示例
// 复制此文件为 config.php 并填写实际的数据库信息

// 数据库配置
define('DB_HOST', 'localhost');          // 数据库主机
define('DB_NAME', 'kebiaov2');           // 数据库名
define('DB_USER', 'root');               // 数据库用户名
define('DB_PASS', '');                   // 数据库密码
define('DB_PREFIX', 'kb_');              // 数据表前缀（如：kb_user_accounts）
define('DB_CHARSET', 'utf8mb4');         // 字符集

// 安全配置
define('INSTALLED', false);              // 安装完成后会自动设置为 true
define('BACKUP_PASSWORD', '');           // 备份/还原密码

// 邮件配置
define('SMTP_HOST', '');                 // SMTP服务器
define('SMTP_PORT', 465);                // SMTP端口 (465/587)
define('SMTP_USER', '');                 // SMTP账号
define('SMTP_PASS', '');                 // SMTP密码
define('SMTP_TEMPLATE', 'default.html'); // 邮件模版

