-- upgrade_key: 20260806-004-cashier-v3-old-card-entry-accounting-method
-- MySQL 5.6 compatible. This only adds a configuration projection for the existing separate legacy-card-entry flow.
SET NAMES utf8mb4;

INSERT INTO eb_cashier_v3_payment_method_config
  (code,default_name,display_name,status,sort,version,created_at,updated_at)
VALUES
  ('old_card_entry','旧卡录入','旧卡录入',1,80,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE default_name=VALUES(default_name);

SELECT 'APPLY_OK' AS apply_result;
