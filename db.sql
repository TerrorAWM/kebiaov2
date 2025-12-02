-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2025-11-26 09:58:58
-- 服务器版本： 10.4.28-MariaDB
-- PHP 版本： 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `kebiaov2`
--

-- --------------------------------------------------------

--
-- 表的结构 `lab_uploads`
--

CREATE TABLE `lab_uploads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `filename` varchar(255) NOT NULL,
  `mimetype` varchar(255) DEFAULT NULL,
  `size_bytes` bigint(20) UNSIGNED DEFAULT 0,
  `parsed_json` longtext DEFAULT NULL COMMENT 'JSON: 解析出的 courses 预览',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `shared_links`
--

CREATE TABLE `shared_links` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` char(32) NOT NULL,
  `share_pass` char(4) NOT NULL,
  `scope` varchar(20) NOT NULL DEFAULT 'main',
  `tz_mode` enum('client_dynamic','client_fixed','custom') NOT NULL DEFAULT 'client_dynamic',
  `tz_value` varchar(64) DEFAULT NULL,
  `display_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`display_fields`)),
  `max_visits` int(10) UNSIGNED DEFAULT NULL,
  `visit_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` datetime DEFAULT NULL,
  `disabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user_accounts`
--

CREATE TABLE `user_accounts` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pin` char(4) NOT NULL,
  `profile` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profile`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user_lab_schedule`
--

CREATE TABLE `user_lab_schedule` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `data` longtext DEFAULT NULL COMMENT 'JSON: {start_date,tz,enabled_days,timeslots,courses,...}',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `user_schedule`
--

CREATE TABLE `user_schedule` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- 表的结构 `password_resets`
--

CREATE TABLE `password_resets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- 转储表的索引
--

--
-- 表的索引 `lab_uploads`
--
ALTER TABLE `lab_uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`);

--
-- 表的索引 `shared_links`
--
ALTER TABLE `shared_links`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_token` (`token`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_valid` (`disabled`,`expires_at`);

--
-- 表的索引 `user_accounts`
--
ALTER TABLE `user_accounts`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_email` (`email`);

--
-- 表的索引 `user_lab_schedule`
--
ALTER TABLE `user_lab_schedule`
  ADD PRIMARY KEY (`user_id`);

--
-- 表的索引 `user_schedule`
--
ALTER TABLE `user_schedule`
  ADD PRIMARY KEY (`user_id`);

--
-- 表的索引 `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_user` (`user_id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `lab_uploads`
--
ALTER TABLE `lab_uploads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `shared_links`
--
ALTER TABLE `shared_links`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 限制导出的表
--

--
-- 限制表 `user_schedule`
--
ALTER TABLE `user_schedule`
  ADD CONSTRAINT `fk_user` FOREIGN KEY (`user_id`) REFERENCES `user_accounts` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
