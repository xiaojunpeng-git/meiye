-- 第一阶段门店端员工操作权限覆盖。MySQL 5.6 兼容；执行前先跑 01-升级前检查.sql。
CREATE TABLE IF NOT EXISTS `eb_staff_store_v3_feature_override` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `staff_id` int unsigned NOT NULL DEFAULT 0 COMMENT '当前门店任职 system_store_staff.id',
  `employee_id` int unsigned NOT NULL DEFAULT 0 COMMENT '员工冗余键，仅用于完整性与审计检索',
  `store_id` int unsigned NOT NULL DEFAULT 0 COMMENT '任职所属门店',
  `feature_code` varchar(128) NOT NULL DEFAULT '' COMMENT 'cashier.v3.* 稳定操作权限码',
  `effect` varchar(16) NOT NULL DEFAULT 'inherit' COMMENT 'inherit/allow/deny',
  `version` int unsigned NOT NULL DEFAULT 1 COMMENT '乐观锁版本',
  `status` tinyint unsigned NOT NULL DEFAULT 1,
  `is_del` tinyint unsigned NOT NULL DEFAULT 0,
  `add_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_employee_store_feature` (`staff_id`,`employee_id`,`store_id`,`feature_code`),
  KEY `idx_employee_active` (`employee_id`,`status`,`is_del`),
  KEY `idx_store_active` (`store_id`,`status`,`is_del`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店端员工个人操作权限覆盖';

-- 初始不回填覆盖行：无记录即继承岗位，避免上线时扩大或收缩既有权限。

-- 兼容已有岗位：曾拥有模块入口的岗位继续拥有该模块原有操作，
-- 仅针对 store_v3 有效规则；客情 301008 不映射会员操作权限。
UPDATE eb_job_position_channel_rule SET rules = CONCAT_WS(',', rules,
  IF(FIND_IN_SET('301010',rules),NULL,'301010'),IF(FIND_IN_SET('301011',rules),NULL,'301011'),IF(FIND_IN_SET('301012',rules),NULL,'301012'),IF(FIND_IN_SET('301013',rules),NULL,'301013'),IF(FIND_IN_SET('301014',rules),NULL,'301014'),IF(FIND_IN_SET('301015',rules),NULL,'301015'),IF(FIND_IN_SET('301016',rules),NULL,'301016'),IF(FIND_IN_SET('301017',rules),NULL,'301017'),IF(FIND_IN_SET('301018',rules),NULL,'301018'),IF(FIND_IN_SET('301019',rules),NULL,'301019'))
WHERE channel = 'store_v3' AND status = 1 AND FIND_IN_SET('301001', rules) > 0;
UPDATE eb_job_position_channel_rule SET rules = CONCAT_WS(',', rules, IF(FIND_IN_SET('301020',rules),NULL,'301020'),IF(FIND_IN_SET('301021',rules),NULL,'301021'))
WHERE channel = 'store_v3' AND status = 1 AND FIND_IN_SET('301005', rules) > 0;
UPDATE eb_job_position_channel_rule SET rules = CONCAT_WS(',', rules, IF(FIND_IN_SET('301030',rules),NULL,'301030'),IF(FIND_IN_SET('301031',rules),NULL,'301031'),IF(FIND_IN_SET('301032',rules),NULL,'301032'),IF(FIND_IN_SET('301033',rules),NULL,'301033'),IF(FIND_IN_SET('301034',rules),NULL,'301034'),IF(FIND_IN_SET('301035',rules),NULL,'301035'),IF(FIND_IN_SET('301036',rules),NULL,'301036'),IF(FIND_IN_SET('301037',rules),NULL,'301037'))
WHERE channel = 'store_v3' AND status = 1 AND FIND_IN_SET('301006', rules) > 0;
UPDATE eb_job_position_channel_rule SET rules = CONCAT_WS(',', rules, IF(FIND_IN_SET('301040',rules),NULL,'301040'),IF(FIND_IN_SET('301041',rules),NULL,'301041'),IF(FIND_IN_SET('301042',rules),NULL,'301042'),IF(FIND_IN_SET('301043',rules),NULL,'301043'))
WHERE channel = 'store_v3' AND status = 1 AND FIND_IN_SET('301007', rules) > 0;
UPDATE eb_job_position_channel_rule SET rules = CONCAT_WS(',', rules, IF(FIND_IN_SET('301120',rules),NULL,'301120'),IF(FIND_IN_SET('301121',rules),NULL,'301121'),IF(FIND_IN_SET('301122',rules),NULL,'301122'))
WHERE channel = 'store_v3' AND status = 1 AND FIND_IN_SET('301100', rules) > 0;
