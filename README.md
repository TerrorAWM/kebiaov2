# KeBiao v2 - 智能课表系统

一个轻量、现代化的个人课表系统，支持主课表和实验课表双课表管理。

[在线体验 https://kebiao.ricardozheng.com](https://kebiao.ricardozheng.com)

**Version:** 2.1.0

---

## 主要特性

- **主课表 / 实验课表双系统**：支持一键切换，实验课表按需显示
- **高度自定义**：支持自定义课表时间、周数，可自由选择显示字段
- **现代化 UI**：响应式设计，适配电脑和手机
- **便捷管理**：右侧齿轮菜单集成编辑与设置入口，支持 CSV 导入/导出
- **课表分享**：支持生成分享链接，可设置访问密码和有效期
- **简单账户**：基于 4 位 PIN 码的轻量级认证系统
- **账号找回**：支持通过邮箱找回账号和重置 PIN 码

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

### 5. 数据库迁移（升级用户）

如果你是从旧版本升级，需要运行迁移脚本来更新数据库结构（添加邮箱字段和重置表）：

1. 确保 `config.php` 配置正确
2. 浏览器访问：`http://你的域名/migrate.php`
3. 等待迁移完成提示
4. 完成后建议删除 `migrate.php` 文件

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

## 版本历史

> 详细更新日志请查看 [HISTORY.md](HISTORY.md)

| 版本 | 日期 | 说明 |
| :--- | :--- | :--- |
| **v2.1.0** | 2025-12-01 | 新增邮箱找回/重置密码功能 |
| **v2.0.2** | 2025-11-28 | 修复显示字段/逻辑优化/设置菜单 |
| **v2.0.1** | 2025-11-25 | **重构版本**：引入新 UI、修复时间问题 |
| **v1.0.0** | 2024-09 | 引入双表系统、课表分享 |
| **v0.1.0** | 2023-11 | 初始版本发布 |

---

## 开源协议

本项目基于 **MIT License** 发布，欢迎 Fork 和二次开发。

如果对你有帮助，欢迎点一个 ⭐ Star 支持作者。
