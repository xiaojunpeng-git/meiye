-- SKU identity and category are queryable snapshot dimensions, not a fabricated source sale line.
ALTER TABLE `eb_cashier_v3_refund_adjustment`
  ADD COLUMN `catalog_item_id` bigint(20) unsigned NOT NULL DEFAULT '0' AFTER `source_line_id`,
  ADD COLUMN `catalog_kind` varchar(16) NOT NULL DEFAULT '' AFTER `catalog_item_id`,
  ADD KEY `idx_catalog_item` (`tenant_id`, `catalog_item_id`, `business_date`, `id`);
