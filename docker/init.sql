-- Database schema for mrpp API (matching Yii2 version)
-- This creates all tables needed by both Yii2 and Yii3 versions

SET NAMES utf8;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `auth_key` varchar(32) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `password_reset_token` varchar(255) DEFAULT NULL,
  `nickname` varchar(255) DEFAULT NULL,
  `created_at` int(11) DEFAULT NULL,
  `updated_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `password_reset_token` (`password_reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `file` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `md5` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `key` varchar(255) DEFAULT NULL,
  `size` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_file_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `verse` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `uuid` varchar(255) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `updater_id` int(11) DEFAULT NULL,
  `image_id` int(11) DEFAULT NULL,
  `version` int(11) DEFAULT 0,
  `info` text DEFAULT NULL,
  `data` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `fk_verse_author` (`author_id`),
  KEY `fk_verse_image` (`image_id`),
  KEY `fk_verse_updater` (`updater_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `snapshot` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `verse_id` int(11) NOT NULL,
  `uuid` varchar(255) DEFAULT NULL,
  `code` text DEFAULT NULL,
  `data` longtext DEFAULT NULL,
  `metas` longtext DEFAULT NULL,
  `resources` longtext DEFAULT NULL,
  `managers` longtext DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_snapshot_verse` (`verse_id`),
  KEY `fk_snapshot_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `meta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `updater_id` int(11) DEFAULT NULL,
  `image_id` int(11) DEFAULT NULL,
  `info` text DEFAULT NULL,
  `data` text DEFAULT NULL,
  `events` text DEFAULT NULL,
  `prefab` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `fk_meta_author` (`author_id`),
  KEY `fk_meta_image` (`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `key` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `property` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `info` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `verse_property` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `verse_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_vp_verse` (`verse_id`),
  KEY `fk_vp_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `verse_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `verse_id` int(11) NOT NULL,
  `tags_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `verse_tags_unique` (`verse_id`, `tags_id`),
  KEY `fk_vt_tags` (`tags_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `info` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_group_image` (`image_id`),
  KEY `fk_group_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `group_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `group_user_unique` (`user_id`, `group_id`),
  KEY `fk_gu_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `group_verse` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `verse_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_gv_group` (`group_id`),
  KEY `fk_gv_verse` (`verse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `manager` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `verse_id` int(11) NOT NULL,
  `type` varchar(255) NOT NULL,
  `data` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manager_unique` (`verse_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `code` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lua` text DEFAULT NULL,
  `js` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `verse_code` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blockly` text DEFAULT NULL,
  `verse_id` int(11) NOT NULL,
  `code_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `verse_id` (`verse_id`),
  UNIQUE KEY `code_id` (`code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `meta_code` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blockly` text DEFAULT NULL,
  `meta_id` int(11) NOT NULL,
  `code_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_id` (`meta_id`),
  UNIQUE KEY `code_id` (`code_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `resource` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `uuid` varchar(255) DEFAULT NULL,
  `file_id` int(11) NOT NULL,
  `image_id` int(11) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `updater_id` int(11) DEFAULT NULL,
  `info` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `fk_resource_file` (`file_id`),
  KEY `fk_resource_author` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `user_linked` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `key` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ul_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `watermark` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sn` varchar(255) NOT NULL,
  `hardware` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sn` (`sn`),
  KEY `fk_watermark_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `phototype` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `uuid` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `image_id` int(11) DEFAULT NULL,
  `updater_id` int(11) DEFAULT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `data` text DEFAULT NULL,
  `schema` text DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Insert test data
-- Test user (password: Test1234)
INSERT INTO `user` (`id`, `username`, `password_hash`, `auth_key`, `nickname`, `created_at`, `updated_at`)
VALUES (1, 'testuser', '$2y$10$OMXZUD/PV8H2QRUZb1gKVeElk3Hi4ASoV1QsAjVFE8WhLklUl4ijW', 'test-auth-key-123', 'TestNick', UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- Test properties
INSERT INTO `property` (`id`, `key`) VALUES (1, 'public'), (2, 'checkin');

-- Test tags
INSERT INTO `tags` (`id`, `name`, `key`, `type`) VALUES (1, 'Nature', 'nature', 'Classify'), (2, 'City', 'city', 'Classify');

-- Test verse
INSERT INTO `verse` (`id`, `name`, `description`, `uuid`, `author_id`, `image_id`, `created_at`, `updated_at`)
VALUES (1, 'Test Verse', 'A test verse', 'verse-uuid-001', 1, NULL, NOW(), NOW());

-- Mark verse as public
INSERT INTO `verse_property` (`verse_id`, `property_id`) VALUES (1, 1);

-- Test snapshot
INSERT INTO `snapshot` (`id`, `verse_id`, `uuid`, `code`, `data`, `metas`, `resources`, `managers`, `created_by`, `created_at`)
VALUES (1, 1, 'snap-uuid-001', 'test-code', '{"test":true}', '[]', '[]', '[]', 1, NOW());

-- Test user_linked
INSERT INTO `user_linked` (`id`, `user_id`, `key`, `created_at`)
VALUES (1, 1, 'test-linked-key', NOW());

SET FOREIGN_KEY_CHECKS = 1;
