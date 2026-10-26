-- upgrade_key: 20260803-004-platform-card-product-rules
-- MySQL 5.6 compatible. Existing rows are deliberately not backfilled.
SET NAMES utf8mb4;

ALTER TABLE `eb_store_product`
  ADD COLUMN `card_rule_type` varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER `card_num_type`,
  ADD COLUMN `card_rule_version` int(10) unsigned NOT NULL DEFAULT '0' AFTER `card_rule_type`,
  ADD COLUMN `card_choice_limit` int(10) unsigned NOT NULL DEFAULT '0' AFTER `card_rule_version`,
  ADD COLUMN `card_shared_times` int(10) unsigned NOT NULL DEFAULT '0' AFTER `card_choice_limit`,
  ADD KEY `idx_card_product_rule` (`product_type`,`card_rule_type`,`is_del`,`is_show`);

ALTER TABLE `eb_store_card_related`
  ADD COLUMN `writeoff_amount` decimal(12,2) unsigned NOT NULL DEFAULT '0.00' AFTER `write_times`;
