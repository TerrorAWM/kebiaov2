# KeBiao v2 - 智能课表系统

一个轻量、现代化的个人课表系统，支持主课表和实验课表双课表管理。

[在线体验 https://kebiao.ricardozheng.com](https://kebiao.ricardozheng.com)

---

## 主要特性

- 主课表 / 实验课表双系统，一键切换
- 自定义作息时间、周数，实时高亮当前课程
- 简单账户系统（4 位 PIN 登录）
- 课表分享：支持访问密码、有效期设置
- 响应式页面，适配电脑和手机

---

## 环境要求

- PHP ≥ 8.0
- MySQL ≥ 5.7 / MariaDB ≥ 10.2
- Web 服务器（Apache / Nginx）

---

## 快速开始

### 1. 获取代码

```bash
git clone https://github.com/TerrorAWM/kebiaov2.git
cd kebiaov2
```

### 2. 配置图标（Font Awesome）

推荐使用 **CDN**，在相关 PHP/HTML 文件中，将：

```html
<link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
```

替换为：

```html
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
```

（如需本地部署，可将官方包解压到 `assets/fontawesome/` 并保持路径一致。）

### 3. 安装向导

在浏览器访问：

```text
http://你的域名/install/
```

按页面提示完成数据库配置和初始化数据表。

### 4. 注册并使用

安装完成后：

- 访问 `/register.php` 注册账号
- 访问根目录查看主课表页（`index.php`），即可开始配置自己的课表

---

## 📂 大致目录结构

```text
kebiaov2/
├── install/       # 安装向导
├── assets/        # 静态资源
├── index.php      # 主课表
├── lab.php        # 实验课表
├── edit.php       # 编辑主课表
├── edit_lab.php   # 编辑实验课表
└── db.sql         # 数据库结构
```

---

## 开源协议

本项目基于 **MIT License** 发布，欢迎 Fork 和二次开发。

如果对你有帮助，欢迎点一个 ⭐ Star 支持作者。


