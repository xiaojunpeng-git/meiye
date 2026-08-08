-- 库存 V3 跨主体调拨：业务调拨人与平台操作审计分离。
-- 执行前先运行同目录 01-升级前检查.sql；本文件只作为源码迁移正本，禁止直接在线上手工改表。

ALTER TABLE `eb_inventory_cross_transfer_document`
  ADD COLUMN `transfer_staff_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '调拨业务人员门店任职ID' AFTER `initiator_store_id`,
  ADD COLUMN `transfer_employee_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '调拨业务人员统一员工ID快照' AFTER `transfer_staff_id`,
  ADD COLUMN `transfer_staff_name_snapshot` varchar(120) NOT NULL DEFAULT '' COMMENT '调拨业务人员姓名快照' AFTER `transfer_employee_id`,
  ADD KEY `idx_transfer_staff` (`transfer_staff_id`);
