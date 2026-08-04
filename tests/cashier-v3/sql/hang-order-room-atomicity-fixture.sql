SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `eb_table_qrcode` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` int(11) unsigned NOT NULL DEFAULT '0',
  `cate_id` int(11) unsigned NOT NULL DEFAULT '0',
  `remarks` varchar(128) NOT NULL DEFAULT '',
  `table_number` varchar(64) NOT NULL DEFAULT '',
  `seat_num` int(11) unsigned NOT NULL DEFAULT '0',
  `is_using` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `is_del` tinyint(3) unsigned NOT NULL DEFAULT '0',
  `add_time` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_store_status` (`store_id`,`is_del`,`is_using`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `eb_table_qrcode`
  (`id`,`store_id`,`cate_id`,`remarks`,`table_number`,`seat_num`,`is_using`,`is_del`,`add_time`)
VALUES
  (301,8,1,'普通1','P301',1,1,0,1785254400),
  (302,8,1,'普通2','P302',1,1,0,1785254400);
