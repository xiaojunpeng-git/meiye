-- 录入人类型区分平台管理员、组织员工与门店任职账号。历史单据留空保留原证据。
SET @inventory_transfer_creator_ddl = IF(
  EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'eb_inventory_cross_transfer_document'
      AND column_name = 'created_by_operator_type'
  ),
  'SELECT 1',
  'ALTER TABLE `eb_inventory_cross_transfer_document`
    ADD COLUMN `created_by_operator_type` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''''
    COMMENT ''原录入人身份类型'' AFTER `created_by_operator_id`'
);
PREPARE inventory_transfer_creator_stmt FROM @inventory_transfer_creator_ddl;
EXECUTE inventory_transfer_creator_stmt;
DEALLOCATE PREPARE inventory_transfer_creator_stmt;
