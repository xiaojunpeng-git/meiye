-- 单次预约视觉元数据；不回填历史状态颜色，不更改会员资料或预约业务版本。
-- MySQL 5.7 可重复执行；只在唯一权威预约表上增加一列。
SET @reservation_color_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'eb_cashier_v3_reservation' AND COLUMN_NAME = 'calendar_color'),
  'SELECT 1',
  'ALTER TABLE `eb_cashier_v3_reservation` ADD COLUMN `calendar_color` varchar(12) NOT NULL DEFAULT '''' COMMENT ''当前预约颜色：空/red/yellow/blue/green/purple'''
);
PREPARE reservation_color_stmt FROM @reservation_color_ddl;
EXECUTE reservation_color_stmt;
DEALLOCATE PREPARE reservation_color_stmt;
