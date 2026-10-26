-- upgrade_key: 20261012-card-debt-writeoff-block
-- MySQL 5.6 compatible; existing products and issued cards remain off.
ALTER TABLE `eb_store_product`
  ADD COLUMN `block_writeoff_on_debt` tinyint(1) unsigned NOT NULL DEFAULT '0' AFTER `card_shared_times`;
