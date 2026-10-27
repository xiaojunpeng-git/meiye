-- 独立人工补充；不覆盖旧工资 JSON、业绩事实或历史核销。冻结只控制门店人工项编辑。
CREATE TABLE IF NOT EXISTS `eb_basic_salary_manual` (
 `row_key` varchar(100) NOT NULL,
 `month` char(7) NOT NULL,
 `store_id` int unsigned NOT NULL,
 `employee_id` int unsigned NOT NULL,
 `values_json` text NOT NULL,
 `version` int unsigned NOT NULL,
 `actor_key` varchar(64) NOT NULL,
 `updated_at` bigint unsigned NOT NULL,
 PRIMARY KEY (`row_key`), UNIQUE KEY `salary_subject` (`month`,`store_id`,`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工资人工补充，覆盖读取默认值，不覆盖事实';
CREATE TABLE IF NOT EXISTS `eb_basic_salary_month_control` (
 `month` char(7) NOT NULL,
 `version` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='月份操作版本锁；冻结标记沿用salary_freeze';
CREATE TABLE IF NOT EXISTS `eb_basic_salary_manual_audit` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `subject_key` varchar(100) NOT NULL,
 `store_id` int unsigned NOT NULL,
 `actor_key` varchar(64) NOT NULL,
 `action` varchar(16) NOT NULL,
 `idempotency_key` varchar(128) NOT NULL,
 `request_fingerprint` char(64) NOT NULL,
 `before_json` mediumtext NOT NULL,
 `result_json` mediumtext NOT NULL,
 `created_at` bigint unsigned NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `salary_request` (`actor_key`,`idempotency_key`), KEY `salary_history` (`subject_key`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='工资人工编辑及冻结审计和幂等回执';
