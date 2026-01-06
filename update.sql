-- 签到功能：添加最后签到时间字段
ALTER TABLE `users` ADD COLUMN `last_checkin_at` TIMESTAMP NULL DEFAULT NULL AFTER `vip_expire_time`;

-- 管理员账号（如果需要，或者直接改 role）
-- 示例：将 id=1 的用户设为 admin
-- UPDATE `users` SET `role` = 'admin' WHERE `id` = 1;

CREATE TABLE IF NOT EXISTS `danmaku` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` varchar(50) NOT NULL COMMENT '来源key',
  `source_id` varchar(50) NOT NULL COMMENT '视频ID',
  `sid` int(11) NOT NULL DEFAULT 0 COMMENT '分组ID',
  `nid` int(11) NOT NULL DEFAULT 0 COMMENT '集数ID',
  `time` float NOT NULL DEFAULT 0 COMMENT '弹幕出现时间(秒)',
  `text` varchar(255) NOT NULL COMMENT '弹幕内容',
  `color` varchar(20) DEFAULT '#ffffff' COMMENT '颜色',
  `type` varchar(20) DEFAULT 'right' COMMENT '位置类型: right, top, bottom',
  `user_id` int(11) DEFAULT NULL COMMENT '发送者ID',
  `ip` varchar(45) DEFAULT NULL COMMENT '发送者IP',
  `status` tinyint(1) DEFAULT 1 COMMENT '状态: 1正常 0隐藏',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_video` (`source`, `source_id`, `sid`, `nid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
