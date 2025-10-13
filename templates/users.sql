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
-- 表的结构 `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `login_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` enum('普通用户','中层','高层','管理员') NOT NULL,
  `birthdate` date NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login_id` (`login_id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1841 ;

--
-- 转存表中的数据 `users`
--

INSERT INTO `users` (`id`, `login_id`, `name`, `role`, `birthdate`, `password_hash`, `created_at`) VALUES
(928, 1001, '系统管理员', '管理员', '1980-01-01', '$2y$12$SAI5OhUuDWCFTT9OawjK/OdJLyzEUbT6IxBrdm6kaSNlpYoQri4gG', '2025-10-03 13:28:35'),
(1838, 1001001, '测试1', '普通用户', '1990-05-05', '$2y$10$foWVTtcMarpdKyA2bZ0ol.tfy93gu2tQwrzroP9zeeY4NrT8NMsHO', '2025-10-04 03:48:23'),
(1839, 1001002, '测试2', '普通用户', '1990-05-06', '$2y$10$/pjldbSdk3TMkjObmsxZqOV0nU.eZxAKMe4j1Q8jVfgqBY5.eYode', '2025-10-04 03:48:23'),
(1840, 1001003, '测试3', '中层', '1990-05-23', '$2y$10$7HFQ2k6otCG.mqynch9D..rwpWBgXxTmje.qBJeauXbmLkFyr9706', '2025-10-04 03:48:23');

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
