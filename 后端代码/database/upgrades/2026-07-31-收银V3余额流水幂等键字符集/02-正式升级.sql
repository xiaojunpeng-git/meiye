-- upgrade_key: 20260731-002-cashier-v3-balance-ledger-idempotency-ascii
SET NAMES utf8mb4;
SET @db := DATABASE();
SELECT COUNT(*) INTO @ascii_exact FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=@db AND TABLE_NAME='eb_user_money' AND COLUMN_NAME='idempotency_key'
  AND CHARACTER_SET_NAME='ascii' AND COLLATION_NAME='ascii_bin';
SET @apply_sql := IF(@ascii_exact=1,'SELECT ''idempotency_key_ascii_bin_exists'' AS migration_step',
  'ALTER TABLE `eb_user_money` MODIFY COLUMN `idempotency_key` varchar(128) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL');
PREPARE apply_stmt FROM @apply_sql; EXECUTE apply_stmt; DEALLOCATE PREPARE apply_stmt;
SELECT 'APPLY_OK' AS apply_result;
