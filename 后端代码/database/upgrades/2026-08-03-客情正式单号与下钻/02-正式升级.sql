-- upgrade_key: 20260803-001-customer-care-document-number
-- MySQL 5.6 compatible. Run only after 01 returns PRECHECK_OK.
-- Historical customer-care rows are deliberately untouched. Only new rows receive a formal number.
SET NAMES utf8mb4;

ALTER TABLE `eb_customer_care_task`
  ADD COLUMN `task_no` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `task_key`,
  ADD UNIQUE KEY `uk_tenant_task_no` (`tenant_id`,`task_no`);

ALTER TABLE `eb_customer_care_record`
  ADD COLUMN `record_no` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `record_key`,
  ADD UNIQUE KEY `uk_tenant_record_no` (`tenant_id`,`record_no`);

CREATE TABLE `eb_customer_care_document_sequence` (
  `tenant_id` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `business_date` char(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `document_type` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `current_value` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(11) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`tenant_id`,`business_date`,`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='customer care immutable document number sequence';

