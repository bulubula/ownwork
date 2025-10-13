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
-- 表的结构 `projects`
--

CREATE TABLE IF NOT EXISTS `projects` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `project_id` int(10) unsigned NOT NULL,
  `category` varchar(100) NOT NULL,
  `level` varchar(100) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `manager_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_id` (`project_id`),
  KEY `manager_id` (`manager_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=2049 ;

--
-- 转存表中的数据 `projects`
--

INSERT INTO `projects` (`id`, `name`, `project_id`, `category`, `level`, `total_amount`, `manager_id`, `created_at`) VALUES
(2034, '项目01', 1, '管理创新', '一等奖', '50000.00', 1001001, '2025-10-04 04:12:14'),
(2035, '项目02', 2, '管理创新', '一等奖', '50000.00', 1001002, '2025-10-04 04:12:14'),
(2036, '项目03', 3, '管理创新', '二等奖', '30000.00', 1001003, '2025-10-04 04:12:14'),
(2037, '项目04', 4, '管理创新', '二等奖', '30000.00', 1001001, '2025-10-04 04:12:14'),
(2038, '项目05', 5, '管理创新', '三等奖', '10000.00', 1001002, '2025-10-04 04:12:14'),
(2039, '项目06', 6, '管理创新', '三等奖', '10000.00', 1001003, '2025-10-04 04:12:14'),
(2040, '项目07', 7, '管理创新', '三等奖', '10000.00', 1001001, '2025-10-04 04:12:14'),
(2041, '项目08', 8, '管理创新', '三等奖', '10000.00', 1001002, '2025-10-04 04:12:14'),
(2042, '项目09', 9, '技术创新', '一等奖', '50000.00', 1001003, '2025-10-04 04:12:14'),
(2043, '项目10', 10, '技术创新', '一等奖', '50000.00', 1001001, '2025-10-04 04:12:14'),
(2044, '项目11', 11, '技术创新', '二等奖', '30000.00', 1001002, '2025-10-04 04:12:14'),
(2045, '项目12', 12, '技术创新', '二等奖', '30000.00', 1001003, '2025-10-04 04:12:14'),
(2046, '项目13', 13, '技术创新', '三等奖', '10000.00', 1001001, '2025-10-04 04:12:14'),
(2047, '项目14', 14, '技术创新', '三等奖', '10000.00', 1001002, '2025-10-04 04:12:14'),
(2048, '项目15', 15, '技术创新', '三等奖', '10000.00', 1001003, '2025-10-04 04:12:14');

--
-- 限制导出的表
--

--
-- 限制表 `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`manager_id`) REFERENCES `users` (`login_id`);

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
