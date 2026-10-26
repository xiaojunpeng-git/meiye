-- upgrade_key: 20260808-001-platform-menu-sort-range
SET NAMES utf8mb4;

ALTER TABLE `eb_system_menus`
  MODIFY COLUMN `sort` INT NOT NULL DEFAULT 1 COMMENT '排序';
