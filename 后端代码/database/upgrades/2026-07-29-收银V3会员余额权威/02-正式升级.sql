-- upgrade_key: 20260729-009-cashier-v3-member-balance-authority
-- MySQL 5.6.51 compatible. Run 01 before this file.
SET NAMES utf8mb4;
SET @mba_db := DATABASE();

SELECT COUNT(*) INTO @mba_has_balance_version
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user' AND COLUMN_NAME='balance_version';
SET @mba_sql := IF(
  @mba_has_balance_version=0,
  'ALTER TABLE `eb_user` ADD COLUMN `balance_version` bigint(20) unsigned NOT NULL DEFAULT ''1'' AFTER `give_money`',
  'SELECT ''balance_version_exists'' AS migration_step'
);
PREPARE mba_stmt FROM @mba_sql;
EXECUTE mba_stmt;
DEALLOCATE PREPARE mba_stmt;

SELECT COUNT(*) INTO @mba_has_ben_change
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='ben_change_amount';
SET @mba_sql := IF(
  @mba_has_ben_change=0,
  'ALTER TABLE `eb_user_money` ADD COLUMN `ben_change_amount` decimal(12,2) NULL DEFAULT NULL AFTER `give_money`',
  'SELECT ''ben_change_amount_exists'' AS migration_step'
);
PREPARE mba_stmt FROM @mba_sql;
EXECUTE mba_stmt;
DEALLOCATE PREPARE mba_stmt;

SELECT COUNT(*) INTO @mba_has_give_change
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='give_change_amount';
SET @mba_sql := IF(
  @mba_has_give_change=0,
  'ALTER TABLE `eb_user_money` ADD COLUMN `give_change_amount` decimal(12,2) NULL DEFAULT NULL AFTER `ben_change_amount`',
  'SELECT ''give_change_amount_exists'' AS migration_step'
);
PREPARE mba_stmt FROM @mba_sql;
EXECUTE mba_stmt;
DEALLOCATE PREPARE mba_stmt;

SELECT COUNT(*) INTO @mba_has_idempotency_key
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key';
SET @mba_sql := IF(
  @mba_has_idempotency_key=0,
  'ALTER TABLE `eb_user_money` ADD COLUMN `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `give_change_amount`',
  'SELECT ''idempotency_key_exists'' AS migration_step'
);
PREPARE mba_stmt FROM @mba_sql;
EXECUTE mba_stmt;
DEALLOCATE PREPARE mba_stmt;

SELECT COUNT(*) INTO @mba_has_idempotency_fingerprint
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_fingerprint';
SET @mba_sql := IF(
  @mba_has_idempotency_fingerprint=0,
  'ALTER TABLE `eb_user_money` ADD COLUMN `idempotency_fingerprint` char(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL AFTER `idempotency_key`',
  'SELECT ''idempotency_fingerprint_exists'' AS migration_step'
);
PREPARE mba_stmt FROM @mba_sql;
EXECUTE mba_stmt;
DEALLOCATE PREPARE mba_stmt;

SELECT COUNT(*) INTO @mba_has_idempotency_unique
FROM (
  SELECT INDEX_NAME,NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS index_columns
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=@mba_db AND TABLE_NAME='eb_user_money'
  GROUP BY INDEX_NAME,NON_UNIQUE
  HAVING NON_UNIQUE=0 AND index_columns='idempotency_key'
    AND SUM(IF(SUB_PART IS NULL,0,1))=0
) mba_unique;
SET @mba_sql := IF(
  @mba_has_idempotency_unique=0,
  'ALTER TABLE `eb_user_money` ADD UNIQUE KEY `uk_balance_idempotency_key` (`idempotency_key`)',
  'SELECT ''idempotency_unique_exists'' AS migration_step'
);
PREPARE mba_stmt FROM @mba_sql;
EXECUTE mba_stmt;
DEALLOCATE PREPARE mba_stmt;

DROP TRIGGER IF EXISTS `eb_user_balance_version_bu`;
DELIMITER $$
CREATE TRIGGER `eb_user_balance_version_bu`
BEFORE UPDATE ON `eb_user`
FOR EACH ROW
BEGIN
  IF NOT (NEW.`now_money` <=> OLD.`now_money`)
     OR NOT (NEW.`ben_money` <=> OLD.`ben_money`)
     OR NOT (NEW.`give_money` <=> OLD.`give_money`) THEN
    IF OLD.`balance_version` >= 18446744073709551615 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='member balance version exhausted';
    END IF;
    SET NEW.`balance_version` = OLD.`balance_version` + 1;
  ELSE
    SET NEW.`balance_version` = OLD.`balance_version`;
  END IF;
END$$
DELIMITER ;

SELECT 'APPLY_OK' AS apply_result;
