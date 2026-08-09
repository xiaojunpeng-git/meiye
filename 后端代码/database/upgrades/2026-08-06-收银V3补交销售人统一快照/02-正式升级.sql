-- upgrade_key: 20260806-001-cashier-v3-repayment-salesperson-snapshot
-- MySQL 5.6 compatible. Adds snapshot columns only; no old-data backfill.
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `cashier_v3_add_repayment_snapshot_column`;
DELIMITER $$
CREATE PROCEDURE `cashier_v3_add_repayment_snapshot_column`(IN p_table varchar(64))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=p_table
      AND COLUMN_NAME='salespeople_snapshot_json'
  ) THEN
    SET @repayment_snapshot_alter = CONCAT(
      'ALTER TABLE `', p_table,
      '` ADD COLUMN `salespeople_snapshot_json` mediumtext NOT NULL COMMENT ''operator-selected repayment salespeople authority snapshot'''
    );
    PREPARE repayment_snapshot_stmt FROM @repayment_snapshot_alter;
    EXECUTE repayment_snapshot_stmt;
    DEALLOCATE PREPARE repayment_snapshot_stmt;
  END IF;
END$$
DELIMITER ;

CALL cashier_v3_add_repayment_snapshot_column('eb_cashier_v3_debt_repayment_draft');
CALL cashier_v3_add_repayment_snapshot_column('eb_cashier_v3_debt_repayment');
CALL cashier_v3_add_repayment_snapshot_column('eb_cashier_v3_recharge_debt_repayment');

DROP PROCEDURE IF EXISTS `cashier_v3_add_repayment_snapshot_column`;
SELECT 'APPLY_OK' AS apply_result;
