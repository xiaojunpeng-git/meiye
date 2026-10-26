CREATE TABLE IF NOT EXISTS `eb_store_fund_subject` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(64) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `subject_type` varchar(16) NOT NULL DEFAULT 'NORMAL',
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_fund_subject_code` (`subject_code`), KEY `idx_fund_subject_enabled` (`is_enabled`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='费用及专项科目';

CREATE TABLE IF NOT EXISTS `eb_store_fund_document` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_no` varchar(48) NOT NULL,
  `store_id` bigint unsigned NOT NULL,
  `business_date` date NOT NULL,
  `direction` varchar(8) NOT NULL,
  `document_status` varchar(16) NOT NULL DEFAULT 'DRAFT',
  `summary` varchar(255) NOT NULL DEFAULT '',
  `remark` text NULL,
  `attachments_json` text NULL,
  `source_document_id` bigint unsigned NOT NULL DEFAULT 0,
  `created_by_type` varchar(16) NOT NULL,
  `created_by_id` bigint unsigned NOT NULL,
  `created_by_name_snapshot` varchar(100) NOT NULL DEFAULT '',
  `audited_by_type` varchar(16) NOT NULL DEFAULT '',
  `audited_by_id` bigint unsigned NOT NULL DEFAULT 0,
  `audited_at` datetime NULL,
  `audit_revision` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_fund_document_no` (`document_no`), KEY `idx_fund_document_store_date` (`store_id`,`business_date`,`document_status`), KEY `idx_fund_document_source` (`source_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店费用收支单';

CREATE TABLE IF NOT EXISTS `eb_store_fund_document_line` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `line_no` int unsigned NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `subject_code_snapshot` varchar(64) NOT NULL,
  `subject_name_snapshot` varchar(100) NOT NULL,
  `subject_type_snapshot` varchar(16) NOT NULL,
  `amount_cents` bigint NOT NULL,
  `summary` varchar(255) NOT NULL DEFAULT '',
  `remark` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uniq_fund_document_line` (`document_id`,`line_no`), KEY `idx_fund_line_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='门店费用收支单明细';

INSERT IGNORE INTO `eb_store_fund_subject` (`subject_code`,`subject_name`,`subject_type`,`sort_order`,`is_enabled`,`created_at`,`updated_at`) VALUES
('TEAM_BUILDING','团建费','SPECIAL',10,1,NOW(),NOW()),
('EXPRESS','快递物流费','NORMAL',20,1,NOW(),NOW()),
('UTILITY','水电燃气费','NORMAL',30,1,NOW(),NOW()),
('PURCHASE','日常零购','NORMAL',40,1,NOW(),NOW()),
('OTHER','其他费用','NORMAL',999,1,NOW(),NOW());
