-- upgrade_key: 20260731-003-cashier-v3-balance-components-not-null
SET NAMES utf8mb4;
ALTER TABLE `eb_user`
  MODIFY COLUMN `ben_money` decimal(10,2) NOT NULL DEFAULT '0.00',
  MODIFY COLUMN `give_money` decimal(10,2) NOT NULL DEFAULT '0.00';
ALTER TABLE `eb_user_money`
  MODIFY COLUMN `ben_money` decimal(10,2) NOT NULL DEFAULT '0.00',
  MODIFY COLUMN `give_money` decimal(10,2) NOT NULL DEFAULT '0.00';
SELECT 'APPLY_OK' AS apply_result;
