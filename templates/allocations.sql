-- phpMyAdmin SQL Dump
-- version phpStudy 2014
-- http://www.phpmyadmin.net
--
-- 主机: localhost
-- 生成日期: 
-- 服务器版本: 5.5.53
-- PHP 版本: 7.2.1

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- 数据库: `reward_app`
--

-- --------------------------------------------------------

--
-- 表的结构 `allocations`
--

CREATE TABLE IF NOT EXISTS `allocations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_member_unique` (`project_id`,`user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=3 ;

--
-- 转存表中的数据 `allocations`
--

INSERT INTO `allocations` (`id`, `project_id`, `user_id`, `amount`, `created_at`) VALUES
(1, 1, 1001003, '0.00', '2025-10-04 04:33:39'),
(2, 4, 1001001, '0.00', '2025-10-04 04:35:20');

--
-- 限制导出的表
--

--
-- 限制表 `allocations`
--
ALTER TABLE `allocations`
  ADD CONSTRAINT `allocations_ibfk_4` FOREIGN KEY (`user_id`) REFERENCES `users` (`login_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `allocations_ibfk_3` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
